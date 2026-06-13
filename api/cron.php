<?php
/**
 * Wintaskly — /api/cron.php
 *
 * Endpoint de déclenchement du cron via URL.
 *
 * Usage :
 *   curl https://wintaskly.example/api/cron.php?token=XXXX
 *
 * Le token doit correspondre à cfg('cron.token'). À configurer dans
 * /admin/cron.php (génération automatique au premier accès).
 *
 * Sécurité :
 *   - Le token est comparé en temps constant (hash_equals)
 *   - Si la clé n'est pas configurée → 503 (cron désactivé)
 *
 * Réponse JSON :
 *   {
 *     "ok": true,
 *     "ran": {
 *       "leaderboard_archive": {"status":"success","summary":"..."},
 *       "clean_expired":       {"status":"skipped","summary":"..."}
 *     }
 *   }
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require __DIR__ . '/../includes/cron.php';

header('Content-Type: application/json; charset=utf-8');

$tokenExpected = (string) cfg('cron.token', '');
$tokenGiven    = (string) ($_GET['token'] ?? $_POST['token'] ?? '');

if ($tokenExpected === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'cron_not_configured']);
    exit;
}
if (!hash_equals($tokenExpected, $tokenGiven)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid_token']);
    exit;
}

/* Charge le registry et déclenche */
wt_cron_load_tasks();
$report = wt_cron_run(force: !empty($_GET['force']));

echo json_encode(['ok' => true, 'ran' => $report], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
