<?php
/**
 * Wintaskly — Installeur · Étape 3 / 5 : Configuration site.
 *
 * Nom du site, URL de base (auto-détectée), email contact, langue, timezone.
 */

$saved = $_SESSION['wt_install']['site'] ?? [];
$detectedUrl = wt_install_detect_base_url();

$site = [
    'site_name'     => $_POST['site_name']     ?? $saved['site_name']     ?? 'Wintaskly',
    'base_url'      => $_POST['base_url']      ?? $saved['base_url']      ?? $detectedUrl,
    'contact_email' => $_POST['contact_email'] ?? $saved['contact_email'] ?? '',
    'default_lang'  => $_POST['default_lang']  ?? $saved['default_lang']  ?? 'fr',
    'default_theme' => $_POST['default_theme'] ?? $saved['default_theme'] ?? 'dark',
    'cookie_secure' => isset($_POST['cookie_secure']) ? 1 : ($saved['cookie_secure'] ?? 0),
];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (trim((string)$site['site_name']) === '') $errors[] = 'Nom du site requis';
    if (!filter_var($site['base_url'], FILTER_VALIDATE_URL)) $errors[] = 'URL de base invalide';
    if (!filter_var($site['contact_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Email contact invalide';
    if (!in_array($site['default_lang'], ['fr', 'en'], true)) $errors[] = 'Langue invalide';
    if (!in_array($site['default_theme'], ['dark', 'light'], true)) $errors[] = 'Thème invalide';

    if (empty($errors)) {
        $_SESSION['wt_install']['site'] = $site;
        header('Location: ?step=4');
        exit;
    }
}

$pageTitle = 'Wintaskly · Site';
$stepNum   = 3;
$stepLabel = 'Site';

ob_start();
?>
<h1 class="card-title">⚙️ Configuration du site</h1>
<p class="card-lead">
  Paramètres généraux de votre instance Wintaskly. Vous pourrez les
  modifier plus tard via l'interface admin.
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

<form method="post">
  <div class="field">
    <label for="site_name">Nom du site</label>
    <input type="text" id="site_name" name="site_name" required maxlength="80"
           value="<?= htmlspecialchars($site['site_name'], ENT_QUOTES, 'UTF-8') ?>">
    <div class="field-hint">Visible sur toutes les pages, dans les emails, et le titre HTML</div>
  </div>

  <div class="field">
    <label for="base_url">URL de base</label>
    <input type="url" id="base_url" name="base_url" required
           value="<?= htmlspecialchars($site['base_url'], ENT_QUOTES, 'UTF-8') ?>">
    <div class="field-hint">
      URL complète (auto-détectée) — Ex : <code>https://wintaskly.com</code>.
      <strong>Sans slash final.</strong>
    </div>
  </div>

  <div class="field">
    <label for="contact_email">Email contact</label>
    <input type="email" id="contact_email" name="contact_email" required
           value="<?= htmlspecialchars($site['contact_email'], ENT_QUOTES, 'UTF-8') ?>"
           placeholder="contact@wintaskly.com">
    <div class="field-hint">Adresse utilisée pour les notifications système et le formulaire de contact</div>
  </div>

  <div class="field-row cols-2">
    <div class="field">
      <label for="default_lang">Langue par défaut</label>
      <select id="default_lang" name="default_lang">
        <option value="fr" <?= $site['default_lang'] === 'fr' ? 'selected' : '' ?>>Français</option>
        <option value="en" <?= $site['default_lang'] === 'en' ? 'selected' : '' ?>>English</option>
      </select>
    </div>
    <div class="field">
      <label for="default_theme">Thème par défaut</label>
      <select id="default_theme" name="default_theme">
        <option value="dark" <?= $site['default_theme'] === 'dark' ? 'selected' : '' ?>>Sombre</option>
        <option value="light" <?= $site['default_theme'] === 'light' ? 'selected' : '' ?>>Clair</option>
      </select>
    </div>
  </div>

  <div class="field">
    <label style="display: flex; align-items: center; gap: .5rem; cursor: pointer;">
      <input type="checkbox" name="cookie_secure" value="1"
             <?= !empty($site['cookie_secure']) ? 'checked' : '' ?>
             style="width: auto;">
      <span>Activer cookie_secure (HTTPS uniquement)</span>
    </label>
    <div class="field-hint">À cocher seulement si votre site est exclusivement en HTTPS</div>
  </div>

  <div class="form-actions">
    <a class="btn btn-ghost" href="?step=2">← Retour</a>
    <button type="submit" class="btn btn-primary">Suivant → Admin</button>
  </div>
</form>
<?php
$content = ob_get_clean();
require __DIR__ . '/../_layout.php';
