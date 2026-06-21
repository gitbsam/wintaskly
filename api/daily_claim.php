<?php
/**
 * API : POST /api/daily_claim.php
 *
 * Réclame le bonus quotidien pour l'utilisateur connecté.
 *
 * Garanties :
 *   - Utilisateur connecté (sinon 401)
 *   - CSRF vérifié
 *   - Toute la logique d'éligibilité + crédit est dans wt_daily_claim()
 *     (transaction atomique anti-double-claim)
 *
 * Réponse JSON :
 *   succès : { ok:true, coins, xp, streak, day, jackpot, label }
 *   échec  : { ok:false, message, seconds_left? }
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    wt_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}
if (!csrf_check($_POST['_csrf'] ?? $_POST['csrf'] ?? null)) {
    wt_json(['ok' => false, 'message' => t('common.error')], 403);
}

$user = current_user();
if (!$user) {
    wt_json(['ok' => false, 'message' => t('auth.required'), 'redirect' => wt_url('/auth/login.php')], 401);
}

$result = wt_daily_claim($user);

if ($result['ok']) {
    wt_json([
        'ok'      => true,
        'coins'   => $result['coins'],
        'xp'      => $result['xp'],
        'streak'  => $result['streak'],
        'day'     => $result['day'],
        'jackpot' => $result['jackpot'],
        'label'   => $result['label'],
        'message' => sprintf((string) t('daily.claimed_msg'), wt_format_coins($result['coins'])),
    ]);
}

// Gestion des erreurs
$msgMap = [
    'disabled'     => t('daily.err_disabled'),
    'cooldown'     => t('daily.err_cooldown'),
    'invalid_user' => t('auth.required'),
    'server_error' => t('common.error'),
];
$msg = $msgMap[$result['error']] ?? t('common.error');

$payload = ['ok' => false, 'message' => $msg];
if (isset($result['seconds_left'])) {
    $payload['seconds_left'] = $result['seconds_left'];
}
wt_json($payload, 200);
