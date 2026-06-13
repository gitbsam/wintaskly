<?php
/**
 * Wintaskly — Vérification 2FA TOTP (V8 modernisé).
 *
 * S'affiche après un POST réussi vers /api/auth_login.php qui a
 * renvoyé { ok:true, two_factor_required:true }. La session contient
 * `pending_2fa_uid` mais PAS encore `uid`.
 *
 * Compat : hooks [data-otp-root], [data-otp-hidden], data-auth-form
 * (le JS d'autotab des cases existe déjà).
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

if (empty($_SESSION['pending_2fa_uid'])) {
    header('Location: ' . wt_url('/auth/login.php'));
    exit;
}

$pageTitle = t('auth.2fa.title');
include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-auth-v2" data-reveal>
  <div class="wt-auth-v2__wrap">

    <section class="wt-auth-v2__form-col">
      <div class="wt-auth-v2__shield-illust" aria-hidden="true">
        <svg viewBox="0 0 120 120" width="120" height="120">
          <defs>
            <linearGradient id="gradShield" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0%"  stop-color="var(--wt-accent)"/>
              <stop offset="100%" stop-color="var(--wt-accent2)"/>
            </linearGradient>
          </defs>
          <path d="M60 12 L 100 28 L 100 60 C 100 84 80 100 60 108 C 40 100 20 84 20 60 L 20 28 Z"
                fill="url(#gradShield)" opacity=".15"/>
          <path d="M60 12 L 100 28 L 100 60 C 100 84 80 100 60 108 C 40 100 20 84 20 60 L 20 28 Z"
                fill="none" stroke="url(#gradShield)" stroke-width="3"/>
          <path d="M42 60 L 55 73 L 80 48" fill="none" stroke="url(#gradShield)"
                stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </div>

      <header class="wt-auth-v2__head wt-auth-v2__head--centered">
        <span class="wt-eyebrow">🔐 <?= e(t('auth.eyebrow_2fa')) ?></span>
        <h1 class="wt-auth-v2__title"><?= e(t('auth.2fa.title')) ?></h1>
        <p class="wt-auth-v2__lead"><?= e(t('auth.2fa.intro')) ?></p>
      </header>

      <div class="wt-alert wt-alert--error is-hidden" data-auth-error></div>

      <form class="wt-form wt-auth-v2__form"
            data-auth-form
            data-endpoint="<?= e(wt_url('/api/auth_verify_2fa.php')) ?>"
            novalidate>
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="code"  data-otp-hidden value="">

        <div class="wt-otp wt-auth-v2__otp" data-otp-root>
          <?php for ($i = 0; $i < 6; $i++): ?>
            <input class="wt-otp__cell"
                   type="text"
                   inputmode="numeric"
                   pattern="[0-9]"
                   maxlength="1"
                   autocomplete="one-time-code"
                   aria-label="<?= e(sprintf((string) t('auth.2fa.digit_aria'), $i + 1)) ?>"
                   <?= $i === 0 ? 'autofocus' : '' ?>>
          <?php endfor; ?>
        </div>

        <button type="submit" class="wt-btn wt-btn--primary wt-btn--lg wt-btn--block" data-submit-btn>
          <span class="wt-btn__label">→ <?= e(t('auth.2fa.submit')) ?></span>
          <span class="wt-btn__spinner is-hidden" aria-hidden="true"></span>
        </button>
      </form>

      <p class="wt-auth-v2__alt">
        <a href="<?= e(wt_url('/auth/login.php')) ?>">← <?= e(t('common.back')) ?></a>
      </p>
    </section>

    <!-- Panneau "Pourquoi 2FA ?" -->
    <aside class="wt-auth-v2__side wt-auth-v2__side--2fa">
      <div class="wt-auth-v2__side-bg" aria-hidden="true"></div>

      <header class="wt-auth-v2__side-head">
        <span class="wt-auth-v2__side-eyebrow">🛡️ <?= e(t('auth.2fa.side_eyebrow')) ?></span>
        <h2 class="wt-auth-v2__side-title"><?= e(t('auth.2fa.side_title')) ?></h2>
      </header>

      <ul class="wt-auth-v2__tips">
        <li>
          <span class="wt-auth-v2__tip-icon" aria-hidden="true">📱</span>
          <span><?= e(t('auth.2fa.tip1')) ?></span>
        </li>
        <li>
          <span class="wt-auth-v2__tip-icon" aria-hidden="true">⏱</span>
          <span><?= e(t('auth.2fa.tip2')) ?></span>
        </li>
        <li>
          <span class="wt-auth-v2__tip-icon" aria-hidden="true">🔒</span>
          <span><?= e(t('auth.2fa.tip3')) ?></span>
        </li>
      </ul>

      <p class="wt-auth-v2__side-foot">
        ℹ️ <?= e(t('auth.2fa.side_foot')) ?>
      </p>
    </aside>

  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
