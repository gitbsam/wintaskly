<?php
/**
 * Wintaskly — Vérification de l'adresse e-mail (V8 modernisé).
 *
 * Deux modes selon les paramètres :
 *
 *   /auth/verify-email.php?token=…
 *     → consomme le token, marque le compte vérifié, connecte
 *       automatiquement l'utilisateur, redirige vers /dashboard/.
 *
 *   /auth/verify-email.php  (sans token)
 *     → affiche la page d'attente avec timeline + bouton renvoi
 *       (60s entre deux clics, géré côté JS via data-resend-btn).
 *
 * Compat : data-resend-btn, [data-resend-success/error], <html data-resendOk>
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

$db        = db();
$pageTitle = t('auth.verify_email.title');

/* ----------------- Branche 1 : token présent → validation ----------------- */
$flash = null;
if (!empty($_GET['token'])) {
    $userId = auth_token_consume((string) $_GET['token'], 'verify_email');

    if ($userId === null) {
        $flash = ['type' => 'error', 'msg' => t('auth.verify_email.invalid')];
    } else {
        $stmt = $db->prepare(
            "UPDATE users
                SET status = 'active',
                    email_verified_at = UTC_TIMESTAMP()
              WHERE id = ? AND status = 'pending'"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();

        session_regenerate_id(true);
        $_SESSION['uid'] = $userId;
        auth_tokens_revoke($userId, 'verify_email');

        header('Location: ' . wt_url('/dashboard/?welcome=1'));
        exit;
    }
}

/* ----------------- Branche 2 : page d'attente ----------------- */
$pendingEmail = $_SESSION['pending_verify_email'] ?? null;

include __DIR__ . '/../header.php';
?>

<script>
(function () {
  document.documentElement.dataset.resendOk =
    <?= json_encode(t('auth.verify_email.intro')) ?>;
})();
</script>

<main class="wt-main wt-auth-v2" data-reveal>
  <div class="wt-auth-v2__wrap">

    <section class="wt-auth-v2__form-col">
      <!-- Illustration moderne SVG -->
      <div class="wt-auth-v2__mail-illust" aria-hidden="true">
        <svg viewBox="0 0 200 140" width="200" height="140">
          <defs>
            <linearGradient id="gradEnv2" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0%"  stop-color="var(--wt-accent)"/>
              <stop offset="100%" stop-color="var(--wt-accent2)"/>
            </linearGradient>
          </defs>
          <rect x="20" y="40" width="160" height="90" rx="14" fill="url(#gradEnv2)" opacity=".12"/>
          <path d="M28 50l72 50 72-50v62a8 8 0 0 1-8 8H36a8 8 0 0 1-8-8z" fill="url(#gradEnv2)"/>
          <path d="M28 50l72 50 72-50" fill="none" stroke="var(--wt-text)" stroke-width="2" opacity=".25"/>
          <circle cx="150" cy="40" r="20" fill="var(--wt-accent2)"/>
          <path d="M141 40l7 7 13-13" fill="none" stroke="var(--wt-text)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </div>

      <header class="wt-auth-v2__head wt-auth-v2__head--centered">
        <span class="wt-eyebrow">📬 <?= e(t('auth.eyebrow_verify_email')) ?></span>
        <h1 class="wt-auth-v2__title"><?= e(t('auth.verify_email.title')) ?></h1>
        <p class="wt-auth-v2__lead">
          <?= e(t('auth.verify_email.intro')) ?>
          <?php if ($pendingEmail): ?>
            <br><strong class="wt-auth-v2__pending-email"><?= e($pendingEmail) ?></strong>
          <?php endif; ?>
        </p>
      </header>

      <?php if ($flash): ?>
        <div class="wt-alert wt-alert--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
      <?php endif; ?>

      <div class="wt-alert wt-alert--success is-hidden" data-resend-success></div>
      <div class="wt-alert wt-alert--error   is-hidden" data-resend-error></div>

      <button type="button"
              class="wt-btn wt-btn--primary wt-btn--lg wt-btn--block"
              data-resend-btn
              data-endpoint="<?= e(wt_url('/api/auth_resend_verification.php')) ?>"
              data-csrf="<?= e(csrf_token()) ?>">
        <span class="wt-btn__label">📨 <?= e(t('auth.verify_email.resend')) ?></span>
      </button>

      <p class="wt-auth-v2__spam-note">
        💡 <?= e(t('auth.verify_email.spam_note')) ?>
      </p>

      <p class="wt-auth-v2__alt">
        <a href="<?= e(wt_url('/auth/login.php')) ?>">← <?= e(t('common.back')) ?></a>
      </p>
    </section>

    <!-- Timeline étapes -->
    <aside class="wt-auth-v2__side wt-auth-v2__side--email">
      <div class="wt-auth-v2__side-bg" aria-hidden="true"></div>

      <header class="wt-auth-v2__side-head">
        <span class="wt-auth-v2__side-eyebrow">📋 <?= e(t('auth.verify_email.side_eyebrow')) ?></span>
        <h2 class="wt-auth-v2__side-title"><?= e(t('auth.verify_email.side_title')) ?></h2>
      </header>

      <ol class="wt-auth-v2__steps">
        <li class="is-done">
          <span class="wt-auth-v2__step-num">✓</span>
          <div>
            <strong><?= e(t('auth.verify_email.step1_title')) ?></strong>
            <small><?= e(t('auth.verify_email.step1_text')) ?></small>
          </div>
        </li>
        <li class="is-current">
          <span class="wt-auth-v2__step-num">2</span>
          <div>
            <strong><?= e(t('auth.verify_email.step2_title')) ?></strong>
            <small><?= e(t('auth.verify_email.step2_text')) ?></small>
          </div>
        </li>
        <li>
          <span class="wt-auth-v2__step-num">3</span>
          <div>
            <strong><?= e(t('auth.verify_email.step3_title')) ?></strong>
            <small><?= e(t('auth.verify_email.step3_text')) ?></small>
          </div>
        </li>
      </ol>

      <p class="wt-auth-v2__side-foot">
        ⏱ <?= e(t('auth.verify_email.expires_note')) ?>
      </p>
    </aside>

  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
