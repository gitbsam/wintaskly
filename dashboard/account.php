<?php
/**
 * Wintaskly — /dashboard/account.php
 *
 * Page profil de l'utilisateur :
 *   • Avatar et identité publique (username, bio, pays)
 *   • Infos de connexion (email, téléphone E.164, mot de passe)
 *   • Suppression du compte (danger zone) avec modal de confirmation
 *
 * Tous les formulaires utilisent [data-auth-form] pour le POST Ajax
 * via le handler existant dans wintaskly.js.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_auth();

$pageTitle  = t('account.title');
$dashActive = 'account';
$u  = current_user();
$db = db();

/* Y a-t-il déjà une demande de suppression en cours ? */
$deletePending = !empty($u['delete_requested_at']);
$graceDays     = (int) cfg('account.delete_grace_days', '7');

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-dash">
  <div class="wt-dash__layout">
    <?php include __DIR__ . '/_nav.php'; ?>

    <section class="wt-dash__content wt-dash-v2__content">
      <header class="wt-dash-v2__page-header" data-reveal>
        <span class="wt-eyebrow">👤 <?= e(t('account.eyebrow')) ?></span>
        <h1 class="wt-dash-v2__title"><?= e(t('account.title')) ?></h1>
        <p class="wt-muted"><?= e(t('account.lead')) ?></p>
      </header>

      <?php if ($deletePending): ?>
        <div class="wt-alert wt-alert--error" style="margin-bottom:1.5rem">
          ⏳ <?= e(t('account.delete_pending_notice')) ?>
          <a href="<?= e(wt_url('/api/account_delete_cancel.php')) ?>"
             data-confirm
             data-confirm-title="<?= e(t('account.cancel_delete_title')) ?>"
             data-confirm-body="<?= e(t('account.cancel_delete_body')) ?>"
             data-confirm-ok="<?= e(t('account.cancel_delete_ok')) ?>"
             data-confirm-post="<?= e(wt_url('/api/account_delete_cancel.php')) ?>">
            <?= e(t('account.cancel_delete')) ?>
          </a>
        </div>
      <?php endif; ?>

      <!-- ============ IDENTITÉ PUBLIQUE ============ -->
      <article class="wt-account__group" data-reveal>
        <h2>🪪 <?= e(t('account.identity_title')) ?></h2>
        <p class="wt-muted"><?= e(t('account.identity_lead')) ?></p>

        <div class="wt-account__avatar">
          <div class="wt-avatar wt-avatar--md"><?= wt_avatar_inner($u) ?></div>
          <div class="wt-account__avatar-meta">
            <strong><?= e($u['username']) ?></strong>
            <p class="wt-muted">
              <?= e(t('account.member_since')) ?>
              <?= e(wt_format_datetime($u['created_at'], 'd M Y')) ?>
            </p>
          </div>
        </div>

        <div class="wt-alert wt-alert--success is-hidden" data-auth-success></div>
        <div class="wt-alert wt-alert--error   is-hidden" data-auth-error></div>

        <form class="wt-form wt-form--grid"
              data-auth-form
              data-keep-form
              data-endpoint="<?= e(wt_url('/api/account_profile.php')) ?>">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('account.username')) ?></span>
            <input class="wt-input" type="text" name="username"
                   value="<?= e($u['username']) ?>" required minlength="3" maxlength="40"
                   pattern="[a-zA-Z0-9._-]+">
          </label>

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('account.country')) ?></span>
            <input class="wt-input" type="text" name="country"
                   value="<?= e($u['country'] ?? '') ?>" maxlength="2"
                   placeholder="FR, YT, RE..."
                   pattern="[A-Za-z]{2}" style="text-transform:uppercase">
          </label>

          <label class="wt-field wt-field--wide">
            <span class="wt-field__label"><?= e(t('account.bio')) ?></span>
            <textarea class="wt-input wt-textarea" name="bio" rows="3" maxlength="500"
                      placeholder="<?= e(t('account.bio_placeholder')) ?>"><?= e($u['bio'] ?? '') ?></textarea>
          </label>

          <div class="wt-form__actions wt-field--wide">
            <button type="submit" class="wt-btn wt-btn--primary" data-submit-btn>
              <span class="wt-btn__label"><?= e(t('common.save')) ?></span>
              <span class="wt-btn__spinner is-hidden" aria-hidden="true"></span>
            </button>
          </div>
        </form>
      </article>

      <!-- ============ INFOS DE CONNEXION ============ -->
      <article class="wt-account__group" data-reveal id="email">
        <h2>📧 <?= e(t('account.email_title')) ?></h2>
        <p class="wt-muted">
          <?= e(t('account.email_lead')) ?>
          <?php if (empty($u['email_verified_at'])): ?>
            <br><strong style="color:#facc15">⚠️ <?= e(t('account.email_unverified')) ?></strong>
          <?php endif; ?>
        </p>

        <div class="wt-alert wt-alert--success is-hidden" data-auth-success></div>
        <div class="wt-alert wt-alert--error   is-hidden" data-auth-error></div>

        <form class="wt-form"
              data-auth-form
              data-keep-form
              data-endpoint="<?= e(wt_url('/api/account_email.php')) ?>">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('auth.email')) ?></span>
            <input class="wt-input" type="email" name="email"
                   value="<?= e($u['email']) ?>" required maxlength="190">
          </label>

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('account.confirm_password')) ?></span>
            <div class="wt-input-wrap wt-input-wrap--password">
              <input class="wt-input" type="password" name="password" required minlength="8"
                     autocomplete="current-password">
              <button type="button" class="wt-input-eye" data-toggle-pw aria-label="Afficher/masquer le mot de passe" tabindex="-1">
                <svg class="wt-input-eye__off" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="wt-input-eye__on is-hidden" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a21.69 21.69 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a21.7 21.7 0 0 1-3.17 4.19M14.12 14.12a3 3 0 1 1-4.24-4.24M1 1l22 22"/></svg>
              </button>
            </div>
          </label>

          <div class="wt-form__actions">
            <button type="submit" class="wt-btn wt-btn--primary" data-submit-btn>
              <span class="wt-btn__label"><?= e(t('account.update_email')) ?></span>
              <span class="wt-btn__spinner is-hidden" aria-hidden="true"></span>
            </button>
          </div>
        </form>
      </article>

      <!-- ============ TÉLÉPHONE (pour 2FA SMS) ============ -->
      <article class="wt-account__group" data-reveal id="phone">
        <h2>📞 <?= e(t('account.phone_title')) ?></h2>
        <p class="wt-muted"><?= e(t('account.phone_lead')) ?></p>

        <div class="wt-alert wt-alert--success is-hidden" data-auth-success></div>
        <div class="wt-alert wt-alert--error   is-hidden" data-auth-error></div>

        <form class="wt-form"
              data-auth-form
              data-keep-form
              data-endpoint="<?= e(wt_url('/api/account_phone.php')) ?>">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('account.phone')) ?></span>
            <input class="wt-input" type="tel" name="phone"
                   value="<?= e($u['phone_e164'] ?? '') ?>"
                   placeholder="+262692123456"
                   pattern="^\+\d{8,15}$" maxlength="20"
                   autocomplete="tel">
            <small class="wt-card__hint" style="margin-top:.3rem">
              <?= e(t('account.phone_format_hint')) ?>
            </small>
          </label>

          <div class="wt-form__actions">
            <button type="submit" class="wt-btn wt-btn--primary" data-submit-btn>
              <span class="wt-btn__label"><?= e(t('common.save')) ?></span>
              <span class="wt-btn__spinner is-hidden" aria-hidden="true"></span>
            </button>
            <?php if (!empty($u['phone_e164'])): ?>
              <button type="button" class="wt-btn wt-btn--ghost"
                      data-confirm
                      data-confirm-title="<?= e(t('account.phone_remove_title')) ?>"
                      data-confirm-body="<?= e(t('account.phone_remove_body')) ?>"
                      data-confirm-ok="<?= e(t('common.remove')) ?>"
                      data-confirm-ok-class="wt-btn--danger"
                      data-confirm-post="<?= e(wt_url('/api/account_phone.php')) ?>"
                      data-confirm-data='{"phone":""}'>
                <?= e(t('common.remove')) ?>
              </button>
            <?php endif; ?>
          </div>
        </form>
      </article>

      <!-- ============ MOT DE PASSE ============ -->
      <article class="wt-account__group" data-reveal>
        <h2>🔑 <?= e(t('account.password_title')) ?></h2>
        <p class="wt-muted"><?= e(t('account.password_lead')) ?></p>

        <div class="wt-alert wt-alert--success is-hidden" data-auth-success></div>
        <div class="wt-alert wt-alert--error   is-hidden" data-auth-error></div>

        <form class="wt-form"
              data-auth-form
              data-keep-form
              data-endpoint="<?= e(wt_url('/api/account_password.php')) ?>">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('account.password_current')) ?></span>
            <div class="wt-input-wrap wt-input-wrap--password">
              <input class="wt-input" type="password" name="current_password" required minlength="8"
                     autocomplete="current-password">
              <button type="button" class="wt-input-eye" data-toggle-pw aria-label="Afficher/masquer" tabindex="-1">
                <svg class="wt-input-eye__off" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="wt-input-eye__on is-hidden" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a21.69 21.69 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a21.7 21.7 0 0 1-3.17 4.19M14.12 14.12a3 3 0 1 1-4.24-4.24M1 1l22 22"/></svg>
              </button>
            </div>
          </label>

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('account.password_new')) ?></span>
            <div class="wt-input-wrap wt-input-wrap--password">
              <input class="wt-input" type="password" name="new_password" required minlength="8"
                     autocomplete="new-password">
              <button type="button" class="wt-input-eye" data-toggle-pw aria-label="Afficher/masquer" tabindex="-1">
                <svg class="wt-input-eye__off" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="wt-input-eye__on is-hidden" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a21.69 21.69 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a21.7 21.7 0 0 1-3.17 4.19M14.12 14.12a3 3 0 1 1-4.24-4.24M1 1l22 22"/></svg>
              </button>
            </div>
          </label>

          <div class="wt-form__actions">
            <button type="submit" class="wt-btn wt-btn--primary" data-submit-btn>
              <span class="wt-btn__label"><?= e(t('account.update_password')) ?></span>
              <span class="wt-btn__spinner is-hidden" aria-hidden="true"></span>
            </button>
          </div>
        </form>
      </article>

      <!-- ============ DANGER ZONE — SUPPRESSION ============ -->
      <?php if (!$deletePending): ?>
        <article class="wt-danger-zone" data-reveal>
          <h2>⚠️ <?= e(t('account.danger_title')) ?></h2>
          <p class="wt-muted">
            <?= e(t('account.danger_lead')) ?>
            <br><strong><?= e(t('account.danger_grace', ['days' => $graceDays])) ?></strong>
          </p>

          <button type="button" class="wt-btn wt-btn--danger"
                  data-confirm
                  data-confirm-title="<?= e(t('account.delete_title')) ?>"
                  data-confirm-body="<?= e(t('account.delete_body', ['days' => $graceDays])) ?>"
                  data-confirm-ok="<?= e(t('account.delete_ok')) ?>"
                  data-confirm-ok-class="wt-btn--danger"
                  data-confirm-typed="SUPPRIMER"
                  data-confirm-post="<?= e(wt_url('/api/account_delete.php')) ?>">
            🗑️ <?= e(t('account.delete_button')) ?>
          </button>
        </article>
      <?php endif; ?>

    </section>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
