<?php
/**
 * API : POST /api/bingo_action.php
 *
 * Actions du joueur sur le bingo. Une seule entrée pour 3 actions :
 *   - activate : activer/acheter un carton (1er gratuit, suivants payants)
 *   - mark     : valider un numéro tiré sur un carton actif
 *   - claim    : réclamer un carton plein (25 validés)
 *
 * Garanties :
 *   - Utilisateur connecté (sinon 401)
 *   - CSRF vérifié
 *   - Bingo jouable pour cet utilisateur (mode test / lancement)
 *   - Toute la logique (concurrence, argent) est dans le module bingo
 *
 * Réponse JSON : { ok:bool, ... } selon l'action.
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

// Bingo jouable pour cet utilisateur ?
if (!function_exists('wt_bingo_visible_for') || !wt_bingo_visible_for($user)) {
    wt_json(['ok' => false, 'message' => t('common.error')], 403);
}

$action = (string) ($_POST['action'] ?? '');
$cardId = (int) ($_POST['card_id'] ?? 0);
$userId = (int) $user['id'];

switch ($action) {

    case 'activate':
        $r = wt_bingo_activate_card($cardId, $userId);
        if ($r['ok']) {
            wt_json([
                'ok'    => true,
                'free'  => $r['free'],
                'price' => $r['price'],
                'reload' => true, // recharge pour révéler les numéros du carton
            ]);
        }
        wt_json(['ok' => false, 'error' => $r['error'], 'message' => wt_bingo_error_msg($r['error'])]);
        break;

    case 'mark':
        $number = (int) ($_POST['number'] ?? 0);
        $r = wt_bingo_mark_number($cardId, $userId, $number);
        if ($r['ok']) {
            wt_json(['ok' => true, 'full' => $r['full'], 'number' => $number]);
        }
        wt_json(['ok' => false, 'error' => $r['error'], 'message' => wt_bingo_error_msg($r['error'])]);
        break;

    case 'claim':
        $r = wt_bingo_claim_card($cardId, $userId);
        if ($r['ok']) {
            wt_json(['ok' => true, 'reload' => true]);
        }
        wt_json(['ok' => false, 'error' => $r['error'], 'message' => wt_bingo_error_msg($r['error'])]);
        break;

    default:
        wt_json(['ok' => false, 'message' => t('common.error')], 400);
}

/**
 * Traduit un code d'erreur du moteur en message i18n.
 */
function wt_bingo_error_msg(string $code): string
{
    $map = [
        'disabled'           => t('bingo.err_disabled'),
        'not_found'          => t('bingo.err_not_found'),
        'already_active'     => t('bingo.err_already_active'),
        'round_closed'       => t('bingo.err_round_closed'),
        'insufficient_coins' => t('bingo.err_insufficient'),
        'not_active'         => t('bingo.err_not_active'),
        'not_on_card'        => t('bingo.err_not_on_card'),
        'not_drawn'          => t('bingo.err_not_drawn'),
        'not_full'           => t('bingo.err_not_full'),
        'already_claimed'    => t('bingo.err_already_claimed'),
        'server'             => t('common.error'),
    ];
    return $map[$code] ?? t('common.error');
}
