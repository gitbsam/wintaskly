<?php
/**
 * Wintaskly — Module Achievements (badges / succès)
 * ─────────────────────────────────────────────────────────────────────
 * Moteur de déblocage de badges basé sur des métriques mesurables.
 *
 * Fonctionnement :
 *   - Chaque achievement définit une métrique (quoi compter) + un seuil.
 *   - wt_ach_metric_value() calcule la valeur courante d'une métrique
 *     pour un utilisateur (ex: nombre de claims faucet).
 *   - wt_ach_check() compare les métriques aux seuils et débloque les
 *     badges atteints, en créditant la récompense via award_user().
 *
 * Appelé :
 *   - En temps réel après chaque action génératrice (faucet, shortlink,
 *     daily bonus, retrait...) via wt_ach_check($userId).
 *   - En filet de sécurité au chargement du dashboard.
 *
 * Optimisation : wt_ach_check() ne calcule QUE les métriques nécessaires
 * pour les achievements pas encore débloqués. Un user qui a déjà tout
 * débloqué ne déclenche aucune requête de calcul.
 *
 * Tolérant aux pannes : si les tables n'existent pas, tout se désactive
 * proprement sans planter.
 */
declare(strict_types=1);

/**
 * ─────────────────────────────────────────────────────────────────────
 * REGISTRE DES MÉTRIQUES — source unique de vérité
 * ─────────────────────────────────────────────────────────────────────
 * Chaque métrique mesurable est déclarée ICI une seule fois, avec son
 * libellé lisible. Ce registre est utilisé par :
 *   - wt_ach_metric_value()  → pour savoir quoi calculer
 *   - l'admin (/admin/achievements.php) → pour peupler le <select>
 *   - wt_ach_metric_valid()  → pour valider en liste blanche à l'écriture
 *
 * Conséquence : impossible de créer un achievement avec une métrique
 * inexistante (typo "fawcet"). Si la métrique n'est pas dans cette liste,
 * l'admin la refuse, et le moteur ne tente jamais de la calculer.
 *
 * Pour ajouter une métrique : 1) l'ajouter ici, 2) ajouter son case dans
 * wt_ach_metric_value(). Les deux au même endroit = pas d'oubli.
 */
if (!function_exists('wt_ach_metrics')) {
    function wt_ach_metrics(): array
    {
        return [
            'faucet_claims'      => 'Réclamations faucet',
            'shortlinks_done'    => 'Shortlinks complétés',
            'ptc_views'          => 'Pubs PTC vues',
            'offerwalls_done'    => 'Offerwalls créditées',
            'referrals'          => 'Filleuls parrainés',
            'withdrawals_done'   => 'Retraits effectués',
            'daily_claims'       => 'Bonus quotidiens réclamés',
            'daily_streak'       => 'Série quotidienne (streak)',
            'total_coins_earned' => 'Coins gagnés au total',
            'level'              => 'Niveau atteint',
            'account_age_days'   => 'Ancienneté du compte (jours)',
        ];
    }
}

if (!function_exists('wt_ach_metric_valid')) {
    /**
     * Valide qu'une métrique existe réellement (liste blanche).
     * Utilisé par l'admin avant d'enregistrer un achievement.
     */
    function wt_ach_metric_valid(string $metric): bool
    {
        return array_key_exists($metric, wt_ach_metrics());
    }
}

if (!function_exists('wt_ach_metric_label')) {
    /**
     * Libellé lisible d'une métrique (ou la clé brute si inconnue).
     */
    function wt_ach_metric_label(string $metric): string
    {
        return wt_ach_metrics()[$metric] ?? $metric;
    }
}


if (!function_exists('wt_ach_enabled')) {
    function wt_ach_enabled(): bool
    {
        return (string) cfg('achievements.enabled', '0') === '1';
    }
}

if (!function_exists('wt_ach_all')) {
    /**
     * Retourne toutes les définitions d'achievements actives, triées.
     * Mises en cache pour la requête courante.
     */
    function wt_ach_all(): array
    {
        if (isset($GLOBALS['__wt_ach_all'])) {
            return $GLOBALS['__wt_ach_all'];
        }
        $all = [];
        try {
            $res = db()->query(
                "SELECT id, k, metric, threshold, tier, title, description, icon,
                        reward_coins, reward_xp, sort_order
                   FROM achievements
                  WHERE active = 1
                  ORDER BY sort_order ASC, id ASC"
            );
            if ($res instanceof mysqli_result) {
                while ($r = $res->fetch_assoc()) {
                    $all[] = $r;
                }
                $res->free();
            }
        } catch (Throwable $e) {
            error_log('[Wintaskly ach] all unavailable: ' . $e->getMessage());
        }
        $GLOBALS['__wt_ach_all'] = $all;
        return $all;
    }
}

if (!function_exists('wt_ach_unlocked_ids')) {
    /**
     * Retourne les IDs des achievements déjà débloqués par un user.
     * @return int[]
     */
    function wt_ach_unlocked_ids(int $userId): array
    {
        $ids = [];
        try {
            $stmt = db()->prepare("SELECT achievement_id FROM user_achievements WHERE user_id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $ids[] = (int) $r['achievement_id'];
            }
            $stmt->close();
        } catch (Throwable $e) {
            error_log('[Wintaskly ach] unlocked_ids: ' . $e->getMessage());
        }
        return $ids;
    }
}

if (!function_exists('wt_get_user_progress')) {
    /**
     * Calcule la progression courante d'un utilisateur sur une métrique
     * donnée, à partir des VRAIES tables d'activité (pas de la table
     * transactions, sauf pour total_coins_earned qui agrège les gains).
     *
     * Garde liste blanche : si la métrique n'est pas déclarée dans
     * wt_ach_metrics(), on retourne 0 sans même tenter de requête. Cela
     * neutralise tout achievement mal configuré (typo de métrique).
     *
     * Optimisations SQL par métrique :
     *   - faucet_claims    → idx (user_id, next_claim_at) couvre le WHERE
     *   - shortlinks_done  → idx (user_id, shortlink_id, status) couvre tout
     *   - total_coins_earned → idx COUVRANT (user_id, coins) : le SUM se
     *     résout dans l'index, sans lecture des lignes.
     *
     * @param  int    $userId
     * @param  string $metric  Clé de métrique (doit être dans wt_ach_metrics)
     * @param  array  $user    Données user en cache (level, daily_streak,
     *                         created_at) pour éviter une requête.
     * @return float
     */
    function wt_get_user_progress(int $userId, string $metric, array $user = []): float
    {
        // Liste blanche : métrique inconnue → 0, aucune requête tentée.
        if (!wt_ach_metric_valid($metric)) {
            return 0.0;
        }

        // --- Métriques sur colonnes users : aucune requête de comptage ---
        switch ($metric) {
            case 'daily_streak':
                return (float) ($user['daily_streak'] ?? 0);
            case 'level':
                return (float) ($user['level'] ?? 1);
            case 'account_age_days':
                $created = $user['created_at'] ?? null;
                if (!$created) return 0.0;
                $days = (time() - strtotime($created . ' UTC')) / 86400;
                return (float) max(0, floor($days));
        }

        // --- Métriques nécessitant une agrégation sur table d'activité ---
        // Chaque requête est paramétrée (user_id en bind) et s'appuie sur
        // un index commençant par user_id.
        $sql = null;
        switch ($metric) {
            case 'faucet_claims':
                $sql = "SELECT COUNT(*) v FROM faucet_claims WHERE user_id = ?";
                break;
            case 'shortlinks_done':
                $sql = "SELECT COUNT(*) v FROM shortlink_attempts
                         WHERE user_id = ? AND status = 'valide'";
                break;
            case 'ptc_views':
                $sql = "SELECT COUNT(*) v FROM ptc_views WHERE user_id = ?";
                break;
            case 'offerwalls_done':
                $sql = "SELECT COUNT(*) v FROM offerwall_transactions
                         WHERE user_id = ? AND status = 'credited'";
                break;
            case 'referrals':
                $sql = "SELECT COUNT(DISTINCT referee_id) v FROM referral_earnings
                         WHERE referrer_id = ?";
                break;
            case 'withdrawals_done':
                $sql = "SELECT COUNT(*) v FROM withdrawals
                         WHERE user_id = ? AND status = 'completed'";
                break;
            case 'daily_claims':
                $sql = "SELECT COUNT(*) v FROM daily_bonus_claims WHERE user_id = ?";
                break;
            case 'total_coins_earned':
                // Utilise l'index couvrant idx_user_coins (user_id, coins).
                $sql = "SELECT COALESCE(SUM(coins),0) v FROM transactions
                         WHERE user_id = ? AND coins > 0";
                break;
        }

        if ($sql === null) {
            return 0.0;
        }

        try {
            $stmt = db()->prepare($sql);
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return (float) ($row['v'] ?? 0);
        } catch (Throwable $e) {
            error_log('[Wintaskly ach] progress ' . $metric . ': ' . $e->getMessage());
            return 0.0;
        }
    }
}

if (!function_exists('wt_ach_metric_value')) {
    /**
     * Alias rétro-compatible de wt_get_user_progress().
     * Conserve l'ancienne signature (metric, userId, user) utilisée
     * ailleurs dans le moteur.
     */
    function wt_ach_metric_value(string $metric, int $userId, array $user = []): float
    {
        return wt_get_user_progress($userId, $metric, $user);
    }
}

if (!function_exists('wt_ach_check')) {
    /**
     * Vérifie et débloque les achievements nouvellement atteints pour un
     * utilisateur. Crédite les récompenses. Idempotent (ne débloque jamais
     * deux fois grâce à la contrainte UNIQUE user+achievement).
     *
     * SÉCURITÉ — double protection contre la récursion et la concurrence :
     *
     *   1. RÉCURSION (intra-requête) : cette fonction est appelée par
     *      award_user() APRÈS son commit. Quand un badge se débloque, il
     *      crédite via award_user(..., 'achievement'). award_user contient
     *      un garde `if ($type !== 'achievement')` qui empêche ce crédit de
     *      relancer wt_ach_check → pas de boucle infinie. (Un drapeau static
     *      ne servirait à rien de plus : il ne protège QUE la récursion,
     *      pas la concurrence, et le garde de type le fait déjà.)
     *
     *   2. CONCURRENCE (inter-requêtes) : deux requêtes AJAX parallèles qui
     *      atteignent le même seuil tentent toutes deux l'INSERT. La
     *      contrainte UNIQUE (user_id, achievement_id) + INSERT IGNORE
     *      sérialise l'opération au niveau BDD : une seule voit
     *      affected_rows > 0 et crédite. L'autre obtient 0 et s'arrête.
     *      C'est atomique et fiable, contrairement à un verrou applicatif.
     *
     * Optimisé : ne calcule que les métriques des achievements pas encore
     * débloqués, et groupe les achievements par métrique pour ne calculer
     * chaque métrique qu'une seule fois.
     *
     * @param  int   $userId
     * @param  array $user  Données user (optionnel, sinon rechargé)
     * @return array        Liste des achievements fraîchement débloqués
     *                      [['title'=>..,'icon'=>..,'coins'=>..,'xp'=>..], ...]
     */
    function wt_ach_check(int $userId, array $user = []): array
    {
        if (!wt_ach_enabled() || $userId <= 0) {
            return [];
        }

        $all = wt_ach_all();
        if (empty($all)) {
            return [];
        }

        $unlockedIds = wt_ach_unlocked_ids($userId);
        $unlockedSet = array_flip($unlockedIds);

        // Liste des achievements restant à débloquer
        $pending = array_filter($all, fn($a) => !isset($unlockedSet[(int)$a['id']]));
        if (empty($pending)) {
            return []; // Tout est déjà débloqué, aucune requête de calcul
        }

        // Recharge user si nécessaire (pour les métriques sur colonnes users)
        if (empty($user)) {
            try {
                $stmt = db()->prepare("SELECT id, level, daily_streak, created_at FROM users WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc() ?: [];
                $stmt->close();
            } catch (Throwable $e) {
                $user = [];
            }
        }

        // Calcule chaque métrique nécessaire UNE SEULE FOIS
        $metricsNeeded = array_unique(array_map(fn($a) => $a['metric'], $pending));
        $metricValues = [];
        foreach ($metricsNeeded as $m) {
            $metricValues[$m] = wt_ach_metric_value($m, $userId, $user);
        }

        // Détermine les achievements atteints
        $newlyUnlocked = [];
        foreach ($pending as $a) {
            $val = $metricValues[$a['metric']] ?? 0;
            if ($val >= (float) $a['threshold']) {
                // Débloque (INSERT IGNORE pour éviter les races)
                $achId = (int) $a['id'];
                try {
                    $stmt = db()->prepare(
                        "INSERT IGNORE INTO user_achievements (user_id, achievement_id, claimed)
                         VALUES (?, ?, 1)"
                    );
                    $stmt->bind_param('ii', $userId, $achId);
                    $stmt->execute();
                    $inserted = $stmt->affected_rows > 0;
                    $stmt->close();
                } catch (Throwable $e) {
                    error_log('[Wintaskly ach] unlock failed: ' . $e->getMessage());
                    continue;
                }

                if (!$inserted) {
                    continue; // Déjà débloqué par une requête concurrente
                }

                // Crédite la récompense
                $coins = (float) $a['reward_coins'];
                $xp    = (int) $a['reward_xp'];
                if ($coins > 0 || $xp > 0) {
                    award_user($userId, $coins, $xp, 'achievement', 'ach:' . $a['k']);
                }

                $newlyUnlocked[] = [
                    'k'     => $a['k'],
                    'title' => $a['title'],
                    'icon'  => $a['icon'],
                    'tier'  => $a['tier'],
                    'coins' => $coins,
                    'xp'    => $xp,
                ];
            }
        }

        return $newlyUnlocked;
    }
}

if (!function_exists('wt_ach_user_list')) {
    /**
     * Retourne la liste complète des achievements avec, pour chacun, l'état
     * de progression de l'utilisateur (débloqué ou non + valeur courante).
     *
     * Utilisé pour la page /achievements et la section dashboard.
     * Calcule toutes les métriques (groupées) pour afficher les barres
     * de progression.
     *
     * @return array  [['ach'=>..., 'unlocked'=>bool, 'unlocked_at'=>..,
     *                  'current'=>float, 'threshold'=>float, 'percent'=>int], ...]
     */
    function wt_ach_user_list(int $userId, array $user = []): array
    {
        $all = wt_ach_all();
        if (empty($all)) {
            return [];
        }

        // Recharge user si besoin
        if (empty($user)) {
            try {
                $stmt = db()->prepare("SELECT id, level, daily_streak, created_at FROM users WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc() ?: [];
                $stmt->close();
            } catch (Throwable $e) {
                $user = [];
            }
        }

        // Dates de déblocage
        $unlockedMap = [];
        try {
            $stmt = db()->prepare("SELECT achievement_id, unlocked_at FROM user_achievements WHERE user_id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $unlockedMap[(int)$r['achievement_id']] = $r['unlocked_at'];
            }
            $stmt->close();
        } catch (Throwable $e) {}

        // Calcule chaque métrique une seule fois
        $metricsNeeded = array_unique(array_map(fn($a) => $a['metric'], $all));
        $metricValues = [];
        foreach ($metricsNeeded as $m) {
            $metricValues[$m] = wt_ach_metric_value($m, $userId, $user);
        }

        $list = [];
        foreach ($all as $a) {
            $id        = (int) $a['id'];
            $isUnlocked = isset($unlockedMap[$id]);
            $current   = $metricValues[$a['metric']] ?? 0;
            $threshold = (float) $a['threshold'];
            $percent   = $threshold > 0 ? min(100, (int) floor(($current / $threshold) * 100)) : 0;
            if ($isUnlocked) $percent = 100;

            $list[] = [
                'ach'         => $a,
                'unlocked'    => $isUnlocked,
                'unlocked_at' => $unlockedMap[$id] ?? null,
                'current'     => $current,
                'threshold'   => $threshold,
                'percent'     => $percent,
            ];
        }

        return $list;
    }
}

if (!function_exists('wt_ach_summary')) {
    /**
     * Résumé rapide pour le dashboard : nb débloqués / total + les 3
     * plus proches d'être débloqués (pour donner un objectif).
     *
     * @return array ['unlocked'=>int, 'total'=>int, 'recent'=>array, 'next'=>array]
     */
    function wt_ach_summary(int $userId, array $user = []): array
    {
        $list = wt_ach_user_list($userId, $user);
        $total = count($list);
        $unlocked = count(array_filter($list, fn($x) => $x['unlocked']));

        // Les 3 prochains à débloquer (pas encore unlocked, triés par % décroissant)
        $pending = array_filter($list, fn($x) => !$x['unlocked']);
        usort($pending, fn($a, $b) => $b['percent'] <=> $a['percent']);
        $next = array_slice(array_values($pending), 0, 3);

        // Les badges récemment débloqués
        $unlockedItems = array_filter($list, fn($x) => $x['unlocked']);
        usort($unlockedItems, fn($a, $b) => strcmp((string)$b['unlocked_at'], (string)$a['unlocked_at']));
        $recent = array_slice(array_values($unlockedItems), 0, 4);

        return [
            'enabled'  => wt_ach_enabled() && $total > 0,
            'unlocked' => $unlocked,
            'total'    => $total,
            'recent'   => $recent,
            'next'     => $next,
        ];
    }
}
