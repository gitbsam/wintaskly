<?php
/**
 * Wintaskly — Connexion (V8 modernisé).
 *
 * Formulaire Ajax (fetch JSON) qui POSTe vers /api/auth_login.php.
 * Compat 100% : hooks data-auth-form, data-endpoint, data-toggle-pw,
 * [data-auth-error], [data-submit-btn] préservés.
 *
 * Layout V8 :
 *   - Card formulaire à gauche (équivalent ancien)
 *   - Panneau marketing à droite (desktop) avec social-proof live :
 *       * Compteur de retraits validés aujourd'hui
 *       * Bénéfices clés (3 puces)
 *       * Lien d'inscription mis en avant
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

if (current_user()) {
    header('Location: ' . wt_url('/dashboard/'));
    exit;
}

/* Stats live pour la sidebar (cache mémoire 60s via session) */
$stats = $_SESSION['__wt_auth_stats'] ?? null;
if (!$stats || ($stats['t'] ?? 0) < time() - 60) {
    $db = db();
    $row = $db->query(
        "SELECT COUNT(*) c, COALESCE(SUM(payout_amount), 0) total
           FROM withdrawals
          WHERE status = 'completed'
            AND processed_at >= UTC_DATE()"
    )->fetch_assoc();
    $stats = [
        't'     => time(),
        'count' => (int)($row['c'] ?? 0),
        'total' => (float)($row['total'] ?? 0),
    ];
    $_SESSION['__wt_auth_stats'] = $stats;
}

$pageTitle = t('auth.login');
include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-auth-v2" data-reveal>
  <div class="wt-auth-v2__wrap">

    <!-- ============ COLONNE FORM (gauche) ============ -->
    <section class="wt-auth-v2__form-col">
      <header class="wt-auth-v2__head">
        <span class="wt-eyebrow">👋 <?= e(t('auth.eyebrow_login')) ?></span>
        <h1 class="wt-auth-v2__title"><?= e(t('auth.login')) ?></h1>
        <p class="wt-auth-v2__lead"><?= e(t('auth.lead_login')) ?></p>
      </header>

      <div class="wt-alert wt-alert--error is-hidden" data-auth-error></div>

      <form class="wt-form wt-auth-v2__form"
            data-auth-form
            data-endpoint="<?= e(wt_url('/api/auth_login.php')) ?>"
            novalidate>
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

        <label class="wt-field">
          <span class="wt-field__label"><?= e(t('auth.identifier')) ?></span>
          <input class="wt-input" type="text" name="identifier"
                 autocomplete="username" required autofocus
                 placeholder="<?= e(t('auth.identifier_placeholder')) ?>">
        </label>

        <label class="wt-field">
          <span class="wt-field__label">
            <?= e(t('auth.password')) ?>
            <a href="<?= e(wt_url('/auth/forgot-password.php')) ?>"
               class="wt-auth-v2__forgot-link">
              <?= e(t('auth.forgot')) ?>
            </a>
          </span>
          <div class="wt-input-wrap wt-input-wrap--password">
            <input class="wt-input" type="password" name="password"
                 autocomplete="current-password" required minlength="8">
            <button type="button" class="wt-input-eye" data-toggle-pw
                    aria-label="<?= e(t('auth.toggle_pw')) ?>" tabindex="-1">
              <svg class="wt-input-eye__off" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg class="wt-input-eye__on is-hidden" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a21.69 21.69 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a21.7 21.7 0 0 1-3.17 4.19M14.12 14.12a3 3 0 1 1-4.24-4.24M1 1l22 22"/></svg>
            </button>
          </div>
        </label>

        <label class="wt-checkbox wt-auth-v2__remember">
          <input type="checkbox" name="remember" value="1">
          <span><?= e(t('auth.remember_me')) ?></span>
        </label>

        <button type="submit" class="wt-btn wt-btn--primary wt-btn--lg wt-btn--block" data-submit-btn>
          <span class="wt-btn__label">→ <?= e(t('auth.submit_login')) ?></span>
          <span class="wt-btn__spinner is-hidden" aria-hidden="true"></span>
        </button>
      </form>

      <p class="wt-auth-v2__alt">
        <?= e(t('auth.no_account')) ?>
        <a href="<?= e(wt_url('/auth/signup.php')) ?>"><?= e(t('auth.register')) ?> →</a>
      </p>
    </section>

    <!-- ============ COLONNE MARKETING (droite) ============ -->
    <aside class="wt-auth-v2__side wt-auth-v2__side--login">
      <div class="wt-auth-v2__side-bg" aria-hidden="true"></div>

      <header class="wt-auth-v2__side-head">
        <span class="wt-auth-v2__side-eyebrow">🚀 <?= e(t('auth.side.live')) ?></span>
        <h2 class="wt-auth-v2__side-title"><?= e(t('auth.side.welcome_back')) ?></h2>
      </header>

      <!-- Stat live -->
      <?php if ($stats['count'] > 0): ?>
        <div class="wt-auth-v2__live-stat">
          <div class="wt-auth-v2__live-stat-pulse" aria-hidden="true"></div>
          <div>
            <strong><?= (int)$stats['count'] ?></strong>
            <small><?= e(t('auth.side.withdrawals_today')) ?></small>
          </div>
        </div>
      <?php endif; ?>

      <ul class="wt-auth-v2__benefits">
        <li>
          <span class="wt-auth-v2__benefit-icon" aria-hidden="true">💰</span>
          <div>
            <strong><?= e(t('auth.benefit_earn_title')) ?></strong>
            <small><?= e(t('auth.benefit_earn_text')) ?></small>
          </div>
        </li>
        <li>
          <span class="wt-auth-v2__benefit-icon" aria-hidden="true">⚡</span>
          <div>
            <strong><?= e(t('auth.benefit_fast_title')) ?></strong>
            <small><?= e(t('auth.benefit_fast_text')) ?></small>
          </div>
        </li>
        <li>
          <span class="wt-auth-v2__benefit-icon" aria-hidden="true">🔒</span>
          <div>
            <strong><?= e(t('auth.benefit_secure_title')) ?></strong>
            <small><?= e(t('auth.benefit_secure_text')) ?></small>
          </div>
        </li>
      </ul>
    </aside>

  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
