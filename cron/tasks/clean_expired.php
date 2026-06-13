<?php
/**
 * Wintaskly — cron/tasks/clean_expired.php
 *
 * Tâche de ménage : supprime les éléments expirés des tables :
 *   - auth_tokens (verify_email / reset_password expirés)
 *   - messages (expires_at < now)
 *   - notifications (expires_at < now)
 *   - cron_runs (logs > 30 jours pour ne pas saturer la table)
 *
 * Fréquence min : 6 heures (purge non urgente).
 */
declare(strict_types=1);

wt_cron_register('clean_expired', static function (): string {
    $db = db();
    $counts = [];

    // Auth tokens expirés (sauf remember_me qu'on garde le temps de leur vie)
    $res = $db->query(
        "DELETE FROM auth_tokens
          WHERE expires_at < UTC_TIMESTAMP()
            AND purpose IN ('verify_email','reset_password')"
    );
    $counts['auth_tokens'] = $db->affected_rows;

    // Messages expirés
    $res = $db->query("DELETE FROM messages WHERE expires_at IS NOT NULL AND expires_at < UTC_TIMESTAMP()");
    $counts['messages'] = $db->affected_rows;

    // Notifications expirées
    $res = $db->query("DELETE FROM notifications WHERE expires_at IS NOT NULL AND expires_at < UTC_TIMESTAMP()");
    $counts['notifications'] = $db->affected_rows;

    // Logs cron > 30 jours (garde un historique léger)
    $res = $db->query("DELETE FROM cron_runs WHERE started_at < UTC_TIMESTAMP() - INTERVAL " . WT_PERIOD_CRON_CLEAN_DAYS . " DAY");
    $counts['cron_runs'] = $db->affected_rows;

    return sprintf(
        'Purge: tokens=%d, messages=%d, notifs=%d, cron_runs=%d',
        $counts['auth_tokens'],
        $counts['messages'],
        $counts['notifications'],
        $counts['cron_runs']
    );
}, /* every */ 21600);  // 6 h
