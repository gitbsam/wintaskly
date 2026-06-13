<?php
/**
 * Wintaskly — Installeur · Étape 1 / 5 : Vérification des prérequis.
 *
 * Affiche la liste des checks (PHP, extensions, permissions, etc.) avec
 * code couleur. Bouton "Suivant" actif uniquement si tous les checks
 * critiques passent.
 *
 * POST : marque les checks comme validés en session puis redirige vers ?step=2.
 */

// Lance les vérifications (toujours fresh, pas de cache)
$checks   = wt_install_check_requirements();
$canPass  = wt_install_requirements_pass($checks);

// POST : validation et passage à l'étape 2
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canPass) {
        // L'utilisateur a soumis mais les checks ne passent pas — on reste là.
    } else {
        $_SESSION['wt_install']['checks_ok'] = true;
        header('Location: ?step=2');
        exit;
    }
}

$pageTitle = 'Wintaskly · Prérequis';
$stepNum   = 1;
$stepLabel = 'Prérequis';

ob_start();
?>
<h1 class="card-title">🔍 Vérification des prérequis</h1>
<p class="card-lead">
  L'installeur vérifie que votre serveur dispose de tout ce qu'il faut pour
  faire tourner Wintaskly. Les éléments marqués <strong>critiques</strong>
  doivent être OK pour continuer.
</p>

<?php if (!$canPass): ?>
  <div class="alert alert-error">
    <span class="alert-icon">⚠️</span>
    <div>
      <strong>Certains prérequis critiques ne sont pas satisfaits.</strong><br>
      Corrigez les éléments en rouge ci-dessous puis rechargez la page.
      Si vous êtes sur un hébergeur mutualisé (LWS, OVH, etc.), contactez
      le support pour activer les extensions manquantes.
    </div>
  </div>
<?php else: ?>
  <div class="alert alert-success">
    <span class="alert-icon">✓</span>
    <div>Tous les prérequis critiques sont satisfaits. Vous pouvez continuer.</div>
  </div>
<?php endif; ?>

<ul class="check-list">
  <?php foreach ($checks as $c):
    if ($c['ok']) {
      $cls = 'ok'; $icon = '✓';
    } elseif ($c['critical']) {
      $cls = 'fail'; $icon = '✗';
    } else {
      $cls = 'warn'; $icon = '!';
    }
  ?>
    <li class="check-item <?= $cls ?>">
      <span class="check-icon"><?= $icon ?></span>
      <div class="check-body">
        <strong><?= htmlspecialchars($c['label'], ENT_QUOTES, 'UTF-8') ?></strong>
        <small><?= htmlspecialchars($c['detail'], ENT_QUOTES, 'UTF-8') ?></small>
      </div>
    </li>
  <?php endforeach; ?>
</ul>

<form method="post">
  <div class="form-actions">
    <span></span>
    <button type="submit" class="btn btn-primary" <?= $canPass ? '' : 'disabled' ?>>
      Suivant → Base de données
    </button>
  </div>
</form>
<?php
$content = ob_get_clean();
require __DIR__ . '/../_layout.php';
