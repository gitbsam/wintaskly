<?php
/**
 * Wintaskly — POST /api/account_delete_cancel.php
 *
 * Annule une demande de suppression de compte pendant la période de grâce.
 * Réinitialise delete_requested_at et delete_token à NULL.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') wt_json(['ok' => false, 'error' => 'method'], 405);
if (!csrf_check($_POST['_csrf'] ?? null))   wt_json(['ok' => false, 'error' => t('common.error')], 403);

$u   = current_user();
$uid = (int) $u['id'];
$db  = db();

if (empty($u['delete_requested_at'])) {
    wt_json(['ok' => false, 'error' => t('account.delete_no_pending')]);
}

$stmt = $db->prepare(
    "UPDATE users
        SET delete_requested_at = NULL,
            delete_token        = NULL
      WHERE id = ?"
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$stmt->close();

wt_notify(
    $uid,
    'security',
    (string) t('account.delete_cancelled_notif_title'),
    (string) t('account.delete_cancelled_notif_body')
);

wt_json([
    'ok'       => true,
    'message'  => (string) t('account.delete_cancelled'),
    'redirect' => wt_url('/dashboard/account.php'),
]);
