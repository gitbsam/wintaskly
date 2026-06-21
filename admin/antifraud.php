<?php
/**
 * Wintaskly — Admin · Anti-fraude
 *
 * Configuration et supervision du système anti-fraude :
 *   - Détection multi-comptes (seuil + action)
 *   - Limites de retrait (âge compte, email vérifié)
 *   - Seuils de score de risque
 *   - Statistiques + journal des événements
 *   - Liste des comptes signalés pour revue
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_admin();

$pageTitle   = t('admin.title') . ' — ' . t('admin.antifraud');
$adminActive = 'antifraud';
$db          = db();
$notice      = null;

/* ====================== ACTIONS POST ====================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['_csrf'] ?? null)) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_config') {
        wt_config_set('fraud.multiaccount_enabled', !empty($_POST['multiaccount_enabled']) ? '1' : '0');
        wt_config_set('fraud.multiaccount_max_per_ip', (string) max(1, (int)($_POST['multiaccount_max_per_ip'] ?? 3)));
        $maAction = ($_POST['multiaccount_action'] ?? 'flag') === 'block' ? 'block' : 'flag';
        wt_config_set('fraud.multiaccount_action', $maAction);

        wt_config_set('fraud.withdraw_min_account_age_hours', (string) max(0, (int)($_POST['withdraw_min_account_age_hours'] ?? 24)));
        wt_config_set('fraud.withdraw_require_verified_email', !empty($_POST['withdraw_require_verified_email']) ? '1' : '0');

        wt_config_set('fraud.risk_threshold_review', (string) max(0, min(100, (int)($_POST['risk_threshold_review'] ?? 50))));
        wt_config_set('fraud.risk_threshold_block', (string) max(0, min(100, (int)($_POST['risk_threshold_block'] ?? 80))));
        $notice = t('admin.af.saved');

    } elseif ($action === 'clear_flag') {
        // Lever le signalement d'un compte (après revue manuelle)
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid > 0) {
            $stmt = $db->prepare("UPDATE users SET flagged_at = NULL, flag_reason = NULL, risk_score = 0 WHERE id = ?");
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $stmt->close();
            $notice = t('admin.af.flag_cleared');
        }
    }
}

/* ====================== LECTURE ====================== */
$cfg = [
    'multiaccount_enabled'            => wt_fraud_cfg('multiaccount_enabled', '0') === '1',
    'multiaccount_max_per_ip'         => (int) wt_fraud_cfg('multiaccount_max_per_ip', '3'),
    'multiaccount_action'             => wt_fraud_cfg('multiaccount_action', 'flag'),
    'withdraw_min_account_age_hours'  => (int) wt_fraud_cfg('withdraw_min_account_age_hours', '24'),
    'withdraw_require_verified_email' => wt_fraud_cfg('withdraw_require_verified_email', '1') === '1',
    'risk_threshold_review'           => (int) wt_fraud_cfg('risk_threshold_review', '50'),
    'risk_threshold_block'            => (int) wt_fraud_cfg('risk_threshold_block', '80'),
];

$stats  = wt_fraud_stats();
$events = wt_fraud_recent_events(25);

// Comptes signalés pour revue
$flagged = [];
if ($res = $db->query(
    "SELECT id, username, risk_score, flagged_at, flag_reason
       FROM users WHERE flagged_at IS NOT NULL
      ORDER BY risk_score DESC, flagged_at DESC LIMIT 30"
)) {
    while ($r = $res->fetch_assoc()) { $flagged[] = $r; }
    $res->free();
}

$sevColors = ['info' => '#3b82f6', 'warning' => '#f59e0b', 'critical' => '#ef4444'];

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
    <section class="wt-admin-v2__content" data-reveal>

      <header class="wt-admin-v2__page-header">
        <div>
          <span class="wt-eyebrow">🛡️ <?= e(t('admin.af.eyebrow')) ?></span>
          <h1 class="wt-admin-v2__title"><?= e(t('admin.antifraud')) ?></h1>
          <p class="wt-muted"><?= e(t('admin.af.lead')) ?></p>
        </div>
      </header>

      <?php if ($notice): ?><div class="wt-alert wt-alert--success"><?= e($notice) ?></div><?php endif; ?>

      <!-- Stats -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:1rem;margin-bottom:1.5rem">
        <div class="wt-card wt-card--padded" style="text-align:center">
          <div style="font-size:1.8rem;font-weight:800;color:#f59e0b"><?= (int)$stats['flagged_users'] ?></div>
          <div class="wt-muted" style="font-size:.85rem"><?= e(t('admin.af.stat_flagged')) ?></div>
        </div>
        <div class="wt-card wt-card--padded" style="text-align:center">
          <div style="font-size:1.8rem;font-weight:800;color:var(--wt-accent)"><?= (int)$stats['events_today'] ?></div>
          <div class="wt-muted" style="font-size:.85rem"><?= e(t('admin.af.stat_today')) ?></div>
        </div>
        <div class="wt-card wt-card--padded" style="text-align:center">
          <div style="font-size:1.8rem;font-weight:800;color:#ef4444"><?= (int)$stats['critical_events'] ?></div>
          <div class="wt-muted" style="font-size:.85rem"><?= e(t('admin.af.stat_critical')) ?></div>
        </div>
        <div class="wt-card wt-card--padded" style="text-align:center">
          <div style="font-size:1.8rem;font-weight:800;color:#fb923c"><?= (int)$stats['high_risk_users'] ?></div>
          <div class="wt-muted" style="font-size:.85rem"><?= e(t('admin.af.stat_highrisk')) ?></div>
        </div>
      </div>

      <!-- Configuration -->
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_config">

        <!-- Multi-comptes -->
        <section class="wt-card wt-card--padded" style="margin-bottom:1.5rem">
          <h2 style="margin-top:0">👥 <?= e(t('admin.af.ma_title')) ?></h2>
          <p class="wt-muted" style="font-size:.9rem"><?= e(t('admin.af.ma_lead')) ?></p>

          <label class="wt-checkbox" style="display:flex;gap:.75rem;align-items:flex-start;margin-bottom:1rem">
            <input type="checkbox" name="multiaccount_enabled" value="1" <?= $cfg['multiaccount_enabled'] ? 'checked' : '' ?> style="margin-top:.3rem;transform:scale(1.4)">
            <span><strong><?= e(t('admin.af.ma_enable')) ?></strong></span>
          </label>

          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem">
            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.af.ma_max')) ?></span>
              <input class="wt-input" type="number" min="1" max="20" name="multiaccount_max_per_ip" value="<?= $cfg['multiaccount_max_per_ip'] ?>">
              <small class="wt-field__hint"><?= e(t('admin.af.ma_max_hint')) ?></small>
            </label>
            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.af.ma_action')) ?></span>
              <select class="wt-input" name="multiaccount_action">
                <option value="flag" <?= $cfg['multiaccount_action'] === 'flag' ? 'selected' : '' ?>><?= e(t('admin.af.ma_action_flag')) ?></option>
                <option value="block" <?= $cfg['multiaccount_action'] === 'block' ? 'selected' : '' ?>><?= e(t('admin.af.ma_action_block')) ?></option>
              </select>
              <small class="wt-field__hint"><?= e(t('admin.af.ma_action_hint')) ?></small>
            </label>
          </div>
        </section>

        <!-- Limites de retrait -->
        <section class="wt-card wt-card--padded" style="margin-bottom:1.5rem">
          <h2 style="margin-top:0">💸 <?= e(t('admin.af.wd_title')) ?></h2>
          <p class="wt-muted" style="font-size:.9rem"><?= e(t('admin.af.wd_lead')) ?></p>

          <label class="wt-checkbox" style="display:flex;gap:.75rem;align-items:flex-start;margin-bottom:1rem">
            <input type="checkbox" name="withdraw_require_verified_email" value="1" <?= $cfg['withdraw_require_verified_email'] ? 'checked' : '' ?> style="margin-top:.3rem;transform:scale(1.4)">
            <span><strong><?= e(t('admin.af.wd_email')) ?></strong></span>
          </label>

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.af.wd_age')) ?></span>
            <input class="wt-input" type="number" min="0" max="720" name="withdraw_min_account_age_hours" value="<?= $cfg['withdraw_min_account_age_hours'] ?>" style="max-width:200px">
            <small class="wt-field__hint"><?= e(t('admin.af.wd_age_hint')) ?></small>
          </label>
        </section>

        <!-- Score de risque -->
        <section class="wt-card wt-card--padded" style="margin-bottom:1.5rem">
          <h2 style="margin-top:0">📊 <?= e(t('admin.af.risk_title')) ?></h2>
          <p class="wt-muted" style="font-size:.9rem"><?= e(t('admin.af.risk_lead')) ?></p>

          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem">
            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.af.risk_review')) ?></span>
              <input class="wt-input" type="number" min="0" max="100" name="risk_threshold_review" value="<?= $cfg['risk_threshold_review'] ?>">
              <small class="wt-field__hint"><?= e(t('admin.af.risk_review_hint')) ?></small>
            </label>
            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.af.risk_block')) ?></span>
              <input class="wt-input" type="number" min="0" max="100" name="risk_threshold_block" value="<?= $cfg['risk_threshold_block'] ?>">
              <small class="wt-field__hint"><?= e(t('admin.af.risk_block_hint')) ?></small>
            </label>
          </div>
        </section>

        <button class="wt-btn wt-btn--primary" style="margin-bottom:1.5rem"><?= e(t('common.save')) ?></button>
      </form>

      <!-- Comptes signalés -->
      <?php if (!empty($flagged)): ?>
      <section class="wt-card wt-card--padded" style="margin-bottom:1.5rem">
        <h2 style="margin-top:0">🚩 <?= e(t('admin.af.flagged_title')) ?> (<?= count($flagged) ?>)</h2>
        <div class="wt-table-wrap">
          <table class="wt-table">
            <thead>
              <tr>
                <th><?= e(t('admin.af.col_user')) ?></th>
                <th><?= e(t('admin.af.col_risk')) ?></th>
                <th><?= e(t('admin.af.col_reason')) ?></th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($flagged as $fu): ?>
                <tr>
                  <td><strong><?= e($fu['username']) ?></strong></td>
                  <td>
                    <span style="font-weight:700;color:<?= (int)$fu['risk_score'] >= 80 ? '#ef4444' : '#f59e0b' ?>">
                      <?= (int)$fu['risk_score'] ?>/100
                    </span>
                  </td>
                  <td style="font-size:.85rem"><?= e((string)($fu['flag_reason'] ?? '—')) ?></td>
                  <td>
                    <form method="post" style="display:inline" onsubmit="return confirm('<?= e(t('admin.af.confirm_clear')) ?>')">
                      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="clear_flag">
                      <input type="hidden" name="user_id" value="<?= (int)$fu['id'] ?>">
                      <button type="submit" class="wt-btn wt-btn--ghost wt-btn--xs"><?= e(t('admin.af.clear_btn')) ?></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php endif; ?>

      <!-- Journal des événements -->
      <section class="wt-card wt-card--padded">
        <h2 style="margin-top:0">📋 <?= e(t('admin.af.events_title')) ?></h2>
        <?php if (empty($events)): ?>
          <p class="wt-muted"><?= e(t('admin.af.events_empty')) ?></p>
        <?php else: ?>
          <div class="wt-table-wrap">
            <table class="wt-table">
              <thead>
                <tr>
                  <th><?= e(t('admin.af.col_when')) ?></th>
                  <th><?= e(t('admin.af.col_type')) ?></th>
                  <th><?= e(t('admin.af.col_sev')) ?></th>
                  <th><?= e(t('admin.af.col_user')) ?></th>
                  <th><?= e(t('admin.af.col_details')) ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($events as $ev): ?>
                  <tr>
                    <td><small><?= e(wt_format_datetime($ev['created_at'], 'd/m H:i')) ?></small></td>
                    <td><code style="font-size:.78rem"><?= e($ev['event_type']) ?></code></td>
                    <td>
                      <span style="font-size:.72rem;padding:.15rem .5rem;border-radius:6px;color:#fff;background:<?= $sevColors[$ev['severity']] ?? '#666' ?>">
                        <?= e($ev['severity']) ?>
                      </span>
                    </td>
                    <td style="font-size:.85rem"><?= e($ev['username'] ?? '—') ?></td>
                    <td style="font-size:.8rem;opacity:.8"><?= e((string)($ev['details'] ?? '')) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

    </section>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
