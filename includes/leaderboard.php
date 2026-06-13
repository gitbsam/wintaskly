<?php
/**
 * Wintaskly — Helpers V5 · Leaderboard mensuel.
 *
 * Source canonique des gains : table `transactions`. On agrège les
 * lignes de type {faucet, shortlink, ptc, offerwall, referral, bonus}
 * (les `withdraw` sont négatifs ou ne représentent pas un "gain" gagné
 * pendant la période). Le calcul est strictement borné par UTC_TIMESTAMP
 * et le 1er du mois courant à 00:00 UTC.
 *
 * Le cache (table leaderboard_cache) est régénéré à la première requête
 * survenant après `cache_minutes` (15 par défaut) depuis la dernière
 * actualisation, OU si le mois courant a changé depuis la dernière entrée.
 *
 * À la première requête survenant après un changement de mois, on
 * archive automatiquement le Top 10 du mois précédent et on attribue
 * les récompenses configurées (paramètre `leaderboard.rewards_enabled`).
 *
 * Inclus automatiquement par init.php.
 */
declare(strict_types=1);

// =====================================================================
// 1) Période & dates de mois
// =====================================================================

/**
 * Période courante au format 'AAAA-MM' (UTC).
 */
function wt_lb_period(?int $ts = null): string
{
    return gmdate('Y-m', $ts ?? time());
}

/**
 * Mois précédent au format 'AAAA-MM' (UTC).
 */
function wt_lb_prev_period(): string
{
    $first = strtotime(gmdate('Y-m-01') . ' 00:00:00 UTC');
    return gmdate('Y-m', $first - 86400);
}

/**
 * Plage UTC d'un mois au format 'AAAA-MM' → ['YYYY-MM-01 00:00:00', début du mois suivant].
 */
function wt_lb_period_bounds(string $ym): array
{
    $start = $ym . '-01 00:00:00';
    $next  = strtotime($start . ' UTC + 1 month');
    return [$start, gmdate('Y-m-d H:i:s', $next)];
}

// =====================================================================
// 2) Calcul du Top 10 (requête agrégée optimisée)
// =====================================================================

/**
 * Calcule (sans cache) le Top 10 du mois donné.
 * Retourne un tableau [['user_id','username','avatar_url','level','coins_month'], ...].
 *
 * Règles métier :
 *   - On compte les gains "positifs" des types : faucet, shortlink, ptc,
 *     offerwall, referral. On EXCLUT volontairement les bonus de
 *     classement (type='bonus' avec meta LIKE 'leaderboard:%') pour
 *     éviter qu'un gagnant du mois précédent parte avec un avantage
 *     dans le classement courant (boucle auto-renforçante).
 *   - On garde les autres 'bonus' (welcome, événementiels) car ils sont
 *     ponctuels et légitimes pour le rang.
 *   - Tie-break : à coins égaux, le premier qui a atteint le score
 *     (= dont la DERNIÈRE transaction comptant pour le mois est la plus
 *     ancienne) prend le meilleur rang. C'est l'effort le plus précoce
 *     qui prime, pas l'ordre d'inscription.
 *
 * Index utilisés : transactions(created_at, type) + (user_id, created_at).
 */
function wt_lb_compute_top(string $ym, int $limit = 10): array
{
    [$start, $end] = wt_lb_period_bounds($ym);

    // Les gains "positifs" qui comptent pour le mois.
    // last_qualifying_at = MAX(created_at) → moment où l'utilisateur
    // a fini de "construire" son score. On le prend ASC : le plus ancien
    // a fini en premier → meilleur rang à coins égaux.
    $sql = "SELECT t.user_id,
                   u.username, u.avatar_url, u.level,
                   SUM(t.coins) AS coins_month,
                   MAX(t.created_at) AS last_qualifying_at
              FROM transactions t
              JOIN users u ON u.id = t.user_id
             WHERE t.created_at >= ?
               AND t.created_at <  ?
               AND t.coins > 0
               AND u.status = 'active'
               AND (
                    t.type IN ('faucet','shortlink','ptc','offerwall','referral')
                 OR (t.type = 'bonus' AND (t.meta IS NULL OR t.meta NOT LIKE 'leaderboard:%'))
               )
             GROUP BY t.user_id
             ORDER BY coins_month DESC, last_qualifying_at ASC, t.user_id ASC
             LIMIT " . max(1, (int)$limit);

    $stmt = db()->prepare($sql);
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = [
            'user_id'            => (int)   $r['user_id'],
            'username'           => (string)$r['username'],
            'avatar_url'         => $r['avatar_url'] ?? null,
            'level'              => (int)   $r['level'],
            'coins_month'        => (float) $r['coins_month'],
            'last_qualifying_at' => $r['last_qualifying_at'] ?? null,
        ];
    }
    $stmt->close();
    return $rows;
}

/**
 * Indique si le cache doit être régénéré.
 */
function wt_lb_cache_stale(string $ym): bool
{
    $stmt = db()->prepare(
        "SELECT MAX(refreshed_at) AS last_at
           FROM leaderboard_cache
          WHERE period_ym = ?"
    );
    $stmt->bind_param('s', $ym);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !$row['last_at']) return true;
    $minutes = (int) cfg('leaderboard.cache_minutes', '15');
    $minutes = max(1, $minutes);
    return strtotime($row['last_at'] . ' UTC') < (time() - $minutes * 60);
}

/**
 * Régénère le cache du Top 10 pour un mois donné.
 * On supprime toutes les lignes du mois pour ne jamais garder un rang
 * orphelin (cas où un utilisateur sort du Top 10 entre deux passes).
 */
function wt_lb_refresh_cache(string $ym): void
{
    $top = wt_lb_compute_top($ym, 10);
    $db  = db();
    $db->begin_transaction();
    try {
        $stmt = $db->prepare("DELETE FROM leaderboard_cache WHERE period_ym = ?");
        $stmt->bind_param('s', $ym);
        $stmt->execute();
        $stmt->close();

        $ins = $db->prepare(
            "INSERT INTO leaderboard_cache
                (period_ym, `rank`, user_id, coins_month)
             VALUES (?, ?, ?, ?)"
        );
        foreach ($top as $i => $row) {
            $rank = $i + 1;
            $ins->bind_param('siid',
                $ym, $rank, $row['user_id'], $row['coins_month']);
            $ins->execute();
        }
        $ins->close();
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        error_log('lb_refresh: ' . $e->getMessage());
    }
}

/**
 * Renvoie le Top 10 du mois courant, en utilisant le cache. Régénère
 * automatiquement si périmé. Inclut user infos (username, avatar, level).
 */
function wt_lb_get_top(?string $ym = null): array
{
    $ym = $ym ?? wt_lb_period();

    // Déclenche l'archivage du mois précédent si pas encore fait.
    wt_lb_maybe_archive_previous();

    if (wt_lb_cache_stale($ym)) {
        wt_lb_refresh_cache($ym);
    }

    $stmt = db()->prepare(
        "SELECT c.`rank`, c.user_id, c.coins_month,
                u.username, u.avatar_url, u.level
           FROM leaderboard_cache c
           JOIN users u ON u.id = c.user_id
          WHERE c.period_ym = ?
          ORDER BY c.`rank` ASC"
    );
    $stmt->bind_param('s', $ym);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = [
            'rank'        => (int)   $r['rank'],
            'user_id'     => (int)   $r['user_id'],
            'username'    => (string)$r['username'],
            'avatar_url'  => $r['avatar_url'],
            'level'       => (int)   $r['level'],
            'coins_month' => (float) $r['coins_month'],
        ];
    }
    $stmt->close();
    return $rows;
}

// =====================================================================
// 3) Rang et gain mensuel d'un utilisateur précis
// =====================================================================

/**
 * Renvoie le rang exact et le gain mensuel d'un utilisateur sur la
 * période courante. Renvoie ['rank'=>null,'coins_month'=>0] s'il
 * n'a rien gagné ce mois-ci.
 *
 * Approche : on compte combien d'utilisateurs ont gagné PLUS que lui ;
 * son rang = (count + 1).
 */
function wt_lb_user_rank(int $userId, ?string $ym = null): array
{
    $ym = $ym ?? wt_lb_period();
    [$start, $end] = wt_lb_period_bounds($ym);

    $db = db();

    // Gain de l'utilisateur
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(coins), 0) AS s
           FROM transactions
          WHERE user_id = ?
            AND created_at >= ? AND created_at < ?
            AND type IN ('faucet','shortlink','ptc','offerwall','referral','bonus')
            AND coins > 0"
    );
    $stmt->bind_param('iss', $userId, $start, $end);
    $stmt->execute();
    $myCoins = (float)($stmt->get_result()->fetch_assoc()['s'] ?? 0);
    $stmt->close();

    if ($myCoins <= 0) {
        return ['rank' => null, 'coins_month' => 0.0];
    }

    // Combien d'utilisateurs ont gagné strictement plus que moi ?
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS c FROM (
             SELECT t.user_id, SUM(t.coins) AS s
               FROM transactions t
               JOIN users u ON u.id = t.user_id
              WHERE t.created_at >= ? AND t.created_at < ?
                AND t.type IN ('faucet','shortlink','ptc','offerwall','referral','bonus')
                AND t.coins > 0
                AND u.status = 'active'
                AND t.user_id <> ?
              GROUP BY t.user_id
             HAVING s > ?
         ) AS x"
    );
    $stmt->bind_param('ssid', $start, $end, $userId, $myCoins);
    $stmt->execute();
    $ahead = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    return [
        'rank'        => $ahead + 1,
        'coins_month' => $myCoins,
    ];
}

/**
 * Calcule combien il manque à un utilisateur pour intégrer le Top 10
 * (différence avec le 10ᵉ + 0.0001 pour le dépasser strictement).
 * Retourne 0 si déjà dans le Top 10 ou si moins de 10 participants
 * actifs ce mois.
 */
function wt_lb_gap_to_top(int $userId): float
{
    $top = wt_lb_get_top();
    if (count($top) < 10) {
        // Pas encore 10 participants → l'utilisateur peut entrer en gagnant 1 Coin.
        return 0.0;
    }
    foreach ($top as $row) {
        if ($row['user_id'] === $userId) return 0.0;
    }
    $tenth = (float) $top[count($top) - 1]['coins_month'];
    $info  = wt_lb_user_rank($userId);
    $mine  = (float) ($info['coins_month'] ?? 0);
    return max(0.0, round($tenth - $mine + 0.0001, 4));
}

// =====================================================================
// 4) Archivage mensuel + récompenses automatiques
// =====================================================================

/**
 * Si le mois précédent n'a pas encore été archivé (cf. config
 * `leaderboard.last_archived_period`), on archive son Top 10 dans
 * leaderboard_history et on attribue les bonus configurés via
 * award_user() ('bonus' type → comptabilisé proprement).
 *
 * Idempotent : si la période est déjà marquée comme archivée, no-op.
 */
/**
 * V8 — Calcule la part en Coins d'un rang donné selon le mode actif.
 *
 *   - Si leaderboard.use_prize_pool = 1 : on lit la cagnotte totale
 *     (leaderboard.prize_pool) et la répartition en pourcentages
 *     (leaderboard.prize_pool_split = "40,20,12,…"), puis on multiplie.
 *   - Sinon (mode legacy) : on lit la clé fixe leaderboard.reward_coins_N
 *     (compatibilité ascendante avec les déploiements antérieurs).
 *
 * @param  int $rank  Rang 1-based (1 = premier).
 * @return float Récompense en Coins (peut être 0 si pas configurée).
 */
function wt_lb_reward_for_rank(int $rank): float
{
    if ($rank < 1) return 0.0;

    $usePool = (string) cfg('leaderboard.use_prize_pool', '0') === '1';

    if ($usePool) {
        $pool = (float) cfg('leaderboard.prize_pool', '0');
        if ($pool <= 0) return 0.0;

        $splitRaw = (string) cfg('leaderboard.prize_pool_split', '');
        $parts    = array_map('trim', explode(',', $splitRaw));
        $parts    = array_filter($parts, static fn ($p) => $p !== '');
        if (!isset($parts[$rank - 1])) return 0.0;

        $pct = (float) $parts[$rank - 1];
        if ($pct <= 0) return 0.0;

        return round(($pool * $pct) / 100.0, 4);
    }

    // Mode legacy
    return (float) cfg('leaderboard.reward_coins_' . $rank, '0');
}

/**
 * V8 — Retourne la liste complète des récompenses [rank => coins]
 * pour les 10 premiers rangs. Utile pour l'affichage UI.
 *
 * @return array<int, float> Format [1 => 2000.00, 2 => 1000.00, …]
 */
function wt_lb_rewards_grid(): array
{
    $grid = [];
    for ($r = 1; $r <= 10; $r++) {
        $grid[$r] = wt_lb_reward_for_rank($r);
    }
    return $grid;
}

/**
 * V8 — Date/heure UTC du début du mois prochain. Utilisée pour le
 * countdown "fin du mois" sur la page leaderboard et par le cron.
 */
function wt_lb_next_period_start(): DateTimeImmutable
{
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    return $now->modify('first day of next month')->setTime(0, 0, 0);
}

function wt_lb_maybe_archive_previous(): void
{
    $prev = wt_lb_prev_period();
    $last = (string) cfg('leaderboard.last_archived_period', '');
    if ($last === $prev) return;

    // Si l'app vient d'être déployée et qu'on est encore dans le 1er mois,
    // il n'y a peut-être aucune transaction sur le mois précédent. On
    // marque quand même comme "archivé" pour ne pas réessayer en boucle.
    $top = wt_lb_compute_top($prev, 10);

    $db = db();
    $db->begin_transaction();
    try {
        // Vide les éventuelles entrées partielles (replay sûr)
        $stmt = $db->prepare("DELETE FROM leaderboard_history WHERE period_ym = ?");
        $stmt->bind_param('s', $prev);
        $stmt->execute();
        $stmt->close();

        $rewardsEnabled = (string) cfg('leaderboard.rewards_enabled', '1') === '1';

        /* Optimisation N+1 → 1 :
         * On précharge en une seule query l'ensemble des transactions
         * 'bonus' déjà créées pour cette période, afin d'éviter une
         * query par utilisateur lors du check d'idempotence.
         */
        $alreadyRewarded = [];
        if ($rewardsEnabled && !empty($top)) {
            $like = 'leaderboard:' . $prev . ':rank%';
            $chk = $db->prepare(
                "SELECT user_id, meta FROM transactions
                  WHERE type = 'bonus' AND meta LIKE ?"
            );
            $chk->bind_param('s', $like);
            $chk->execute();
            $res = $chk->get_result();
            while ($r = $res->fetch_assoc()) {
                $alreadyRewarded[$r['meta']] = true;
            }
            $chk->close();
        }

        $ins = $db->prepare(
            "INSERT INTO leaderboard_history
                (period_ym, `rank`, user_id, username,
                 coins_month, reward_coins, reward_xp)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($top as $i => $row) {
            $rank   = $i + 1;
            // V8 : passe par le helper unique qui gère cagnotte OU mode legacy
            $coins  = $rewardsEnabled ? wt_lb_reward_for_rank($rank) : 0.0;
            $xp     = $rewardsEnabled ? (int) cfg('leaderboard.reward_xp_' . $rank, '0') : 0;

            $ins->bind_param(
                'siisddi',
                $prev,
                $rank,
                $row['user_id'],
                $row['username'],
                $row['coins_month'],
                $coins,
                $xp
            );
            $ins->execute();

            // Attribution réelle des récompenses (coins + xp + notif)
            // Idempotence forte : on vérifie via le cache préchargé qu'aucune
            // transaction 'bonus' avec ce `meta` exact n'existe déjà. Protège
            // contre les replays admin ("Forcer archivage") qui ne doivent
            // pas re-créditer les bonus.
            if ($rewardsEnabled && ($coins > 0 || $xp > 0)) {
                $meta = 'leaderboard:' . $prev . ':rank' . $rank;
                $already = isset($alreadyRewarded[$meta]);

                if (!$already) {
                    try {
                        award_user($row['user_id'], $coins, $xp, 'bonus', $meta);
                        wt_notify(
                            $row['user_id'],
                            'leaderboard_reward',
                            sprintf('🏆 Bravo ! Top %d du mois %s', $rank, $prev),
                            sprintf('Tu reçois %s Coins et %d XP.', number_format($coins, 2, ',', ' '), $xp),
                            wt_url('/leaderboard/?period=' . $prev)
                        );
                    } catch (Throwable $e) {
                        error_log('lb_reward: ' . $e->getMessage());
                    }
                }
            }
        }
        $ins->close();

        // Marqueur idempotence
        $stmt = $db->prepare(
            "INSERT INTO config (k, v) VALUES ('leaderboard.last_archived_period', ?)
             ON DUPLICATE KEY UPDATE v = VALUES(v)"
        );
        $stmt->bind_param('s', $prev);
        $stmt->execute();
        $stmt->close();

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        error_log('lb_archive: ' . $e->getMessage());
    }
}
