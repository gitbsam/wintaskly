<?php
/**
 * Wintaskly — Module Bonus Quotidien (Daily Bonus / Streak)
 * ─────────────────────────────────────────────────────────────────────
 * Logique centrale du système de récompense de connexion quotidienne.
 *
 * Concepts :
 *   - STREAK : nombre de jours consécutifs où l'utilisateur a réclamé.
 *   - FENÊTRE (window_hours) : délai après lequel un nouveau claim est
 *     possible (par défaut 24h).
 *   - RESET (reset_hours) : délai au-delà duquel le streak retombe à 0
 *     (par défaut 48h — l'utilisateur a "raté" un jour).
 *   - PALIERS (tiers) : table configurable jour → récompense.
 *   - CYCLE : 'repeat' (recommence à J1 après le dernier palier) ou
 *     'hold' (reste au dernier palier indéfiniment).
 *
 * Tout est configurable via /admin/daily-bonus.php.
 *
 * Fonctions tolérantes aux pannes : si les tables n'existent pas encore
 * (migration non appliquée), le système se désactive proprement sans
 * planter le site.
 */
declare(strict_types=1);

if (!function_exists('wt_daily_enabled')) {
    /**
     * Le bonus quotidien est-il activé globalement ?
     */
    function wt_daily_enabled(): bool
    {
        return (string) cfg('daily_bonus.enabled', '0') === '1';
    }
}

if (!function_exists('wt_daily_tiers')) {
    /**
     * Retourne les paliers de récompense, triés par jour de streak.
     * Format : [['streak_day'=>1,'coins'=>10.0,'xp'=>5,'label'=>null,
     *            'is_jackpot'=>0], ...]
     * Mis en cache pour la requête courante. Vide si table absente.
     */
    function wt_daily_tiers(): array
    {
        if (isset($GLOBALS['__wt_daily_tiers'])) {
            return $GLOBALS['__wt_daily_tiers'];
        }
        $tiers = [];
        try {
            $res = db()->query(
                "SELECT streak_day, coins, xp, label, is_jackpot
                   FROM daily_bonus_tiers ORDER BY streak_day ASC"
            );
            if ($res instanceof mysqli_result) {
                while ($r = $res->fetch_assoc()) {
                    $tiers[] = [
                        'streak_day' => (int) $r['streak_day'],
                        'coins'      => (float) $r['coins'],
                        'xp'         => (int) $r['xp'],
                        'label'      => $r['label'],
                        'is_jackpot' => (int) $r['is_jackpot'],
                    ];
                }
                $res->free();
            }
        } catch (Throwable $e) {
            error_log('[Wintaskly daily] tiers unavailable: ' . $e->getMessage());
        }
        $GLOBALS['__wt_daily_tiers'] = $tiers;
        return $tiers;
    }
}

if (!function_exists('wt_daily_cycle_length')) {
    /**
     * Nombre de paliers configurés (= longueur du cycle).
     */
    function wt_daily_cycle_length(): int
    {
        return count(wt_daily_tiers());
    }
}

if (!function_exists('wt_daily_state')) {
    /**
     * Calcule l'état du bonus quotidien pour un utilisateur donné.
     *
     * Retourne un tableau :
     *   [
     *     'enabled'        => bool,   // système actif
     *     'can_claim'      => bool,   // peut réclamer maintenant
     *     'current_streak' => int,    // streak actuel (avant ce claim)
     *     'next_day'       => int,    // jour du cycle qu'il va réclamer
     *     'next_tier'      => array,  // palier correspondant (coins/xp)
     *     'seconds_left'   => int,    // secondes avant prochain claim (si pas dispo)
     *     'streak_broken'  => bool,   // le streak a été cassé (reset)
     *     'tiers'          => array,  // tous les paliers (pour affichage)
     *   ]
     *
     * @param array $user Données user (doit contenir id, daily_streak,
     *                    daily_last_claim_at)
     */
    function wt_daily_state(array $user): array
    {
        $tiers       = wt_daily_tiers();
        $cycleLen    = count($tiers);
        $windowHours = max(1, (int) cfg('daily_bonus.window_hours', '24'));
        $resetHours  = max($windowHours, (int) cfg('daily_bonus.reset_hours', '48'));
        $cycleMode   = (string) cfg('daily_bonus.cycle_mode', 'repeat');

        $state = [
            'enabled'        => wt_daily_enabled() && $cycleLen > 0,
            'can_claim'      => false,
            'current_streak' => (int) ($user['daily_streak'] ?? 0),
            'next_day'       => 1,
            'next_tier'      => $tiers[0] ?? ['streak_day' => 1, 'coins' => 0, 'xp' => 0, 'label' => null, 'is_jackpot' => 0],
            'seconds_left'   => 0,
            'streak_broken'  => false,
            'tiers'          => $tiers,
            'window_hours'   => $windowHours,
        ];

        if (!$state['enabled']) {
            return $state;
        }

        $lastClaimRaw = $user['daily_last_claim_at'] ?? null;
        $now          = time();

        // Jamais réclamé → peut claim immédiatement, jour 1
        if (empty($lastClaimRaw)) {
            $state['can_claim'] = true;
            $state['next_day']  = 1;
            $state['next_tier'] = $tiers[0];
            return $state;
        }

        $lastClaimTs = strtotime($lastClaimRaw . ' UTC');
        $hoursSince  = ($now - $lastClaimTs) / 3600;

        // Fenêtre pas encore écoulée → cooldown
        if ($hoursSince < $windowHours) {
            $state['can_claim']    = false;
            $state['seconds_left'] = (int) ceil(($lastClaimTs + $windowHours * 3600) - $now);
            // Le prochain jour qu'il réclamera (info anticipée)
            $nextStreak = $state['current_streak'] + 1;
            $state['next_day']  = wt_daily_resolve_day($nextStreak, $cycleLen, $cycleMode);
            $state['next_tier'] = wt_daily_tier_for_day($state['next_day']);
            return $state;
        }

        // Fenêtre écoulée → peut réclamer
        $state['can_claim'] = true;

        // Streak cassé ? (au-delà du reset → repart à 1)
        if ($hoursSince >= $resetHours) {
            $state['streak_broken']  = true;
            $state['current_streak'] = 0;
            $state['next_day']       = 1;
            $state['next_tier']      = $tiers[0];
        } else {
            // Streak continue
            $nextStreak = $state['current_streak'] + 1;
            $state['next_day']  = wt_daily_resolve_day($nextStreak, $cycleLen, $cycleMode);
            $state['next_tier'] = wt_daily_tier_for_day($state['next_day']);
        }

        return $state;
    }
}

if (!function_exists('wt_daily_resolve_day')) {
    /**
     * Résout le jour du cycle affiché à partir du streak absolu.
     *
     * - mode 'repeat' : le jour boucle (streak 8 sur cycle 7 → jour 1)
     * - mode 'hold'   : reste au dernier palier (streak 8 → jour 7)
     *
     * @param int    $streak    Streak absolu (1, 2, 3, ...)
     * @param int    $cycleLen  Nombre de paliers
     * @param string $mode      'repeat' | 'hold'
     * @return int              Jour du cycle (1..cycleLen)
     */
    function wt_daily_resolve_day(int $streak, int $cycleLen, string $mode): int
    {
        if ($cycleLen <= 0) return 1;
        if ($streak <= $cycleLen) {
            return max(1, $streak);
        }
        if ($mode === 'hold') {
            return $cycleLen;
        }
        // repeat : modulo cyclique 1-indexé
        $day = $streak % $cycleLen;
        return $day === 0 ? $cycleLen : $day;
    }
}

if (!function_exists('wt_daily_tier_for_day')) {
    /**
     * Retourne le palier (tier) correspondant à un jour de cycle donné.
     * Fallback sur un palier vide si introuvable.
     */
    function wt_daily_tier_for_day(int $day): array
    {
        foreach (wt_daily_tiers() as $t) {
            if ($t['streak_day'] === $day) {
                return $t;
            }
        }
        return ['streak_day' => $day, 'coins' => 0, 'xp' => 0, 'label' => null, 'is_jackpot' => 0];
    }
}

if (!function_exists('wt_daily_claim')) {
    /**
     * Effectue la réclamation du bonus quotidien pour un utilisateur.
     *
     * Vérifie l'éligibilité, calcule le nouveau streak, crédite via
     * award_user(), enregistre le claim et met à jour le streak user.
     *
     * Retourne :
     *   ['ok'=>true, 'coins'=>X, 'xp'=>Y, 'streak'=>N, 'day'=>D, 'jackpot'=>bool]
     *   ou ['ok'=>false, 'error'=>'code', 'seconds_left'=>S]
     *
     * @param array $user Données user (id, daily_streak, daily_last_claim_at)
     */
    function wt_daily_claim(array $user): array
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return ['ok' => false, 'error' => 'invalid_user'];
        }

        $state = wt_daily_state($user);

        if (!$state['enabled']) {
            return ['ok' => false, 'error' => 'disabled'];
        }
        if (!$state['can_claim']) {
            return ['ok' => false, 'error' => 'cooldown', 'seconds_left' => $state['seconds_left']];
        }

        $tier        = $state['next_tier'];
        $coins       = (float) $tier['coins'];
        $xp          = (int) $tier['xp'];
        $day         = (int) $state['next_day'];
        $newStreak   = $state['streak_broken'] ? 1 : ($state['current_streak'] + 1);
        $windowHours = (int) $state['window_hours'];

        $db = db();

        // Re-vérification anti-double-claim dans une transaction atomique :
        // on relit le dernier claim AVANT de créditer pour éviter qu'un
        // double-clic ou une requête concurrente crédite deux fois.
        try {
            $db->begin_transaction();

            // Verrou applicatif : relire la date de dernier claim
            $stmt = $db->prepare("SELECT daily_last_claim_at FROM users WHERE id = ? FOR UPDATE");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $lastRaw = $row['daily_last_claim_at'] ?? null;
            if (!empty($lastRaw)) {
                $hoursSince = (time() - strtotime($lastRaw . ' UTC')) / 3600;
                if ($hoursSince < $windowHours) {
                    // Quelqu'un a déjà claim entre temps
                    $db->rollback();
                    return ['ok' => false, 'error' => 'cooldown',
                            'seconds_left' => (int) ceil(($windowHours - $hoursSince) * 3600)];
                }
            }

            // Met à jour le streak + date sur users
            $nextClaimAt = gmdate('Y-m-d H:i:s', time() + $windowHours * 3600);
            $stmt = $db->prepare(
                "UPDATE users
                    SET daily_streak = ?, daily_last_claim_at = UTC_TIMESTAMP()
                  WHERE id = ?"
            );
            $stmt->bind_param('ii', $newStreak, $userId);
            $stmt->execute();
            $stmt->close();

            // Enregistre le claim dans l'historique
            $ipBin = wt_daily_ip_bin();
            $stmt = $db->prepare(
                "INSERT INTO daily_bonus_claims
                   (user_id, streak_day, coins_awarded, xp_awarded, ip, next_claim_at)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('iidiss', $userId, $day, $coins, $xp, $ipBin, $nextClaimAt);
            $stmt->execute();
            $stmt->close();

            $db->commit();
        } catch (Throwable $e) {
            try { $db->rollback(); } catch (Throwable $e2) {}
            error_log('[Wintaskly daily] claim failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'server_error'];
        }

        // Crédite coins + XP (award_user gère sa propre transaction +
        // niveau + parrainage). Type 'daily_bonus' pour la traçabilité.
        if ($coins > 0 || $xp > 0) {
            award_user($userId, $coins, $xp, 'daily_bonus', 'streak_day:' . $day);
        }

        return [
            'ok'      => true,
            'coins'   => $coins,
            'xp'      => $xp,
            'streak'  => $newStreak,
            'day'     => $day,
            'jackpot' => (bool) $tier['is_jackpot'],
            'label'   => $tier['label'],
        ];
    }
}

if (!function_exists('wt_daily_ip_bin')) {
    /**
     * Retourne l'IP courante en binaire (pour la colonne VARBINARY),
     * ou null si indisponible.
     */
    function wt_daily_ip_bin(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($ip === '') return null;
        $bin = @inet_pton($ip);
        return $bin !== false ? $bin : null;
    }
}
