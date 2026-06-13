<?php
/**
 * Wintaskly — POST /api/ptc_cancel.php
 *
 * Libère le verrou utilisateur quand l'onglet partenaire a été fermé
 * trop tôt (signal envoyé par le JS dès que window.closed === true).
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') wt_json(['ok' => false, 'error' => 'method'], 405);
if (!csrf_check($_POST['_csrf'] ?? null)) wt_json(['ok' => false, 'error' => 'csrf'], 403);

$u = current_user();
if (!$u) wt_json(['ok' => false, 'error' => 'auth'], 401);

$token = trim((string)($_POST['token'] ?? ''));
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    wt_json(['ok' => false, 'error' => 'token']);
}

$db = db();
$stmt = $db->prepare(
    "UPDATE ptc_sessions
        SET status = 'cancelled', reject_reason = 'tab_closed'
      WHERE token = ? AND user_id = ? AND status = 'active'"
);
$stmt->bind_param('si', $token, $u['id']);
$stmt->execute();
$ok = $stmt->affected_rows >= 0;
$stmt->close();

wt_json(['ok' => $ok]);
