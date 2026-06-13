<?php
/**
 * Wintaskly — POST /api/auth_reset.php
 *
 * Consomme un token reset_password, met à jour le mot de passe avec
 * PASSWORD_DEFAULT (bcrypt aujourd'hui, argon2 demain), invalide tous
 * les autres tokens de l'utilisateur (remember-me + reset).
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') wt_json(['ok' => false, 'error' => 'method'], 405);
if (!csrf_check($_POST['_csrf'] ?? null))   wt_json(['ok' => false, 'error' => t('common.error')], 403);

$token = trim((string)($_POST['token']     ?? ''));
$pass  = (string)      ($_POST['password']  ?? '');
$pass2 = (string)      ($_POST['password2'] ?? '');

if (strlen($pass) < 8) {
    wt_json(['ok' => false, 'error' => t('auth.weak')]);
}
if ($pass !== $pass2) {
    wt_json(['ok' => false, 'error' => t('auth.mismatch')]);
}

$uid = auth_token_consume($token, 'reset_password');
if (!$uid) {
    wt_json(['ok' => false, 'error' => t('auth.reset.invalid_token')]);
}

$hash = password_hash($pass, PASSWORD_DEFAULT);

$db = db();
$stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
$stmt->bind_param('si', $hash, $uid);
$stmt->execute();
$stmt->close();

// Révoque tous les remember-me ouverts (sécurité)
auth_tokens_revoke($uid, 'remember_me');
auth_tokens_revoke($uid, 'reset_password');

// Auto-connecte l'utilisateur sur la nouvelle session
session_regenerate_id(true);
$_SESSION['uid'] = $uid;

wt_json([
    'ok'       => true,
    'redirect' => wt_url('/dashboard/'),
]);
