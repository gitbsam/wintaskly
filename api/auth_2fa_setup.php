<?php
/**
 * Wintaskly — POST /api/auth_2fa_setup.php
 *
 * Active ou désactive la 2FA par application (TOTP) pour l'utilisateur
 * connecté.
 *
 * Actions :
 *   - action=enable  : valide le code TOTP saisi contre le secret en
 *                      attente (transmis), puis enregistre le secret et
 *                      active totp_enabled.
 *   - action=disable : désactive totp_enabled et efface le secret.
 *
 * Sécurités :
 *   - require_auth() + CSRF strict
 *   - le secret n'est enregistré QUE si un code valide le confirme
 *     (preuve que l'app d'authentification est bien configurée)
 *   - notification de sécurité à chaque changement
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wt_json(['ok' => false, 'error' => 'method'], 405);
}
if (!csrf_check($_POST['_csrf'] ?? null)) {
    wt_json(['ok' => false, 'error' => t('common.error')], 403);
}

$u      = current_user();
$uid    = (int) $u['id'];
$action = (string) ($_POST['action'] ?? '');
$db     = db();

if ($action === 'enable') {
    // Disponibilité de la méthode (configurable par l'admin)
    if ((int) cfg('tfa.totp_available', '1') !== 1) {
        wt_json(['ok' => false, 'error' => t('settings.method_unavailable')]);
    }

    $secret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', (string) ($_POST['secret'] ?? '')));
    $code   = preg_replace('/\s+/', '', (string) ($_POST['code'] ?? ''));

    if (strlen($secret) < 16) {
        wt_json(['ok' => false, 'error' => t('tfa_setup.err_secret')]);
    }
    if (!preg_match('/^\d{6}$/', $code)) {
        wt_json(['ok' => false, 'error' => t('tfa_setup.err_code_format')]);
    }

    // Le code doit être valide pour prouver que l'app est bien configurée
    if (!auth_totp_verify($secret, $code)) {
        wt_json(['ok' => false, 'error' => t('tfa_setup.err_code_invalid')]);
    }

    $stmt = $db->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?");
    $stmt->bind_param('si', $secret, $uid);
    $stmt->execute();
    $stmt->close();

    // Notification de sécurité
    if (function_exists('wt_notify')) {
        wt_notify(
            $uid,
            'security',
            (string) t('tfa_setup.notif_enabled_title'),
            (string) t('tfa_setup.notif_enabled_body'),
            wt_url('/dashboard/settings.php')
        );
    }

    wt_json([
        'ok'      => true,
        'message' => (string) t('tfa_setup.enabled'),
        'redirect' => wt_url('/dashboard/settings.php'),
    ]);
}

if ($action === 'disable') {
    $stmt = $db->prepare("UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $stmt->close();

    if (function_exists('wt_notify')) {
        wt_notify(
            $uid,
            'security',
            (string) t('tfa_setup.notif_disabled_title'),
            (string) t('tfa_setup.notif_disabled_body'),
            wt_url('/dashboard/settings.php')
        );
    }

    wt_json([
        'ok'      => true,
        'message' => (string) t('tfa_setup.disabled'),
        'redirect' => wt_url('/dashboard/settings.php'),
    ]);
}

wt_json(['ok' => false, 'error' => 'invalid_action'], 400);
