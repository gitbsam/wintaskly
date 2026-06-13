<?php
/**
 * API : démarrage d'une session Faucet (étape 1 → étape 2).
 *
 * Garanties :
 *   - Utilisateur connecté (sinon 401).
 *   - Pas de réclamation valide dans les 3 dernières heures (cooldown).
 *   - Aucune session "open" récente ne reste : on les force à "expired" si > 5 min.
 *   - Génère un jeton SHA-256 lié à l'utilisateur, à son IP et à son UA.
 *   - Pré-calcule l'icône cible du captcha et l'ordre d'affichage.
 *   - Stocke step1_at = NOW(), expires_at = NOW() + 5 min (paramétrable).
 *   - Renvoie la prochaine URL avec le token en query string.
 */

declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    wt_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}
// Standard projet : '_csrf'. Fallback 'csrf' pour rétro-compat clients en cache.
if (!csrf_check($_POST['_csrf'] ?? $_POST['csrf'] ?? null)) {
    wt_json(['ok' => false, 'message' => 'CSRF invalide'], 403);
}

$user = current_user();
if (!$user) {
    wt_json(['ok' => false, 'message' => 'Auth requise', 'redirect' => wt_url('/auth/login.php')], 401);
}

$db = db();

// ---- 1) Cooldown 3h : a-t-il déjà réclamé récemment ? ---------------
$cooldownSec = (int)cfg('faucet_cooldown_seconds', 10800);
$stmt = $db->prepare(
    "SELECT next_claim_at FROM faucet_claims
      WHERE user_id = ?
      ORDER BY id DESC LIMIT 1"
);
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($row && strtotime($row['next_claim_at']) > time()) {
    wt_json([
        'ok'      => false,
        'message' => t('faucet.next_in'),
        'next_at' => gmdate('c', strtotime($row['next_claim_at'])),
    ], 429);
}

// ---- 2) Nettoyer les sessions expirées de cet utilisateur ----------
$stmt = $db->prepare(
    "UPDATE faucet_sessions
        SET status = 'expired'
      WHERE user_id = ?
        AND status = 'open'
        AND expires_at < NOW()"
);
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$stmt->close();

// ---- 3) Choix aléatoire de l'icône cible + 5 icônes -----------------
$stmt = $db->prepare("SELECT id, slug FROM captcha_icons WHERE active = 1 ORDER BY RAND() LIMIT 5");
$stmt->execute();
$pool = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (count($pool) < 5) {
    wt_json(['ok' => false, 'message' => 'Captcha mal configuré (admin).'], 500);
}
$targetSlug = $pool[random_int(0, count($pool) - 1)]['slug'];
$order      = array_column($pool, 'slug');

// ---- 4) Génération du jeton + insertion en base --------------------
$ttl       = (int)cfg('faucet_session_ttl_seconds', 300);
$token     = hash('sha256', random_bytes(32) . $user['id'] . microtime(true));
$ipBin     = wt_ip_bin();
$ua        = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
$orderJson = json_encode($order, JSON_UNESCAPED_UNICODE);
$step1     = gmdate('Y-m-d H:i:s');
$expires   = gmdate('Y-m-d H:i:s', time() + $ttl);

$stmt = $db->prepare(
    "INSERT INTO faucet_sessions
       (user_id, token, step1_at, expires_at, captcha_target, captcha_order, ip, user_agent)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param(
    'isssssss',
    $user['id'], $token, $step1, $expires, $targetSlug, $orderJson, $ipBin, $ua
);
$stmt->execute();
$stmt->close();

// ---- 5) Réponse ----------------------------------------------------
wt_json([
    'ok'         => true,
    'token'      => $token,
    'expires_at' => $expires,
    'next'       => wt_url('/tasks/faucet/transition.php?t=' . urlencode($token)),
]);
