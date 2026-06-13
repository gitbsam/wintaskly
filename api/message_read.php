<?php
/**
 * Wintaskly — POST /api/message_read.php
 *
 * Marque un message comme lu (read_at = NOW) si appartient à l'utilisateur
 * courant et n'est pas encore lu. Idempotent.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') wt_json(['ok' => false, 'error' => 'method'], 405);
if (!csrf_check($_POST['_csrf'] ?? null))   wt_json(['ok' => false, 'error' => t('common.error')], 403);

$u = current_user();
if (!$u) wt_json(['ok' => false, 'error' => 'auth'], 401);

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) wt_json(['ok' => false, 'error' => 'payload']);

$stmt = db()->prepare(
    "UPDATE messages SET read_at = UTC_TIMESTAMP()
      WHERE id = ? AND user_id = ? AND read_at IS NULL"
);
$stmt->bind_param('ii', $id, $u['id']);
$stmt->execute();
$ok = $stmt->affected_rows > 0;
$stmt->close();

wt_json(['ok' => true, 'changed' => $ok]);
