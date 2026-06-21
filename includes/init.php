<?php
/**
 * Wintaskly — Initialisation globale.
 *
 * À inclure en première ligne de chaque page :
 *   require __DIR__ . '/../includes/init.php';
 *
 * Responsabilités :
 *   - chargement de la configuration ;
 *   - démarrage de session sécurisée ;
 *   - détection i18n (langue) et thème ;
 *   - calage UTC côté serveur (datetimes en base = UTC strict) ;
 *   - exposition de la timezone client pour l'affichage uniquement ;
 *   - connexion DB et helpers (auth, csrf, t, e, …).
 */

declare(strict_types=1);

// ----------------------------------------------------------------------
// 0) Constantes globales — périodes temporelles standardisées
// ----------------------------------------------------------------------
// Centralisation des magic numbers temporels. Modifier ici impacte
// l'ensemble du projet de manière cohérente.
if (!defined('WT_PERIOD_DASHBOARD_DAYS')) {
    define('WT_PERIOD_DASHBOARD_DAYS', 7);     // Plage des KPI 'cette semaine'
    define('WT_PERIOD_COMPARISON_DAYS', 14);   // Plage pour comparer 7d vs 7d préc.
    define('WT_PERIOD_CHART_DAYS', 6);         // Chart 7 jours (J-6 inclus)
    define('WT_PERIOD_CRON_CLEAN_DAYS', 30);   // Purge des cron_runs/auth_attempts
    define('WT_PERIOD_TOKEN_CLEAN_DAYS', 7);   // Purge des tokens expirés
    define('WT_PERIOD_CRON_HEALTH_HOURS', 24); // Fenêtre de santé du cron dashboard
}

// ----------------------------------------------------------------------
// 0bis) Versionning Wintaskly
// ----------------------------------------------------------------------
// Source unique de vérité pour la version courante. Cette valeur est
// utilisée par :
//   - /api/cron.php (check de mise à jour vs latest.json distant)
//   - /admin/updates.php (affichage version courante)
//   - Le footer (affichage en mode admin uniquement)
//
// Format : SemVer (MAJOR.MINOR.PATCH)
//   MAJOR  → cassure de compat (refonte BDD, archi)
//   MINOR  → nouvelle fonctionnalité rétrocompatible
//   PATCH  → bugfix, sécurité
//
// L'URL latest.json est configurable via la BDD (clé config 'update.feed_url')
// pour permettre de changer de canal (stable/beta) sans redéployer.
if (!defined('WT_VERSION')) {
    define('WT_VERSION', '8.12.0');
    define('WT_VERSION_CHANNEL', 'stable');  // stable | beta | dev
    define('WT_UPDATE_FEED_DEFAULT', 'https://gitbsam.github.io/wintaskly/latest.json');
}

// ----------------------------------------------------------------------
// 1) Détection "système non installé" → redirection /install/
// ----------------------------------------------------------------------
// On vérifie deux conditions :
//   a) `config.php` doit exister (sinon : 1ère install)
//   b) `.installed.lock` doit exister (sinon : install incomplète)
// Si l'une manque ET qu'on n'est pas déjà dans /install/, on redirige.
// Stratégie "C" : double vérification (config + lock) pour éviter qu'une
// perte accidentelle de l'un des deux ne ré-ouvre l'installeur.
$wtConfigPath  = __DIR__ . '/../config.php';
$wtLockPath    = __DIR__ . '/../.installed.lock';
$wtNeedInstall = !is_file($wtConfigPath) || !is_file($wtLockPath);

// Détection de l'URI courante pour savoir si on est déjà sur /install/
$wtCurrentUri = (string)($_SERVER['REQUEST_URI'] ?? '');
$wtIsInstall  = (strpos($wtCurrentUri, '/install/') !== false
              || strpos($wtCurrentUri, '/install.php') !== false);

if ($wtNeedInstall && !$wtIsInstall) {
    // Calcul du base path pour rediriger correctement quel que soit
    // le sous-dossier d'installation (ex: /wintaskly/install/ chez LWS)
    $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $basePath   = str_replace('\\', '/', dirname($scriptName));
    // Remonte d'un cran si on est dans /admin/, /tasks/, etc.
    if (preg_match('#^(.+?)/(admin|api|auth|cron|dashboard|help|legal|tasks|testimonials|leaderboard)(/|$)#', $basePath, $m)) {
        $basePath = $m[1];
    }
    $basePath = rtrim($basePath, '/');
    header('Location: ' . $basePath . '/install/');
    exit;
}

// ----------------------------------------------------------------------
// 1.5) Fallback fatal error → page d'erreur custom (au lieu du LWS default)
// ----------------------------------------------------------------------
// Si quelque chose plante en cascade (config.php corrompu, BDD inaccessible,
// extension manquante…), on intercepte la fatal error pour servir une page
// HTML lisible au lieu du HTTP 500 brut (qui révèle le hosting LWS).
register_shutdown_function(static function () {
    $err = error_get_last();
    if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (headers_sent()) return;
    @http_response_code(500);
    @header('Content-Type: text/html; charset=UTF-8');
    error_log('[Wintaskly] Fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);

    // Si le fichier error-500.html existe, on le sert directement
    $errFile = __DIR__ . '/../error-500.html';
    if (is_file($errFile)) {
        readfile($errFile);
        return;
    }
    // Sinon page minimale inline
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Erreur · Wintaskly</title>'
       . '<style>body{font-family:sans-serif;background:#0a0e1a;color:#e8eaf0;text-align:center;padding:3rem 1rem}'
       . 'h1{color:#fca5a5}.btn{display:inline-block;margin-top:1rem;padding:.7rem 1.4rem;'
       . 'background:linear-gradient(135deg,#ff9933,#ffcc33);color:#0a0e1a;border-radius:10px;'
       . 'text-decoration:none;font-weight:700}</style></head><body>'
       . '<h1>⚠️ Erreur serveur</h1>'
       . '<p>Une erreur est survenue. Réessayez dans quelques instants.</p>'
       . '<a class="btn" href="/">🏠 Retour à l\'accueil</a>'
       . '</body></html>';
});

// ----------------------------------------------------------------------
// 2) Chargement configuration
// ----------------------------------------------------------------------
$configFile = __DIR__ . '/../config.php';
if (!is_file($configFile)) {
    $configFile = __DIR__ . '/../config.example.php';
}
$GLOBALS['WT_CONFIG'] = require $configFile;

// ----------------------------------------------------------------------
// 3) Sécurité PHP
// ----------------------------------------------------------------------
ini_set('display_errors', !empty($GLOBALS['WT_CONFIG']['debug']) ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Tout le moteur tourne en UTC. La datetime affichée est convertie ensuite.
date_default_timezone_set('UTC');

// En-têtes de sécurité de base
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

// =====================================================================
// Anti-cache pour les pages dynamiques (admin, auth, dashboard)
// =====================================================================
// IMPORTANT : sur LWS, Varnish 7.7 + lwscache cachent par défaut. Cela
// CASSE le CSRF sur l'admin (le user voit une vieille page avec un
// token expiré → POST refusé silencieusement, AUCUNE sauvegarde).
//
// On envoie des headers no-cache + bypass Varnish/lwscache spécifiques :
$_uri = $_SERVER['REQUEST_URI'] ?? '/';
$_isDynamic = (
    str_starts_with($_uri, '/admin')
    || str_starts_with($_uri, '/dashboard')
    || str_starts_with($_uri, '/auth')
    || str_starts_with($_uri, '/api')
);
if ($_isDynamic) {
    header('Cache-Control: no-cache, no-store, must-revalidate, private, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
    // Bypass Varnish / Nginx / LWS lwscache
    header('X-Accel-Expires: 0');
    header('Surrogate-Control: no-store');
    // Vary sur Cookie pour forcer Varnish à différencier par session
    header('Vary: Cookie');
}

// HSTS : force HTTPS pendant 1 an (active uniquement si la requête arrive en HTTPS,
// sinon ça n'a aucun sens et peut bloquer le dev local en HTTP).
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// Content-Security-Policy : protection XSS forte.
// On autorise :
//   - les scripts inline (used dans header.php pour le theme bootstrap)
//   - 'unsafe-inline' sur style aussi car Tailwind+CSS inline présents
//   - cdnjs et fonts.googleapis pour les fonts/icônes optionnelles
//   - les images depuis n'importe où en https (avatars hash-color etc.)
// Si tu veux durcir, retire 'unsafe-inline' et passe à des nonces.
header(
    "Content-Security-Policy: "
    . "default-src 'self'; "
    . "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
    . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; "
    . "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com data:; "
    . "img-src 'self' data: https:; "
    . "connect-src 'self'; "
    . "frame-src 'self' https:; "
    . "object-src 'none'; "
    . "base-uri 'self'; "
    . "form-action 'self'"
);

// ----------------------------------------------------------------------
// 3) Session sécurisée
// ----------------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookieSecure = !empty($GLOBALS['WT_CONFIG']['cookie_secure']);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => $GLOBALS['WT_CONFIG']['cookie_domain'] ?? '',
        'secure'   => $cookieSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('WT_SESS');
    session_start();
}

// ----------------------------------------------------------------------
// 3.5) Compression gzip de la sortie HTML
// ----------------------------------------------------------------------
// ob_gzhandler négocie automatiquement avec le client (Accept-Encoding).
// Si le client n'accepte pas gzip, ça reste plain.
// Ne s'active QUE si zlib est dispo et que Apache/Nginx ne compresse
// pas déjà (vérifié via la présence de mod_deflate dans .htaccess :
// dans ce cas, php passera quand même mais Apache écrasera notre header.
// L'ordre = sécurité, double compression évitée).
if (
    !ini_get('zlib.output_compression')          // pas déjà gzip natif
    && extension_loaded('zlib')                  // zlib dispo
    && strpos((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') !== false
) {
    ob_start('ob_gzhandler');
}

// ----------------------------------------------------------------------
// 4) Inclusions internes
// ----------------------------------------------------------------------
require __DIR__ . '/db.php';
require __DIR__ . '/i18n.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/auth_extra.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/mailer.php';
require __DIR__ . '/messaging.php';
require __DIR__ . '/leaderboard.php';
require __DIR__ . '/update.php';
require __DIR__ . '/daily_bonus.php';
require __DIR__ . '/achievements.php';
require __DIR__ . '/blog.php';
require __DIR__ . '/fraud.php';

// Si l'utilisateur n'a pas de session active mais possède un cookie
// « remember-me » valide, on connecte automatiquement.
auth_remember_check();

// TTL : nettoyage stochastique (probabilité configurable) des messages,
// notifications, jetons et tentatives expirés.
wt_ttl_maybe_cleanup();

// ----------------------------------------------------------------------
// 4bis) Middleware MAINTENANCE
// ----------------------------------------------------------------------
// Si le mode maintenance est activé (via /admin/updates.php), on retourne
// une page 503 à TOUS les visiteurs sauf :
//   - Les admins connectés (pour qu'ils puissent finaliser l'update)
//   - Les requêtes vers /admin/* et /api/admin_* (idem)
//   - Les requêtes vers /install/ (au cas où il faille réinstaller)
//
// Le but : pendant la maintenance, les utilisateurs ne peuvent pas
// effectuer d'actions risquées (claim faucet, withdraw, etc.) qui
// pourraient corrompre l'état pendant qu'on migre la BDD.
if (function_exists('wt_maintenance_on') && wt_maintenance_on()) {
    $reqPath = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    // On parse le path pour ignorer les query strings
    $reqPath = parse_url($reqPath, PHP_URL_PATH) ?: '/';

    $isAdminPath   = strpos($reqPath, '/admin/') === 0
                  || strpos($reqPath, '/api/admin_') === 0;
    $isInstallPath = strpos($reqPath, '/install/') === 0;

    // Détecte si user connecté ET admin. On regarde d'abord $_SESSION['role']
    // pour la perf (posé au login), puis on fallback sur une requête BDD au
    // cas où la session vient d'un ancien login avant le déploiement du
    // middleware (compat rétro).
    $isAdminUser = false;
    if (!empty($_SESSION['uid'])) {
        if (isset($_SESSION['role'])) {
            $isAdminUser = $_SESSION['role'] === 'admin';
        } else {
            // Fallback BDD (mis en cache en session pour les prochaines fois)
            try {
                $stmt = db()->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
                $uid = (int) $_SESSION['uid'];
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $_SESSION['role'] = (string) ($row['role'] ?? 'user');
                    $isAdminUser = $_SESSION['role'] === 'admin';
                }
            } catch (Throwable $e) {
                // Si la BDD est down (cas pas rare pendant la maintenance),
                // on n'a pas le rôle. On refuse l'accès aux non-admin par
                // précaution (l'admin avait sa session role déjà au login).
                $isAdminUser = false;
            }
        }
    }

    if (!$isAdminPath && !$isInstallPath && !$isAdminUser) {
        // Page maintenance affichée — pas de header.php (peut planter si
        // maintenance liée à une refonte du header). Tout est inline.
        $maintMsg = (string) cfg('update.maintenance_msg', '');
        if ($maintMsg === '') {
            $maintMsg = 'Wintaskly est en maintenance pour quelques minutes. Reviens très vite !';
        }
        http_response_code(503);
        header('Retry-After: 300');  // suggère 5 min aux moteurs de recherche
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Maintenance — Wintaskly</title>';
        echo '<style>body{font-family:system-ui,sans-serif;background:#0a0e1a;color:#e8eaf0;margin:0;padding:2rem;display:flex;min-height:100vh;align-items:center;justify-content:center}';
        echo '.box{max-width:480px;text-align:center;background:#131829;border:1px solid #2a3252;border-radius:16px;padding:2.5rem}';
        echo 'h1{color:#ff9933;margin:.5rem 0 1rem}p{line-height:1.6;opacity:.85}small{display:block;margin-top:1.5rem;opacity:.5}</style>';
        echo '</head><body><div class="box">';
        echo '<div style="font-size:3rem">🔧</div>';
        echo '<h1>Maintenance en cours</h1>';
        echo '<p>' . htmlspecialchars($maintMsg, ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<small>Wintaskly · ' . htmlspecialchars(WT_VERSION) . '</small>';
        echo '</div></body></html>';
        exit;
    }
}

// ----------------------------------------------------------------------
// 5) Langue
// ----------------------------------------------------------------------
$allowed = $GLOBALS['WT_CONFIG']['languages']        ?? ['fr', 'en'];
$default = $GLOBALS['WT_CONFIG']['default_language'] ?? 'fr';

$GLOBALS['WT_LANG_CODE'] = wt_detect_lang($allowed, $default);
$GLOBALS['WT_LANG']      = wt_load_lang($GLOBALS['WT_LANG_CODE']);

// Persiste la préférence côté utilisateur connecté
if (!empty($_SESSION['uid']) && !empty($_GET['lang'])) {
    $stmt = db()->prepare("UPDATE users SET lang = ? WHERE id = ?");
    $lang = $GLOBALS['WT_LANG_CODE'];
    $uid  = (int)$_SESSION['uid'];
    $stmt->bind_param('si', $lang, $uid);
    $stmt->execute();
    $stmt->close();
}

// ----------------------------------------------------------------------
// 6) Thème (light / dark) — cookie + override admin user
// ----------------------------------------------------------------------
$theme = $_COOKIE['wt_theme'] ?? null;
if (isset($_GET['theme']) && in_array($_GET['theme'], ['light', 'dark'], true)) {
    $theme = $_GET['theme'];
    setcookie('wt_theme', $theme, [
        'expires'  => time() + 60 * 60 * 24 * 365,
        'path'     => '/',
        'secure'   => !empty($GLOBALS['WT_CONFIG']['cookie_secure']),
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}
if (!in_array($theme, ['light', 'dark'], true)) {
    $theme = 'dark'; // thème par défaut : premium
}
$GLOBALS['WT_THEME'] = $theme;

// ----------------------------------------------------------------------
// 7) Timezone client (pour affichage uniquement)
//    Sauvegardée via api/set_timezone.php côté JS.
// ----------------------------------------------------------------------
$GLOBALS['WT_TIMEZONE'] = $_COOKIE['wt_tz'] ?? 'UTC';

// ----------------------------------------------------------------------
// 8) Helpers d'affichage
// ----------------------------------------------------------------------
if (!function_exists('wt_format_datetime')) {
    /**
     * Convertit une datetime UTC issue de la base vers la timezone client.
     */
    function wt_format_datetime(?string $utcDatetime, string $format = 'd/m/Y H:i'): string
    {
        if (!$utcDatetime) {
            return '—';
        }
        try {
            $dt = new DateTime($utcDatetime, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone($GLOBALS['WT_TIMEZONE'] ?? 'UTC'));
            return $dt->format($format);
        } catch (Throwable $e) {
            return $utcDatetime;
        }
    }
}

if (!function_exists('wt_format_coins')) {
    /**
     * Formate un montant de coins pour l'affichage : retire les zéros
     * décimaux inutiles (10.0000 → "10", 12.5000 → "12.5") et ajoute
     * un séparateur de milliers pour les grands nombres.
     *
     * @param  float $coins  Montant
     * @param  bool  $thousands  Ajouter un séparateur de milliers (espace)
     * @return string
     */
    function wt_format_coins(float $coins, bool $thousands = false): string
    {
        $s = rtrim(rtrim(number_format($coins, 4, '.', $thousands ? ' ' : ''), '0'), '.');
        return $s === '' ? '0' : $s;
    }
}

if (!function_exists('wt_json')) {
    function wt_json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// ----------------------------------------------------------------------
// 9) Check des bans actifs
// ----------------------------------------------------------------------
$_currentUid = !empty($_SESSION['uid']) ? (int)$_SESSION['uid'] : null;
if (is_banned($_currentUid)) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>403</title>'
       . '<style>body{font-family:system-ui;padding:3rem;text-align:center}</style>'
       . '<h1>Accès refusé</h1><p>Votre accès est suspendu pour activité suspecte.</p>';
    exit;
}
