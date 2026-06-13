<?php
/**
 * API : enregistre la timezone du client (cookie + base si connecté).
 * Affichage uniquement — n'impacte JAMAIS les calculs internes.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    wt_json(['ok' => false], 405);
}
// Standard projet : '_csrf'. Fallback 'csrf' pour rétro-compat clients en cache.
if (!csrf_check($_POST['_csrf'] ?? $_POST['csrf'] ?? null)) {
    wt_json(['ok' => false, 'message' => 'CSRF invalide'], 403);
}

$tz = trim((string)($_POST['tz'] ?? ''));
// On valide via le pool officiel PHP
if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
    wt_json(['ok' => false, 'message' => 'timezone invalide'], 400);
}

setcookie('wt_tz', $tz, [
    'expires'  => time() + 60 * 60 * 24 * 365,
    'path'     => '/',
    'secure'   => !empty($GLOBALS['WT_CONFIG']['cookie_secure']),
    'httponly' => false,
    'samesite' => 'Lax',
]);

if (!empty($_SESSION['uid'])) {
    $uid  = (int)$_SESSION['uid'];
    $stmt = db()->prepare("UPDATE users SET timezone = ? WHERE id = ?");
    $stmt->bind_param('si', $tz, $uid);
    $stmt->execute();
    $stmt->close();
}

wt_json(['ok' => true, 'tz' => $tz]);
