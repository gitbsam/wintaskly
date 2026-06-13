<?php
/**
 * Wintaskly — cron/run.php
 *
 * Entrypoint CLI pour le cron système.
 *
 * Usage :
 *   php cron/run.php                # exécute les tâches dues
 *   php cron/run.php --force        # force toutes les tâches
 *   php cron/run.php --list         # liste les tâches enregistrées
 *
 * À configurer dans crontab :
 *   "x slash 5" * * * *  /usr/bin/php /var/www/wintaskly/cron/run.php >> /var/log/wintaskly-cron.log 2>&1
 *   (remplacer "x slash 5" par l'expression cron habituelle pour
 *   "toutes les 5 minutes" — on n'écrit pas le caractère ici car la
 *   séquence ferme un commentaire PHP "C-style")
 *
 * Note : ce fichier n'est PAS exposé via le web (vérification basique
 * du SAPI). Pour le web, voir /api/cron.php.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden — utilisez /api/cron.php pour les déclenchements web.\n";
    exit(1);
}

require __DIR__ . '/../includes/init.php';
require __DIR__ . '/../includes/cron.php';

wt_cron_load_tasks();

// Args CLI simples
$args  = $argv;
$force = in_array('--force', $args, true);

if (in_array('--list', $args, true)) {
    echo "Tâches enregistrées :\n";
    foreach ($GLOBALS['wt_cron_tasks'] as $key => $task) {
        $last = wt_cron_last_success($key);
        $ago  = $last ? date('Y-m-d H:i:s', $last) : 'jamais';
        echo sprintf("  - %-30s every=%ds  last_success=%s\n", $key, $task['every'], $ago);
    }
    exit(0);
}

echo "[cron] Démarrage " . date('Y-m-d H:i:s') . " (force=" . ($force ? 'oui' : 'non') . ")\n";
$report = wt_cron_run(force: $force);

foreach ($report as $key => $r) {
    $line = sprintf("  [%-7s] %s", $r['status'], $key);
    if ($r['summary']) $line .= ' — ' . $r['summary'];
    if ($r['error'])   $line .= ' — ERR: ' . $r['error'];
    echo $line . "\n";
}
echo "[cron] Terminé.\n";
