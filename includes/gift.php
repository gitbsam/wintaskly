<?php
/**
 * Wintaskly — includes/gift.php
 *
 * Boîte à cadeaux.
 *
 * Une partie est une grille de N boîtes, toutes gagnantes. Les lots
 * sont tirés à la création de la grille, jamais à l'ouverture.
 *
 * Ce choix n'est pas anodin. Tirer à l'ouverture rendrait l'enveloppe
 * impossible à tenir — on ne saurait jamais ce qu'il reste à
 * distribuer — et permettrait à un lot exceptionnel de sortir deux
 * fois. La grille figée d'avance se vérifie, et se défend en cas de
 * contestation.
 */
declare(strict_types=1);

if (!function_exists('wt_gift_enabled')) {
    function wt_gift_enabled(): bool
    {
        return (string) cfg('gift.enabled', '0') === '1';
    }
}

if (!function_exists('wt_gift_visible_for')) {
    /** En mode test, seuls les administrateurs voient le jeu. */
    function wt_gift_visible_for(?array $user): bool
    {
        if (!wt_gift_enabled()) { return false; }
        if ((string) cfg('gift.test_mode', '1') !== '1') { return true; }
        return $user !== null && (($user['role'] ?? '') === 'admin');
    }
}

if (!function_exists('wt_gift_multipliers')) {
    /** Bonus proposés, en pourcent. Ni 0 ni 100. */
    function wt_gift_multipliers(): array
    {
        $l = array_values(array_filter(
            array_map('intval', explode(',', (string) cfg('gift.multipliers', '20,50,80'))),
            static fn(int $v): bool => $v > 0 && $v < 100
        ));
        return $l ?: [20, 50, 80];
    }
}

if (!function_exists('wt_gift_envelope')) {
    /**
     * Enveloppe en coins d'une grille de N boîtes.
     *
     * Calculée à partir d'une recette publicitaire estimée et de la
     * part que vous acceptez de reverser. Le reste est votre marge —
     * diminuée ensuite par le multiplicateur, qui verse au-delà de
     * l'enveloppe.
     */
    function wt_gift_envelope(int $boxes): float
    {
        $rpv   = (float) cfg('gift.rpv_estimate', 0.005);
        $ratio = (float) cfg('gift.envelope_ratio', 0.40);
        $eur   = $boxes * max(0.0, $rpv) * max(0.0, min(1.0, $ratio));
        return round($eur * 10000, 0);   // 10 000 coins = 1 €
    }
}

if (!function_exists('wt_gift_build_round')) {
    /**
     * Crée une grille et y place les lots.
     *
     * Répartition en paliers plutôt qu'uniforme : une grille où chaque
     * boîte donne 25 coins n'intéresse personne. Concentrer la même
     * enveloppe sur quelques boîtes crée du relief sans rien coûter de
     * plus.
     *
     * @param  string $special 'none', 'big' ou 'jackpot'
     * @return int    identifiant de la partie, 0 en cas d'échec
     */
    function wt_gift_build_round(?int $boxes = null, string $special = 'none'): int
    {
        $db    = db();
        $boxes = $boxes ?? (int) cfg('gift.boxes_count', 100);
        $boxes = max(10, min(500, $boxes));
        $env   = wt_gift_envelope($boxes);

        /* Valeur du lot exceptionnel. Le jackpot est vidé au moment où
           il entre en grille : le laisser courir pendant qu'il est en
           jeu le ferait grossir puis payer deux fois. */
        /* Un lot exceptionnel ne sort JAMAIS de l'enveloppe de la
           partie : un jackpot de 75 000 coins coûte quinze fois ce
           qu'une grille rapporte. Il est financé par une cagnotte
           alimentée à chaque boîte ouverte, et ne peut être mis en jeu
           que si cette cagnotte le couvre.
           Sans ce garde-fou, une partie sur deux sortait 690 % de sa
           recette — le jeu aurait ruiné la plateforme en un mois. */
        $specialValue = 0.0;
        $cagnotte = (float) cfg('gift.prize_pot', 0);

        if ($special === 'jackpot') {
            $specialValue = (float) cfg('gift.jackpot', 0);
            if ($specialValue <= 0 || $specialValue > $cagnotte) { $special = 'none'; $specialValue = 0.0; }
        } elseif ($special === 'big') {
            /* Paliers fixes plutôt qu'un tirage dans un intervalle : un
               gros gain de 3 417 coins ne parle à personne, 2 000 si.
               On ne retient que ceux que la cagnotte couvre, et on tire
               parmi eux — le plus gros palier finançable n'est pas
               toujours le bon choix, un jeu qui sort systématiquement
               son maximum épuise la cagnotte sans créer de surprise. */
            $paliers = array_values(array_filter(
                array_map('intval', explode(',', (string) cfg('gift.big_tiers', '800,1000,2000,5000'))),
                static fn(int $v): bool => $v > 0 && $v <= $cagnotte
            ));
            if (!$paliers) { $special = 'none'; }
            else { $specialValue = (float) $paliers[random_int(0, count($paliers) - 1)]; }
        }

        try {
            $db->begin_transaction();

            $st = $db->prepare(
                "INSERT INTO gift_rounds (boxes_count, envelope, special_kind, special_value)
                 VALUES (?, ?, ?, ?)"
            );
            $st->bind_param('idsd', $boxes, $env, $special, $specialValue);
            $st->execute();
            $roundId = (int) $st->insert_id;
            $st->close();

            /* Paliers : beaucoup de petits lots, quelques gros. Les
               proportions sont volontairement figées ici plutôt que
               réglables : un administrateur qui ajusterait les deux à
               la fois (enveloppe et paliers) perdrait vite la maîtrise
               du coût réel. */
            $paliers = [
                ['part' => 0.30, 'poids' => 0.10],   // 30 % des boîtes, 10 % de l'enveloppe
                ['part' => 0.45, 'poids' => 0.25],
                ['part' => 0.20, 'poids' => 0.35],
                ['part' => 0.05, 'poids' => 0.30],
            ];

            $lots = [];
            $reste = $env;
            foreach ($paliers as $i => $p) {
                $n = max(1, (int) round($boxes * $p['part']));
                if ($i === count($paliers) - 1) { $n = max(1, $boxes - count($lots)); }
                $pool = ($i === count($paliers) - 1) ? $reste : round($env * $p['poids'], 0);
                $unit = $n > 0 ? max(1.0, floor($pool / $n)) : 1.0;
                for ($k = 0; $k < $n && count($lots) < $boxes; $k++) {
                    $lots[] = ['kind' => 'coins', 'coins' => $unit, 'xp' => 0];
                    $reste -= $unit;
                }
            }
            /* Complément éventuel dû aux arrondis. */
            while (count($lots) < $boxes) {
                $lots[] = ['kind' => 'coins', 'coins' => 1.0, 'xp' => 0];
            }

            /* Une boîte sur huit donne de l'XP au lieu de coins. L'XP ne
               coûte rien et fait progresser le niveau : c'est le seul
               lot qu'on peut distribuer généreusement. */
            $nbXp = (int) floor($boxes / 8);
            for ($i = 0; $i < $nbXp; $i++) {
                $lots[$i] = ['kind' => 'xp', 'coins' => 0.0, 'xp' => random_int(5, 25)];
            }

            /* Le lot exceptionnel remplace une boîte, il ne s'ajoute
               pas : sinon l'enveloppe serait dépassée du montant du
               jackpot. */
            if ($special !== 'none' && $specialValue > 0) {
                $lots[count($lots) - 1] = [
                    'kind'  => $special,
                    'coins' => $specialValue,
                    'xp'    => 0,
                ];
            }

            shuffle($lots);

            $st = $db->prepare(
                "INSERT INTO gift_boxes (round_id, position, kind, coins, xp)
                 VALUES (?, ?, ?, ?, ?)"
            );
            foreach ($lots as $pos => $l) {
                $p1 = $pos + 1;
                $st->bind_param('iisdi', $roundId, $p1, $l['kind'], $l['coins'], $l['xp']);
                $st->execute();
            }
            $st->close();

            if ($special !== 'none' && $specialValue > 0) {
                /* La cagnotte est débitée dès la mise en jeu, pas à
                   l'ouverture de la boîte : sinon deux parties
                   successives pourraient engager le même argent. */
                cfg_set('gift.prize_pot', (string) max(0.0, $cagnotte - $specialValue));
            }
            if ($special === 'jackpot') {
                /* Remis à sa valeur d'amorce, pas à zéro : un jackpot
                   reparti de rien n'attire plus personne. */
                cfg_set('gift.jackpot', (string) (float) cfg('gift.jackpot_seed', 20000));
            }

            $db->commit();
            return $roundId;
        } catch (Throwable $e) {
            try { $db->rollback(); } catch (Throwable $e2) {}
            error_log('[Wintaskly gift] ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('wt_gift_current_round')) {
    /**
     * Partie en cours, créée si nécessaire.
     *
     * À la clôture d'une grille, un tirage décide si la suivante
     * contiendra un lot exceptionnel, et lequel. Ils ne cohabitent
     * jamais : deux lots exceptionnels dans la même grille videraient
     * l'enveloppe d'un coup.
     */
    function wt_gift_current_round(): ?array
    {
        try {
            $r = db_one("SELECT * FROM gift_rounds WHERE status = 'active' ORDER BY id DESC LIMIT 1");
            if ($r) { return $r; }

            $chance  = max(0, min(100, (int) cfg('gift.special_chance', 50)));
            $special = 'none';
            if (random_int(1, 100) <= $chance) {
                /* Le jackpot n'entre en jeu qu'au-delà d'un SEUIL, pas
                   dès qu'il dépasse son amorce.
                   Sans ce seuil, il devenait éligible dès la partie
                   suivante et sortait dix fois sur cent parties, sans
                   jamais dépasser 20 000 coins : un jackpot qui tombe
                   souvent et petit n'est plus un jackpot, c'est un gros
                   gain avec un nom ronflant. */
                $jp    = (float) cfg('gift.jackpot', 0);
                $seuil = (float) cfg('gift.jackpot_min', 40000);
                $special = ($jp >= $seuil && random_int(0, 1) === 1) ? 'jackpot' : 'big';
            }
            $id = wt_gift_build_round(null, $special);
            return $id > 0 ? db_one("SELECT * FROM gift_rounds WHERE id = " . $id) : null;
        } catch (Throwable $e) {
            error_log('[Wintaskly gift] ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('wt_gift_open_start')) {
    /**
     * Ouvre le parcours publicitaire d'une boîte.
     *
     * La boîte n'est PAS révélée ici : elle le sera au retour, une fois
     * le temps vérifié. Révéler maintenant permettrait de voir le lot
     * sans jamais regarder la publicité.
     *
     * @return array{ok:bool, error:string, token:string, seconds:int}
     */
    function wt_gift_open_start(int $userId, int $boxId): array
    {
        $out = ['ok' => false, 'error' => '', 'token' => '', 'seconds' => 30];
        if ($userId <= 0 || $boxId <= 0) { $out['error'] = 'invalid'; return $out; }

        try {
            $b = db_one(
                "SELECT b.*, r.status FROM gift_boxes b
                   JOIN gift_rounds r ON r.id = b.round_id
                  WHERE b.id = " . $boxId
            );
            if (!$b) { $out['error'] = 'not_found'; return $out; }
            if ($b['status'] !== 'active') { $out['error'] = 'round_closed'; return $out; }
            if ($b['opened_by'] !== null) { $out['error'] = 'taken'; return $out; }

            /* Un seul parcours ouvert à la fois, toutes boîtes
               confondues. Sans cela, on ouvrirait dix boîtes d'un coup
               pour ne suivre qu'une seule publicité. */
            $st = db()->prepare(
                "UPDATE gift_claims SET status = 'expire'
                  WHERE user_id = ? AND status = 'en_attente'"
            );
            $st->bind_param('i', $userId);
            $st->execute();
            $st->close();

            $token = bin2hex(random_bytes(32));
            $ip    = wt_ip_bin();
            $st = db()->prepare(
                "INSERT INTO gift_claims (box_id, user_id, token, ip) VALUES (?, ?, ?, ?)"
            );
            $st->bind_param('iiss', $boxId, $userId, $token, $ip);
            $st->execute();
            $st->close();

            $out['ok']      = true;
            $out['token']   = $token;
            $out['seconds'] = max(5, (int) cfg('gift.ads_seconds', 30));
        } catch (Throwable $e) {
            error_log('[Wintaskly gift] ' . $e->getMessage());
            $out['error'] = 'db';
        }
        return $out;
    }
}

if (!function_exists('wt_gift_open_finish')) {
    /**
     * Révèle la boîte et crédite le lot de base.
     *
     * Le lot de base est versé tout de suite, sans condition. Le
     * multiplicateur est proposé ensuite, séparément : si le joueur
     * ferme la page à cet instant, il garde ce qu'il a gagné. Retenir
     * un gain le temps d'une décision est ce qui fait traiter un site
     * d'arnaque.
     *
     * @return array{ok:bool, error:string, kind:string, coins:float,
     *               xp:int, offer:int, left:int}
     */
    function wt_gift_open_finish(int $userId, string $token): array
    {
        $out = ['ok' => false, 'error' => '', 'kind' => '', 'coins' => 0.0,
                'xp' => 0, 'offer' => 0, 'left' => 0];
        if ($userId <= 0 || $token === '') { $out['error'] = 'invalid'; return $out; }

        $db = db();
        try {
            $db->begin_transaction();

            $st = $db->prepare(
                "SELECT c.id cid, c.status cstatus, c.created_at copened, c.box_id,
                        b.kind, b.coins, b.xp, b.opened_by, b.round_id,
                        r.status rstatus, r.boxes_count, r.opened_count
                   FROM gift_claims c
                   JOIN gift_boxes  b ON b.id = c.box_id
                   JOIN gift_rounds r ON r.id = b.round_id
                  WHERE c.token = ? AND c.user_id = ?
                  FOR UPDATE"
            );
            $st->bind_param('si', $token, $userId);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();

            if (!$row) { $db->rollback(); $out['error'] = 'not_found'; return $out; }
            if ($row['cstatus'] !== 'en_attente') { $db->rollback(); $out['error'] = 'used'; return $out; }
            if ($row['opened_by'] !== null) {
                /* Quelqu'un a ouvert cette boîte pendant le parcours. Le
                   parcours est clos sans lot : décevant, mais fabriquer
                   un lot hors grille casserait l'enveloppe. */
                $db->rollback();
                $out['error'] = 'taken';
                return $out;
            }

            $requis = max(5, (int) cfg('gift.ads_seconds', 30));
            $ecoule = time() - (strtotime((string) $row['copened'] . ' UTC') ?: time());
            if ($ecoule < $requis - 1) {
                $db->rollback();
                $out['error'] = 'too_fast';
                return $out;
            }

            $coins = (float) $row['coins'];
            $xp    = (int) $row['xp'];

            /* Multiplicateur proposé : tiré maintenant et figé en base.
               Le tirer à l'acceptation permettrait de recharger la page
               jusqu'à obtenir 80 %. */
            $offre = 0;
            if ($coins > 0 && wt_tickets_balance($userId) > 0) {
                $m = wt_gift_multipliers();
                $offre = (int) $m[random_int(0, count($m) - 1)];
            }

            $st = $db->prepare(
                "UPDATE gift_boxes
                    SET opened_by = ?, opened_at = UTC_TIMESTAMP(),
                        coins_paid = ?, offer_pct = ?
                  WHERE id = ? AND opened_by IS NULL"
            );
            $st->bind_param('idii', $userId, $coins, $offre, $row['box_id']);
            $st->execute();
            $pris = $st->affected_rows;
            $st->close();

            if ($pris !== 1) { $db->rollback(); $out['error'] = 'taken'; return $out; }

            $st = $db->prepare("UPDATE gift_claims SET status = 'valide' WHERE id = ?");
            $st->bind_param('i', $row['cid']);
            $st->execute();
            $st->close();

            $ouvertes = (int) $row['opened_count'] + 1;
            $fini     = ($ouvertes >= (int) $row['boxes_count']);
            $st = $db->prepare(
                "UPDATE gift_rounds
                    SET opened_count = ?, status = ?, closed_at = IF(? = 'done', UTC_TIMESTAMP(), closed_at)
                  WHERE id = ?"
            );
            $etat = $fini ? 'done' : 'active';
            $st->bind_param('issi', $ouvertes, $etat, $etat, $row['round_id']);
            $st->execute();
            $st->close();

            $db->commit();

            /* Cagnotte et jackpot progressent hors transaction : ce sont
               des compteurs globaux, les bloquer allongerait le verrou
               de la grille pour rien. */
            cfg_set('gift.prize_pot', (string) ((float) cfg('gift.prize_pot', 0) + (float) cfg('gift.pot_step', 20)));
            cfg_set('gift.jackpot',   (string) ((float) cfg('gift.jackpot', 0)   + (float) cfg('gift.jackpot_step', 5)));

            if ($coins > 0 || $xp > 0) {
                award_user($userId, $coins, $xp, 'gift', 'box:' . (int) $row['box_id']);
            }

            $out['ok']    = true;
            $out['kind']  = (string) $row['kind'];
            $out['coins'] = $coins;
            $out['xp']    = $xp;
            $out['offer'] = $offre;
            $out['left']  = max(0, (int) $row['boxes_count'] - $ouvertes);
        } catch (Throwable $e) {
            try { $db->rollback(); } catch (Throwable $e2) {}
            error_log('[Wintaskly gift] ' . $e->getMessage());
            $out['error'] = 'db';
        }
        return $out;
    }
}

if (!function_exists('wt_gift_boost')) {
    /**
     * Applique le multiplicateur proposé, contre un ticket.
     *
     * Le pourcentage vient de la base, pas de la requête : le joueur ne
     * choisit que d'accepter ou non.
     *
     * @return array{ok:bool, error:string, bonus:float, pct:int}
     */
    function wt_gift_boost(int $userId, int $boxId): array
    {
        $out = ['ok' => false, 'error' => '', 'bonus' => 0.0, 'pct' => 0];

        try {
            $b = db_one("SELECT * FROM gift_boxes WHERE id = " . max(0, $boxId));
            if (!$b || (int) $b['opened_by'] !== $userId) { $out['error'] = 'not_found'; return $out; }
            if ((int) $b['multiplier'] > 0) { $out['error'] = 'already'; return $out; }

            $pct = (int) $b['offer_pct'];
            if ($pct <= 0) { $out['error'] = 'no_offer'; return $out; }

            $bonus = round((float) $b['coins'] * $pct / 100, 4);
            if ($bonus <= 0) { $out['error'] = 'no_offer'; return $out; }

            /* Le ticket est débité d'abord. S'il manque, rien d'autre
               n'a été fait et le joueur garde son lot de base. */
            if (!wt_tickets_add($userId, -1, 'play', 'Boîte #' . $boxId)) {
                $out['error'] = 'no_tickets';
                return $out;
            }

            $st = db()->prepare(
                "UPDATE gift_boxes SET multiplier = ?, coins_paid = coins_paid + ?
                  WHERE id = ? AND multiplier = 0"
            );
            $st->bind_param('idi', $pct, $bonus, $boxId);
            $st->execute();
            $ok = ($st->affected_rows === 1);
            $st->close();

            if (!$ok) {
                /* Double clic : le ticket est rendu plutôt que perdu. */
                wt_tickets_add($userId, 1, 'refund', 'Boîte #' . $boxId);
                $out['error'] = 'already';
                return $out;
            }

            award_user($userId, $bonus, 0, 'gift', 'boost:' . $boxId);

            $out['ok']    = true;
            $out['bonus'] = $bonus;
            $out['pct']   = $pct;
        } catch (Throwable $e) {
            error_log('[Wintaskly gift] ' . $e->getMessage());
            $out['error'] = 'db';
        }
        return $out;
    }
}
