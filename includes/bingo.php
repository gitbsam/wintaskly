<?php
/**
 * Wintaskly — Module BINGO (cycle de 7 jours)
 * ─────────────────────────────────────────────────────────────────────
 * Moteur du bingo à cycle : une partie dure jusqu'à 7 jours, 1 tirage/jour,
 * numéros accumulés, jackpot évolutif (+25%/carton payant).
 *
 * Sécurité & concurrence :
 *   - Crédits via award_user() (transactionnel).
 *   - Achat de carton : verrou sur le solde (SELECT ... FOR UPDATE).
 *   - Tirage quotidien & settlement : protégés contre la double-exécution.
 *   - Tolérant aux pannes : lectures renvoient vide si table absente.
 *
 * Modèle "lazy + cron" :
 *   - wt_bingo_tick() fait avancer le jeu : ouvre une partie si besoin,
 *     effectue le tirage du jour s'il manque, déclenche/règle les fins.
 *     Appelée à l'affichage de la page ET par le cron. Le premier qui passe
 *     fait le travail.
 *
 * Fins de partie (status active → ending) :
 *   - max_days tirages effectués
 *   - 1ère réclamation enregistrée
 *   - détection auto d'un carton 25/25 tiré
 * La distribution (ending → settled) a lieu au minuit suivant, puis une
 * nouvelle partie s'ouvre.
 */
declare(strict_types=1);

/* ===================================================================
 * CONFIG
 * =================================================================== */

if (!function_exists('wt_bingo_enabled')) {
    function wt_bingo_enabled(): bool
    {
        return (string) cfg('bingo.enabled', '0') === '1';
    }
}

if (!function_exists('wt_bingo_cfg')) {
    function wt_bingo_cfg(string $key, int $default = 0): int
    {
        return (int) cfg('bingo.' . $key, (string) $default);
    }
}

if (!function_exists('wt_bingo_today')) {
    function wt_bingo_today(): string
    {
        return gmdate('Y-m-d');
    }
}

/* ===================================================================
 * LECTURE DES PARTIES (rounds)
 * =================================================================== */

if (!function_exists('wt_bingo_round_by_id')) {
    function wt_bingo_round_by_id(int $id): ?array
    {
        try {
            $stmt = db()->prepare("SELECT * FROM bingo_rounds WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('wt_bingo_current_round')) {
    /**
     * La partie en cours (active ou ending = pas encore réglée).
     * Null si aucune.
     */
    function wt_bingo_current_round(): ?array
    {
        try {
            $res = db()->query(
                "SELECT * FROM bingo_rounds
                  WHERE status IN ('active','ending')
                  ORDER BY id DESC LIMIT 1"
            );
            if ($res instanceof mysqli_result) {
                $row = $res->fetch_assoc();
                $res->free();
                return $row ?: null;
            }
        } catch (Throwable $e) {
            error_log('[Wintaskly bingo] current_round: ' . $e->getMessage());
        }
        return null;
    }
}

if (!function_exists('wt_bingo_round_draws')) {
    /**
     * Tous les tirages d'une partie, ordonnés.
     * @return array Liste de tirages (draw_index, draw_date, numbers)
     */
    function wt_bingo_round_draws(int $roundId): array
    {
        $draws = [];
        try {
            $stmt = db()->prepare(
                "SELECT draw_index, draw_date, numbers FROM bingo_draws
                  WHERE round_id = ? ORDER BY draw_index ASC"
            );
            $stmt->bind_param('i', $roundId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) { $draws[] = $r; }
            $stmt->close();
        } catch (Throwable $e) {}
        return $draws;
    }
}

if (!function_exists('wt_bingo_all_drawn')) {
    /**
     * Tous les numéros tirés sur le cycle (union de tous les tirages),
     * triés. Optionnellement, ne compte que jusqu'à un certain draw_index.
     *
     * @return int[]
     */
    function wt_bingo_all_drawn(int $roundId): array
    {
        $all = [];
        foreach (wt_bingo_round_draws($roundId) as $d) {
            foreach (explode(',', (string)$d['numbers']) as $n) {
                if ($n !== '') { $all[(int)$n] = true; }
            }
        }
        $nums = array_keys($all);
        sort($nums);
        return $nums;
    }
}

if (!function_exists('wt_bingo_today_drawn')) {
    /**
     * Numéros tirés aujourd'hui uniquement (pour la couleur "jour").
     * @return int[]
     */
    function wt_bingo_today_drawn(int $roundId): array
    {
        $today = wt_bingo_today();
        foreach (wt_bingo_round_draws($roundId) as $d) {
            if ($d['draw_date'] === $today) {
                return array_map('intval', array_filter(explode(',', (string)$d['numbers']), 'strlen'));
            }
        }
        return [];
    }
}

/* ===================================================================
 * MOTEUR PRINCIPAL : LE "TICK" (lazy + cron)
 * =================================================================== */

if (!function_exists('wt_bingo_tick')) {
    /**
     * Fait avancer le jeu. Idempotent et sûr en concurrence. Étapes :
     *   1. Règle une partie 'ending' si on a passé minuit depuis (distribution
     *      + ouverture d'une nouvelle partie).
     *   2. Ouvre une partie 'active' s'il n'y en a aucune.
     *   3. Effectue le tirage du jour s'il manque (1 par jour).
     *   4. Vérifie les conditions de fin (max_days / auto_full) → 'ending'.
     *
     * @return array|null La partie courante après traitement, ou null si OFF.
     */
    function wt_bingo_tick(): ?array
    {
        if (!wt_bingo_enabled()) {
            return null;
        }

        // 1) Régler une éventuelle partie en fin de vie dont le minuit est passé
        $ending = null;
        try {
            $res = db()->query("SELECT * FROM bingo_rounds WHERE status='ending' ORDER BY id DESC LIMIT 1");
            if ($res instanceof mysqli_result) { $ending = $res->fetch_assoc() ?: null; $res->free(); }
        } catch (Throwable $e) {}

        if ($ending) {
            // On règle quand on a changé de jour par rapport au déclenchement
            $endingDay = substr((string)$ending['ending_at'], 0, 10);
            if ($endingDay !== '' && $endingDay < wt_bingo_today()) {
                wt_bingo_settle_round((int) $ending['id']);
            }
        }

        // 2) Ouvrir une partie si aucune active/ending
        $round = wt_bingo_current_round();
        if (!$round) {
            wt_bingo_open_round();
            $round = wt_bingo_current_round();
        }

        if (!$round) {
            return null;
        }

        // 3) Tirage du jour si la partie est active et qu'il manque
        if ($round['status'] === 'active') {
            wt_bingo_daily_draw((int) $round['id']);
            $round = wt_bingo_round_by_id((int) $round['id']);

            // 4) Vérifier les conditions de fin
            wt_bingo_check_end((int) $round['id']);
            $round = wt_bingo_round_by_id((int) $round['id']);
        }

        return $round;
    }
}

if (!function_exists('wt_bingo_open_round')) {
    /**
     * Ouvre une nouvelle partie (cycle). Le jackpot de départ = base +
     * report éventuel de la dernière partie réglée sans gagnant.
     *
     * Concurrence : on s'appuie sur le fait qu'il ne doit y avoir qu'une
     * partie active/ending. On vérifie juste avant d'insérer.
     */
    function wt_bingo_open_round(): void
    {
        $db = db();
        try {
            // Garde : ne pas ouvrir si une partie active/ending existe déjà
            $existing = wt_bingo_current_round();
            if ($existing) { return; }

            $base = wt_bingo_cfg('jackpot_base', 30000);
            $carry = 0;
            if (wt_bingo_cfg('jackpot_carryover', 1) === 1) {
                // Report depuis la dernière partie réglée sans gagnant
                $res = $db->query(
                    "SELECT jackpot, winners_count FROM bingo_rounds
                      WHERE status='settled' ORDER BY id DESC LIMIT 1"
                );
                if ($res instanceof mysqli_result) {
                    $prev = $res->fetch_assoc();
                    $res->free();
                    if ($prev && (int)$prev['winners_count'] === 0) {
                        $carry = (int) $prev['jackpot'];
                    }
                }
            }
            $jackpot = $base + $carry;

            $maxDays   = max(1, wt_bingo_cfg('max_days', 7));
            $drawCount = max(1, wt_bingo_cfg('draw_count', 14));
            $numberMax = max(25, wt_bingo_cfg('number_max', 99));
            $today     = wt_bingo_today();

            $stmt = $db->prepare(
                "INSERT INTO bingo_rounds
                   (started_on, max_days, draw_count, number_max, jackpot, status)
                 VALUES (?, ?, ?, ?, ?, 'active')"
            );
            $stmt->bind_param('siiii', $today, $maxDays, $drawCount, $numberMax, $jackpot);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log('[Wintaskly bingo] open_round: ' . $e->getMessage());
        }
    }
}

if (!function_exists('wt_bingo_daily_draw')) {
    /**
     * Effectue le tirage du jour pour une partie, s'il n'a pas déjà eu lieu.
     * Protégé contre la double-exécution par UNIQUE(round_id, draw_date) :
     * une 2e tentative le même jour échoue silencieusement.
     *
     * Les numéros tirés sont choisis parmi ceux PAS ENCORE sortis sur le
     * cycle (pas de doublon entre tirages).
     *
     * @return bool true si un tirage a été ajouté par cet appel
     */
    function wt_bingo_daily_draw(int $roundId): bool
    {
        $db = db();
        try {
            $round = wt_bingo_round_by_id($roundId);
            if (!$round || $round['status'] !== 'active') {
                return false;
            }

            $today = wt_bingo_today();

            // Déjà tiré aujourd'hui ?
            $stmt = $db->prepare(
                "SELECT COUNT(*) c FROM bingo_draws WHERE round_id = ? AND draw_date = ?"
            );
            $stmt->bind_param('is', $roundId, $today);
            $stmt->execute();
            $existsToday = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();
            if ($existsToday > 0) {
                return false; // tirage du jour déjà fait
            }

            // Limite : ne pas dépasser max_days tirages
            $daysDrawn = (int) $round['days_drawn'];
            $maxDays   = (int) $round['max_days'];
            if ($daysDrawn >= $maxDays) {
                return false; // plus de tirage possible
            }

            // Numéros déjà sortis sur le cycle
            $already = wt_bingo_all_drawn($roundId);
            $max = (int) $round['number_max'];
            $pool = array_diff(range(1, $max), $already);
            $pool = array_values($pool);

            if (empty($pool)) {
                return false; // tous les numéros sont sortis
            }

            shuffle($pool);
            $count = min((int) $round['draw_count'], count($pool));
            $picked = array_slice($pool, 0, $count);
            sort($picked);
            $csv = implode(',', $picked);

            $drawIndex = $daysDrawn + 1;

            // Insère le tirage (UNIQUE protège la concurrence)
            $stmt = $db->prepare(
                "INSERT IGNORE INTO bingo_draws (round_id, draw_index, draw_date, numbers)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->bind_param('iiss', $roundId, $drawIndex, $today, $csv);
            $stmt->execute();
            $inserted = $stmt->affected_rows;
            $stmt->close();

            if ($inserted < 1) {
                return false; // un autre process a tiré en même temps
            }

            // Met à jour le compteur de jours tirés
            $stmt = $db->prepare(
                "UPDATE bingo_rounds SET days_drawn = days_drawn + 1, last_draw_on = ?
                  WHERE id = ?"
            );
            $stmt->bind_param('si', $today, $roundId);
            $stmt->execute();
            $stmt->close();

            return true;
        } catch (Throwable $e) {
            error_log('[Wintaskly bingo] daily_draw: ' . $e->getMessage());
            return false;
        }
    }
}

/* ===================================================================
 * DÉTECTION DE FIN DE PARTIE
 * =================================================================== */

if (!function_exists('wt_bingo_trigger_end')) {
    /**
     * Passe une partie de 'active' à 'ending' avec une raison, de façon
     * atomique (ne fait rien si déjà ending/settled).
     *
     * @return bool true si cet appel a déclenché la fin
     */
    function wt_bingo_trigger_end(int $roundId, string $reason): bool
    {
        $valid = ['max_days', 'claim', 'auto_full'];
        if (!in_array($reason, $valid, true)) { return false; }
        try {
            $stmt = db()->prepare(
                "UPDATE bingo_rounds
                    SET status='ending', end_reason=?, ending_at=UTC_TIMESTAMP()
                  WHERE id = ? AND status='active'"
            );
            $stmt->bind_param('si', $reason, $roundId);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();
            return $changed > 0;
        } catch (Throwable $e) {
            error_log('[Wintaskly bingo] trigger_end: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('wt_bingo_check_end')) {
    /**
     * Vérifie les conditions de fin AUTOMATIQUES d'une partie active :
     *   - max_days tirages effectués
     *   - détection d'un carton dont les 25 numéros sont tous tirés
     *     (peu importe que le joueur ait validé/réclamé : ça déclenche la
     *      fin ; mais seul un carton RÉCLAMÉ gagnera au settlement)
     *
     * La condition "1ère réclamation" est gérée directement dans
     * wt_bingo_claim_card() (qui appelle trigger_end('claim')).
     *
     * @return string '' si rien, sinon la raison de fin déclenchée
     */
    function wt_bingo_check_end(int $roundId): string
    {
        $round = wt_bingo_round_by_id($roundId);
        if (!$round || $round['status'] !== 'active') {
            return '';
        }

        // Condition 1 : max_days tirages atteints
        if ((int) $round['days_drawn'] >= (int) $round['max_days']) {
            if (wt_bingo_trigger_end($roundId, 'max_days')) {
                return 'max_days';
            }
        }

        // Condition 2 : un carton a ses 25 numéros tous tirés
        $drawn = wt_bingo_all_drawn($roundId);
        if (count($drawn) >= 25) {
            $drawnSet = array_flip($drawn);
            // Parcourt les cartons actifs/réclamés (ceux en jeu)
            try {
                $stmt = db()->prepare(
                    "SELECT id, numbers FROM bingo_cards
                      WHERE round_id = ? AND status IN ('active','claimed')"
                );
                $stmt->bind_param('i', $roundId);
                $stmt->execute();
                $res = $stmt->get_result();
                $found = false;
                while ($c = $res->fetch_assoc()) {
                    $nums = array_map('intval', explode(',', $c['numbers']));
                    $allDrawn = true;
                    foreach ($nums as $n) {
                        if (!isset($drawnSet[$n])) { $allDrawn = false; break; }
                    }
                    if ($allDrawn) { $found = true; break; }
                }
                $stmt->close();
                if ($found && wt_bingo_trigger_end($roundId, 'auto_full')) {
                    return 'auto_full';
                }
            } catch (Throwable $e) {
                error_log('[Wintaskly bingo] check_end: ' . $e->getMessage());
            }
        }

        return '';
    }
}

/* ===================================================================
 * CARTONS
 * =================================================================== */

if (!function_exists('wt_bingo_gen_card_numbers')) {
    /** 25 numéros uniques dans 1..max. CSV ordonné. */
    function wt_bingo_gen_card_numbers(int $max): string
    {
        if ($max < 25) { $max = 99; }
        $pool = range(1, $max);
        shuffle($pool);
        $nums = array_slice($pool, 0, 25);
        sort($nums);
        return implode(',', $nums);
    }
}

if (!function_exists('wt_bingo_ensure_user_cards')) {
    /**
     * Garantit que l'utilisateur a ses N cartons pour la partie (cycle).
     * Crée les manquants (statut 'locked'). Idempotent.
     */
    function wt_bingo_ensure_user_cards(int $roundId, int $userId): void
    {
        $round = wt_bingo_round_by_id($roundId);
        if (!$round) { return; }
        $total = max(1, wt_bingo_cfg('cards_per_user', 5));
        $max = (int) $round['number_max'];
        $db = db();
        try {
            $stmt = $db->prepare(
                "SELECT COUNT(*) c FROM bingo_cards WHERE round_id = ? AND user_id = ?"
            );
            $stmt->bind_param('ii', $roundId, $userId);
            $stmt->execute();
            $have = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();

            for ($slot = $have; $slot < $total; $slot++) {
                $numbers = wt_bingo_gen_card_numbers($max);
                $stmt = $db->prepare(
                    "INSERT IGNORE INTO bingo_cards
                       (round_id, user_id, numbers, slot_index, is_free, status)
                     VALUES (?, ?, ?, ?, 0, 'locked')"
                );
                $stmt->bind_param('iisi', $roundId, $userId, $numbers, $slot);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('[Wintaskly bingo] ensure_cards: ' . $e->getMessage());
        }
    }
}

if (!function_exists('wt_bingo_user_cards')) {
    /**
     * Cartons d'un user pour une partie + leurs validations.
     * @return array
     */
    function wt_bingo_user_cards(int $roundId, int $userId): array
    {
        wt_bingo_ensure_user_cards($roundId, $userId);
        $cards = [];
        try {
            $db = db();
            $stmt = $db->prepare(
                "SELECT * FROM bingo_cards WHERE round_id = ? AND user_id = ?
                  ORDER BY slot_index ASC"
            );
            $stmt->bind_param('ii', $roundId, $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) { $r['marks'] = []; $cards[] = $r; }
            $stmt->close();

            if (!empty($cards)) {
                $byId = [];
                foreach ($cards as $i => $c) { $byId[(int)$c['id']] = $i; }
                $in = implode(',', array_map(static fn($c) => (int)$c['id'], $cards));
                $res = $db->query("SELECT card_id, number FROM bingo_card_marks WHERE card_id IN ($in)");
                if ($res instanceof mysqli_result) {
                    while ($m = $res->fetch_assoc()) {
                        $cid = (int) $m['card_id'];
                        if (isset($byId[$cid])) { $cards[$byId[$cid]]['marks'][] = (int) $m['number']; }
                    }
                    $res->free();
                }
            }
        } catch (Throwable $e) {
            error_log('[Wintaskly bingo] user_cards: ' . $e->getMessage());
        }
        return $cards;
    }
}

/* ===================================================================
 * ACTIVATION / ACHAT DE CARTON
 * =================================================================== */

if (!function_exists('wt_bingo_activate_card')) {
    /**
     * Active un carton. 1er gratuit (si free_cards dispo), suivants payants
     * (débit du solde, transaction atomique). Jackpot +growth_pct % par achat.
     *
     * @return array ['ok'=>bool,'error'=>string,'free'=>bool,'price'=>int]
     */
    function wt_bingo_activate_card(int $cardId, int $userId): array
    {
        $out = ['ok' => false, 'error' => '', 'free' => false, 'price' => 0];
        if (!wt_bingo_enabled()) { $out['error'] = 'disabled'; return $out; }

        $db = db();
        try {
            $db->begin_transaction();

            $stmt = $db->prepare("SELECT * FROM bingo_cards WHERE id = ? AND user_id = ? FOR UPDATE");
            $stmt->bind_param('ii', $cardId, $userId);
            $stmt->execute();
            $card = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$card) { $db->rollback(); $out['error']='not_found'; return $out; }
            if ($card['status'] !== 'locked') { $db->rollback(); $out['error']='already_active'; return $out; }

            $roundId = (int) $card['round_id'];
            $round = wt_bingo_round_by_id($roundId);
            // On ne peut activer/acheter que sur une partie active
            if (!$round || $round['status'] !== 'active') {
                $db->rollback(); $out['error']='round_closed'; return $out;
            }

            // Gratuit ou payant ?
            $freeAllowed = wt_bingo_cfg('free_cards', 1);
            $stmt = $db->prepare(
                "SELECT COUNT(*) c FROM bingo_cards
                  WHERE round_id = ? AND user_id = ? AND is_free = 1"
            );
            $stmt->bind_param('ii', $roundId, $userId);
            $stmt->execute();
            $freeUsed = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();

            $isFree = ($freeUsed < $freeAllowed);

            if ($isFree) {
                $stmt = $db->prepare(
                    "UPDATE bingo_cards SET status='active', is_free=1, activated_at=UTC_TIMESTAMP()
                      WHERE id = ? AND status='locked'"
                );
                $stmt->bind_param('i', $cardId);
                $stmt->execute();
                $ok = $stmt->affected_rows > 0;
                $stmt->close();
                if (!$ok) { $db->rollback(); $out['error']='already_active'; return $out; }
                $db->commit();
                $out['ok'] = true; $out['free'] = true;
                return $out;
            }

            // ---- PAYANT ----
            $price = wt_bingo_cfg('card_price_coins', 5000);
            $out['price'] = $price;

            $stmt = $db->prepare("SELECT coins FROM users WHERE id = ? FOR UPDATE");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $balance = (float) ($stmt->get_result()->fetch_assoc()['coins'] ?? 0);
            $stmt->close();

            if ($balance < $price) { $db->rollback(); $out['error']='insufficient_coins'; return $out; }

            $stmt = $db->prepare("UPDATE users SET coins = coins - ? WHERE id = ?");
            $stmt->bind_param('di', $price, $userId);
            $stmt->execute();
            $stmt->close();

            $stmt = $db->prepare(
                "UPDATE bingo_cards SET status='active', is_free=0, activated_at=UTC_TIMESTAMP()
                  WHERE id = ? AND status='locked'"
            );
            $stmt->bind_param('i', $cardId);
            $stmt->execute();
            $ok = $stmt->affected_rows > 0;
            $stmt->close();
            if (!$ok) { $db->rollback(); $out['error']='already_active'; return $out; }

            // Jackpot +growth_pct %
            $growthPct = wt_bingo_cfg('jackpot_growth_pct', 25);
            $jackpotAdd = (int) floor($price * $growthPct / 100);
            if ($jackpotAdd > 0) {
                $stmt = $db->prepare("UPDATE bingo_rounds SET jackpot = jackpot + ? WHERE id = ?");
                $stmt->bind_param('ii', $jackpotAdd, $roundId);
                $stmt->execute();
                $stmt->close();
            }

            $db->commit();

            // Log transaction (best-effort)
            try {
                $meta = 'bingo_card #' . $cardId;
                $neg = -$price;
                $stmt = $db->prepare(
                    "INSERT INTO transactions (user_id, coins, xp, type, meta, created_at)
                     VALUES (?, ?, 0, 'bingo_buy', ?, UTC_TIMESTAMP())"
                );
                $stmt->bind_param('ids', $userId, $neg, $meta);
                $stmt->execute();
                $stmt->close();
            } catch (Throwable $e) {}

            $out['ok'] = true; $out['free'] = false;
            return $out;

        } catch (Throwable $e) {
            try { $db->rollback(); } catch (Throwable $e2) {}
            error_log('[Wintaskly bingo] activate: ' . $e->getMessage());
            $out['error'] = 'server';
            return $out;
        }
    }
}

/* ===================================================================
 * VALIDATION MANUELLE
 * =================================================================== */

if (!function_exists('wt_bingo_mark_number')) {
    /**
     * Valide un numéro sur un carton actif. Conditions : carton du user &
     * actif, numéro sur le carton, numéro tiré sur le cycle.
     *
     * @return array ['ok'=>bool,'error'=>string,'full'=>bool]
     */
    function wt_bingo_mark_number(int $cardId, int $userId, int $number): array
    {
        $out = ['ok' => false, 'error' => '', 'full' => false];
        try {
            $db = db();
            $stmt = $db->prepare(
                "SELECT c.*, r.id AS rid FROM bingo_cards c
                   JOIN bingo_rounds r ON r.id = c.round_id
                  WHERE c.id = ? AND c.user_id = ? LIMIT 1"
            );
            $stmt->bind_param('ii', $cardId, $userId);
            $stmt->execute();
            $card = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$card) { $out['error']='not_found'; return $out; }
            if ($card['status'] !== 'active') { $out['error']='not_active'; return $out; }

            $cardNums = array_map('intval', explode(',', $card['numbers']));
            if (!in_array($number, $cardNums, true)) { $out['error']='not_on_card'; return $out; }

            $drawn = wt_bingo_all_drawn((int) $card['rid']);
            if (!in_array($number, $drawn, true)) { $out['error']='not_drawn'; return $out; }

            $stmt = $db->prepare("INSERT IGNORE INTO bingo_card_marks (card_id, number) VALUES (?, ?)");
            $stmt->bind_param('ii', $cardId, $number);
            $stmt->execute();
            $stmt->close();

            $stmt = $db->prepare("SELECT COUNT(*) c FROM bingo_card_marks WHERE card_id = ?");
            $stmt->bind_param('i', $cardId);
            $stmt->execute();
            $marked = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();

            $out['ok'] = true;
            $out['full'] = ($marked >= 25);
            return $out;
        } catch (Throwable $e) {
            error_log('[Wintaskly bingo] mark: ' . $e->getMessage());
            $out['error'] = 'server';
            return $out;
        }
    }
}

/* ===================================================================
 * RÉCLAMATION (déclenche la fin de partie)
 * =================================================================== */

if (!function_exists('wt_bingo_claim_card')) {
    /**
     * Réclame un carton plein (25 validés). La 1ère réclamation de la partie
     * déclenche la fin ('claim'). Rend les autres cartons du user caducs.
     * La récompense est distribuée au settlement (à minuit).
     *
     * @return array ['ok'=>bool,'error'=>string]
     */
    function wt_bingo_claim_card(int $cardId, int $userId): array
    {
        $out = ['ok' => false, 'error' => ''];
        if (!wt_bingo_enabled()) { $out['error']='disabled'; return $out; }

        $db = db();
        try {
            $db->begin_transaction();

            $stmt = $db->prepare("SELECT * FROM bingo_cards WHERE id = ? AND user_id = ? FOR UPDATE");
            $stmt->bind_param('ii', $cardId, $userId);
            $stmt->execute();
            $card = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$card) { $db->rollback(); $out['error']='not_found'; return $out; }
            if ($card['status'] !== 'active') { $db->rollback(); $out['error']='not_active'; return $out; }

            $roundId = (int) $card['round_id'];

            // 25 validations ?
            $stmt = $db->prepare("SELECT COUNT(*) c FROM bingo_card_marks WHERE card_id = ?");
            $stmt->bind_param('i', $cardId);
            $stmt->execute();
            $marked = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();
            if ($marked < 25) { $db->rollback(); $out['error']='not_full'; return $out; }

            // Déjà réclamé pour ce user/round ?
            $stmt = $db->prepare("SELECT COUNT(*) c FROM bingo_claims WHERE round_id = ? AND user_id = ?");
            $stmt->bind_param('ii', $roundId, $userId);
            $stmt->execute();
            $already = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();
            if ($already > 0) { $db->rollback(); $out['error']='already_claimed'; return $out; }

            // Inscrit la réclamation
            $stmt = $db->prepare("INSERT INTO bingo_claims (round_id, user_id, card_id) VALUES (?, ?, ?)");
            $stmt->bind_param('iii', $roundId, $userId, $cardId);
            $stmt->execute();
            $stmt->close();

            // Carton 'claimed', autres cartons du user 'void'
            $stmt = $db->prepare("UPDATE bingo_cards SET status='claimed' WHERE id = ?");
            $stmt->bind_param('i', $cardId);
            $stmt->execute();
            $stmt->close();

            $stmt = $db->prepare(
                "UPDATE bingo_cards SET status='void'
                  WHERE round_id = ? AND user_id = ? AND id <> ? AND status IN ('locked','active')"
            );
            $stmt->bind_param('iii', $roundId, $userId, $cardId);
            $stmt->execute();
            $stmt->close();

            $db->commit();

            // 1ère réclamation → déclenche la fin de partie (hors transaction)
            wt_bingo_trigger_end($roundId, 'claim');

            $out['ok'] = true;
            return $out;
        } catch (Throwable $e) {
            try { $db->rollback(); } catch (Throwable $e2) {}
            error_log('[Wintaskly bingo] claim: ' . $e->getMessage());
            $out['error'] = 'already_claimed';
            return $out;
        }
    }
}

/* ===================================================================
 * SETTLEMENT (distribution à minuit) + STATS
 * =================================================================== */

if (!function_exists('wt_bingo_settle_round')) {
    /**
     * Distribue le jackpot d'une partie en fin de vie (status 'ending').
     * Partage égal entre gagnants (cartons réclamés). Min 1 coin/gagnant ;
     * report du jackpot si aucun gagnant. Atomique (ending → settled).
     *
     * @return bool true si réglé par cet appel
     */
    function wt_bingo_settle_round(int $roundId): bool
    {
        $db = db();
        try {
            $stmt = $db->prepare(
                "UPDATE bingo_rounds SET status='settled', settled_at=UTC_TIMESTAMP()
                  WHERE id = ? AND status='ending'"
            );
            $stmt->bind_param('i', $roundId);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();
            if ($changed < 1) { return false; }

            $round = wt_bingo_round_by_id($roundId);
            $jackpot = (int) ($round['jackpot'] ?? 0);

            $winners = [];
            $res = $db->query("SELECT id, user_id FROM bingo_claims WHERE round_id = " . $roundId);
            if ($res instanceof mysqli_result) {
                while ($w = $res->fetch_assoc()) { $winners[] = $w; }
                $res->free();
            }
            $n = count($winners);

            if ($n === 0) {
                // Pas de gagnant : jackpot reporté (winners_count reste 0)
                return true;
            }

            $each = intdiv($jackpot, $n);
            $carryMode = false;
            if ($each < 1) { $each = 1; $carryMode = true; }

            foreach ($winners as $w) {
                $uid = (int) $w['user_id'];
                if (function_exists('award_user')) {
                    award_user($uid, (float) $each, 0, 'bingo_win', 'round #' . $roundId);
                }
                try {
                    $stmt = $db->prepare("UPDATE bingo_claims SET reward = ? WHERE id = ?");
                    $cid = (int) $w['id'];
                    $stmt->bind_param('ii', $each, $cid);
                    $stmt->execute();
                    $stmt->close();
                } catch (Throwable $e) {}
            }

            $recordedWinners = $carryMode ? 0 : $n;
            $stmt = $db->prepare("UPDATE bingo_rounds SET winners_count = ?, reward_each = ? WHERE id = ?");
            $stmt->bind_param('iii', $recordedWinners, $each, $roundId);
            $stmt->execute();
            $stmt->close();

            return true;
        } catch (Throwable $e) {
            error_log('[Wintaskly bingo] settle: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('wt_bingo_round_stats')) {
    function wt_bingo_round_stats(int $roundId): array
    {
        $s = ['cards_active'=>0,'cards_paid'=>0,'players'=>0,'claims'=>0,'draws'=>0];
        try {
            $r = db_one("SELECT
                    SUM(status IN ('active','claimed')) ac,
                    SUM(status='active' AND is_free=0) pc,
                    COUNT(DISTINCT user_id) pl
                  FROM bingo_cards WHERE round_id = " . $roundId);
            $s['cards_active'] = (int) ($r['ac'] ?? 0);
            $s['cards_paid']   = (int) ($r['pc'] ?? 0);
            $s['players']      = (int) ($r['pl'] ?? 0);
            $s['claims'] = (int) (db_one("SELECT COUNT(*) c FROM bingo_claims WHERE round_id = " . $roundId)['c'] ?? 0);
            $s['draws']  = (int) (db_one("SELECT COUNT(*) c FROM bingo_draws WHERE round_id = " . $roundId)['c'] ?? 0);
        } catch (Throwable $e) {}
        return $s;
    }
}

/* ===================================================================
 * VISIBILITÉ (mode test / lancement / teaser)
 * =================================================================== */

if (!function_exists('wt_bingo_is_test_mode')) {
    /** Le bingo est-il en mode test (visible admins seulement) ? */
    function wt_bingo_is_test_mode(): bool
    {
        return (string) cfg('bingo.test_mode', '0') === '1';
    }
}

if (!function_exists('wt_bingo_launch_ts')) {
    /**
     * Timestamp de lancement public (0 si non défini).
     * Stocké en config 'bingo.launch_at' au format 'Y-m-d H:i' (UTC).
     */
    function wt_bingo_launch_ts(): int
    {
        $raw = trim((string) cfg('bingo.launch_at', ''));
        if ($raw === '') { return 0; }
        $ts = strtotime($raw . ' UTC');
        return $ts !== false ? $ts : 0;
    }
}

if (!function_exists('wt_bingo_is_launched')) {
    /**
     * Le bingo est-il lancé publiquement ? (date de lancement passée ou
     * non définie). Le mode test n'entre pas en compte ici.
     */
    function wt_bingo_is_launched(): bool
    {
        $ts = wt_bingo_launch_ts();
        return $ts === 0 || time() >= $ts;
    }
}

if (!function_exists('wt_bingo_visible_for')) {
    /**
     * Le bingo doit-il être JOUABLE pour cet utilisateur ?
     *   - Désactivé globalement → non
     *   - Mode test → admins uniquement
     *   - Avant la date de lancement → admins uniquement (les autres voient
     *     le teaser "bientôt" mais ne peuvent pas jouer)
     *
     * @param array|null $user  L'utilisateur courant (avec 'role' éventuel)
     */
    function wt_bingo_visible_for(?array $user): bool
    {
        if (!wt_bingo_enabled()) {
            return false;
        }
        $isAdmin = $user && (($user['role'] ?? '') === 'admin');
        if ($isAdmin) {
            return true; // l'admin voit toujours (pour tester)
        }
        // Non-admin : ni mode test, ni avant lancement
        if (wt_bingo_is_test_mode()) {
            return false;
        }
        if (!wt_bingo_is_launched()) {
            return false;
        }
        return true;
    }
}

if (!function_exists('wt_bingo_show_teaser')) {
    /**
     * Faut-il afficher le teaser "bientôt disponible" dans la liste des
     * tâches pour cet utilisateur ?
     *   - coming_soon activé
     *   - le jeu n'est pas (encore) jouable pour lui
     *   - mais le mode test n'est PAS actif pour un non-admin (en test pur,
     *     on cache tout aux non-admins ; le teaser n'apparaît qu'en phase
     *     "lancement programmé")
     *
     * En clair : le teaser s'affiche quand une date de lancement future est
     * définie et que coming_soon est activé.
     */
    function wt_bingo_show_teaser(?array $user): bool
    {
        if (!wt_bingo_enabled()) { return false; }
        if ((string) cfg('bingo.coming_soon', '0') !== '1') { return false; }

        $isAdmin = $user && (($user['role'] ?? '') === 'admin');
        // Si déjà jouable pour lui, pas de teaser
        if (wt_bingo_visible_for($user)) { return false; }

        // En mode test pur (pas de date de lancement), on cache tout aux
        // non-admins : pas de teaser non plus.
        if (wt_bingo_is_test_mode() && wt_bingo_launch_ts() === 0 && !$isAdmin) {
            return false;
        }

        // Sinon : teaser visible (phase "bientôt", date de lancement future)
        return wt_bingo_launch_ts() > 0 && !wt_bingo_is_launched();
    }
}
