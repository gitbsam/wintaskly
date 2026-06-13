<?php
/**
 * Wintaskly — /admin/security.php
 *
 * Paramètres globaux de sécurité :
 *   • Méthodes 2FA disponibles pour les utilisateurs (TOTP, Email, SMS)
 *   • Provider SMS (placeholder pour Twilio/Vonage/etc.)
 *   • Délai de grâce avant purge effective d'un compte supprimé
 *
 * Les changements sont enregistrés dans la table `config` via
 * /api/admin_config_set.php.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_role('admin');

$pageTitle    = t('admin.security');
$adminActive  = 'security';

$cfg = [
    'tfa.totp_available'        => (int) cfg('tfa.totp_available',        '1'),
    'tfa.email_available'       => (int) cfg('tfa.email_available',       '1'),
    'tfa.sms_available'         => (int) cfg('tfa.sms_available',         '0'),
    'tfa.sms_provider'          => (string) cfg('tfa.sms_provider',       ''),
    'account.delete_grace_days' => (int) cfg('account.delete_grace_days', '7'),
];

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
  <section class="wt-admin-v2__content">
    <header data-reveal>
      <header class="wt-admin-v2__page-header">
        <div>
          <span class="wt-eyebrow">🔐 <?= e(t('admin.eyebrow_security')) ?></span>
          <h1 class="wt-admin-v2__title"><?= e(t('admin.security')) ?></h1>
          <p class="wt-muted"><?= e(t('admin.security.lead')) ?></p>
        </div>
      </header>
      <p class="wt-muted"><?= e(t('admin.security_lead')) ?></p>
    </header>

    <!-- ============ MÉTHODES 2FA DISPONIBLES ============ -->
    <article class="wt-settings__group" data-reveal>
      <h2><?= e(t('admin.security_tfa_title')) ?></h2>
      <p class="wt-muted"><?= e(t('admin.security_tfa_lead')) ?></p>

      <div class="wt-settings__row">
        <div class="wt-settings__row-info">
          <strong>📱 <?= e(t('settings.tfa_totp')) ?></strong>
          <span class="wt-muted"><?= e(t('admin.security_tfa_totp_desc')) ?></span>
        </div>
        <label class="wt-switch">
          <input type="checkbox" data-admin-config data-key="tfa.totp_available"
                 <?= $cfg['tfa.totp_available'] === 1 ? 'checked' : '' ?>>
          <span class="wt-switch__slider"></span>
        </label>
      </div>

      <div class="wt-settings__row">
        <div class="wt-settings__row-info">
          <strong>✉️ <?= e(t('settings.tfa_email')) ?></strong>
          <span class="wt-muted"><?= e(t('admin.security_tfa_email_desc')) ?></span>
        </div>
        <label class="wt-switch">
          <input type="checkbox" data-admin-config data-key="tfa.email_available"
                 <?= $cfg['tfa.email_available'] === 1 ? 'checked' : '' ?>>
          <span class="wt-switch__slider"></span>
        </label>
      </div>

      <div class="wt-settings__row">
        <div class="wt-settings__row-info">
          <strong>💬 <?= e(t('settings.tfa_sms')) ?></strong>
          <span class="wt-muted">
            <?= e(t('admin.security_tfa_sms_desc')) ?>
            <?php if (empty($cfg['tfa.sms_provider'])): ?>
              <br><strong style="color:#facc15">⚠️ <?= e(t('admin.security_provider_missing')) ?></strong>
            <?php endif; ?>
          </span>
        </div>
        <label class="wt-switch">
          <input type="checkbox" data-admin-config data-key="tfa.sms_available"
                 <?= $cfg['tfa.sms_available'] === 1 ? 'checked' : '' ?>>
          <span class="wt-switch__slider"></span>
        </label>
      </div>

      <div class="wt-settings__row">
        <div class="wt-settings__row-info">
          <strong><?= e(t('admin.security_sms_provider')) ?></strong>
          <span class="wt-muted"><?= e(t('admin.security_sms_provider_desc')) ?></span>
        </div>
        <input class="wt-input wt-input--sm" type="text"
               data-admin-config data-key="tfa.sms_provider"
               value="<?= e($cfg['tfa.sms_provider']) ?>"
               placeholder="twilio | vonage | bandwidth | …"
               style="max-width:200px">
      </div>
    </article>

    <!-- ============ SUPPRESSION COMPTE ============ -->
    <article class="wt-settings__group" data-reveal>
      <h2>🗑️ <?= e(t('admin.security_delete_title')) ?></h2>
      <p class="wt-muted"><?= e(t('admin.security_delete_lead')) ?></p>

      <div class="wt-settings__row">
        <div class="wt-settings__row-info">
          <strong><?= e(t('admin.security_grace_days')) ?></strong>
          <span class="wt-muted"><?= e(t('admin.security_grace_days_desc')) ?></span>
        </div>
        <input class="wt-input wt-input--sm" type="number"
               data-admin-config data-key="account.delete_grace_days"
               value="<?= (int)$cfg['account.delete_grace_days'] ?>"
               min="0" max="90" style="max-width:100px">
      </div>
    </article>

    <!-- ============ JOURNAL DES ACTIONS ADMIN ============ -->
    <article class="wt-settings__group" data-reveal>
      <h2>📜 <?= e(t('admin.security_log_title')) ?></h2>
      <p class="wt-muted"><?= e(t('admin.security_log_lead')) ?></p>

      <?php
      $logs = [];
      $exists = db()->query("SHOW TABLES LIKE 'admin_actions'");
      if ($exists && $exists->num_rows > 0) {
          $logs = db()->query("
            SELECT a.*, u1.username AS admin_name, u2.username AS target_name
              FROM admin_actions a
              LEFT JOIN users u1 ON u1.id = a.admin_id
              LEFT JOIN users u2 ON u2.id = a.target_id
             ORDER BY a.id DESC
             LIMIT 25
          ")->fetch_all(MYSQLI_ASSOC);
      }
      ?>
      <?php if (empty($logs)): ?>
        <p class="wt-muted"><?= e(t('admin.security_log_empty')) ?></p>
      <?php else: ?>
        <div class="wt-table-wrap">
          <table class="wt-table">
            <thead>
              <tr>
                <th><?= e(t('common.when')) ?></th>
                <th><?= e(t('admin.security_log_admin')) ?></th>
                <th><?= e(t('admin.security_log_action')) ?></th>
                <th><?= e(t('admin.security_log_target')) ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($logs as $l): ?>
                <tr>
                  <td><small data-fmt-time data-utc="<?= e($l['created_at']) ?>"><?= e(wt_format_datetime($l['created_at'], 'd/m H:i')) ?></small></td>
                  <td><strong><?= e($l['admin_name'] ?: '#' . $l['admin_id']) ?></strong></td>
                  <td><code><?= e($l['action']) ?></code></td>
                  <td><?= e($l['target_name'] ?: ($l['meta'] ?: '#' . $l['target_id'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </article>

  </section>
</div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
