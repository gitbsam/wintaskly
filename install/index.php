<?php
/**
 * Wintaskly — Installeur (entry point du wizard).
 *
 * URL : /install/
 * Étapes : ?step=1..5 (défaut = 1).
 *
 * Le wizard utilise une session PHP dédiée (nom WT_INSTALL) pour
 * conserver les valeurs saisies entre les étapes. À l'étape 5, on
 * exécute tout (CREATE DB + schema + migrations + seed + admin
 * + config.php + .installed.lock).
 *
 * SÉCURITÉ :
 *   - Si .installed.lock existe : refus catégorique d'aller plus loin.
 *   - On utilise une session séparée du système principal pour isoler.
 */

declare(strict_types=1);

// ----- Vérif anti-replay : refus si déjà installé -----
$rootPath = dirname(__DIR__);
if (is_file($rootPath . '/.installed.lock') && is_file($rootPath . '/config.php')) {
    // Installation déjà réalisée → on n'a rien à faire ici
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
      <meta charset="UTF-8">
      <title>Wintaskly · Déjà installé</title>
      <style>
        body { font-family: sans-serif; background: #0a0e1a; color: #e8eaf0;
               display: flex; align-items: center; justify-content: center;
               min-height: 100vh; margin: 0; padding: 1rem; text-align: center; }
        .box { max-width: 480px; padding: 2rem; background: #131829;
               border: 1px solid #2a3252; border-radius: 16px; }
        h1 { margin-top: 0; color: #ff9933; }
        code { background: rgba(255,255,255,.1); padding: 2px 6px; border-radius: 4px; font-size: .9em; }
      </style>
    </head>
    <body>
      <div class="box">
        <h1>✅ Wintaskly est déjà installé</h1>
        <p>L'installeur a été désactivé pour des raisons de sécurité.</p>
        <p style="color: #a4abc4; font-size: .9rem;">
          Pour réinstaller, supprimez manuellement les deux fichiers :<br>
          <code>config.php</code> et <code>.installed.lock</code>
        </p>
        <p><a href="../" style="color: #ff9933;">→ Retour au site</a></p>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// ----- Démarrage session dédiée installeur -----
ini_set('session.use_strict_mode', '1');
session_name('WT_INSTALL');
session_start();

// ----- Inclusion du module installeur -----
require __DIR__ . '/_installer.php';

// ----- Routing par étape -----
$step = (int)($_GET['step'] ?? 1);
$step = max(1, min(5, $step));

// Si on arrive sur step 1 ET que l'install n'est pas réellement faite
// (fichiers config.php/.installed.lock absents sur disque), on RESET la
// session pour repartir d'un état propre. Sinon, une session traînante
// d'un test précédent peut te projeter directement à step 5 avec "déjà
// fait" alors qu'en réalité il faut tout refaire.
if ($step === 1) {
    $rootPath = dirname(__DIR__);
    $alreadyDone = is_file($rootPath . '/config.php') && is_file($rootPath . '/.installed.lock');
    if (!$alreadyDone && !empty($_SESSION['wt_install'])) {
        // Garde uniquement les valeurs pré-remplies sympas (db host, site_name)
        // mais oublie l'état (checks_ok, done) pour forcer une vraie nouvelle install.
        $previousDb   = $_SESSION['wt_install']['db']   ?? null;
        $previousSite = $_SESSION['wt_install']['site'] ?? null;
        $_SESSION['wt_install'] = [];
        if ($previousDb)   $_SESSION['wt_install']['db_prefill']   = $previousDb;
        if ($previousSite) $_SESSION['wt_install']['site_prefill'] = $previousSite;
    }
}

// On bloque l'accès aux étapes > 1 si les checks initiaux n'ont pas été validés
// (sauf si déjà fait : présence de la marker `wt_install_checks_ok` en session)
if ($step > 1 && empty($_SESSION['wt_install']['checks_ok'])) {
    header('Location: ?step=1');
    exit;
}
if ($step > 2 && empty($_SESSION['wt_install']['db'])) {
    header('Location: ?step=2');
    exit;
}
if ($step > 3 && empty($_SESSION['wt_install']['site'])) {
    header('Location: ?step=3');
    exit;
}
if ($step > 4 && empty($_SESSION['wt_install']['admin'])) {
    header('Location: ?step=4');
    exit;
}

// Inclusion de l'étape demandée
$stepFile = __DIR__ . '/_steps/' . $step . '_*.php';
$files = glob($stepFile);
if (!$files || !is_file($files[0])) {
    http_response_code(404);
    echo 'Étape introuvable';
    exit;
}

require $files[0];
