<?php
/**
 * Wintaskly — GET /api/badges.php
 *
 * Petit endpoint pour rafraîchir les compteurs du Header sans
 * recharger la page. Retourne {messages, notifications}.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

$u = current_user();
if (!$u) wt_json(['ok' => true, 'messages' => 0, 'notifications' => 0]);

wt_json([
    'ok'            => true,
    'messages'      => wt_messages_unread_count((int) $u['id']),
    'notifications' => wt_notifications_unread_count((int) $u['id']),
]);
