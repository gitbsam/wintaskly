<?php
/**
 * Wintaskly — router.php
 *
 * Routeur de développement pour le serveur intégré PHP.
 *
 * Usage :
 *   php -S localhost:8000 router.php
 *
 * Le serveur built-in PHP (-S) ne lit PAS .htaccess. Sans ce routeur, les
 * URLs "pretty" comme /help/contact-track/<token> renvoient un 404 car
 * aucun fichier n'existe à cet emplacement.
 *
 * Ce script :
 *   1. Sert directement les fichiers statiques existants (.css, .js,
 *      images, polices) tels quels.
 *   2. Intercepte les pretty URLs (ex. /help/contact-track/<token>) et
 *      les dispatch vers le bon index.php avec les query params.
 *   3. Pour tout le reste, laisse le serveur built-in router naturellement.
 *
 * En production Apache, ce fichier est inutile : .htaccess gère tout.
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

/* ---------------------------------------------------------------------
 * 1) Sécurité : on refuse l'accès direct aux dossiers sensibles
 * --------------------------------------------------------------------- */
if (preg_match('#^/(includes|sql|node_modules|logs)(/|$)#', $uri)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}
if (preg_match('#^/config\.php$#', $uri)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

/* ---------------------------------------------------------------------
 * 2) Pretty URL : /help/contact-track/<token>
 *    On simule la règle de rewrite .htaccess :
 *      RewriteRule ^help/contact-track/([a-f0-9]{32,64})/?$
 *                  help/contact-track/index.php?token=$1
 * --------------------------------------------------------------------- */
if (preg_match('#^/help/contact-track/([a-f0-9]{32,64})/?$#i', $uri, $m)) {
    $_GET['token']           = $m[1];
    $_SERVER['SCRIPT_NAME']  = '/help/contact-track/index.php';
    $_SERVER['PHP_SELF']     = '/help/contact-track/index.php';
    require __DIR__ . '/help/contact-track/index.php';
    return true;
}

/* ---------------------------------------------------------------------
 * 3) Fichiers statiques : laisser le serveur built-in les servir
 *    (retour de `false` = le serveur sert le fichier réel s'il existe).
 * --------------------------------------------------------------------- */
$file = __DIR__ . $uri;
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    return false;
}

/* ---------------------------------------------------------------------
 * 4) DirectoryIndex implicite : /foo/ → /foo/index.php
 *    Apache le fait automatiquement, le built-in aussi pour /foo/ s'il
 *    trouve index.php, donc on retombe sur le comportement par défaut.
 * --------------------------------------------------------------------- */
return false;
