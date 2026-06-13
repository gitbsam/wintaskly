<?php
/**
 * Wintaskly — Installeur · Étape 4 / 5 : Compte super-admin.
 *
 * Création du premier admin (qui pourra ensuite tout configurer
 * depuis le panneau /admin/).
 */

$saved = $_SESSION['wt_install']['admin'] ?? [];

$admin = [
    'username' => $_POST['admin_username'] ?? $saved['username'] ?? '',
    'email'    => $_POST['admin_email']    ?? $saved['email']    ?? '',
    'password' => $_POST['admin_password'] ?? '',  // jamais re-prefill un mot de passe
];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $admin['username'])) {
        $errors[] = 'Nom d\'utilisateur invalide (3-32 caractères, lettres/chiffres/underscore)';
    }
    if (!filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Adresse email invalide';
    }
    if (strlen($admin['password']) < 10) {
        $errors[] = 'Mot de passe trop court (10 caractères minimum)';
    }
    // Confirmation
    if (($_POST['admin_password_confirm'] ?? '') !== $admin['password']) {
        $errors[] = 'Les deux mots de passe ne correspondent pas';
    }

    if (empty($errors)) {
        $_SESSION['wt_install']['admin'] = $admin;
        header('Location: ?step=5');
        exit;
    }
}

$pageTitle = 'Wintaskly · Compte admin';
$stepNum   = 4;
$stepLabel = 'Compte admin';

ob_start();
?>
<h1 class="card-title">👤 Création du compte super-administrateur</h1>
<p class="card-lead">
  Ce compte aura accès à toutes les fonctionnalités d'administration
  (gestion users, validations retraits, configuration des passerelles, etc.).
  Choisissez un mot de passe fort.
</p>

<?php if ($errors): ?>
  <div class="alert alert-error">
    <span class="alert-icon">⚠️</span>
    <div>
      <strong>Corrigez les champs ci-dessous :</strong>
      <ul style="margin: .35rem 0 0; padding-left: 1.2rem;">
        <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
<?php endif; ?>

<form method="post" autocomplete="off">
  <div class="field">
    <label for="admin_username">Nom d'utilisateur</label>
    <input type="text" id="admin_username" name="admin_username" required
           autocomplete="off" maxlength="32" pattern="[a-zA-Z0-9_]+"
           value="<?= htmlspecialchars($admin['username'], ENT_QUOTES, 'UTF-8') ?>"
           placeholder="admin">
    <div class="field-hint">3 à 32 caractères, lettres / chiffres / underscore uniquement</div>
  </div>

  <div class="field">
    <label for="admin_email">Adresse email</label>
    <input type="email" id="admin_email" name="admin_email" required
           autocomplete="off"
           value="<?= htmlspecialchars($admin['email'], ENT_QUOTES, 'UTF-8') ?>"
           placeholder="admin@wintaskly.com">
    <div class="field-hint">Utilisée pour les notifications et la récupération de compte</div>
  </div>

  <div class="field-row cols-2">
    <div class="field">
      <label for="admin_password">Mot de passe</label>
      <input type="password" id="admin_password" name="admin_password" required
             autocomplete="new-password" minlength="10">
      <div class="field-hint">10 caractères minimum — mélangez majuscules, chiffres et symboles</div>
    </div>
    <div class="field">
      <label for="admin_password_confirm">Confirmer le mot de passe</label>
      <input type="password" id="admin_password_confirm" name="admin_password_confirm" required
             autocomplete="new-password" minlength="10">
    </div>
  </div>

  <div class="alert alert-warning">
    <span class="alert-icon">🔐</span>
    <div>
      <strong>Important :</strong> notez ce mot de passe en lieu sûr.
      L'installeur ne le mémorise pas — si vous l'oubliez après l'install,
      il faudra le réinitialiser via la BDD.
    </div>
  </div>

  <div class="form-actions">
    <a class="btn btn-ghost" href="?step=3">← Retour</a>
    <button type="submit" class="btn btn-primary">Suivant → Récapitulatif</button>
  </div>
</form>
<?php
$content = ob_get_clean();
require __DIR__ . '/../_layout.php';
