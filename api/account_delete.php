<?php
/**
 * Wintaskly — POST /api/account_delete.php
 *
 * Demande de suppression du compte avec délai de grâce.
 *
 * Workflow :
 *   1. L'utilisateur clique sur "Supprimer mon compte" → modal de
 *      confirmation (typeguard "SUPPRIMER") → POST ici.
 *   2. On positionne users.delete_requested_at = NOW() et un
 *      delete_token (au cas où il aurait besoin de l'annuler par lien).
 *   3. Pendant la période de grâce (config account.delete_grace_days,
 *      par défaut 7 jours), l'utilisateur peut toujours se connecter
 *      et annuler via /api/account_delete_cancel.php.
 *   4. À l'expiration, un cron (à brancher séparément) purge :
 *        DELETE FROM users WHERE delete_requested_at < NOW() - 7 DAYS
 *      Tous les FK ON DELETE CASCADE nettoient les enfants.
 *
 * On envoie aussi un e-mail de confirmation pour que l'utilisateur
 * puisse vérifier que la demande est bien la sienne.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') wt_json(['ok' => false, 'error' => 'method'], 405);
if (!csrf_check($_POST['_csrf'] ?? null))   wt_json(['ok' => false, 'error' => t('common.error')], 403);

$u   = current_user();
$uid = (int) $u['id'];
$db  = db();

if (!empty($u['delete_requested_at'])) {
    wt_json(['ok' => false, 'error' => t('account.delete_already_pending')]);
}

$token     = bin2hex(random_bytes(32));
$graceDays = (int) cfg('account.delete_grace_days', '7');

$stmt = $db->prepare(
    "UPDATE users
        SET delete_requested_at = UTC_TIMESTAMP(),
            delete_token        = ?
      WHERE id = ?"
);
$stmt->bind_param('si', $token, $uid);
$stmt->execute();
$stmt->close();

/* Notification interne */
wt_notify(
    $uid,
    'security',
    (string) t('account.delete_notif_title'),
    (string) t('account.delete_notif_body', ['days' => $graceDays]),
    wt_url('/dashboard/account.php')
);

/* E-mail de confirmation */
try {
    wt_mail($u['email'], 'security_alert', [
        'username' => $u['username'],
        'link'     => wt_url('/dashboard/account.php'),
        'body'     => (string) t('account.delete_mail_body', ['days' => $graceDays]),
    ]);
} catch (Throwable $e) {
    error_log('account_delete/mail: ' . $e->getMessage());
}

wt_json([
    'ok'       => true,
    'message'  => (string) t('account.delete_scheduled', ['days' => $graceDays]),
    'redirect' => wt_url('/dashboard/account.php'),
]);
