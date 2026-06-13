<?php
/**
 * Wintaskly — POST /api/settings_toggle.php
 *
 * Endpoint générique pour les toggles & sélecteurs de /dashboard/settings.
 *
 * Body attendu :
 *   _csrf = token
 *   key   = nom du paramètre (whitelisté côté serveur — voir $ALLOWED)
 *   value = nouvelle valeur (1/0 pour booléens, sinon string)
 *
 * Réponse : { ok, message?, error?, snapshot? }
 *   snapshot est l'état renvoyé après modification pour que le client
 *   puisse rafraichir des composants dépendants si besoin.
 *
 * Sécurités :
 *   - require_auth() : la session doit être valide
 *   - CSRF strict
 *   - whitelist des clés modifiables
 *   - validation par type (boolean strict, enum strict)
 *   - les toggles 2FA email/sms vérifient leur disponibilité config
 *   - le toggle 2FA SMS exige un phone_e164 enregistré
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') wt_json(['ok' => false, 'error' => 'method'], 405);
if (!csrf_check($_POST['_csrf'] ?? null))   wt_json(['ok' => false, 'error' => t('common.error')], 403);

$u   = current_user();
$uid = (int) $u['id'];
$key = (string) ($_POST['key']   ?? '');
$val = (string) ($_POST['value'] ?? '');

/* Whitelist : clé → ['type' => bool|enum|string, 'col' => 'col_name', 'enum' => [...]] */
$ALLOWED = [
    'tfa_email_enabled' => ['type' => 'bool', 'col' => 'tfa_email_enabled'],
    'tfa_sms_enabled'   => ['type' => 'bool', 'col' => 'tfa_sms_enabled'],
    'theme'             => ['type' => 'enum', 'col' => 'theme', 'enum' => ['light', 'dark']],
    'lang'              => ['type' => 'enum', 'col' => 'lang',  'enum' => ['fr', 'en']],
];

if (!isset($ALLOWED[$key])) {
    wt_json(['ok' => false, 'error' => 'invalid_key'], 400);
}

$spec = $ALLOWED[$key];
$db   = db();

/* ---- Validation valeur ---- */
$dbValue = null;
switch ($spec['type']) {
    case 'bool':
        $dbValue = ($val === '1' || $val === 'true' || $val === 'on') ? 1 : 0;
        break;
    case 'enum':
        if (!in_array($val, $spec['enum'], true)) {
            wt_json(['ok' => false, 'error' => 'invalid_value'], 400);
        }
        $dbValue = $val;
        break;
    case 'string':
        $dbValue = trim($val);
        break;
}

/* ---- Règles métier supplémentaires ---- */
if ($key === 'tfa_email_enabled') {
    if ((int) cfg('tfa.email_available', '1') !== 1) {
        wt_json(['ok' => false, 'error' => t('settings.method_unavailable')]);
    }
    // Email doit être vérifié pour activer la 2FA email
    if ($dbValue === 1 && empty($u['email_verified_at'])) {
        wt_json(['ok' => false, 'error' => t('settings.email_not_verified')]);
    }
}
if ($key === 'tfa_sms_enabled') {
    if ((int) cfg('tfa.sms_available', '0') !== 1) {
        wt_json(['ok' => false, 'error' => t('settings.method_unavailable')]);
    }
    if ($dbValue === 1 && empty($u['phone_e164'])) {
        wt_json(['ok' => false, 'error' => t('settings.phone_required')]);
    }
}

/* ---- Mise à jour ---- */
$sql  = "UPDATE users SET `{$spec['col']}` = ? WHERE id = ?";
$stmt = $db->prepare($sql);

if ($spec['type'] === 'bool') {
    $stmt->bind_param('ii', $dbValue, $uid);
} else {
    $stmt->bind_param('si', $dbValue, $uid);
}
$stmt->execute();
$stmt->close();

/* ---- Cas spécial : theme/lang aussi en cookie pour effet immédiat ---- */
if ($key === 'theme') {
    setcookie('wt_theme', $val, [
        'expires'  => time() + 86400 * 365,
        'path'     => '/',
        'samesite' => 'Lax',
        'secure'   => !empty($GLOBALS['WT_CONFIG']['cookie_secure']),
    ]);
}
if ($key === 'lang') {
    setcookie('wt_lang', $val, [
        'expires'  => time() + 86400 * 365,
        'path'     => '/',
        'samesite' => 'Lax',
        'secure'   => !empty($GLOBALS['WT_CONFIG']['cookie_secure']),
    ]);
}

wt_json([
    'ok'      => true,
    'message' => (string) t('settings.saved'),
    'key'     => $key,
    'value'   => $dbValue,
    // Recharge la page si le changement affecte l'UI (langue/thème)
    'reload'  => in_array($key, ['lang', 'theme'], true),
]);
