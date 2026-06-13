<?php
/**
 * Wintaskly — Installeur · Étape 2 / 5 : Connexion BDD.
 *
 * Affiche un formulaire pour saisir les credentials BDD.
 * Bouton "Tester la connexion" → vérifie en live.
 * Bouton "Suivant" → valide + stocke en session.
 */

$saved = $_SESSION['wt_install']['db'] ?? [];

// Auto-détection du socket par défaut depuis la config PHP système
// Sur LWS : /var/run/mysqld/mysqld.sock
// Sur OVH : /var/run/mysqld/mysqld.sock
// Sur XAMPP/local : vide (utilise TCP)
$defaultSocket = '';
$phpSocket = ini_get('mysqli.default_socket');
if ($phpSocket && is_file($phpSocket)) {
    $defaultSocket = $phpSocket;
}

$db = [
    'host'    => $_POST['db_host']    ?? $saved['host']    ?? 'localhost',
    'socket'  => $_POST['db_socket']  ?? $saved['socket']  ?? $defaultSocket,
    'name'    => $_POST['db_name']    ?? $saved['name']    ?? '',
    'user'    => $_POST['db_user']    ?? $saved['user']    ?? '',
    'pass'    => $_POST['db_pass']    ?? $saved['pass']    ?? '',
    'charset' => 'utf8mb4',
];

$result   = null;
$benchmark = null;  // ['ms_connect', 'ms_query', 'warning_level']
$action   = (string)($_POST['_action'] ?? '');

if ($action === 'test' || $action === 'next') {
    // Mesure du temps de connexion
    $tConnectStart = microtime(true);
    $result = wt_install_test_db_connection($db);
    $msConnect = (microtime(true) - $tConnectStart) * 1000;

    if ($result['ok'] && $result['mysqli'] instanceof mysqli) {
        // Mesure d'une query simple (round-trip)
        $tQueryStart = microtime(true);
        @$result['mysqli']->query("SELECT 1");
        $msQuery = (microtime(true) - $tQueryStart) * 1000;

        // Détermination du niveau d'alerte
        $warningLevel = 'ok';  // < 100ms : excellent
        if ($msQuery > 1000)       $warningLevel = 'critical';
        elseif ($msQuery > 500)    $warningLevel = 'slow';
        elseif ($msQuery > 200)    $warningLevel = 'moderate';

        $benchmark = [
            'ms_connect'    => $msConnect,
            'ms_query'      => $msQuery,
            'warning_level' => $warningLevel,
        ];
    }

    if ($result['ok'] && $action === 'next') {
        // Si la BDD n'existe pas, on tente de la créer maintenant
        if (!$result['db_exists']) {
            $create = wt_install_create_database($result['mysqli'], $db['name'], $db['charset']);
            if (!$create['ok']) {
                $result = ['ok' => false, 'message' => $create['message']];
            }
        }
        if ($result['ok']) {
            $_SESSION['wt_install']['db'] = $db;
            $result['mysqli']->close();
            header('Location: ?step=3');
            exit;
        }
    }
}

$pageTitle = 'Wintaskly · Base de données';
$stepNum   = 2;
$stepLabel = 'Base de données';

ob_start();
?>
<h1 class="card-title">🗄 Connexion à la base de données</h1>
<p class="card-lead">
  Saisissez les paramètres de connexion à votre base de données MySQL/MariaDB.
  Si la base n'existe pas encore, l'installeur tentera de la créer
  automatiquement.
</p>

<?php if ($result): ?>
  <?php if ($result['ok']): ?>
    <div class="alert alert-success">
      <span class="alert-icon">✓</span>
      <div><?= htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8') ?></div>
    </div>
  <?php else: ?>
    <div class="alert alert-error">
      <span class="alert-icon">⚠️</span>
      <div><?= htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8') ?></div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php
/* ====== Benchmark : temps de réponse BDD avec avertissement contextuel ====== */
if ($benchmark):
    $msQuery   = $benchmark['ms_query'];
    $msConnect = $benchmark['ms_connect'];
    $level     = $benchmark['warning_level'];

    // Choix du style + message selon le niveau
    [$alertClass, $alertIcon, $alertLabel, $alertDesc] = match ($level) {
        'critical' => [
            'alert-error',
            '🐌',
            sprintf('Serveur BDD très lent (%.0f ms)', $msQuery),
            'L\'installation risque de prendre 30+ secondes ou de timeout. Si possible, choisissez un meilleur hébergeur ou attendez un moment moins chargé.',
        ],
        'slow' => [
            'alert-error',
            '🐢',
            sprintf('Serveur BDD lent (%.0f ms)', $msQuery),
            'L\'installation peut prendre 15-30 secondes. Pas critique mais soyez patient. Si l\'installation timeout, recommencez à un moment moins chargé.',
        ],
        'moderate' => [
            'alert-info',
            '⏱️',
            sprintf('Serveur BDD légèrement lent (%.0f ms)', $msQuery),
            'L\'installation prendra environ 5-15 secondes. Tout reste OK.',
        ],
        default => [
            'alert-success',
            '⚡',
            sprintf('Serveur BDD rapide (%.0f ms)', $msQuery),
            sprintf('Connexion : %.0f ms · Query : %.0f ms. L\'installation devrait prendre moins de 5 secondes.', $msConnect, $msQuery),
        ],
    };
?>
  <div class="alert <?= $alertClass ?>">
    <span class="alert-icon"><?= $alertIcon ?></span>
    <div>
      <strong><?= $alertLabel ?></strong><br>
      <small><?= $alertDesc ?></small>
    </div>
  </div>
<?php endif; ?>

<div class="alert alert-info">
  <span class="alert-icon">💡</span>
  <div>
    <strong>Hébergement LWS</strong> : créez d'abord votre base + utilisateur dans
    <code>cPanel → MySQL Databases</code>. Le hôte est généralement <code>localhost</code>.
    Notez bien le nom complet de la BDD (souvent préfixé par votre login : <code>monlogin_wintaskly</code>).
  </div>
</div>

<form method="post">
  <div class="field-row cols-2">
    <div class="field">
      <label for="db_host">Hôte</label>
      <input type="text" id="db_host" name="db_host"
             value="<?= htmlspecialchars($db['host'], ENT_QUOTES, 'UTF-8') ?>"
             placeholder="localhost">
      <div class="field-hint">Souvent <code>localhost</code> sur LWS/OVH/Hostinger</div>
    </div>
    <div class="field">
      <label for="db_socket">Socket Unix (optionnel)</label>
      <input type="text" id="db_socket" name="db_socket"
             value="<?= htmlspecialchars($db['socket'], ENT_QUOTES, 'UTF-8') ?>"
             placeholder="ex: /var/run/mysqld/mysqld.sock">
      <div class="field-hint">Laisser vide pour TCP normal</div>
    </div>
  </div>

  <div class="field">
    <label for="db_name">Nom de la base</label>
    <input type="text" id="db_name" name="db_name" required
           value="<?= htmlspecialchars($db['name'], ENT_QUOTES, 'UTF-8') ?>"
           placeholder="wintaskly">
    <div class="field-hint">Sur LWS : préfixé par votre login (ex: <code>monlogin_wintaskly</code>)</div>
  </div>

  <div class="field-row cols-2">
    <div class="field">
      <label for="db_user">Utilisateur</label>
      <input type="text" id="db_user" name="db_user" required
             value="<?= htmlspecialchars($db['user'], ENT_QUOTES, 'UTF-8') ?>"
             placeholder="root">
    </div>
    <div class="field">
      <label for="db_pass">Mot de passe</label>
      <input type="password" id="db_pass" name="db_pass"
             value="<?= htmlspecialchars($db['pass'], ENT_QUOTES, 'UTF-8') ?>">
    </div>
  </div>

  <div class="form-actions">
    <a class="btn btn-ghost" href="?step=1">← Retour</a>
    <div style="display: flex; gap: .75rem;">
      <button type="submit" name="_action" value="test" class="btn btn-secondary">
        Tester la connexion
      </button>
      <button type="submit" name="_action" value="next" class="btn btn-primary">
        Suivant → Site
      </button>
    </div>
  </div>
</form>
<?php
$content = ob_get_clean();
require __DIR__ . '/../_layout.php';
