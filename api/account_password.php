<?php
/**
 * Wintaskly — POST /api/account_password.php
 *
 * Changement de mot de passe :
 *   - exige l'ancien mot de passe
 *   - rate-limité (3 tentatives échouées = lockout 15 min sur cette action)
 *   - après succès : invalide TOUS les tokens remember-me sauf le courant
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') wt_json(['ok' => false, 'error' => 'method'], 405);
if (!csrf_check($_POST['_csrf'] ?? null))   wt_json(['ok' => false, 'error' => t('common.error')], 403);

$u   = current_user();
$uid = (int) $u['id'];
$db  = db();

$current = (string)($_POST['current_password'] ?? '');
$new     = (string)($_POST['new_password']     ?? '');

if (wt_strlen($new) < 8) {
    wt_json(['ok' => false, 'error' => t('auth.password_too_short')]);
}
if ($current === $new) {
    wt_json(['ok' => false, 'error' => t('account.password_same')]);
}

/* Rate-limit dédié pour cette action sensible */
$ipBin = wt_ip_bin();
list($blocked, $resetIn) = auth_attempt_blocked('pw_change:' . $uid, $ipBin);
if ($blocked) {
    wt_json([
        'ok' => false,
        'error' => t('contact.error_rate_limit'),
        'cooldown' => $resetIn,
    ]);
}

/* ---- Vérification ancien mot de passe ---- */
$stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $uid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || !password_verify($current, $row['password_hash'])) {
    auth_attempt_record('pw_change:' . $uid, $ipBin, false);
    wt_json(['ok' => false, 'error' => t('account.password_invalid')]);
}

/* ---- Hash + mise à jour + invalidation remember-me ---- */
$hash = password_hash($new, PASSWORD_DEFAULT);

$db->begin_transaction();
try {
    $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $stmt->bind_param('si', $hash, $uid);
    $stmt->execute();
    $stmt->close();

    /* Invalide tous les tokens de connexion permanente */
    $stmt = $db->prepare("DELETE FROM auth_tokens WHERE user_id = ? AND purpose = 'remember'");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $stmt->close();

    auth_attempt_record('pw_change:' . $uid, $ipBin, true);

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    error_log('account_password: ' . $e->getMessage());
    wt_json(['ok' => false, 'error' => t('common.error')], 500);
}

/* Notification de sécurité */
wt_notify(
    $uid,
    'security',
    (string) t('account.password_changed_notif_title'),
    (string) t('account.password_changed_notif_body'),
    wt_url('/dashboard/account.php')
);

wt_json([
    'ok'      => true,
    'message' => (string) t('account.password_changed'),
]);
