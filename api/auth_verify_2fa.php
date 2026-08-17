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

/* Le code n'a plus un format unique : 6 chiffres en TOTP, 7 caractères
   alphanumériques par e-mail, 8 chiffres par SMS, 10 caractères pour un code
   de secours. On ne valide donc plus le format ici — chaque vérificateur
   applique le sien. On se contente de retirer les espaces et le tiret de
   lisibilité des codes de secours. */
$code   = trim((string) ($_POST['code'] ?? ''));
$code   = preg_replace('/\s+/', '', $code) ?? '';
$method = (string) ($_POST['method'] ?? ($_SESSION['pending_2fa_method'] ?? 'totp'));

if ($code === '') {
    wt_json(['ok' => false, 'error' => t('auth.2fa.invalid')]);
}

$db = db();
$stmt = $db->prepare(
    "SELECT id, username, email, status, role, totp_secret, totp_enabled,
            twofa_email_enabled, twofa_sms_enabled,
            twofa_phone, twofa_phone_verified_at, twofa_preferred
       FROM users WHERE id = ? LIMIT 1"
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    wt_json(['ok' => false, 'error' => t('auth.2fa.expired')]);
}
if ($row['status'] !== 'active') {
    wt_json(['ok' => false, 'error' => t('auth.banned')]);
}

/* Le code de secours est TOUJOURS accepté, quelle que soit la méthode en
   cours : c'est précisément le recours quand la méthode habituelle est
   inaccessible (téléphone perdu, boîte e-mail fermée). Le refuser ici
   viderait les codes de secours de leur raison d'être. */
$allowed = wt_2fa_user_methods($row);
if ($method !== 'backup' && !in_array($method, $allowed, true)) {
    wt_json(['ok' => false, 'error' => t('auth.2fa.err_method_unsupported')]);
}

$verified = false;
$usedBackup = false;

if ($method === 'backup') {
    if ((string) cfg('2fa.backup_enabled', '1') !== '1') {
        wt_json(['ok' => false, 'error' => t('auth.2fa.err_method_unsupported')]);
    }
    $verified   = wt_2fa_verify_backup_code($uid, $code);
    $usedBackup = $verified;
} elseif ($method === 'totp') {
    $verified = !empty($row['totp_secret']) && auth_totp_verify((string) $row['totp_secret'], $code);
} else {
    $r = wt_2fa_verify_code($uid, $method, $code);
    if (!$r['ok']) {
        auth_attempt_record('__2fa__:' . $uid, wt_ip_bin(), false);
        wt_json(['ok' => false, 'error' => t('auth.2fa.err_' . $r['error'])]);
    }
    $verified = true;
}

if (!$verified) {
    auth_attempt_record('__2fa__:' . $uid, wt_ip_bin(), false);
    wt_json(['ok' => false, 'error' => t('auth.2fa.invalid')]);
}

// Connexion finalisée
$remember = !empty($_SESSION['pending_2fa_remember']);
unset($_SESSION['pending_2fa_uid'], $_SESSION['pending_2fa_remember'],
      $_SESSION['pending_2fa_methods'], $_SESSION['pending_2fa_method']);

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

// Alerte de connexion (e-mail + notification)
if (function_exists('wt_2fa_alert_new_login')) {
    wt_2fa_alert_new_login($row);
}

/* Un code de secours vient d'être consommé : on le signale. Si le stock
   descend bas, l'utilisateur doit le savoir avant d'arriver à zéro. */
$warn = null;
if ($usedBackup) {
    $left = wt_2fa_backup_remaining((int) $row['id']);
    if (function_exists('wt_notify')) {
        wt_notify((int) $row['id'], 'security',
            (string) t('auth.2fa.method_backup'),
            sprintf((string) t('auth.2fa.backup_remaining'), $left));
    }
    if ($left <= 2) {
        $warn = (string) t('auth.2fa.backup_warning');
    }
}

wt_json(array_filter([
    'ok'       => true,
    'redirect' => wt_url('/dashboard/'),
    'warning'  => $warn,
]));
