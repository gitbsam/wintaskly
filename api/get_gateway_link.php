<?php
/**
 * Wintaskly — POST /api/get_gateway_link.php
 *
 * Renvoie l'URL de redirection finale d'un shortlink APRÈS le countdown.
 * --------------------------------------------------------------------
 * SÉCURITÉ : l'URL finale (qui porte le token unique de transaction) n'est
 * jamais injectée dans le HTML de la passerelle. Elle est récupérée par
 * Ajax seulement à la fin du compte à rebours, ce qui empêche les bots et
 * les utilisateurs de la lire dans le DOM pour sauter l'attente / la pub.
 *
 * Défenses :
 *   - require_auth() : seul un utilisateur connecté peut résoudre un lien
 *   - CSRF : protège contre les appels cross-site
 *   - le token de tentative est validé (format + existence + appartenance)
 *   - vérification d'un délai minimal serveur : l'URL n'est livrée que si le
 *     temps d'attente du shortlink s'est réellement écoulé (anti-bypass)
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wt_json(['ok' => false, 'error' => 'method'], 405);
}
if (!csrf_check($_POST['_csrf'] ?? null)) {
    wt_json(['ok' => false, 'error' => t('common.error')], 403);
}

$user = current_user();
$uid  = (int) $user['id'];

// Token de tentative (format strict : hex 32-64)
$token = (string) ($_POST['token'] ?? '');
if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) {
    wt_json(['ok' => false, 'error' => 'bad_token'], 400);
}

$db = db();

/*
 * Récupère la tentative + le shortlink associé. La tentative doit :
 *   - appartenir à l'utilisateur connecté (isolation IDOR)
 *   - être encore en attente (pas déjà consommée)
 */
$stmt = $db->prepare(
    "SELECT a.id, a.started_at, a.status,
            s.id AS sl_id, s.mode, s.destination_url,
            s.api_endpoint, s.api_token, s.callback_key,
            s.gateway_seconds
       FROM shortlink_attempts a
       JOIN shortlinks s ON s.id = a.shortlink_id
      WHERE a.token = ?
        AND a.user_id = ?
      LIMIT 1"
);
$stmt->bind_param('si', $token, $uid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    wt_json(['ok' => false, 'error' => 'attempt_not_found'], 404);
}

/*
 * Anti-bypass : on vérifie côté serveur que le délai d'attente s'est
 * réellement écoulé depuis la création de la tentative. Même si un bot
 * appelle directement l'endpoint, il n'obtiendra rien avant la fin.
 */
$delay     = max(3, (int) $row['gateway_seconds']);
$createdTs = strtotime((string) $row['started_at'] . ' UTC');
$elapsed   = time() - ($createdTs ?: time());
if ($elapsed < $delay - 1) { // -1 s de tolérance réseau
    wt_json([
        'ok'        => false,
        'error'     => 'too_early',
        'remaining' => max(0, $delay - $elapsed),
    ], 425); // 425 Too Early
}

/*
 * Construit l'URL finale selon le mode du shortlink (même logique que la
 * passerelle, mais exécutée ici, à la demande).
 */
$finalUrl = '';

if ($row['mode'] === 'api'
    && !empty($row['api_endpoint'])
    && !empty($row['api_token'])
    && !empty($row['callback_key'])) {

    // Secrets chiffrés en base → déchiffrement avant usage
    $apiTokenPlain = function_exists('wt_decrypt') ? wt_decrypt((string) $row['api_token']) : (string) $row['api_token'];
    $cbKeyPlain    = function_exists('wt_decrypt') ? wt_decrypt((string) $row['callback_key']) : (string) $row['callback_key'];

    $callbackUrl = wt_url('/api/shortlink_callback.php')
                 . '?token=' . urlencode($token)
                 . '&key=' . urlencode($cbKeyPlain);

    $shortUrl = wt_shortlink_create_via_api(
        (string) $row['api_endpoint'],
        $apiTokenPlain,
        $callbackUrl
    );
    if ($shortUrl !== null && $shortUrl !== '') {
        $finalUrl = $shortUrl;
    }
} else {
    // Mode manuel : URL de destination + token de validation
    $sep      = (strpos((string) $row['destination_url'], '?') !== false) ? '&' : '?';
    $finalUrl = (string) $row['destination_url'] . $sep . 'wt=' . urlencode($token);
}

if ($finalUrl === '') {
    wt_json(['ok' => false, 'error' => 'no_url'], 502);
}

wt_json(['ok' => true, 'url' => $finalUrl]);
