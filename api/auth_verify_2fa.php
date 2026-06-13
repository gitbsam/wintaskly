<?php
/**
 * Wintaskly — POST /api/auth_verify_2fa.php
 *
 * Vérifie un code TOTP à 6 chiffres pour finaliser la connexion 2FA.
 * La session doit déjà contenir `pending_2fa_uid` (posé par
 * /api/auth_login.php).
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') wt_json(['ok' => false, 'error' => 'method'], 405);
if (!csrf_check($_POST['_csrf'] ?? null))   wt_json(['ok' => false, 'error' => t('common.error')], 403);

$uid = (int)($_SESSION['pending_2fa_uid'] ?? 0);
if ($uid <= 0) {
    wt_json(['ok' => false, 'error' => t('auth.2fa.expired')]);
}

$code = preg_replace('/\s+/', '', (string)($_POST['code'] ?? ''));
if (!preg_match('/^\d{6}$/', $code)) {
    wt_json(['ok' => false, 'error' => t('auth.2fa.invalid')]);
}

$db = db();
$stmt = $db->prepare(
    "SELECT id, status, totp_secret, totp_enabled
       FROM users WHERE id = ? LIMIT 1"
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || (int) $row['totp_enabled'] !== 1 || empty($row['totp_secret'])) {
    wt_json(['ok' => false, 'error' => t('auth.2fa.expired')]);
}
if ($row['status'] !== 'active') {
    wt_json(['ok' => false, 'error' => t('auth.banned')]);
}

if (!auth_totp_verify($row['totp_secret'], $code)) {
    auth_attempt_record('__2fa__:' . $uid, wt_ip_bin(), false);
    wt_json(['ok' => false, 'error' => t('auth.2fa.invalid')]);
}

// Connexion finalisée
$remember = !empty($_SESSION['pending_2fa_remember']);
unset($_SESSION['pending_2fa_uid'], $_SESSION['pending_2fa_remember']);

session_regenerate_id(true);
$_SESSION['uid'] = (int) $row['id'];

$ipBin = wt_ip_bin();
$upd = $db->prepare(
    "UPDATE users
        SET last_login_at = UTC_TIMESTAMP(),
            last_login_ip = ?
      WHERE id = ?"
);
$upd->bind_param('si', $ipBin, $row['id']);
$upd->execute();
$upd->close();

if ($remember) {
    auth_remember_set((int) $row['id']);
}

wt_json(['ok' => true, 'redirect' => wt_url('/dashboard/')]);
