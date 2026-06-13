<?php
/**
 * Wintaskly — POST /api/admin_user_action.php
 *
 * API centralisée des actions admin sur un compte utilisateur.
 * Body : { _csrf, action, user_id, ... }
 *
 * Actions :
 *   • activate          → status='active'
 *   • suspend           → status='suspended'  (peut se reconnecter ?)
 *   • ban               → status='banned'     (compte gelé)
 *   • verify_email      → email_verified_at = NOW()
 *   • reset_totp        → totp_secret=NULL, totp_enabled=0
 *   • cancel_delete     → delete_requested_at=NULL
 *   • promote           → role='admin'
 *   • demote            → role='user'
 *   • hard_delete       → DELETE FROM users (FK CASCADE purge)
 *
 * Sécurités :
 *   - require_role('admin')
 *   - CSRF strict
 *   - whitelist d'actions
 *   - jamais d'action sur soi-même (anti-lock-out)
 *   - log dans table admin_actions (création à la volée si absente)
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') wt_json(['ok' => false, 'error' => 'method'], 405);
if (!csrf_check($_POST['_csrf'] ?? null))   wt_json(['ok' => false, 'error' => t('common.error')], 403);

$me  = current_user();
$db  = db();

$action = (string)($_POST['action']  ?? '');
$uid    = (int)   ($_POST['user_id'] ?? 0);

if ($uid <= 0) wt_json(['ok' => false, 'error' => 'invalid_user']);
if ($uid === (int)$me['id']) {
    wt_json(['ok' => false, 'error' => t('admin.users_cant_self')]);
}

$ALLOWED = [
    'activate', 'suspend', 'ban',
    'verify_email', 'reset_totp', 'cancel_delete',
    'promote', 'demote', 'hard_delete',
];
if (!in_array($action, $ALLOWED, true)) {
    wt_json(['ok' => false, 'error' => 'invalid_action']);
}

/* Vérifier que l'utilisateur cible existe */
$stmt = $db->prepare("SELECT id, username, email, role, status FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $uid);
$stmt->execute();
$target = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$target) wt_json(['ok' => false, 'error' => 'user_not_found'], 404);

/* Création paresseuse de la table de log */
$db->query(
    "CREATE TABLE IF NOT EXISTS `admin_actions` (
        `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `admin_id`   INT UNSIGNED NOT NULL,
        `target_id`  INT UNSIGNED NOT NULL,
        `action`     VARCHAR(32) NOT NULL,
        `meta`       VARCHAR(255) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_target` (`target_id`, `created_at`),
        KEY `idx_admin`  (`admin_id`,  `created_at`)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

/* ----- Exécution ----- */
$db->begin_transaction();
try {
    /*
     * Toutes les actions utilisent des prepared statements même si $uid est
     * déjà casté en (int) plus haut — défense en profondeur et cohérence
     * stricte avec le reste du codebase.
     */
    switch ($action) {
        case 'activate':
            $stmt = $db->prepare("UPDATE users SET status='active' WHERE id = ?");
            $stmt->bind_param('i', $uid); $stmt->execute(); $stmt->close();
            break;
        case 'suspend':
            $stmt = $db->prepare("UPDATE users SET status='suspended' WHERE id = ?");
            $stmt->bind_param('i', $uid); $stmt->execute(); $stmt->close();
            break;
        case 'ban':
            $stmt = $db->prepare("UPDATE users SET status='banned' WHERE id = ?");
            $stmt->bind_param('i', $uid); $stmt->execute(); $stmt->close();
            break;
        case 'verify_email':
            $stmt = $db->prepare("UPDATE users SET email_verified_at = UTC_TIMESTAMP() WHERE id = ?");
            $stmt->bind_param('i', $uid); $stmt->execute(); $stmt->close();
            break;
        case 'reset_totp':
            $stmt = $db->prepare("UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?");
            $stmt->bind_param('i', $uid); $stmt->execute(); $stmt->close();
            break;
        case 'cancel_delete':
            $stmt = $db->prepare("UPDATE users SET delete_requested_at = NULL, delete_token = NULL WHERE id = ?");
            $stmt->bind_param('i', $uid); $stmt->execute(); $stmt->close();
            break;
        case 'promote':
            $stmt = $db->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
            $stmt->bind_param('i', $uid); $stmt->execute(); $stmt->close();
            break;
        case 'demote':
            $stmt = $db->prepare("UPDATE users SET role = 'user' WHERE id = ?");
            $stmt->bind_param('i', $uid); $stmt->execute(); $stmt->close();
            break;
        case 'hard_delete':
            // FK ON DELETE CASCADE purgera transactions, faucet_*, etc.
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param('i', $uid); $stmt->execute(); $stmt->close();
            break;
    }

    $stmt = $db->prepare("INSERT INTO admin_actions (admin_id, target_id, action, meta) VALUES (?, ?, ?, ?)");
    $meta = $target['username'] . ' (' . $target['email'] . ')';
    $aid  = (int) $me['id'];
    $stmt->bind_param('iiss', $aid, $uid, $action, $meta);
    $stmt->execute();
    $stmt->close();

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    error_log('admin_user_action: ' . $e->getMessage());
    wt_json(['ok' => false, 'error' => t('common.error')], 500);
}

/* Notification éventuelle à la cible (sauf pour hard_delete, target n'existe plus) */
if ($action !== 'hard_delete') {
    $titleKey = 'admin.notif.' . $action;
    wt_notify(
        $uid,
        'security',
        (string) t($titleKey),
        (string) t($titleKey . '_body')
    );
}

wt_json([
    'ok'      => true,
    'message' => (string) t('admin.action_done'),
    'reload'  => true,
]);
