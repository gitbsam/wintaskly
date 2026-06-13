<?php
/**
 * Wintaskly — Mot de passe oublié (V8 modernisé).
 *
 * Demande l'adresse e-mail. Le serveur répond TOUJOURS de façon
 * générique pour empêcher l'énumération de comptes.
 *
 * Compat : hooks data-auth-form + data-keep-form préservés
 * (l'utilisateur voit son formulaire rester visible après l'envoi).
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

$pageTitle = t('auth.forgot.title');
include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-auth-v2" data-reveal>
  <div class="wt-auth-v2__wrap">

    <section class="wt-auth-v2__form-col">
      <header class="wt-auth-v2__head">
        <span class="wt-eyebrow">🔑 <?= e(t('auth.eyebrow_forgot')) ?></span>
        <h1 class="wt-auth-v2__title"><?= e(t('auth.forgot.title')) ?></h1>
        <p class="wt-auth-v2__lead"><?= e(t('auth.forgot.intro')) ?></p>
      </header>

      <div class="wt-alert wt-alert--success is-hidden" data-auth-success></div>
      <div class="wt-alert wt-alert--error   is-hidden" data-auth-error></div>

      <form class="wt-form wt-auth-v2__form"
            data-auth-form
            data-endpoint="<?= e(wt_url('/api/auth_forgot.php')) ?>"
            data-keep-form
            novalidate>
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

        <label class="wt-field">
          <span class="wt-field__label"><?= e(t('auth.email')) ?></span>
          <input class="wt-input" type="email" name="email" required autofocus
                 placeholder="exemple@email.com">
        </label>

        <button type="submit" class="wt-btn wt-btn--primary wt-btn--lg wt-btn--block" data-submit-btn>
          <span class="wt-btn__label">📧 <?= e(t('auth.forgot.submit')) ?></span>
          <span class="wt-btn__spinner is-hidden" aria-hidden="true"></span>
        </button>
      </form>

      <p class="wt-auth-v2__alt">
        <a href="<?= e(wt_url('/auth/login.php')) ?>">← <?= e(t('common.back')) ?></a>
      </p>
    </section>

    <!-- Panneau "Comment ça marche ?" -->
    <aside class="wt-auth-v2__side wt-auth-v2__side--forgot">
      <div class="wt-auth-v2__side-bg" aria-hidden="true"></div>

      <header class="wt-auth-v2__side-head">
        <span class="wt-auth-v2__side-eyebrow">🔐 <?= e(t('auth.side.security')) ?></span>
        <h2 class="wt-auth-v2__side-title"><?= e(t('auth.side.forgot_how')) ?></h2>
      </header>

      <ol class="wt-auth-v2__steps">
        <li>
          <span class="wt-auth-v2__step-num">1</span>
          <div>
            <strong><?= e(t('auth.forgot.step1_title')) ?></strong>
            <small><?= e(t('auth.forgot.step1_text')) ?></small>
          </div>
        </li>
        <li>
          <span class="wt-auth-v2__step-num">2</span>
          <div>
            <strong><?= e(t('auth.forgot.step2_title')) ?></strong>
            <small><?= e(t('auth.forgot.step2_text')) ?></small>
          </div>
        </li>
        <li>
          <span class="wt-auth-v2__step-num">3</span>
          <div>
            <strong><?= e(t('auth.forgot.step3_title')) ?></strong>
            <small><?= e(t('auth.forgot.step3_text')) ?></small>
          </div>
        </li>
      </ol>

      <p class="wt-auth-v2__side-foot">
        ℹ️ <?= e(t('auth.forgot.privacy_note')) ?>
      </p>
    </aside>

  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
