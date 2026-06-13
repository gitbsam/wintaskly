<?php
/**
 * Wintaskly — POST /api/notification_delete.php
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') wt_json(['ok' => false, 'error' => 'method'], 405);
if (!csrf_check($_POST['_csrf'] ?? null))   wt_json(['ok' => false, 'error' => t('common.error')], 403);

$u = current_user();
if (!$u) wt_json(['ok' => false, 'error' => 'auth'], 401);

$ids = $_POST['ids'] ?? [];
if (!is_array($ids)) wt_json(['ok' => false, 'error' => 'payload']);
$ids = array_values(array_filter(array_map('intval', $ids), fn ($x) => $x > 0));
if (!$ids) wt_json(['ok' => false, 'error' => t('common.nothing_selected')]);
if (count($ids) > 200) $ids = array_slice($ids, 0, 200);

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types        = str_repeat('i', count($ids)) . 'i';
$params       = $ids;
$params[]     = (int) $u['id'];

$stmt = db()->prepare(
    "DELETE FROM notifications
      WHERE id IN ($placeholders) AND user_id = ?"
);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

wt_json(['ok' => true, 'deleted' => $affected]);
