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

/* La clé de callback est nécessaire dans LES DEUX modes : elle authentifie
   le retour. Elle était déchiffrée uniquement dans la branche API, ce qui
   laissait le mode manuel sans clé — donc sans validation possible. */
$cbKeyPlain = function_exists('wt_decrypt')
            ? wt_decrypt((string) $row['callback_key'])
            : (string) $row['callback_key'];

if ($row['mode'] === 'api'
    && !empty($row['api_endpoint'])
    && !empty($row['api_token'])
    && !empty($row['callback_key'])) {

    // Secret d'API chiffré en base → déchiffrement avant usage
    $apiTokenPlain = function_exists('wt_decrypt') ? wt_decrypt((string) $row['api_token']) : (string) $row['api_token'];

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
    /* ---- Mode manuel -------------------------------------------------
     *
     * ⚠️ CE BLOC ÉTAIT CASSÉ, de deux façons cumulées.
     *
     * Il ajoutait `wt=TOKEN` à la fin de l'URL du raccourcisseur. Or :
     *
     *   1. Le callback attend `token` et `key` — pas `wt`, qui n'est lu
     *      nulle part. Même arrivé à destination, il n'aurait rien validé.
     *
     *   2. Surtout, le paramètre était collé à l'URL EXTERNE. Avec une
     *      destination du type `https://ouo.io/qs/xxx?s=<notre-callback>`,
     *      le `&wt=` devenait un paramètre de ouo.io, jamais transmis à
     *      notre callback. L'utilisateur atterrissait donc sur
     *      `shortlink_callback.php` sans aucun paramètre — d'où la page 404
     *      ou le message « Paramètres manquants ».
     *
     * LA CORRECTION
     * Le jeton doit voyager DANS l'URL de destination, encodée comme
     * valeur de paramètre du raccourcisseur. Deux façons de l'obtenir :
     *
     *   • {CALLBACK} — l'administrateur place ce marqueur là où le
     *     raccourcisseur attend l'adresse de retour. C'est la méthode
     *     explicite, et la seule qui fonctionne avec tous les services.
     *
     *   • Détection automatique — si la destination contient déjà notre
     *     propre adresse de callback (cas des liens créés avant cette
     *     correction), on y injecte les paramètres au bon endroit plutôt
     *     que d'exiger une réécriture manuelle de chaque lien.
     * ------------------------------------------------------------------ */
    $dest = (string) $row['destination_url'];

    $cbBase   = wt_url('/api/shortlink_callback.php');
    $cbFull   = $cbBase . '?token=' . urlencode($token)
                        . '&key=' . urlencode($cbKeyPlain);

    if (strpos($dest, '{CALLBACK}') !== false) {
        /* Le marqueur est remplacé par l'adresse ENCODÉE : elle devient la
           valeur d'un paramètre, ses propres `?` et `&` ne doivent donc pas
           être interprétés par le raccourcisseur. */
        $finalUrl = str_replace('{CALLBACK}', urlencode($cbFull), $dest);

    } elseif (strpos($dest, $cbBase) !== false) {
        /* Notre callback est déjà présent en clair dans la destination :
           on le remplace par sa version complète et encodée. */
        $finalUrl = str_replace($cbBase, urlencode($cbFull), $dest);

    } else {
        /* Aucun point d'ancrage : le raccourcisseur ne saura pas où nous
           renvoyer, et aucun gain ne pourra être validé. On le signale
           plutôt que de produire un lien qui semble fonctionner mais ne
           crédite jamais — c'est exactement le genre de panne qui remonte
           en tickets de support des semaines plus tard. */
        error_log('[Wintaskly shortlink] destination sans {CALLBACK} : id=' . (int) $row['sl_id']);
        wt_json(['ok' => false, 'error' => 'no_callback_marker'], 502);
    }
}

if ($finalUrl === '') {
    wt_json(['ok' => false, 'error' => 'no_url'], 502);
}

wt_json(['ok' => true, 'url' => $finalUrl]);
