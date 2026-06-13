<?php
/**
 * Wintaskly — Installeur · Étape 5 / 5 : Exécution (avec streaming).
 *
 * MODES :
 *   - GET / pas d'action → écran de RÉCAPITULATIF (récap des choix + bouton "Lancer")
 *   - POST + _action=install → STREAMING : chaque étape envoyée en live via flush()
 *
 * Streaming = HTTP/1.1 chunked transfer encoding (natif via flush() PHP).
 * L'utilisateur voit défiler les étapes en temps réel au lieu d'une page blanche.
 * Si une étape prend 10s, on voit que ça travaille (pas qu'on est planté).
 *
 * Robustesse :
 *   - mysqli en mode silencieux (pas d'exceptions auto PHP 8.1+)
 *   - register_shutdown_function pour capturer les fatal errors
 *   - try/catch large autour de toute l'exécution
 *   - Chaque étape catch ses propres erreurs et continue ou arrête proprement
 */

/* ---------- Setup robustesse maximale ---------- */
@mysqli_report(MYSQLI_REPORT_OFF);  // Désactive les exceptions auto PHP 8.1+
@set_time_limit(120);                // 2 min max (l'install prend < 5s normalement)
@ini_set('memory_limit', '128M');

// Capture les fatal errors finaux pour afficher une vraie page d'erreur
register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;

    if (headers_sent()) {
        // Headers déjà envoyés : on injecte juste un message en HTML
        echo "\n\n<div style='padding:1rem;margin:1rem;background:#7f1d1d;color:white;border-radius:8px;font-family:sans-serif'>"
           . "<strong>⚠️ Erreur fatale :</strong> " . htmlspecialchars($err['message']) . "<br>"
           . "<small>" . htmlspecialchars(basename($err['file'])) . ':' . (int)$err['line'] . "</small></div>";
        @flush();
        return;
    }

    @http_response_code(500);
    @header('Content-Type: text/html; charset=UTF-8');
    error_log('[Wintaskly Install] Fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Erreur installation</title>'
       . '<style>body{font-family:sans-serif;background:#0a0e1a;color:#e8eaf0;padding:2rem;line-height:1.6}'
       . '.box{max-width:640px;margin:2rem auto;padding:2rem;background:#1a2138;border:1px solid #ef4444;border-radius:12px}'
       . 'h1{color:#fca5a5;margin-top:0}code{background:#0a0e1a;padding:2px 6px;border-radius:4px;font-size:.9em}</style>'
       . '</head><body><div class="box">'
       . '<h1>⚠️ Erreur fatale durant l\'installation</h1>'
       . '<p><strong>Message :</strong> <code>' . htmlspecialchars($err['message']) . '</code></p>'
       . '<p><strong>Fichier :</strong> <code>' . htmlspecialchars(basename($err['file'])) . ':' . (int)$err['line'] . '</code></p>'
       . '<p>👉 Supprimez <code>config.php</code> et <code>.installed.lock</code> s\'ils existent,'
       . ' puis <a href="?step=1" style="color:#ff9933">recommencez l\'installation</a>.</p>'
       . '</div></body></html>';
});

/**
 * Helper : exécute une étape avec affichage en streaming.
 *
 * Affiche l'étape en mode "busy" via flush(), exécute le travail, puis
 * met à jour l'affichage via un petit script JS inline.
 *
 * @param string   $label Nom affiché de l'étape
 * @param callable $work  Closure qui retourne ['ok'=>bool, 'detail'=>string]
 * @return array          Le résultat retourné par le work
 */
function wt_install_run_step(string $label, callable $work): array
{
    $stepId = 'step_' . bin2hex(random_bytes(4));

    // 1) Mode "busy" : icône spinner
    echo '<div id="' . $stepId . '" class="step-progress busy">'
       . '<span class="icon">⟳</span>'
       . '<div class="text"><strong>' . htmlspecialchars($label) . '</strong>'
       . '<small>En cours…</small></div>'
       . '<span class="duration">…</span>'
       . '</div>' . PHP_EOL;
    @flush();

    // 2) Exécution (peut prendre du temps)
    $start = microtime(true);
    try {
        $result = $work();
    } catch (Throwable $e) {
        $result = ['ok' => false, 'detail' => $e->getMessage()];
    }
    $duration = microtime(true) - $start;

    // 3) Mise à jour DOM via JS pour ne pas dupliquer le HTML
    $status   = !empty($result['ok']) ? 'ok' : 'fail';
    $iconChar = !empty($result['ok']) ? '✓' : '✗';
    $detail   = (string)($result['detail'] ?? '');
    $durStr   = sprintf('%.2fs', $duration);

    echo '<script>(function(){'
       . 'var el=document.getElementById("' . $stepId . '");'
       . 'if(el){'
       .   'el.className="step-progress ' . $status . '";'
       .   'el.querySelector(".icon").innerHTML=' . json_encode($iconChar) . ';'
       .   'el.querySelector(".text small").innerHTML=' . json_encode($detail) . ';'
       .   'el.querySelector(".duration").innerHTML=' . json_encode($durStr) . ';'
       . '}})();</script>' . PHP_EOL;
    @flush();

    return $result;
}

$db    = $_SESSION['wt_install']['db']    ?? [];
$site  = $_SESSION['wt_install']['site']  ?? [];
$admin = $_SESSION['wt_install']['admin'] ?? [];

if (empty($db) || empty($site) || empty($admin)) {
    header('Location: ?step=1');
    exit;
}

if (empty($_SESSION['wt_install']['cron_token'])) {
    $_SESSION['wt_install']['cron_token'] = wt_install_generate_cron_token();
}
$cronToken = $_SESSION['wt_install']['cron_token'];

$pageTitle = 'Wintaskly · Installation';
$stepNum   = 5;
$stepLabel = 'Installation';

// $alreadyDone : vrai SEULEMENT si les 2 fichiers existent sur disque.
// Ne PAS se fier uniquement à $_SESSION : la session peut garder cet état
// d'un test précédent alors que les fichiers ont été supprimés/déplacés.
// Source de vérité = disque, pas session.
$rootPath = dirname(__DIR__, 2);  // de install/_steps/ vers la racine wintaskly
$configExists = is_file($rootPath . '/config.php');
$lockExists   = is_file($rootPath . '/.installed.lock');
$alreadyDone  = $configExists && $lockExists;

// Si la session pense que c'est fait MAIS les fichiers manquent : on reset
// (sinon l'utilisateur est piégé : message "déjà fait" mais init.php
// le redirige en boucle vers /install/ car config/lock manquent).
if (!empty($_SESSION['wt_install']['done']) && !$alreadyDone) {
    unset($_SESSION['wt_install']['done']);
    error_log('[Wintaskly install] Session "done" reset (files missing on disk: config='
            . ($configExists ? 'OK' : 'MISSING') . ', lock=' . ($lockExists ? 'OK' : 'MISSING') . ')');
}

$isInstalling  = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'install');


/* ====================================================================
 * MODE STREAMING : exécution en direct avec flush() entre chaque étape
 * ==================================================================== */
if ($isInstalling) {

    // Désactive TOUS les output buffers pour que flush() envoie en temps réel
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');

    header('Content-Type: text/html; charset=UTF-8');
    header('X-Accel-Buffering: no');  // Nginx : désactive le buffering proxy
    header('Cache-Control: no-cache, no-store, must-revalidate');

    // Padding initial pour forcer certains navigateurs à commencer le rendu
    $padding = str_repeat(' ', 2048);

    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Wintaskly · Installation en cours…</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  :root {
    --bg: #0a0e1a; --bg-elev: #131829; --bg-card: #1a2138;
    --border: #2a3252; --text: #e8eaf0; --text-soft: #a4abc4; --text-mute: #6b7390;
    --accent: #ff9933; --accent2: #ffcc33;
    --success: #22c55e; --danger: #ef4444; --warning: #f59e0b; --info: #38bdf8;
  }
  html, body {
    margin: 0; padding: 0;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: var(--bg); color: var(--text);
    min-height: 100vh; line-height: 1.55;
  }
  body {
    background-image:
      radial-gradient(at 20% 0%,  rgba(255, 153, 51, .08) 0%, transparent 50%),
      radial-gradient(at 80% 100%, rgba(255, 204, 51, .05) 0%, transparent 50%);
  }
  .wrapper { max-width: 720px; margin: 0 auto; padding: 2rem 1rem 4rem; }
  .header { text-align: center; margin-bottom: 1.5rem; }
  .logo {
    display: inline-flex; align-items: center; gap: .6rem;
    font-size: 1.6rem; font-weight: 800;
    background: linear-gradient(135deg, var(--accent) 0%, var(--accent2) 100%);
    -webkit-background-clip: text; background-clip: text;
    -webkit-text-fill-color: transparent;
  }
  .card {
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: 16px; padding: 2rem;
    box-shadow: 0 20px 60px rgba(0, 0, 0, .3);
  }
  h1.card-title {
    font-size: 1.4rem; font-weight: 700;
    margin: 0 0 1rem;
  }
  .step-progress {
    display: flex; align-items: center; gap: .85rem;
    padding: .85rem 1rem;
    margin-bottom: .65rem;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 10px;
    animation: stepIn .35s ease-out backwards;
    transition: background .2s ease, border-color .2s ease;
  }
  @keyframes stepIn {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
  }
  .step-progress .icon {
    flex-shrink: 0;
    width: 28px; height: 28px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; font-weight: 700;
  }
  .step-progress.ok   .icon { background: rgba(34,197,94,.18); color: #86efac; }
  .step-progress.fail .icon { background: rgba(239,68,68,.18); color: #fca5a5; }
  .step-progress.fail { border-color: rgba(239, 68, 68, .4); }
  .step-progress.busy .icon {
    background: rgba(255,153,51,.18); color: var(--accent);
    animation: spin 1s linear infinite;
  }
  @keyframes spin { to { transform: rotate(360deg); } }
  .step-progress .text { flex: 1; min-width: 0; }
  .step-progress .text strong {
    display: block; font-size: .92rem; line-height: 1.3;
  }
  .step-progress .text small {
    display: block; color: var(--text-mute); font-size: .78rem; margin-top: .15rem;
  }
  .step-progress .duration {
    font-family: 'JetBrains Mono', monospace;
    font-size: .75rem; color: var(--text-mute);
    flex-shrink: 0;
  }
  .alert {
    padding: .85rem 1rem; border-radius: 10px;
    margin: 1.25rem 0;
    border: 1px solid;
    display: flex; gap: .6rem; align-items: flex-start;
    font-size: .9rem;
  }
  .alert-success { background: rgba(34, 197, 94, .1);  border-color: rgba(34, 197, 94, .3);  color: #86efac; }
  .alert-error   { background: rgba(239, 68, 68, .1);  border-color: rgba(239, 68, 68, .3);  color: #fca5a5; }
  .alert-info    { background: rgba(56, 189, 248, .1); border-color: rgba(56, 189, 248, .3); color: #7dd3fc; }
  .summary-box {
    background: var(--bg); border: 1px solid var(--border);
    border-radius: 10px; padding: 1.25rem; margin: 1.25rem 0;
  }
  .summary-box h3 {
    margin: 0 0 .75rem; font-size: 1rem; color: var(--accent);
  }
  pre {
    font-family: 'JetBrains Mono', monospace; font-size: .8rem;
    background: var(--bg); border: 1px solid var(--border);
    border-radius: 8px; padding: .85rem 1rem; overflow-x: auto;
    margin: .5rem 0; color: var(--text-soft);
    word-break: break-all; white-space: pre-wrap;
  }
  code { font-family: 'JetBrains Mono', monospace; font-size: .88em;
         background: rgba(255,255,255,.05); padding: 1px 6px; border-radius: 4px; }
  .btn {
    display: inline-flex; align-items: center; gap: .5rem;
    padding: .7rem 1.4rem;
    border-radius: 10px; border: 1px solid transparent;
    font-family: inherit; font-size: .95rem; font-weight: 600;
    cursor: pointer; text-decoration: none;
    transition: transform .12s ease, box-shadow .12s ease;
  }
  .btn-primary {
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    color: var(--bg);
  }
  .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 8px 20px rgba(255, 153, 51, .3); }
  .form-actions { display: flex; justify-content: center; margin-top: 1.5rem; }
</style>
</head>
<body>
<div class="wrapper">
  <header class="header">
    <div class="logo">⚡ Wintaskly</div>
  </header>
  <main class="card">
    <h1 class="card-title">🚀 Installation en cours…</h1>
    <p style="color:var(--text-soft);margin-bottom:1.5rem">
      Chaque étape s'exécute en direct ci-dessous. Ne ferme pas la page.
    </p>
    <div id="steps">
<?php
    echo $padding;
    @flush();

    /* ========================================================================
     * EXÉCUTION : chaque étape envoyée individuellement avec flush()
     * ====================================================================== */

    $allOk = true;
    $errorMessage = null;
    $totalStart = microtime(true);
    $mysqliRef = null;

    try {
        /* ---- 1. Connexion BDD ---- */
        $conn = null;
        $r = wt_install_run_step('Connexion à la base de données', function () use ($db, &$conn) {
            $conn = wt_install_test_db_connection($db);
            if (!$conn['ok']) return ['ok' => false, 'detail' => $conn['message']];
            return ['ok' => true, 'detail' => $conn['message']];
        });
        if (!$r['ok']) {
            throw new RuntimeException('Connexion BDD échouée : ' . $r['detail']);
        }
        $mysqliRef = $conn['mysqli'];

        /* ---- 2. CREATE DATABASE si absente ---- */
        if (!$conn['db_exists']) {
            $r = wt_install_run_step('Création de la base de données', function () use ($mysqliRef, $db) {
                $cr = wt_install_create_database($mysqliRef, $db['name'], $db['charset']);
                return ['ok' => $cr['ok'], 'detail' => $cr['message']];
            });
            if (!$r['ok']) throw new RuntimeException($r['detail']);
        }

        /* ---- 3. Sélection BDD ---- */
        if (!$mysqliRef->select_db($db['name'])) {
            throw new RuntimeException('Impossible de sélectionner la BDD : ' . $mysqliRef->error);
        }

        /* ---- 4. Schéma principal ---- */
        $r = wt_install_run_step('Chargement du schéma SQL principal', function () use ($mysqliRef) {
            $schemaPath = dirname(__DIR__, 2) . '/sql/schema.sql';
            $res = wt_install_run_sql_file($mysqliRef, $schemaPath);
            if (!$res['ok'] && empty($res['executed'])) {
                return ['ok' => false, 'detail' => 'Échec : ' . implode(' / ', array_slice($res['errors'], 0, 2))];
            }
            return ['ok' => true, 'detail' => $res['executed'] . ' statements exécutés' .
                ($res['errors'] ? ' (' . count($res['errors']) . ' warnings non-bloquants)' : '')];
        });
        if (!$r['ok']) throw new RuntimeException($r['detail']);

        /* ---- 5. Migrations V8 ---- */
        $migrations = [
            'migration_indexes_v8.sql'        => 'Indexes de performance',
            'migration_api_credentials.sql'   => 'Credentials API pour passerelles',
            'migration_payout_tracking.sql'   => 'Traçabilité des paiements',
        ];
        foreach ($migrations as $file => $label) {
            $path = dirname(__DIR__, 2) . '/sql/' . $file;
            if (!is_file($path)) continue;
            wt_install_run_step('Migration : ' . $label, function () use ($mysqliRef, $path) {
                $res = wt_install_run_sql_file($mysqliRef, $path);
                return ['ok' => true, 'detail' => $res['executed'] . ' statements'];
            });
        }

        /* ---- 6. Seed ---- */
        $seedPath = dirname(__DIR__, 2) . '/sql/seed.sql';
        if (is_file($seedPath)) {
            wt_install_run_step('Données par défaut (seed)', function () use ($mysqliRef, $seedPath) {
                $res = wt_install_run_sql_file($mysqliRef, $seedPath);
                return ['ok' => true, 'detail' => $res['executed'] . ' statements'];
            });
        }

        /* ---- 7. Admin ---- */
        $r = wt_install_run_step('Création du compte super-admin', function () use ($mysqliRef, $admin) {
            $adm = wt_install_create_admin($mysqliRef, $admin['username'], $admin['email'], $admin['password']);
            return ['ok' => $adm['ok'], 'detail' => $adm['message']];
        });
        if (!$r['ok']) throw new RuntimeException($r['detail']);

        /* ---- 8. config.php ---- */
        $r = wt_install_run_step('Écriture de config.php', function () use ($db, $site, $cronToken) {
            $configData = [
                'db' => [
                    'host'    => $db['host']    ?? 'localhost',
                    'socket'  => $db['socket']  ?? '',
                    'port'    => 3306,
                    'name'    => $db['name'],
                    'user'    => $db['user'],
                    'pass'    => $db['pass'],
                    'charset' => 'utf8mb4',
                ],
                'base_url'       => rtrim($site['base_url'], '/'),
                'site_name'      => $site['site_name'],
                'contact_email'  => $site['contact_email'],
                'app_secret'     => bin2hex(random_bytes(32)),
                'cron_token'     => $cronToken,
                'cookie_secure'  => !empty($site['cookie_secure']),
                'cookie_domain'  => '',
                'session_name'   => 'WT_SESS',
                'default_lang'   => $site['default_lang'],
                'allowed_langs'  => ['fr', 'en'],
                'default_theme'  => $site['default_theme'],
                'debug'          => false,
                'environment'    => 'production',

                // Mail : par défaut mail() natif PHP en prod (gratuit, OK pour LWS).
                // L'admin peut activer SMTP depuis /admin/settings.php → onglet Email/SMTP.
                'mail' => [
                    'from'      => 'no-reply@' . parse_url($site['base_url'], PHP_URL_HOST),
                    'from_name' => $site['site_name'],
                    'reply_to'  => $site['contact_email'],
                    'driver'    => 'mail',  // mail() natif PHP — modifiable via admin
                ],
            ];
            $w = wt_install_write_config($configData);
            return ['ok' => $w['ok'], 'detail' => $w['message']];
        });
        if (!$r['ok']) throw new RuntimeException($r['detail']);

        /* ---- 9. Lock file ---- */
        $r = wt_install_run_step('Création de .installed.lock', function () {
            $l = wt_install_create_lock();
            return ['ok' => $l['ok'], 'detail' => $l['message']];
        });
        if (!$r['ok']) throw new RuntimeException($r['detail']);

    } catch (Throwable $e) {
        $allOk = false;
        $errorMessage = $e->getMessage();
        error_log('[Wintaskly Install] ' . $errorMessage);
    }

    if ($mysqliRef instanceof mysqli) {
        @$mysqliRef->close();
    }

    $totalDuration = microtime(true) - $totalStart;

    /* ========================================================================
     * RÉSULTAT FINAL
     * ====================================================================== */
    echo '</div>';  // ferme #steps

    if ($allOk) {
        $_SESSION['wt_install'] = ['cron_token' => $cronToken, 'done' => true];
        $rootPathAbs = dirname(__DIR__, 2);
        ?>

        <div class="alert alert-success">
          <span style="font-size:1.1rem">🎉</span>
          <div>
            <strong>Installation terminée avec succès en <?= sprintf('%.1fs', $totalDuration) ?> !</strong><br>
            Wintaskly est prêt. Voici les dernières étapes recommandées.
          </div>
        </div>

        <div class="summary-box">
          <h3>📋 À faire maintenant</h3>
          <ol style="margin:0;padding-left:1.2rem;font-size:.9rem;line-height:1.7">
            <li><strong>Connectez-vous</strong> avec votre compte admin et configurez les paiements
                dans <code>/admin/payment_methods.php</code> (clés API FaucetPay, etc.).</li>
            <li><strong>Activez le cron</strong> dans cPanel (fréquence <code>* * * * *</code>) :
                <pre>* * * * * /usr/bin/php <?= htmlspecialchars($rootPathAbs . '/cron/run.php') ?> --token=<?= htmlspecialchars($cronToken) ?></pre>
            </li>
            <li><strong>Supprimez le dossier <code>/install/</code></strong> par sécurité
                (ou laissez-le, bloqué par <code>.installed.lock</code>).</li>
            <li><strong>Vérifiez HTTPS</strong> + activez <code>cookie_secure</code> dans <code>config.php</code>.</li>
          </ol>
        </div>

        <div class="form-actions">
          <a class="btn btn-primary" href="../">🚀 Accéder à Wintaskly</a>
        </div>

        <?php
    } else {
        ?>
        <div class="alert alert-error">
          <span style="font-size:1.1rem">⚠️</span>
          <div>
            <strong>Installation interrompue après <?= sprintf('%.1fs', $totalDuration) ?>.</strong><br>
            <?= htmlspecialchars($errorMessage ?: 'Erreur inconnue') ?>
          </div>
        </div>

        <div class="alert alert-info">
          <span style="font-size:1.1rem">💡</span>
          <div>
            Si <code>config.php</code> a été partiellement créé, supprimez-le manuellement avant de réessayer.
            Si l'erreur concerne la BDD, vérifiez les credentials à l'étape 2.
          </div>
        </div>

        <div class="form-actions">
          <a class="btn btn-primary" href="?step=1">← Recommencer</a>
        </div>
        <?php
    }
    ?>

  </main>
</div>
<script>
  // Auto-scroll en bas à chaque ajout d'étape
  (function() {
    var observer = new MutationObserver(function() {
      window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
    });
    var steps = document.getElementById('steps');
    if (steps) observer.observe(steps, { childList: true });
  })();
</script>
</body>
</html>
<?php
    @flush();
    exit;  // Important : on coupe ici, pas de rendu via layout standard
}


/* ====================================================================
 * MODE NON-STREAMING : récapitulatif AVANT install (GET)
 * Ou écran SUCCESS au refresh après install OK
 * ==================================================================== */

ob_start();
?>

<?php if ($alreadyDone): ?>
  <h1 class="card-title">🎉 Installation déjà terminée</h1>
  <p class="card-lead">Wintaskly est installé. Vous pouvez accéder au site.</p>

  <div class="alert alert-success">
    <span class="alert-icon">✓</span>
    <div>L'installation a déjà été effectuée avec succès.</div>
  </div>

  <div class="form-actions" style="justify-content: center;">
    <a class="btn btn-primary" href="../">🚀 Accéder à Wintaskly</a>
  </div>

<?php else: ?>
  <h1 class="card-title">🚀 Récapitulatif et installation</h1>
  <p class="card-lead">
    Vérifiez les paramètres ci-dessous puis lancez l'installation.
    L'opération prend quelques secondes — chaque étape s'affichera en temps réel.
  </p>

  <div class="summary-box">
    <h3>🗄 Base de données</h3>
    <dl>
      <dt>Hôte</dt><dd><?= htmlspecialchars($db['host'], ENT_QUOTES, 'UTF-8') ?><?= !empty($db['socket']) ? ' (socket)' : '' ?></dd>
      <dt>Base</dt><dd><?= htmlspecialchars($db['name'], ENT_QUOTES, 'UTF-8') ?></dd>
      <dt>Utilisateur</dt><dd><?= htmlspecialchars($db['user'], ENT_QUOTES, 'UTF-8') ?></dd>
    </dl>
  </div>

  <div class="summary-box">
    <h3>⚙️ Site</h3>
    <dl>
      <dt>Nom</dt><dd><?= htmlspecialchars($site['site_name'], ENT_QUOTES, 'UTF-8') ?></dd>
      <dt>URL</dt><dd><?= htmlspecialchars($site['base_url'], ENT_QUOTES, 'UTF-8') ?></dd>
      <dt>Email</dt><dd><?= htmlspecialchars($site['contact_email'], ENT_QUOTES, 'UTF-8') ?></dd>
      <dt>Langue</dt><dd><?= htmlspecialchars($site['default_lang'], ENT_QUOTES, 'UTF-8') ?></dd>
      <dt>Thème</dt><dd><?= htmlspecialchars($site['default_theme'], ENT_QUOTES, 'UTF-8') ?></dd>
      <dt>Cookie sécurisé</dt><dd><?= !empty($site['cookie_secure']) ? 'Oui (HTTPS uniquement)' : 'Non' ?></dd>
    </dl>
  </div>

  <div class="summary-box">
    <h3>👤 Compte administrateur</h3>
    <dl>
      <dt>Nom d'utilisateur</dt><dd><?= htmlspecialchars($admin['username'], ENT_QUOTES, 'UTF-8') ?></dd>
      <dt>Email</dt><dd><?= htmlspecialchars($admin['email'], ENT_QUOTES, 'UTF-8') ?></dd>
      <dt>Mot de passe</dt><dd>•••••••••• (défini)</dd>
    </dl>
  </div>

  <div class="alert alert-info">
    <span class="alert-icon">⚡</span>
    <div>
      L'installation va créer la base, charger les tables et migrations,
      seed les données par défaut, créer votre compte admin, et générer <code>config.php</code>.
      <strong>Vous verrez chaque étape se compléter en direct.</strong>
    </div>
  </div>

  <form method="post">
    <input type="hidden" name="_action" value="install">
    <div class="form-actions">
      <a class="btn btn-ghost" href="?step=4">← Retour</a>
      <button type="submit" class="btn btn-primary"
              onclick="this.disabled=true; this.innerHTML='<span class=\'spinner\'></span> Démarrage…'; this.form.submit();">
        🚀 Lancer l'installation
      </button>
    </div>
  </form>
<?php endif; ?>

<?php
$content = ob_get_clean();
require __DIR__ . '/../_layout.php';
