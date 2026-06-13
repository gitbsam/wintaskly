<?php
/**
 * Wintaskly — POST /api/admin_config_set.php
 *
 * Mise à jour d'une clé de la table `config` par un admin.
 * Body : { _csrf, key, value }
 *
 * Whitelist stricte : seules certaines clés sont modifiables via cet
 * endpoint (les autres restent ALTER manuel ou via leur API dédiée).
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') wt_json(['ok' => false, 'error' => 'method'], 405);
if (!csrf_check($_POST['_csrf'] ?? null))   wt_json(['ok' => false, 'error' => t('common.error')], 403);

$key = (string)($_POST['key']   ?? '');
$val = (string)($_POST['value'] ?? '');

/* Whitelist : clé → ['type' => bool|int|string, 'min'=>?, 'max'=>?] */
$ALLOWED = [
    'tfa.totp_available'        => ['type' => 'bool'],
    'tfa.email_available'       => ['type' => 'bool'],
    'tfa.sms_available'         => ['type' => 'bool'],
    'tfa.sms_provider'          => ['type' => 'string', 'max' => 80],
    'account.delete_grace_days' => ['type' => 'int', 'min' => 0, 'max' => 90],
];

if (!isset($ALLOWED[$key])) {
    wt_json(['ok' => false, 'error' => 'invalid_key'], 400);
}

$spec = $ALLOWED[$key];

/* Validation par type */
switch ($spec['type']) {
    case 'bool':
        $dbValue = ($val === '1' || $val === 'true' || $val === 'on') ? '1' : '0';
        break;
    case 'int':
        if (!ctype_digit($val) && !preg_match('/^-?\d+$/', $val)) {
            wt_json(['ok' => false, 'error' => 'invalid_value'], 400);
        }
        $n = (int) $val;
        if (isset($spec['min']) && $n < $spec['min']) wt_json(['ok' => false, 'error' => 'invalid_value']);
        if (isset($spec['max']) && $n > $spec['max']) wt_json(['ok' => false, 'error' => 'invalid_value']);
        $dbValue = (string) $n;
        break;
    case 'string':
    default:
        if (isset($spec['max']) && strlen($val) > $spec['max']) {
            wt_json(['ok' => false, 'error' => 'invalid_value']);
        }
        $dbValue = trim($val);
        break;
}

/* INSERT … ON DUPLICATE KEY UPDATE pour idempotence */
$stmt = db()->prepare(
    "INSERT INTO config (k, v) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE v = VALUES(v)"
);
$stmt->bind_param('ss', $key, $dbValue);
$stmt->execute();
$stmt->close();

wt_json([
    'ok'      => true,
    'message' => (string) t('admin.config_saved'),
    'key'     => $key,
    'value'   => $dbValue,
]);
