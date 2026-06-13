<?php
/**
 * Wintaskly — POST /api/account_phone.php
 * Enregistre ou supprime un numéro de téléphone E.164.
 * Si vide, désactive aussi tfa_sms_enabled (impossible sans téléphone).
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') wt_json(['ok' => false, 'error' => 'method'], 405);
if (!csrf_check($_POST['_csrf'] ?? null))   wt_json(['ok' => false, 'error' => t('common.error')], 403);

$u   = current_user();
$uid = (int) $u['id'];
$db  = db();

$phone = trim((string)($_POST['phone'] ?? ''));

/* ---- Validation E.164 (+ suivi de 8-15 chiffres) ou vide pour supprimer ---- */
if ($phone !== '' && !preg_match('/^\+\d{8,15}$/', $phone)) {
    wt_json(['ok' => false, 'error' => t('account.invalid_phone')]);
}

$phoneDb = $phone !== '' ? $phone : null;

/* Si on vide le téléphone → on désactive aussi la 2FA SMS */
if ($phoneDb === null) {
    $stmt = $db->prepare("UPDATE users SET phone_e164 = NULL, tfa_sms_enabled = 0 WHERE id = ?");
    $stmt->bind_param('i', $uid);
} else {
    $stmt = $db->prepare("UPDATE users SET phone_e164 = ? WHERE id = ?");
    $stmt->bind_param('si', $phoneDb, $uid);
}
$stmt->execute();
$stmt->close();

wt_json([
    'ok'      => true,
    'message' => $phoneDb === null
        ? (string) t('account.phone_removed')
        : (string) t('account.phone_saved'),
]);
