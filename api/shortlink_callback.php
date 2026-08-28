<?php
/**
 * Wintaskly — API callback shortlinks (mode HYBRIDE).
 *
 * URL à donner au provider :
 *   https://wintaskly.com/api/shortlink_callback.php?token=XXX&key=YYY
 *     - token : token unique généré au clic (table shortlink_attempts)
 *     - key   : clé partagée stockée dans shortlinks.callback_key
 *
 * Cette URL peut être appelée de DEUX façons :
 *
 *   1. REDIRECT — l'utilisateur lui-même arrive ici avec son navigateur
 *      après avoir complété le shortlink (cas le plus courant, ex: exe.io).
 *      On crédite + on le redirige vers une jolie page de succès.
 *
 *   2. POSTBACK — le provider appelle cette URL côté serveur (server-to-server)
 *      pour confirmer la complétion (cas plus avancé, ex: Linkvertise).
 *      On crédite + on retourne du JSON {"ok": true}.
 *
 * Détection : si l'User-Agent ressemble à un navigateur ET que la requête
 * accepte du HTML → mode REDIRECT. Sinon → mode POSTBACK (JSON).
 *
 * Idempotence : grâce à `status='en_attente'` + UPDATE atomique avec check
 * affected_rows, un même token ne peut JAMAIS être crédité deux fois, même
 * si redirect ET postback arrivent (race condition impossible).
 *
 * Sécurité :
 *   - Validation `callback_key` via hash_equals (timing-safe)
 *   - Token aléatoire 64 chars (256 bits d'entropie)
 *   - Idempotence par contrainte SQL (status enum + affected_rows)
 *   - Audit log dans coin_transactions via award_user()
 */

declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

$token = trim((string)($_GET['token'] ?? ''));
$key   = trim((string)($_GET['key']   ?? ''));

// =============================================================================
// Détection du mode (redirect navigateur vs postback serveur)
// =============================================================================
$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
$isBrowser = (
    stripos($ua, 'Mozilla') !== false
    || stripos($ua, 'Chrome') !== false
    || stripos($ua, 'Safari') !== false
    || stripos($accept, 'text/html') !== false
);

/**
 * Helper de réponse adapté au mode.
 *
 * @param bool   $ok           Succès ou erreur
 * @param string $messageKey   Clé message (i18n en mode redirect)
 * @param int    $http         Code HTTP en mode postback
 * @param array  $params       Paramètres URL supplémentaires en mode redirect
 */
$respond = static function (bool $ok, string $messageKey, int $http = 200, array $params = []) use ($isBrowser): void {
    if ($isBrowser) {
        // Mode redirect : renvoie l'utilisateur vers la page shortlinks avec
        // un paramètre ?success= ou ?error= pour afficher un toast.
        $base = function_exists('wt_url') ? wt_url('/tasks/shortlinks/') : '/tasks/shortlinks/';
        $qs   = $ok ? 'success=1' : 'error=1';
        $qs  .= '&msg=' . urlencode($messageKey);
        foreach ($params as $k => $v) {
            $qs .= '&' . urlencode($k) . '=' . urlencode((string)$v);
        }
        header('Location: ' . $base . '?' . $qs, true, 302);
        exit;
    }
    // Mode postback : JSON propre
    wt_json(['ok' => $ok, 'message' => $messageKey] + $params, $http);
};

// =============================================================================
// Validation paramètres
// =============================================================================
if ($token === '' || $key === '') {
    $respond(false, 'missing_params', 400);
}

$db = db();

// =============================================================================
// Récupération tentative + lien
// =============================================================================
$stmt = $db->prepare(
    "SELECT a.id, a.user_id, a.shortlink_id, a.status,
            TIMESTAMPDIFF(SECOND, a.started_at, NOW()) AS elapsed,
            s.callback_key, s.reward_coins, s.reward_xp, s.cooldown_hours,
            s.gateway_seconds, s.name
       FROM shortlink_attempts a
       JOIN shortlinks s ON s.id = a.shortlink_id
      WHERE a.token = ?
      LIMIT 1"
);
$stmt->bind_param('s', $token);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    $respond(false, 'token_unknown', 404);
}
// Déchiffrement de la clé stockée (rétrocompatible : clair lu tel quel)
$cbKeyStored = function_exists('wt_decrypt') ? wt_decrypt((string)$row['callback_key']) : (string)$row['callback_key'];
if (!hash_equals($cbKeyStored, $key)) {
    error_log('[Wintaskly shortlink_callback] invalid key for token ' . substr($token, 0, 12) . '...');
    $respond(false, 'invalid_key', 403);
}

/* ---- Délai minimal ----------------------------------------------------
 *
 * ⚠️ POURQUOI CE CONTRÔLE EST LA VRAIE PROTECTION
 *
 * La clé de callback n'est PAS un secret vis-à-vis du membre : elle figure
 * dans l'adresse sur laquelle il atterrit, donc dans sa barre d'adresse et
 * dans son historique. Il lui suffit de la lire une fois.
 *
 * Ensuite, rien ne l'empêcherait d'ouvrir la passerelle pour obtenir un
 * nouveau jeton, puis d'appeler directement ce callback avec ce jeton et
 * la clé — sans jamais traverser le raccourcisseur ni voir la moindre
 * publicité. Le gain serait crédité, et l'annonceur n'aurait rien payé.
 *
 * La clé protège donc contre un tiers qui n'a jamais utilisé le lien, pas
 * contre le membre lui-même. Ce qui protège contre lui, c'est le TEMPS :
 * un parcours réel impose l'attente de la passerelle, puis celle du
 * raccourcisseur. Un retour deux secondes après la génération du jeton est
 * matériellement impossible.
 *
 * Le seuil reste volontairement bas — on cherche à écarter l'appel direct,
 * pas à punir une connexion rapide. */
$minSeconds = max(3, (int) ($row['gateway_seconds'] ?? 5))
            + max(0, (int) cfg('shortlinks.min_provider_seconds', 5));

if ((int) $row['elapsed'] < $minSeconds) {
    error_log(sprintf(
        '[Wintaskly shortlink_callback] retour trop rapide : %ds < %ds requis '
        . '(token %s…, user %d) — appel direct probable',
        (int) $row['elapsed'], $minSeconds, substr($token, 0, 12), (int) $row['user_id']
    ));
    $respond(false, 'too_fast', 403);
}

// Si déjà traité : pas une erreur fatale (l'user vient de voir crédit).
// On affiche juste "déjà traité" pour info, sans recréditer.
if ($row['status'] !== 'en_attente') {
    $respond(true, 'already_processed', 200, ['name' => (string)$row['name']]);
}

// =============================================================================
// Validation atomique (idempotence garantie par affected_rows)
// =============================================================================
$stmt = $db->prepare(
    "UPDATE shortlink_attempts
        SET status='valide', completed_at = NOW()
      WHERE id = ? AND status = 'en_attente'"
);
$stmt->bind_param('i', $row['id']);
$stmt->execute();
$updated = $stmt->affected_rows;
$stmt->close();

if ($updated !== 1) {
    // Une autre requête vient de créditer (race redirect/postback).
    // On NE crédite PAS deux fois.
    $respond(true, 'already_processed', 200, ['name' => (string)$row['name']]);
}

// =============================================================================
// Crédit utilisateur + XP + commission parrainage (10% via award_user)
// =============================================================================
$result = award_user(
    (int)$row['user_id'],
    (float)$row['reward_coins'],
    (int)$row['reward_xp'],
    'shortlink',
    'attempt:' . $row['id']
);

// =============================================================================
// Cooldown 24h (ou autre selon config du lien)
// =============================================================================
$availableAt = gmdate('Y-m-d H:i:s', time() + ((int)$row['cooldown_hours'] * 3600));
$stmt = $db->prepare(
    "INSERT INTO shortlink_cooldowns (user_id, shortlink_id, available_at)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE available_at = VALUES(available_at)"
);
$stmt->bind_param('iis', $row['user_id'], $row['shortlink_id'], $availableAt);
$stmt->execute();
$stmt->close();

// =============================================================================
// Réponse finale (redirect avec coins gagnés, ou JSON)
// =============================================================================
$respond(true, 'credited', 200, [
    'credited' => (float)$row['reward_coins'],
    'xp'       => (int)$row['reward_xp'],
    'name'     => (string)$row['name'],
]);
