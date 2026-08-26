<?php
/**
 * Wintaskly — cron/tasks/campaign_rewards.php
 * ---------------------------------------------------------------------------
 * Versement des primes de bienvenue issues des campagnes d'acquisition.
 *
 * POURQUOI UNE TÂCHE PLANIFIÉE PLUTÔT QU'UN VERSEMENT IMMÉDIAT
 * -----------------------------------------------------------
 * La prime dépend d'une activité étalée sur plusieurs jours. Il n'existe donc
 * aucun instant précis où l'on pourrait dire « c'est gagné » au fil de l'eau
 * sans recalculer la condition à chaque tâche accomplie par chaque membre —
 * coûteux et inutile. Un passage périodique suffit largement : la prime
 * arrive au plus tard le lendemain de la qualification.
 *
 * TROIS CONDITIONS CUMULATIVES
 * ----------------------------
 *   1. Le compte est rattaché à une campagne (converted_user_id).
 *   2. L'adresse e-mail est vérifiée — sinon un compte jetable suffirait.
 *   3. L'activité minimale est atteinte : plusieurs journées distinctes avec
 *      au moins une tâche rémunérée.
 *
 * La colonne rewarded_at garantit l'unicité : une prime versée ne peut pas
 * l'être une seconde fois, même si la tâche est relancée.
 */
declare(strict_types=1);

wt_cron_register('campaign_rewards', static function (): array {

    $db      = db();
    $paid    = 0;
    $checked = 0;
    $skipped = 0;

    try {
        /* Candidats : rattachés, non encore récompensés, e-mail vérifié.
           On ne remonte que les comptes créés dans une fenêtre raisonnable —
           au-delà, la condition d'activité ne peut plus être remplie, et
           les réexaminer à chaque passage serait du travail perdu. */
        $window = max(1, (int) cfg('campaign.active_days', 10));
        $res = $db->query(
            "SELECT v.id AS visit_id, v.campaign_id, v.converted_user_id AS uid,
                    c.reward_coins, c.name AS campaign_name
               FROM campaign_visits v
               JOIN campaigns c ON c.id = v.campaign_id
               JOIN users u     ON u.id = v.converted_user_id
              WHERE v.converted_user_id IS NOT NULL
                AND v.rewarded_at IS NULL
                AND u.email_verified_at IS NOT NULL
                AND u.status = 'active'
                AND c.reward_coins > 0
                AND u.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . ($window + 5) . " DAY)
              LIMIT 200"
        );
    } catch (Throwable $e) {
        error_log('[Wintaskly campaign] ' . $e->getMessage()
                  . ' — appliquez sql/migration_campaigns.sql');
        return ['summary' => 'table missing'];
    }

    while ($res && ($row = $res->fetch_assoc())) {
        $checked++;
        $uid = (int) $row['uid'];

        if (!wt_campaign_user_qualifies($uid)) {
            $skipped++;
            continue;   // pas encore assez actif : réexaminé au prochain passage
        }

        $coins = (float) $row['reward_coins'];

        try {
            /* On marque AVANT de créditer, et seulement si la ligne est
               encore vierge. Si deux exécutions se chevauchaient, la seconde
               ne modifierait aucune ligne et s'arrêterait ici — la prime ne
               peut donc pas être versée deux fois. */
            $stmt = $db->prepare(
                "UPDATE campaign_visits
                    SET rewarded_at = UTC_TIMESTAMP(), reward_coins = ?
                  WHERE id = ? AND rewarded_at IS NULL"
            );
            $vid = (int) $row['visit_id'];
            $stmt->bind_param('di', $coins, $vid);
            $stmt->execute();
            $claimed = $stmt->affected_rows > 0;
            $stmt->close();

            if (!$claimed) { continue; }

            award_user($uid, $coins, 0, 'bonus',
                       'campaign:' . (string) $row['campaign_name']);
            $paid++;

            if (function_exists('wt_notify')) {
                wt_notify($uid, 'system',
                    (string) t('campaign.notif_title'),
                    sprintf((string) t('campaign.notif_body'),
                            rtrim(rtrim(number_format($coins, 2, ',', ' '), '0'), ',')));
            }
        } catch (Throwable $e) {
            error_log('[Wintaskly campaign] versement uid=' . $uid . ' : ' . $e->getMessage());
        }
    }

    /* ------------------------------------------------------------------
     * Purge RGPD
     * ------------------------------------------------------------------
     * Conserver indéfiniment des données de visite n'est pas défendable.
     * Les pages vues partent en premier : c'est le plus volumineux et le
     * moins utile passé quelques mois. Les visites CONVERTIES sont
     * conservées plus longtemps, car elles portent la trace du versement —
     * mais elles disparaissent avec le compte (ON DELETE CASCADE). */
    $keep   = max(30, (int) cfg('campaign.retention_days', 400));
    $purged = 0;
    try {
        $db->query("DELETE FROM campaign_pageviews
                     WHERE viewed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL $keep DAY)
                     LIMIT 5000");
        $purged += $db->affected_rows;

        $db->query("DELETE FROM campaign_visits
                     WHERE converted_user_id IS NULL
                       AND last_seen_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL $keep DAY)
                     LIMIT 5000");
        $purged += $db->affected_rows;
    } catch (Throwable $e) {
        error_log('[Wintaskly campaign] purge : ' . $e->getMessage());
    }

    return [
        'summary' => sprintf('checked=%d paid=%d pending=%d purged=%d',
                             $checked, $paid, $skipped, $purged),
    ];
});
