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
if (!function_exists('wt_safe_notif_url')) {
    /**
     * Valide une URL destinée à une notification/message avant stockage.
     * Défense en profondeur contre le XSS : même si toutes les URLs sont
     * aujourd'hui internes (wt_url), on garantit à la source qu'aucun schéma
     * dangereux (javascript:, data:, vbscript:...) ne pourra être stocké
     * puis rendu dans un href.
     *
     * Règle : on accepte les URLs relatives (commençant par "/") et les
     * URLs http(s) absolues. Tout le reste est rejeté (→ null).
     *
     * @param  string|null $url  URL candidate
     * @return string|null  URL si sûre, sinon null
     */
    function wt_safe_notif_url(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        // URL relative interne (cas normal : wt_url produit "/dashboard/...")
        if ($url[0] === '/' && (strlen($url) === 1 || $url[1] !== '/')) {
            return $url;
        }
        // URL absolue : uniquement http(s)
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== null && in_array(strtolower($scheme), ['http', 'https'], true)) {
            return $url;
        }
        // Tout le reste (javascript:, data:, //evil.com, schéma inconnu) → rejeté
        error_log('[Wintaskly wt_notify] URL rejetée (schéma non sûr): ' . substr($url, 0, 80));
        return null;
    }
}

function wt_notify(
    int    $userId,
    string $type,
    string $title,
    ?string $body = null,
    ?string $url  = null
): int {
    // Défense en profondeur : neutralise toute URL au schéma dangereux
    $url = wt_safe_notif_url($url);
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

/**
 * Diffuse un message à une liste d'utilisateurs de façon performante.
 * ----------------------------------------------------------------------
 * Au lieu d'une boucle qui ferait 4 requêtes par destinataire (timeout
 * garanti sur grosse base), on insère en MASSE par lots : un INSERT
 * multi-valeurs pour les messages, un autre pour les notifications.
 * Pour 5000 utilisateurs : ~10 requêtes au lieu de ~20000.
 *
 * @param  int[]  $userIds  Liste d'IDs destinataires
 * @param  string $subject  Sujet du message (= titre de la notif)
 * @param  string $body     Corps du message
 * @param  string $url      URL de la notification (page messages)
 * @param  int    $chunk    Taille des lots (défaut 500)
 * @return int    Nombre de destinataires traités
 */
function wt_broadcast_message(
    array $userIds,
    string $subject,
    string $body,
    string $url = '',
    int $chunk = 500
): int {
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    if (!$userIds) {
        return 0;
    }

    $db          = db();
    $msgTtl      = (int) cfg('ttl.message_unread_days', '90');
    $notifTtl    = (int) cfg('ttl.notif_unread_days', '90');
    $safeUrl     = wt_safe_notif_url($url !== '' ? $url : null);
    $senderRole  = 'admin';
    $notifType   = 'admin_message';
    $sent        = 0;

    foreach (array_chunk($userIds, max(1, $chunk)) as $batch) {
        // --- Insertion groupée des messages ---
        $placeholders = [];
        $params       = [];
        $types        = '';
        foreach ($batch as $uid) {
            $placeholders[] = "(?, ?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? DAY)";
            $params[] = $uid; $params[] = $senderRole; $params[] = $subject;
            $params[] = $body; $params[] = $msgTtl;
            $types   .= 'isssi';
        }
        $sql  = "INSERT INTO messages (user_id, sender_role, subject, body, expires_at) VALUES "
              . implode(', ', $placeholders);
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();

        // --- Insertion groupée des notifications ---
        $placeholders = [];
        $params       = [];
        $types        = '';
        foreach ($batch as $uid) {
            $placeholders[] = "(?, ?, ?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? DAY)";
            $params[] = $uid; $params[] = $notifType; $params[] = $subject;
            $params[] = null; $params[] = $safeUrl; $params[] = $notifTtl;
            $types   .= 'issssi';
        }
        $sql  = "INSERT INTO notifications (user_id, type, title, body, url, expires_at) VALUES "
              . implode(', ', $placeholders);
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();

        $sent += count($batch);
    }

    return $sent;
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
