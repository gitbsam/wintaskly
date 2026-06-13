<?php
/**
 * Wintaskly — POST /api/account_profile.php
 * Mise à jour des champs publics : username, country, bio.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') wt_json(['ok' => false, 'error' => 'method'], 405);
if (!csrf_check($_POST['_csrf'] ?? null))   wt_json(['ok' => false, 'error' => t('common.error')], 403);

$u   = current_user();
$uid = (int) $u['id'];
$db  = db();

$username = trim((string)($_POST['username'] ?? ''));
$country  = strtoupper(trim((string)($_POST['country'] ?? '')));
$bio      = trim((string)($_POST['bio']     ?? ''));

/* ---- Validations ---- */
if (wt_strlen($username) < 3 || wt_strlen($username) > 40
    || !preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
    wt_json(['ok' => false, 'error' => t('account.invalid_username')]);
}
if ($country !== '' && !preg_match('/^[A-Z]{2}$/', $country)) {
    wt_json(['ok' => false, 'error' => t('account.invalid_country')]);
}
if (wt_strlen($bio) > 500) {
    wt_json(['ok' => false, 'error' => t('account.invalid_bio')]);
}

/* ---- Unicité username (sauf le sien) ---- */
if ($username !== $u['username']) {
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1");
    $stmt->bind_param('si', $username, $uid);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($exists) wt_json(['ok' => false, 'error' => t('account.username_taken')]);
}

$countryDb = $country !== '' ? $country : null;
$bioDb     = $bio !== ''     ? $bio     : null;

$stmt = $db->prepare("UPDATE users SET username=?, country=?, bio=? WHERE id=?");
$stmt->bind_param('sssi', $username, $countryDb, $bioDb, $uid);
$stmt->execute();
$stmt->close();

wt_json(['ok' => true, 'message' => (string) t('account.profile_saved')]);
