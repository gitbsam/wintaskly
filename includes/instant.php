<?php
/**
 * Wintaskly — includes/instant.php
 *
 * Moteur du jeu « Instant Gagnant ».
 *
 * Principe : chaque jeu porte un compteur PARTAGÉ entre tous les
 * joueurs. Une partie le décrémente de 1 ; celui qui l'amène à zéro
 * remporte le gain, et le compteur repart à son total.
 *
 * Deux façons de jouer une partie :
 *   - en suivant un parcours publicitaire (voie 'ads') ;
 *   - en misant un ticket (voie 'tickets').
 *
 * La difficulté n'est pas le tirage — il n'y en a pas — mais la
 * concurrence. Deux joueurs qui misent au même instant sur un compteur
 * à 1 doivent obtenir des résultats différents : un gagnant, un
 * perdant. Sans verrou, les deux liraient « 1 » et se croiraient
 * gagnants tous les deux.
 */
declare(strict_types=1);

/**
 * Nombre maximal de parties traitées en un seul envoi.
 *
 * Un joueur qui mise 100 tickets d'un coup produirait 100 écritures
 * dans une même transaction, et maintiendrait le verrou du jeu pendant
 * tout ce temps — bloquant les autres joueurs. On traite par paquets,
 * le client rappelle pour la suite.
 */
const WT_INSTANT_BATCH_MAX = 25;

if (!function_exists('wt_instant_enabled')) {
    function wt_instant_enabled(): bool
    {
        return (string) cfg('instant.enabled', '0') === '1';
    }
}

if (!function_exists('wt_instant_ticket_label')) {
    /** Nom affiché du jeton. Jamais « WINT » : ce sigle est réservé au token. */
    function wt_instant_ticket_label(): string
    {
        $l = trim((string) cfg('instant.ticket_label', 'Ticket'));
        return $l !== '' ? $l : 'Ticket';
    }
}

if (!function_exists('wt_instant_visible_for')) {
    /**
     * Le jeu est-il visible pour cet utilisateur ?
     *
     * Même logique que le bingo : l'administrateur voit toujours, afin
     * de pouvoir tester avant la mise en ligne.
     */
    function wt_instant_visible_for(array $game, ?array $user): bool
    {
        if (!wt_instant_enabled() || (int) $game['active'] !== 1) { return false; }
        if ($user && (($user['role'] ?? '') === 'admin')) { return true; }
        if ((int) $game['test_mode'] === 1) { return false; }
        if (!empty($game['launch_at'])
            && strtotime((string) $game['launch_at'] . ' UTC') > time()) {
            return false;
        }
        return true;
    }
}

if (!function_exists('wt_tickets_balance')) {
    /** Solde de tickets d'un utilisateur. */
    function wt_tickets_balance(int $userId): int
    {
        if ($userId <= 0) { return 0; }
        try {
            $r = db_one("SELECT balance FROM user_tickets WHERE user_id = " . $userId);
            return $r ? (int) $r['balance'] : 0;
        } catch (Throwable $e) {
            error_log('[Wintaskly instant] ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('wt_tickets_add')) {
    /**
     * Crédite ou débite des tickets, et journalise l'opération.
     *
     * L'origine n'est pas décorative : c'est elle qui permet de prouver
     * qu'un ticket a été gagné plutôt qu'acheté. Sans cette trace, la
     * distinction entre un jeu promotionnel et une loterie payante
     * devient indémontrable.
     *
     * @param  int    $delta  positif pour créditer, négatif pour débiter
     * @return bool   false si le solde deviendrait négatif
     */
    function wt_tickets_add(int $userId, int $delta, string $origin = 'admin', string $note = ''): bool
    {
        if ($userId <= 0 || $delta === 0) { return false; }
        $origins = ['task','bonus','referral','admin','purchase','play','refund'];
        if (!in_array($origin, $origins, true)) { $origin = 'admin'; }

        /* La vente est verrouillée par un réglage distinct de tout le
           reste. Un crédit d'origine « achat » alors que la vente est
           désactivée signale une erreur d'appel, pas un cas normal. */
        if ($origin === 'purchase' && (string) cfg('instant.tickets_purchase_enabled', '0') !== '1') {
            error_log('[Wintaskly instant] credit achat refuse : vente desactivee');
            return false;
        }

        try {
            $db = db();
            $db->begin_transaction();

            /* Le solde est verrouillé le temps de l'opération : sans
               cela, deux mises simultanées liraient le même solde et
               pourraient toutes deux passer avec un seul ticket. */
            $st = $db->prepare("SELECT balance FROM user_tickets WHERE user_id = ? FOR UPDATE");
            $st->bind_param('i', $userId);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();

            $solde = $row ? (int) $row['balance'] : 0;
            $neuf  = $solde + $delta;
            if ($neuf < 0) { $db->rollback(); return false; }

            $st = $db->prepare(
                "INSERT INTO user_tickets (user_id, balance) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE balance = VALUES(balance)"
            );
            $st->bind_param('ii', $userId, $neuf);
            $st->execute();
            $st->close();

            $st = $db->prepare(
                "INSERT INTO ticket_ledger (user_id, delta, origin, note) VALUES (?, ?, ?, ?)"
            );
            $st->bind_param('iiss', $userId, $delta, $origin, $note);
            $st->execute();
            $st->close();

            $db->commit();
            return true;
        } catch (Throwable $e) {
            try { db()->rollback(); } catch (Throwable $e2) {}
            error_log('[Wintaskly instant] ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('wt_instant_play_one')) {
    /**
     * Joue UNE partie sur le compteur partagé.
     *
     * À appeler à l'intérieur d'une transaction où le jeu a déjà été
     * verrouillé par SELECT ... FOR UPDATE : la lecture et l'écriture du
     * compteur doivent être indivisibles, sinon deux joueurs peuvent
     * gagner le même lot.
     *
     * @return array{won:bool, parts_left:int}
     */
    function wt_instant_play_one(array $game): array
    {
        $db      = db();
        $gameId  = (int) $game['id'];
        $total   = max(1, (int) $game['parts_total']);
        $left    = (int) $game['parts_left'];

        /* Un compteur déjà à zéro ou incohérent est ramené au total
           plutôt que de bloquer le jeu : mieux vaut une partie de trop
           que des joueurs devant un écran mort. */
        if ($left <= 0) { $left = $total; }

        $left--;
        $gagne = ($left <= 0);
        if ($gagne) { $left = $total; }

        $st = $db->prepare(
            "UPDATE instant_games
                SET parts_left = ?, plays_total = plays_total + 1,
                    wins_total = wins_total + ?
              WHERE id = ?"
        );
        $inc = $gagne ? 1 : 0;
        $st->bind_param('iii', $left, $inc, $gameId);
        $st->execute();
        $st->close();

        return ['won' => $gagne, 'parts_left' => $left];
    }
}

if (!function_exists('wt_instant_play_tickets')) {
    /**
     * Mise un ou plusieurs tickets sur un jeu.
     *
     * Chaque ticket joue une partie. Le joueur peut donc perdre
     * plusieurs fois puis gagner au sein du même envoi — c'est le
     * comportement attendu, et c'est pourquoi on renvoie le détail.
     *
     * @return array{ok:bool, error:string, plays:array, wins:int, coins:float, left:int}
     */
    function wt_instant_play_tickets(int $userId, int $gameId, int $tickets): array
    {
        $out = ['ok' => false, 'error' => '', 'plays' => [], 'wins' => 0,
                'coins' => 0.0, 'left' => 0];

        $tickets = max(1, min(WT_INSTANT_BATCH_MAX, $tickets));
        if ($userId <= 0 || $gameId <= 0) { $out['error'] = 'invalid'; return $out; }

        if (wt_tickets_balance($userId) < $tickets) {
            $out['error'] = 'no_tickets';
            return $out;
        }

        $db = db();
        try {
            $db->begin_transaction();

            /* Verrou sur le jeu. C'est lui qui garantit qu'un seul
               joueur amènera le compteur à zéro. */
            $st = $db->prepare("SELECT * FROM instant_games WHERE id = ? FOR UPDATE");
            $st->bind_param('i', $gameId);
            $st->execute();
            $game = $st->get_result()->fetch_assoc();
            $st->close();

            if (!$game || (int) $game['active'] !== 1 || $game['mode'] !== 'tickets') {
                $db->rollback();
                $out['error'] = 'unavailable';
                return $out;
            }

            $coins = 0.0;
            for ($i = 0; $i < $tickets; $i++) {
                $r = wt_instant_play_one($game);
                $game['parts_left'] = $r['parts_left'];

                $gagne  = $r['won'] ? 1 : 0;
                $montant = $r['won'] ? (float) $game['reward_coins'] : 0.0;
                $coins  += $montant;
                if ($r['won']) { $out['wins']++; }

                $st = $db->prepare(
                    "INSERT INTO instant_plays
                       (game_id, user_id, mode, tickets_used, won, coins_won, parts_left, status, ip)
                     VALUES (?, ?, 'tickets', 1, ?, ?, ?, 'valide', ?)"
                );
                $ip = wt_ip_bin();
                $st->bind_param('iiidis', $gameId, $userId, $gagne, $montant, $r['parts_left'], $ip);
                $st->execute();
                $st->close();

                $out['plays'][] = ['won' => $r['won'], 'left' => $r['parts_left']];
            }

            $db->commit();
            $out['left'] = (int) $game['parts_left'];
        } catch (Throwable $e) {
            try { $db->rollback(); } catch (Throwable $e2) {}
            error_log('[Wintaskly instant] ' . $e->getMessage());
            $out['error'] = 'db';
            return $out;
        }

        /* Débit et crédit hors transaction de jeu : ce sont deux
           opérations distinctes, chacune avec son propre verrou et son
           propre journal. */
        if (!wt_tickets_add($userId, -$tickets, 'play', 'Jeu #' . $gameId)) {
            /* Le solde a bougé entre la vérification et le débit. Les
               parties sont jouées et tracées : on les laisse, mais on
               signale, car la situation ne devrait pas se produire. */
            error_log('[Wintaskly instant] debit impossible apres jeu — user=' . $userId);
        }
        if ($coins > 0) {
            /* award_user() est la seule porte d'entree du credit sur ce
               site : elle met a jour le solde, l'XP, le niveau et verse
               la commission de parrainage. Ecrire directement dans
               users.coins contournerait les trois derniers. */
            award_user($userId, $coins, 0, 'instant', 'game:' . $gameId);
        }

        $out['ok']    = true;
        $out['coins'] = $coins;
        return $out;
    }
}

if (!function_exists('wt_instant_can_play')) {
    /**
     * L'utilisateur peut-il lancer une participation maintenant ?
     *
     * Deux limites, comptees sur les parties VALIDEES : un parcours
     * abandonne ne doit pas consommer un essai, sinon une coupure
     * reseau couterait une participation.
     *
     * @return string '' si autorise, sinon 'daily' ou 'cooldown'
     */
    function wt_instant_can_play(int $userId, array $game): string
    {
        $gameId = (int) $game['id'];
        $max    = (int) $game['daily_max'];
        $wait   = (int) $game['cooldown_hours'];

        try {
            if ($max > 0) {
                $st = db()->prepare(
                    "SELECT COUNT(*) c FROM instant_plays
                      WHERE user_id = ? AND game_id = ? AND status = 'valide'
                        AND DATE(created_at) = UTC_DATE()"
                );
                $st->bind_param('ii', $userId, $gameId);
                $st->execute();
                $n = (int) ($st->get_result()->fetch_assoc()['c'] ?? 0);
                $st->close();
                if ($n >= $max) { return 'daily'; }
            }

            if ($wait > 0) {
                $st = db()->prepare(
                    "SELECT created_at FROM instant_plays
                      WHERE user_id = ? AND game_id = ? AND status = 'valide'
                      ORDER BY id DESC LIMIT 1"
                );
                $st->bind_param('ii', $userId, $gameId);
                $st->execute();
                $r = $st->get_result()->fetch_assoc();
                $st->close();
                if ($r && strtotime((string) $r['created_at'] . ' UTC') + $wait * 3600 > time()) {
                    return 'cooldown';
                }
            }
        } catch (Throwable $e) {
            error_log('[Wintaskly instant] ' . $e->getMessage());
        }
        return '';
    }
}

if (!function_exists('wt_instant_ads_start')) {
    /**
     * Ouvre un parcours publicitaire et retourne son jeton.
     *
     * La partie n'est PAS jouee ici : elle le sera au retour, une fois
     * le temps verifie. Creer la partie maintenant permettrait de la
     * reclamer sans jamais avoir attendu.
     *
     * Tout parcours encore ouvert sur ce jeu est perime d'abord : sans
     * cela, ouvrir la page plusieurs fois donnerait plusieurs jetons
     * valides, et autant de participations pour un seul parcours.
     *
     * @return array{ok:bool, error:string, token:string, seconds:int}
     */
    function wt_instant_ads_start(int $userId, array $game): array
    {
        $out = ['ok' => false, 'error' => '', 'token' => '', 'seconds' => 45];
        $gameId = (int) $game['id'];
        if ($userId <= 0 || $gameId <= 0) { $out['error'] = 'invalid'; return $out; }

        $bloc = wt_instant_can_play($userId, $game);
        if ($bloc !== '') { $out['error'] = $bloc; return $out; }

        $secondes = max(5, (int) cfg('instant.ads_seconds', 45));

        try {
            $st = db()->prepare(
                "UPDATE instant_plays SET status = 'expire'
                  WHERE user_id = ? AND game_id = ? AND status = 'en_attente'"
            );
            $st->bind_param('ii', $userId, $gameId);
            $st->execute();
            $st->close();

            $token = bin2hex(random_bytes(32));
            $ip    = wt_ip_bin();
            $st = db()->prepare(
                "INSERT INTO instant_plays (game_id, user_id, mode, token, status, ip)
                 VALUES (?, ?, 'ads', ?, 'en_attente', ?)"
            );
            $st->bind_param('iiss', $gameId, $userId, $token, $ip);
            $st->execute();
            $st->close();

            $out['ok']      = true;
            $out['token']   = $token;
            $out['seconds'] = $secondes;
        } catch (Throwable $e) {
            error_log('[Wintaskly instant] ' . $e->getMessage());
            $out['error'] = 'db';
        }
        return $out;
    }
}

if (!function_exists('wt_instant_ads_finish')) {
    /**
     * Cloture un parcours publicitaire et joue la partie.
     *
     * Le temps ecoule est recalcule ici, a partir de l'horodatage
     * d'ouverture en base. Le chrono du navigateur n'est qu'un
     * affichage : le supprimer depuis la console ne sert a rien.
     *
     * @return array{ok:bool, error:string, won:bool, coins:float, left:int}
     */
    function wt_instant_ads_finish(int $userId, string $token): array
    {
        $out = ['ok' => false, 'error' => '', 'won' => false, 'coins' => 0.0, 'left' => 0];
        if ($userId <= 0 || $token === '') { $out['error'] = 'invalid'; return $out; }

        $db = db();
        try {
            $db->begin_transaction();

            $st = $db->prepare(
                "SELECT p.*, g.parts_total, g.parts_left, g.reward_coins, g.active, g.mode AS gmode
                   FROM instant_plays p
                   JOIN instant_games g ON g.id = p.game_id
                  WHERE p.token = ? AND p.user_id = ?
                  FOR UPDATE"
            );
            $st->bind_param('si', $token, $userId);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();

            if (!$row) { $db->rollback(); $out['error'] = 'not_found'; return $out; }
            if ($row['status'] !== 'en_attente') { $db->rollback(); $out['error'] = 'used'; return $out; }
            if ((int) $row['active'] !== 1 || $row['gmode'] !== 'ads') {
                $db->rollback(); $out['error'] = 'unavailable'; return $out;
            }

            $requis  = max(5, (int) cfg('instant.ads_seconds', 45));
            $ecoule  = time() - (strtotime((string) $row['created_at'] . ' UTC') ?: time());
            /* Une seconde de tolerance : entre la fin du chrono affiche
               et l'arrivee de la requete, la latence reseau peut faire
               perdre quelques dixiemes. Refuser un joueur honnete coute
               plus cher que d'accorder une seconde. */
            if ($ecoule < $requis - 1) {
                $db->rollback();
                $out['error'] = 'too_fast';
                return $out;
            }

            /* Le jeu est deja verrouille par la jointure FOR UPDATE. */
            $jeu = ['id' => (int) $row['game_id'], 'parts_total' => (int) $row['parts_total'],
                    'parts_left' => (int) $row['parts_left'], 'reward_coins' => (float) $row['reward_coins']];
            $r = wt_instant_play_one($jeu);

            $gagne   = $r['won'] ? 1 : 0;
            $montant = $r['won'] ? (float) $row['reward_coins'] : 0.0;

            $st = $db->prepare(
                "UPDATE instant_plays
                    SET status = 'valide', won = ?, coins_won = ?, parts_left = ?
                  WHERE id = ?"
            );
            $st->bind_param('idii', $gagne, $montant, $r['parts_left'], $row['id']);
            $st->execute();
            $st->close();

            $db->commit();

            if ($montant > 0) {
                award_user($userId, $montant, 0, 'instant', 'game:' . (int) $row['game_id']);
            }

            $out['ok']    = true;
            $out['won']   = $r['won'];
            $out['coins'] = $montant;
            /* Le compteur n'est rendu qu'en cas de defaite. Le montrer
               avant de jouer permettrait d'attendre qu'il tombe a 1 :
               les gains iraient a ceux qui surveillent, pas a ceux qui
               participent. */
            $out['left']  = $r['won'] ? 0 : (int) $r['parts_left'];
        } catch (Throwable $e) {
            try { $db->rollback(); } catch (Throwable $e2) {}
            error_log('[Wintaskly instant] ' . $e->getMessage());
            $out['error'] = 'db';
        }
        return $out;
    }
}
