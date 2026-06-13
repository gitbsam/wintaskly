<?php
/**
 * Wintaskly — Fichier de configuration EXEMPLE
 *
 * Ce fichier sert de modèle. NE LE MODIFIE PAS DIRECTEMENT EN PROD.
 * L'installeur (/install/) génère un config.php avec tes vraies valeurs.
 *
 * Si tu dois éditer manuellement en prod : copie ce fichier en config.php
 * puis remplis les valeurs marquées << ... >>.
 *
 * ───────────────────────────────────────────────────────────────────────
 * PHILOSOPHIE :
 *   - Les paramètres TECHNIQUES (BDD, secrets) vivent ici
 *   - Les paramètres ÉDITORIAUX (SMTP, SEO, économie) vivent dans la
 *     table `config` et sont modifiables via /admin/settings.php
 *     → pas besoin de re-deployer pour changer un SMTP
 * ───────────────────────────────────────────────────────────────────────
 */

// =====================================================================
// Détection automatique de l'environnement
// =====================================================================
$_host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_isLocal = (strpos($_host, 'localhost') !== false
          || strpos($_host, '127.0.0.1') !== false
          || strpos($_host, '.local')    !== false
          || strpos($_host, '.test')     !== false);

// HTTPS détecté : direct OU via proxy (Cloudflare, Varnish LWS, etc.)
$_isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
         || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
         || (($_SERVER['SERVER_PORT'] ?? '') == '443');

// URL de base : auto en local, FIGÉE en prod (anti Host-Header injection)
if ($_isLocal) {
    $_baseUrl = ($_isHttps ? 'https' : 'http') . '://' . $_host;
} else {
    // ⚠️ EN PROD : remplace par TON vrai domaine final
    $_baseUrl = 'https://wintaskly.com';
}

return [

    // =================================================================
    // 1. BASE DE DONNÉES
    // =================================================================
    'db' => [
        // En local : MySQL/MariaDB local (XAMPP, MAMP, Laragon…)
        // En prod LWS : socket Unix (plus rapide que TCP)
        'host'    => $_isLocal ? '127.0.0.1' : 'localhost',
        'port'    => 3306,
        'socket'  => $_isLocal ? '' : '/var/run/mysqld/mysqld.sock',  // ⚠️ LWS : OBLIGATOIRE
        'name'    => $_isLocal ? 'wintaskly' : 'winta2810082',         // ⚠️ << ta BDD LWS >>
        'user'    => $_isLocal ? 'root' : 'winta2810082',              // ⚠️ << ton user LWS >>
        'pass'    => $_isLocal ? '' : 'pipouihul',                     // ⚠️ << ton password LWS >>
        'charset' => 'utf8mb4',
    ],

    // =================================================================
    // 2. URL ET SECRETS
    // =================================================================
    'base_url'   => $_baseUrl,
    'app_secret' => 'CHANGE_ME_TO_A_RANDOM_64_CHAR_STRING',  // ⚠️ génère un long token random

    // =================================================================
    // 3. COOKIES ET SESSIONS
    // =================================================================
    'cookie_secure'        => $_isHttps,   // auto : true si HTTPS, false sinon
    'cookie_domain'        => '',          // vide = utilise le domaine courant
    'cookie_lifetime_days' => 30,
    'session_name'         => 'WT_SESS',

    // =================================================================
    // 4. ENVIRONNEMENT
    // =================================================================
    'debug'       => $_isLocal,                          // true en local, false en prod
    'environment' => $_isLocal ? 'development' : 'production',

    // =================================================================
    // 5. LANGUES
    // =================================================================
    'default_lang'  => 'fr',
    'allowed_langs' => ['fr', 'en'],
    'default_theme' => 'dark',

    // =================================================================
    // 6. EMAIL — Configuration MINIMALE
    // =================================================================
    // Wintaskly utilise une logique 3 niveaux pour l'envoi :
    //   1. En PROD par défaut : mail() natif PHP (sendmail LWS, gratuit, OK)
    //   2. Si SMTP configuré via /admin/settings.php : SMTP (recommandé)
    //   3. En DEV local : journalise dans logs au lieu d'envoyer
    //
    // Cette config est juste un FALLBACK. Le vrai SMTP se configure via
    // l'admin (table `config`), modifiable sans redéploiement.
    'mail' => [
        'from'       => 'no-reply@wintaskly.com',
        'from_name'  => 'Wintaskly',
        'reply_to'   => 'support@wintaskly.com',

        // Driver par défaut selon environnement
        // 'mail'  → mail() natif PHP (prod LWS)
        // 'smtp'  → SMTP via /admin/settings.php
        // 'log'   → ne pas envoyer, juste logger (dev)
        'driver'     => $_isLocal ? 'log' : 'mail',
    ],

    // =================================================================
    // 7. SÉCURITÉ D'AUTHENTIFICATION
    // =================================================================
    'auth' => [
        'max_attempts_per_account' => 5,
        'max_attempts_per_ip'      => 15,
        'lockout_minutes'          => 15,
        'verify_email_ttl'         => 24 * 3600,
        'reset_password_ttl'       =>      3600,
        'remember_me_ttl'          => 60 * 24 * 3600,
    ],
];
