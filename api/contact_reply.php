<?php
/**
 * Wintaskly — POST /api/contact_reply.php
 *
 * Permet aux invités (via leur token) et aux utilisateurs connectés
 * (via ticket_id) de répondre à un ticket existant.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') wt_json(['ok' => false, 'error' => 'method'], 405);
if (!csrf_check($_POST['_csrf'] ?? null))   wt_json(['ok' => false, 'error' => t('common.error')], 403);

$body = trim((string)($_POST['body'] ?? ''));
if ($body === '' || wt_strlen($body) > 5000) {
    wt_json(['ok' => false, 'error' => t('contact.invalid_body')]);
}

$u  = current_user();
$db = db();

if ($u) {
    /* Utilisateur connecté */
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    if ($ticketId <= 0) wt_json(['ok' => false, 'error' => 'ticket_id']);

    $stmt = $db->prepare(
        "SELECT id, status FROM support_tickets
          WHERE id = ? AND user_id = ?
          LIMIT 1"
    );
    $stmt->bind_param('ii', $ticketId, $u['id']);
    $stmt->execute();
    $tk = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$tk) wt_json(['ok' => false, 'error' => 'not_found'], 404);
    if ($tk['status'] === 'closed') wt_json(['ok' => false, 'error' => t('contact.closed')]);

    $stmt = $db->prepare(
        "INSERT INTO support_messages (ticket_id, author_role, author_id, body)
         VALUES (?, 'user', ?, ?)"
    );
    $stmt->bind_param('iis', $ticketId, $u['id'], $body);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare(
        "UPDATE support_tickets
            SET last_reply_by = 'user', last_reply_at = UTC_TIMESTAMP(), status = 'open'
          WHERE id = ?"
    );
    $stmt->bind_param('i', $ticketId);
    $stmt->execute();
    $stmt->close();

    wt_json(['ok' => true, 'message' => (string) t('contact.reply_sent')]);
}

/* Invité — auth par token */
$token = trim((string)($_POST['token'] ?? ''));
if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) {
    wt_json(['ok' => false, 'error' => t('common.error')], 403);
}
$stmt = $db->prepare(
    "SELECT id, status FROM support_tickets WHERE guest_token = ? LIMIT 1"
);
$stmt->bind_param('s', $token);
$stmt->execute();
$tk = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$tk) wt_json(['ok' => false, 'error' => 'not_found'], 404);
if ($tk['status'] === 'closed') wt_json(['ok' => false, 'error' => t('contact.closed')]);

$tkId = (int) $tk['id'];
$stmt = $db->prepare(
    "INSERT INTO support_messages (ticket_id, author_role, body)
     VALUES (?, 'guest', ?)"
);
$stmt->bind_param('is', $tkId, $body);
$stmt->execute();
$stmt->close();

$stmt = $db->prepare(
    "UPDATE support_tickets
        SET last_reply_by='guest', last_reply_at=UTC_TIMESTAMP(), status='open'
      WHERE id = ?"
);
$stmt->bind_param('i', $tkId);
$stmt->execute();
$stmt->close();

wt_json(['ok' => true, 'message' => (string) t('contact.reply_sent')]);
