<?php
/**
 * Cron task — Update check
 *
 * Toutes les 6 heures (21600s), va chercher le latest.json distant pour
 * détecter si une nouvelle version de Wintaskly est disponible.
 *
 * Le résultat est persisté en BDD (table config + table update_checks).
 * La page /admin/updates.php affiche ensuite l'état "à jour" ou
 * "update X.Y.Z disponible" sans requête réseau supplémentaire.
 *
 * Tolérance aux pannes : si GitHub est down, le check log l'erreur et
 * le site continue de fonctionner normalement. Le prochain cron réessaiera.
 */
declare(strict_types=1);

wt_cron_register('update_check', function (): array {
    $result = wt_update_check_now();

    $summary = sprintf(
        'check=%s current=%s latest=%s update=%s',
        $result['status'],
        $result['current'],
        $result['latest'] ?? '-',
        $result['has_update'] ? 'yes' : 'no'
    );

    if ($result['status'] !== 'ok') {
        // On considère pas ça comme une vraie erreur du cron : un check
        // qui échoue n'empêche pas le site de fonctionner. On log juste
        // l'incident en summary.
        return ['summary' => $summary . ' err=' . ($result['error'] ?? '?')];
    }

    return ['summary' => $summary];
}, 21600);  // 6h
