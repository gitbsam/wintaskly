<?php
/**
 * Wintaskly — Helpers V4
 *   - Compteurs unread (messages + notifications) pour le Header.
 *   - Création de notifications/messages.
 *   - Nettoyage TTL (déclenché stochastiquement par init.php).
 *   - URL d'avatar (image ou initiale stylisée).
 *
 * Inclus automatiquement par includes/init.php.
 */
declare(strict_types=1);

// =====================================================================
// 1) Compteurs unread
// =====================================================================

/**
 * Nombre de messages non lus pour un utilisateur.
 * Filtre les messages expirés (TTL).
 */
function wt_messages_unread_count(int $userId): int
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) c FROM messages
          WHERE user_id = ?
            AND read_at IS NULL
            AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $n = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $n;
}

/**
 * Nombre de notifications non lues pour un utilisateur.
 */
function wt_notifications_unread_count(int $userId): int
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) c FROM notifications
          WHERE user_id = ?
            AND read_at IS NULL
            AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $n = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $n;
}

/**
 * Formate un compteur pour un badge : 1..9 puis "9+".
 */
function wt_badge_count(int $n): string
{
    if ($n <= 0)  return '';
    if ($n > 9)   return '9+';
    return (string) $n;
}

// =====================================================================
// 2) Factories — créer un message ou une notification
// =====================================================================

/**
 * Crée un message (admin → user) dans la boîte de réception.
 * Pose une expires_at par défaut basée sur ttl.message_unread_days.
 */
function wt_create_message(
    int $userId,
    string $subject,
    string $body,
    string $senderRole = 'admin'
): int {
    $ttlDays = (int) cfg('ttl.message_unread_days', '90');
    $stmt = db()->prepare(
        "INSERT INTO messages (user_id, sender_role, subject, body, expires_at)
         VALUES (?, ?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? DAY)"
    );
    $stmt->bind_param('isssi', $userId, $senderRole, $subject, $body, $ttlDays);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

/**
 * Crée une notification courte.
 */
function wt_notify(
    int    $userId,
    string $type,
    string $title,
    ?string $body = null,
    ?string $url  = null
): int {
    $ttlDays = (int) cfg('ttl.notif_unread_days', '90');
    $stmt = db()->prepare(
        "INSERT INTO notifications (user_id, type, title, body, url, expires_at)
         VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? DAY)"
    );
    $stmt->bind_param('issssi', $userId, $type, $title, $body, $url, $ttlDays);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

// =====================================================================
// 3) TTL cleanup — passe en revue messages, notifs, tokens, attempts
// =====================================================================

/**
 * Nettoie les enregistrements expirés.
 * Politique :
 *   - messages/notifs lus : retirés après `ttl.<type>_read_days` jours
 *     à compter de read_at ;
 *   - messages/notifs non lus : retirés à expires_at (posé à la création).
 *   - auth_tokens expirés ou consommés depuis >7 jours ;
 *   - auth_attempts plus vieux que 30 jours.
 */
function wt_ttl_cleanup(): array
{
    $db = db();
    $stats = ['messages' => 0, 'notifications' => 0, 'tokens' => 0, 'attempts' => 0];

    $msgReadDays  = max(1, (int) cfg('ttl.message_read_days', '30'));
    $notifReadDays = max(1, (int) cfg('ttl.notif_read_days',  '30'));

    // 1) messages lus depuis trop longtemps
    $sql = "DELETE FROM messages
             WHERE (read_at IS NOT NULL AND read_at < UTC_TIMESTAMP() - INTERVAL ? DAY)
                OR (expires_at IS NOT NULL AND expires_at < UTC_TIMESTAMP())";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param('i', $msgReadDays);
        $stmt->execute();
        $stats['messages'] = $stmt->affected_rows;
        $stmt->close();
    }

    // 2) notifications
    $sql = "DELETE FROM notifications
             WHERE (read_at IS NOT NULL AND read_at < UTC_TIMESTAMP() - INTERVAL ? DAY)
                OR (expires_at IS NOT NULL AND expires_at < UTC_TIMESTAMP())";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param('i', $notifReadDays);
        $stmt->execute();
        $stats['notifications'] = $stmt->affected_rows;
        $stmt->close();
    }

    // 3) auth_tokens : expirés OU consommés depuis plus de 7 jours
    $res = $db->query(
        "DELETE FROM auth_tokens
          WHERE expires_at < UTC_TIMESTAMP()
             OR (used_at IS NOT NULL AND used_at < UTC_TIMESTAMP() - INTERVAL 7 DAY)"
    );
    if ($res) $stats['tokens'] = $db->affected_rows;

    // 4) auth_attempts > 30 jours
    $res = $db->query("DELETE FROM auth_attempts WHERE created_at < UTC_TIMESTAMP() - INTERVAL 30 DAY");
    if ($res) $stats['attempts'] = $db->affected_rows;

    return $stats;
}

/**
 * Cleanup probabiliste : appelé par init.php sur ~ttl.cleanup_probability
 * des requêtes (0.02 = 2 % par défaut).
 */
function wt_ttl_maybe_cleanup(): void
{
    $p = (float) cfg('ttl.cleanup_probability', '0.02');
    if ($p <= 0) return;
    // random_int distribué uniformément sur 0..999999
    if (random_int(0, 999999) < (int) round($p * 1000000)) {
        try { wt_ttl_cleanup(); } catch (Throwable $e) { error_log('ttl_cleanup: ' . $e->getMessage()); }
    }
}

// =====================================================================
// 4) Avatar — URL d'image ou pastille initiale
// =====================================================================

/**
 * Indique si l'utilisateur a une image d'avatar définie.
 */
function wt_avatar_has_image(?array $u): bool
{
    return $u && !empty($u['avatar_url']);
}

/**
 * Construit le contenu HTML d'un avatar circulaire (image ou initiale).
 * À utiliser dans un wrapper `.wt-avatar`.
 */
function wt_avatar_inner(array $u): string
{
    if (!empty($u['avatar_url'])) {
        return '<img src="' . htmlspecialchars($u['avatar_url'], ENT_QUOTES, 'UTF-8') . '" alt="" loading="lazy">';
    }
    $name = (string)($u['username'] ?? '?');
    // mbstring fallback : on garde l'UTF-8 si dispo, sinon on prend
    // le premier code-point représentable en ASCII.
    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        $initial = mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8');
    } else {
        // Fallback : extrait le premier octet ASCII, ou '?'
        $initial = '?';
        for ($i = 0, $len = strlen($name); $i < $len; $i++) {
            $c = $name[$i];
            if (preg_match('/[a-zA-Z0-9]/', $c)) { $initial = strtoupper($c); break; }
        }
    }
    if ($initial === '' || $initial === false) $initial = '?';
    return '<span class="wt-avatar__initial">' . htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') . '</span>';
}
