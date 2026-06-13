<?php
/**
 * Wintaskly — cron/tasks/leaderboard_archive.php
 *
 * Tâche : archive le classement du mois précédent et distribue les
 * récompenses aux Top N selon la cagnotte configurée.
 *
 * Fréquence min : 1 fois par heure. Le helper `wt_lb_maybe_archive_previous`
 * gère lui-même l'idempotence (skip si déjà archivé pour ce mois).
 */
declare(strict_types=1);

wt_cron_register('leaderboard_archive', static function (): string {
    // Le helper renvoie void → on capture l'état avant/après via cfg
    $before = (string) cfg('leaderboard.last_archived_period', '');
    wt_lb_maybe_archive_previous();
    $after  = (string) cfg('leaderboard.last_archived_period', '');

    if ($before === $after) {
        return 'Aucun nouveau mois à archiver (dernier = ' . $after . ')';
    }
    return 'Mois ' . $after . ' archivé et récompenses distribuées';
}, /* every */ 3600);  // toutes les heures, mais idempotent intra-mois
