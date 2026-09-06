<?php
/**
 * Wintaskly — /api  (via .htaccess → api/shortlink_local_api.php)
 *
 * Crée un lien court maison. Répond dans le format que le système
 * utilise déjà pour exe.io, shrinkme.io et compatibles, de sorte que
 * wt_shortlink_create_via_api() n'ait rien à apprendre :
 *
 *   GET /api?api=<cle>&url=<destination>&format=json
 *   → { "status": "success", "shortenedUrl": "https://…/aB3xY9kL2p" }
 *
 * En cas d'échec, on renvoie la forme d'erreur du même protocole plutôt
 * qu'un code HTTP d'erreur : l'appelant lit `status`, et un 500 le
 * ferait basculer sur un chemin de repli qui n'a pas lieu d'être ici.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

header('Content-Type: application/json; charset=utf-8');
/* Aucune mise en cache : chaque appel doit produire un code neuf. */
header('Cache-Control: no-store');

/** Réponse au format attendu, puis sortie. */
function wt_api_out(bool $ok, string $payload): void
{
    echo json_encode(
        $ok ? ['status' => 'success', 'shortenedUrl' => $payload]
            : ['status' => 'error',   'message'      => $payload],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

$apiKey = (string) ($_GET['api'] ?? $_POST['api'] ?? '');
$destUrl = (string) ($_GET['url'] ?? $_POST['url'] ?? '');

if ($apiKey === '') { wt_api_out(false, 'missing api key'); }
if ($destUrl === '') { wt_api_out(false, 'missing url'); }

$local = wt_sl_local_by_key($apiKey);
if ($local === null) {
    /* Même message pour une clé inconnue et une clé désactivée : la
       distinction n'apprendrait rien d'utile à un appelant légitime, et
       renseignerait un attaquant sur l'existence de la clé. */
    wt_api_out(false, 'invalid api key');
}

/* La destination doit rester chez nous. Sans ce contrôle, quiconque
   obtient la clé transforme le site en redirecteur ouvert : des liens
   en wintaskly.com menant n'importe où, et la réputation du domaine
   avec eux. */
$destHost = parse_url($destUrl, PHP_URL_HOST);
$ourHost  = parse_url((string) ($GLOBALS['WT_CONFIG']['base_url'] ?? ''), PHP_URL_HOST);
if (!$destHost || !$ourHost || strcasecmp($destHost, $ourHost) !== 0) {
    wt_api_out(false, 'destination must stay on this domain');
}

$shortUrl = wt_sl_run_create($local, $destUrl);
if ($shortUrl === null) { wt_api_out(false, 'could not create link'); }

wt_api_out(true, $shortUrl);
