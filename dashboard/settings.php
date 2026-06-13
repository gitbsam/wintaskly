<?php
/**
 * Wintaskly — /dashboard/settings.php
 *
 * Paramètres compte : méthodes 2FA, langue, thème, fuseau,
 * préférences de notifications.
 *
 * Chaque toggle appelle /api/settings_toggle.php en POST avec
 * { _csrf, key, value } et reçoit { ok, error?, snapshot? }.
 *
 * Les méthodes 2FA non activées par l'admin (config tfa.*_available=0)
 * sont affichées en lecture seule avec un badge "indisponible".
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_auth();

$pageTitle  = t('settings.title');
$dashActive = 'settings';
$u  = current_user();
$db = db();

/* Quelles méthodes 2FA sont activées globalement par l'admin ? */
$tfaAvail = [
    'totp'  => (int) cfg('tfa.totp_available',  '1') === 1,
    'email' => (int) cfg('tfa.email_available', '1') === 1,
    'sms'   => (int) cfg('tfa.sms_available',   '0') === 1,
];

/* État courant pour l'utilisateur */
$tfaUser = [
    'totp'  => (int) ($u['totp_enabled']      ?? 0) === 1,
    'email' => (int) ($u['tfa_email_enabled'] ?? 0) === 1,
    'sms'   => (int) ($u['tfa_sms_enabled']   ?? 0) === 1,
];

/* Combien de méthodes actives au total (pour message d'état) */
$tfaActiveCount = array_sum(array_map('intval', $tfaUser));

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-dash">
  <div class="wt-dash__layout">
    <?php include __DIR__ . '/_nav.php'; ?>

    <section class="wt-dash__content wt-dash-v2__content">
      <header class="wt-dash-v2__page-header" data-reveal>
        <span class="wt-eyebrow">⚙️ <?= e(t('settings.eyebrow')) ?></span>
        <h1 class="wt-dash-v2__title"><?= e(t('settings.title')) ?></h1>
        <p class="wt-muted"><?= e(t('settings.lead')) ?></p>
      </header>

      <!-- =============== SÉCURITÉ — 2FA =============== -->
      <article class="wt-settings__group" data-reveal>
        <h2>🔐 <?= e(t('settings.tfa_title')) ?></h2>
        <p class="wt-muted">
          <?= e(t('settings.tfa_lead')) ?>
          <?php if ($tfaActiveCount > 0): ?>
            <strong style="color:#22c55e">
              · <?= (int)$tfaActiveCount ?> <?= e(t('settings.tfa_active_count')) ?>
            </strong>
          <?php endif; ?>
        </p>

        <!-- Méthode TOTP (Google Authenticator, Authy, 1Password…) -->
        <div class="wt-settings__row">
          <div class="wt-settings__row-info">
            <strong>📱 <?= e(t('settings.tfa_totp')) ?></strong>
            <span class="wt-muted"><?= e(t('settings.tfa_totp_desc')) ?></span>
          </div>
          <?php if (!$tfaAvail['totp']): ?>
            <span class="wt-settings__row-status wt-settings__row-status--off">
              <?= e(t('settings.unavailable')) ?>
            </span>
          <?php elseif ($tfaUser['totp']): ?>
            <span class="wt-settings__row-status wt-settings__row-status--on">
              <?= e(t('common.enabled')) ?>
            </span>
            <a class="wt-btn wt-btn--xs wt-btn--ghost"
               href="<?= e(wt_url('/dashboard/2fa-setup.php?disable=1')) ?>">
              <?= e(t('common.disable')) ?>
            </a>
          <?php else: ?>
            <a class="wt-btn wt-btn--xs wt-btn--primary"
               href="<?= e(wt_url('/dashboard/2fa-setup.php')) ?>">
              <?= e(t('settings.tfa_configure')) ?>
            </a>
          <?php endif; ?>
        </div>

        <!-- Méthode Email -->
        <div class="wt-settings__row">
          <div class="wt-settings__row-info">
            <strong>✉️ <?= e(t('settings.tfa_email')) ?></strong>
            <span class="wt-muted"><?= e(t('settings.tfa_email_desc')) ?></span>
          </div>
          <?php if (!$tfaAvail['email']): ?>
            <span class="wt-settings__row-status wt-settings__row-status--off">
              <?= e(t('settings.unavailable')) ?>
            </span>
          <?php else: ?>
            <label class="wt-switch">
              <input type="checkbox" data-settings-toggle data-key="tfa_email_enabled"
                     <?= $tfaUser['email'] ? 'checked' : '' ?>>
              <span class="wt-switch__slider"></span>
            </label>
          <?php endif; ?>
        </div>

        <!-- Méthode SMS -->
        <div class="wt-settings__row">
          <div class="wt-settings__row-info">
            <strong>💬 <?= e(t('settings.tfa_sms')) ?></strong>
            <span class="wt-muted">
              <?= e(t('settings.tfa_sms_desc')) ?>
              <?php if (!empty($u['phone_e164'])): ?>
                <br><?= e(t('settings.phone_current')) ?> <code><?= e($u['phone_e164']) ?></code>
                · <a href="<?= e(wt_url('/dashboard/account.php')) ?>"><?= e(t('common.edit')) ?></a>
              <?php endif; ?>
            </span>
          </div>
          <?php if (!$tfaAvail['sms']): ?>
            <span class="wt-settings__row-status wt-settings__row-status--off">
              <?= e(t('settings.unavailable')) ?>
            </span>
          <?php elseif (empty($u['phone_e164'])): ?>
            <a class="wt-btn wt-btn--xs wt-btn--ghost"
               href="<?= e(wt_url('/dashboard/account.php#phone')) ?>">
              <?= e(t('settings.tfa_add_phone')) ?>
            </a>
          <?php else: ?>
            <label class="wt-switch">
              <input type="checkbox" data-settings-toggle data-key="tfa_sms_enabled"
                     <?= $tfaUser['sms'] ? 'checked' : '' ?>>
              <span class="wt-switch__slider"></span>
            </label>
          <?php endif; ?>
        </div>
      </article>

      <!-- =============== APPARENCE =============== -->
      <article class="wt-settings__group" data-reveal>
        <h2>🎨 <?= e(t('settings.appearance_title')) ?></h2>

        <div class="wt-settings__row">
          <div class="wt-settings__row-info">
            <strong><?= e(t('settings.theme')) ?></strong>
            <span class="wt-muted"><?= e(t('settings.theme_desc')) ?></span>
          </div>
          <select class="wt-input wt-input--sm" data-settings-toggle data-key="theme" style="max-width:140px">
            <option value="dark"  <?= ($u['theme'] ?? 'dark') === 'dark'  ? 'selected' : '' ?>><?= e(t('settings.theme_dark')) ?></option>
            <option value="light" <?= ($u['theme'] ?? 'dark') === 'light' ? 'selected' : '' ?>><?= e(t('settings.theme_light')) ?></option>
          </select>
        </div>

        <div class="wt-settings__row">
          <div class="wt-settings__row-info">
            <strong><?= e(t('settings.language')) ?></strong>
            <span class="wt-muted"><?= e(t('settings.language_desc')) ?></span>
          </div>
          <select class="wt-input wt-input--sm" data-settings-toggle data-key="lang" style="max-width:140px">
            <option value="fr" <?= ($u['lang'] ?? 'fr') === 'fr' ? 'selected' : '' ?>>Français</option>
            <option value="en" <?= ($u['lang'] ?? 'fr') === 'en' ? 'selected' : '' ?>>English</option>
          </select>
        </div>

        <div class="wt-settings__row">
          <div class="wt-settings__row-info">
            <strong><?= e(t('settings.timezone')) ?></strong>
            <span class="wt-muted"><?= e(t('settings.timezone_desc')) ?> <code><?= e($u['timezone'] ?? 'UTC') ?></code></span>
          </div>
          <span class="wt-settings__row-status wt-settings__row-status--on">
            <?= e(t('settings.auto')) ?>
          </span>
        </div>
      </article>

      <!-- =============== NOTIFICATIONS =============== -->
      <article class="wt-settings__group" data-reveal>
        <h2>🔔 <?= e(t('settings.notif_title')) ?></h2>
        <p class="wt-muted"><?= e(t('settings.notif_lead')) ?></p>

        <div class="wt-settings__row">
          <div class="wt-settings__row-info">
            <strong><?= e(t('settings.notif_messages')) ?></strong>
            <span class="wt-muted"><?= e(t('settings.notif_messages_desc')) ?></span>
          </div>
          <label class="wt-switch">
            <input type="checkbox" disabled checked>
            <span class="wt-switch__slider"></span>
          </label>
        </div>

        <div class="wt-settings__row">
          <div class="wt-settings__row-info">
            <strong><?= e(t('settings.notif_withdrawals')) ?></strong>
            <span class="wt-muted"><?= e(t('settings.notif_withdrawals_desc')) ?></span>
          </div>
          <label class="wt-switch">
            <input type="checkbox" disabled checked>
            <span class="wt-switch__slider"></span>
          </label>
        </div>
      </article>

    </section>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
