<?php
/**
 * Wintaskly — Réinitialisation du mot de passe (V8 modernisé).
 *
 * Accès via /auth/reset-password.php?token=XYZ. Si le token est
 * invalide ou expiré, redirection avec message d'erreur.
 *
 * Compat : tous les hooks JS (data-strength-input/bar/fill/label,
 * data-toggle-pw, data-auth-form, [data-auth-error]) préservés.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

$token  = trim((string)($_GET['token'] ?? ''));
$userId = $token !== '' ? auth_token_peek($token, 'reset_password') : null;

if (!$userId) {
    $_SESSION['flash_error'] = (string) t('auth.reset.invalid_token');
    header('Location: ' . wt_url('/auth/forgot-password.php'));
    exit;
}

$pageTitle = t('auth.reset.title');
include __DIR__ . '/../header.php';
?>

<script>
(function () {
  var h = document.documentElement;
  h.dataset.strengthWeak   = <?= json_encode(t('auth.strength.weak')) ?>;
  h.dataset.strengthFair   = <?= json_encode(t('auth.strength.fair')) ?>;
  h.dataset.strengthGood   = <?= json_encode(t('auth.strength.good')) ?>;
  h.dataset.strengthStrong = <?= json_encode(t('auth.strength.strong')) ?>;
})();
</script>

<main class="wt-main wt-auth-v2" data-reveal>
  <div class="wt-auth-v2__wrap">

    <section class="wt-auth-v2__form-col">
      <header class="wt-auth-v2__head">
        <span class="wt-eyebrow">🔓 <?= e(t('auth.eyebrow_reset')) ?></span>
        <h1 class="wt-auth-v2__title"><?= e(t('auth.reset.title')) ?></h1>
        <p class="wt-auth-v2__lead"><?= e(t('auth.reset.intro')) ?></p>
      </header>

      <div class="wt-alert wt-alert--error is-hidden" data-auth-error></div>

      <form class="wt-form wt-auth-v2__form"
            data-auth-form
            data-endpoint="<?= e(wt_url('/api/auth_reset.php')) ?>"
            novalidate>
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <label class="wt-field">
          <span class="wt-field__label"><?= e(t('auth.reset.new')) ?></span>
          <div class="wt-input-wrap wt-input-wrap--password">
            <input class="wt-input" type="password" name="password"
                 autocomplete="new-password" required minlength="8"
                 data-strength-input>
            <button type="button" class="wt-input-eye" data-toggle-pw
                    aria-label="<?= e(t('auth.toggle_pw')) ?>" tabindex="-1">
              <svg class="wt-input-eye__off" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg class="wt-input-eye__on is-hidden" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a21.69 21.69 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a21.7 21.7 0 0 1-3.17 4.19M14.12 14.12a3 3 0 1 1-4.24-4.24M1 1l22 22"/></svg>
            </button>
          </div>
          <div class="wt-strength" data-strength-bar>
            <div class="wt-strength__fill" data-strength-fill></div>
          </div>
          <small class="wt-field__hint" data-strength-label>
            <?= e(t('auth.strength.weak')) ?>
          </small>
        </label>

        <label class="wt-field">
          <span class="wt-field__label"><?= e(t('auth.reset.confirm')) ?></span>
          <div class="wt-input-wrap wt-input-wrap--password">
            <input class="wt-input" type="password" name="password2"
                 autocomplete="new-password" required minlength="8">
            <button type="button" class="wt-input-eye" data-toggle-pw
                    aria-label="<?= e(t('auth.toggle_pw')) ?>" tabindex="-1">
              <svg class="wt-input-eye__off" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg class="wt-input-eye__on is-hidden" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a21.69 21.69 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a21.7 21.7 0 0 1-3.17 4.19M14.12 14.12a3 3 0 1 1-4.24-4.24M1 1l22 22"/></svg>
            </button>
          </div>
        </label>

        <button type="submit" class="wt-btn wt-btn--primary wt-btn--lg wt-btn--block" data-submit-btn>
          <span class="wt-btn__label">✓ <?= e(t('auth.reset.submit')) ?></span>
          <span class="wt-btn__spinner is-hidden" aria-hidden="true"></span>
        </button>
      </form>
    </section>

    <!-- Conseils sécurité -->
    <aside class="wt-auth-v2__side wt-auth-v2__side--reset">
      <div class="wt-auth-v2__side-bg" aria-hidden="true"></div>

      <header class="wt-auth-v2__side-head">
        <span class="wt-auth-v2__side-eyebrow">💡 <?= e(t('auth.side.tips')) ?></span>
        <h2 class="wt-auth-v2__side-title"><?= e(t('auth.reset.tips_title')) ?></h2>
      </header>

      <ul class="wt-auth-v2__tips">
        <li>
          <span class="wt-auth-v2__tip-icon" aria-hidden="true">✓</span>
          <span><?= e(t('auth.reset.tip1')) ?></span>
        </li>
        <li>
          <span class="wt-auth-v2__tip-icon" aria-hidden="true">✓</span>
          <span><?= e(t('auth.reset.tip2')) ?></span>
        </li>
        <li>
          <span class="wt-auth-v2__tip-icon" aria-hidden="true">✓</span>
          <span><?= e(t('auth.reset.tip3')) ?></span>
        </li>
        <li>
          <span class="wt-auth-v2__tip-icon" aria-hidden="true">✓</span>
          <span><?= e(t('auth.reset.tip4')) ?></span>
        </li>
      </ul>

      <p class="wt-auth-v2__side-foot">
        🛡️ <?= e(t('auth.reset.security_note')) ?>
      </p>
    </aside>

  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
