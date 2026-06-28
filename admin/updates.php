<?php
/**
 * Wintaskly — Admin · Mises à jour
 *
 * Tableau de bord pour gérer les mises à jour de Wintaskly :
 *   - État actuel : version installée, version dispo, dernier check
 *   - Bouton "Vérifier maintenant" (déclenche un check manuel)
 *   - Affichage du changelog si update dispo
 *   - Contrôle du mode maintenance (toggle on/off + message custom)
 *   - Bannière utilisateur planifiable (texte + date d'expiration)
 *   - Historique des derniers checks (audit)
 *
 * Important : cette page ne fait QUE de l'affichage et de la
 * configuration. Elle ne télécharge PAS le ZIP automatiquement
 * (volontaire pour V8.7.6 — l'admin télécharge le ZIP via FTP/SFTP).
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

$adminUser   = require_admin();
$pageTitle   = t('admin.title') . ' — ' . t('admin.updates');
$adminActive = 'updates';
$db          = db();
$notice      = null;
$error       = null;

/* ====================== ACTIONS POST ====================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['_csrf'] ?? null)) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'check_now') {
        $res = wt_update_check_now();
        if ($res['status'] === 'ok') {
            if ($res['has_update']) {
                $notice = sprintf((string) t('admin.upd.check_found'), $res['latest']);
            } else {
                $notice = (string) t('admin.upd.check_uptodate');
            }
        } else {
            $error = sprintf((string) t('admin.upd.check_error'), $res['error'] ?? '?');
        }
    } elseif ($action === 'toggle_maintenance') {
        $on = !empty($_POST['enabled']) ? '1' : '0';
        wt_config_set('update.maintenance_on', $on);
        wt_config_set('update.maintenance_msg', trim((string)($_POST['maintenance_msg'] ?? '')));
        $notice = $on === '1'
            ? (string) t('admin.upd.maint_enabled')
            : (string) t('admin.upd.maint_disabled');
    } elseif ($action === 'save_banner') {
        $on = !empty($_POST['banner_on']) ? '1' : '0';
        wt_config_set('update.user_banner_on',    $on);
        wt_config_set('update.user_banner_msg',   trim((string)($_POST['banner_msg'] ?? '')));
        // banner_until : le champ caché porte l'heure en UTC (converti par
        // wt-datetime-utc.js). On normalise en 'Y-m-d H:i' UTC pour le stockage.
        $bannerUntilRaw = trim((string)($_POST['banner_until'] ?? ''));
        if ($bannerUntilRaw !== '') {
            $bannerUntilRaw = str_replace('T', ' ', $bannerUntilRaw);
            $buTs = strtotime($bannerUntilRaw . ' UTC');
            $bannerUntilRaw = $buTs !== false ? gmdate('Y-m-d H:i', $buTs) : '';
        }
        wt_config_set('update.user_banner_until', $bannerUntilRaw);
        $notice = (string) t('admin.upd.banner_saved');
    } elseif ($action === 'save_feed_url') {
        $url = trim((string)($_POST['feed_url'] ?? ''));
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL)) {
            wt_config_set('update.feed_url', $url);
            $notice = (string) t('admin.upd.feed_saved');
        } else {
            $error = (string) t('admin.upd.feed_invalid');
        }
    }
}

/* ====================== LECTURE DE L'ÉTAT ====================== */
$currentVer = WT_VERSION;
$latestVer  = (string) cfg('update.latest_version', '');
$lastCheck  = (string) cfg('update.last_check_at', '');
$feedUrl    = (string) cfg('update.feed_url', WT_UPDATE_FEED_DEFAULT);
$maintOn    = (string) cfg('update.maintenance_on', '0') === '1';
$maintMsg   = (string) cfg('update.maintenance_msg', '');
$bannerOn   = (string) cfg('update.user_banner_on', '0') === '1';
$bannerMsg  = (string) cfg('update.user_banner_msg', '');
$bannerUntilRawCfg = (string) cfg('update.user_banner_until', '');
// Valeur UTC au format datetime-local pour le champ (le JS la convertit en local)
$bannerUntil = '';
if ($bannerUntilRawCfg !== '') {
    $buTs = strtotime(str_replace('T', ' ', $bannerUntilRawCfg) . ' UTC');
    if ($buTs !== false) { $bannerUntil = gmdate('Y-m-d\TH:i', $buTs); }
}

$latestData = wt_update_latest_data();
$hasUpdate  = wt_update_has_pending();
$isCritical = wt_update_is_critical();

// Historique des 10 derniers checks
$history = [];
if ($res = $db->query("SELECT checked_at, status, current_ver, latest_ver, error_message
                         FROM update_checks ORDER BY id DESC LIMIT 10")) {
    $history = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();
}

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
  <section class="wt-admin-v2__content" data-reveal>
    <header class="wt-admin-v2__page-header">
      <div>
        <span class="wt-eyebrow">🔄 <?= e(t('admin.eyebrow_updates')) ?></span>
        <h1 class="wt-admin-v2__title"><?= e(t('admin.updates')) ?></h1>
        <p class="wt-muted"><?= e(t('admin.upd.lead')) ?></p>
      </div>
    </header>

    <?php if ($notice): ?><div class="wt-alert wt-alert--success"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error):  ?><div class="wt-alert wt-alert--error"><?= e($error)   ?></div><?php endif; ?>

    <!-- ============ STATUT DE VERSION ============ -->
    <section class="wt-card" style="margin-bottom:1.5rem">
      <div style="display:flex;gap:1.5rem;align-items:center;flex-wrap:wrap">
        <div style="flex:1;min-width:200px">
          <div style="font-size:.8rem;text-transform:uppercase;opacity:.6;letter-spacing:.1em">
            <?= e(t('admin.upd.current_version')) ?>
          </div>
          <div style="font-size:1.8rem;font-weight:800;color:var(--wt-accent);font-family:var(--wt-font-mono)">
            v<?= e($currentVer) ?>
          </div>
        </div>

        <div style="flex:1;min-width:200px">
          <div style="font-size:.8rem;text-transform:uppercase;opacity:.6;letter-spacing:.1em">
            <?= e(t('admin.upd.latest_version')) ?>
          </div>
          <?php if ($latestVer === ''): ?>
            <div style="font-size:1.4rem;opacity:.5"><?= e(t('admin.upd.never_checked')) ?></div>
          <?php elseif ($hasUpdate): ?>
            <div style="font-size:1.8rem;font-weight:800;color:<?= $isCritical ? 'var(--wt-danger, #ef4444)' : '#22c55e' ?>;font-family:var(--wt-font-mono)">
              v<?= e($latestVer) ?>
              <?php if ($isCritical): ?>
                <span style="font-size:.7rem;background:var(--wt-danger,#ef4444);color:#fff;padding:.2rem .5rem;border-radius:8px;vertical-align:middle">
                  🚨 <?= e(t('admin.upd.critical')) ?>
                </span>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div style="font-size:1.4rem;color:#22c55e">✅ <?= e(t('admin.upd.uptodate')) ?></div>
          <?php endif; ?>
        </div>

        <div style="flex:1;min-width:200px">
          <div style="font-size:.8rem;text-transform:uppercase;opacity:.6;letter-spacing:.1em">
            <?= e(t('admin.upd.last_check')) ?>
          </div>
          <div style="font-size:1rem;opacity:.8">
            <?= $lastCheck === '' ? '—' : e(wt_format_datetime($lastCheck)) ?>
          </div>
          <form method="post" style="margin-top:.5rem">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="check_now">
            <button class="wt-btn wt-btn--ghost wt-btn--xs">
              🔍 <?= e(t('admin.upd.check_now')) ?>
            </button>
          </form>
        </div>
      </div>
    </section>

    <!-- ============ CHANGELOG SI UPDATE DISPO ============ -->
    <?php if ($hasUpdate && $latestData): ?>
      <section class="wt-card wt-card--padded" style="margin-bottom:1.5rem;border:2px solid <?= $isCritical ? 'var(--wt-danger,#ef4444)' : 'var(--wt-accent)' ?>">
        <h2 style="margin-top:0">
          <?= $isCritical ? '🚨' : '🎁' ?>
          <?= e(sprintf((string) t('admin.upd.new_version_avail'), $latestVer)) ?>
        </h2>

        <?php if (!empty($latestData['released'])): ?>
          <p style="opacity:.8;font-size:.9rem">
            📅 <?= e(t('admin.upd.released')) ?> :
            <strong><?= e($latestData['released']) ?></strong>
            <?php if (!empty($latestData['min_php'])): ?>
              · 🐘 PHP ≥ <?= e($latestData['min_php']) ?>
            <?php endif; ?>
          </p>
        <?php endif; ?>

        <?php if (!empty($latestData['summary'])): ?>
          <p style="line-height:1.6"><?= e($latestData['summary']) ?></p>
        <?php endif; ?>

        <?php if (!empty($latestData['changelog']) && is_array($latestData['changelog'])): ?>
          <h3 style="margin-top:1.5rem"><?= e(t('admin.upd.changelog')) ?></h3>
          <ul style="line-height:1.7">
            <?php foreach ($latestData['changelog'] as $item): ?>
              <li><?= e((string) $item) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if (!empty($latestData['changelog_url'])): ?>
          <p style="margin-top:1rem">
            <a href="<?= e($latestData['changelog_url']) ?>" target="_blank" rel="noopener" class="wt-btn wt-btn--ghost wt-btn--sm">
              📄 <?= e(t('admin.upd.full_changelog')) ?> ↗
            </a>
          </p>
        <?php endif; ?>

        <h3 style="margin-top:1.5rem">📋 <?= e(t('admin.upd.howto')) ?></h3>
        <ol style="line-height:1.8;padding-left:1.5rem">
          <li><?= e(t('admin.upd.step1')) ?></li>
          <li><?= e(t('admin.upd.step2')) ?></li>
          <li><?= e(t('admin.upd.step3')) ?></li>
          <li><?= e(t('admin.upd.step4')) ?></li>
          <li><?= e(t('admin.upd.step5')) ?></li>
          <li><?= e(t('admin.upd.step6')) ?></li>
        </ol>

        <?php if (!empty($latestData['download_url'])): ?>
          <p style="margin-top:1rem">
            <a href="<?= e($latestData['download_url']) ?>" target="_blank" rel="noopener" class="wt-btn wt-btn--primary">
              📦 <?= e(t('admin.upd.download_zip')) ?> ↗
            </a>
          </p>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <!-- ============ MODE MAINTENANCE ============ -->
    <section class="wt-card wt-card--padded" style="margin-bottom:1.5rem">
      <h2 style="margin-top:0">🔧 <?= e(t('admin.upd.maint_title')) ?></h2>
      <p class="wt-muted" style="font-size:.9rem"><?= e(t('admin.upd.maint_lead')) ?></p>

      <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="toggle_maintenance">

        <label class="wt-checkbox" style="margin-bottom:1rem;display:flex;gap:.75rem;align-items:flex-start">
          <input type="checkbox" name="enabled" value="1" <?= $maintOn ? 'checked' : '' ?>
                 style="margin-top:.3rem;transform:scale(1.4)">
          <span>
            <strong><?= e(t('admin.upd.maint_toggle')) ?></strong>
            <small class="wt-muted" style="display:block;margin-top:.3rem">
              <?= e(t('admin.upd.maint_hint')) ?>
            </small>
          </span>
        </label>

        <label class="wt-field">
          <span class="wt-field__label"><?= e(t('admin.upd.maint_msg')) ?></span>
          <textarea class="wt-input" name="maintenance_msg" rows="2"
                    placeholder="<?= e(t('admin.upd.maint_msg_placeholder')) ?>"><?= e($maintMsg) ?></textarea>
        </label>

        <button class="wt-btn wt-btn--primary"><?= e(t('common.save')) ?></button>
      </form>

      <?php if ($maintOn): ?>
        <div class="wt-alert wt-alert--warn" style="margin-top:1rem">
          ⚠️ <strong><?= e(t('admin.upd.maint_active_warning')) ?></strong>
        </div>
      <?php endif; ?>
    </section>

    <!-- ============ BANNIÈRE UTILISATEUR ============ -->
    <section class="wt-card wt-card--padded" style="margin-bottom:1.5rem">
      <h2 style="margin-top:0">📢 <?= e(t('admin.upd.banner_title')) ?></h2>
      <p class="wt-muted" style="font-size:.9rem"><?= e(t('admin.upd.banner_lead')) ?></p>

      <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_banner">

        <label class="wt-checkbox" style="margin-bottom:1rem;display:flex;gap:.75rem;align-items:flex-start">
          <input type="checkbox" name="banner_on" value="1" <?= $bannerOn ? 'checked' : '' ?>
                 style="margin-top:.3rem;transform:scale(1.4)">
          <span><strong><?= e(t('admin.upd.banner_enable')) ?></strong></span>
        </label>

        <label class="wt-field">
          <span class="wt-field__label"><?= e(t('admin.upd.banner_msg')) ?></span>
          <textarea class="wt-input" name="banner_msg" rows="2"
                    placeholder="<?= e(t('admin.upd.banner_msg_placeholder')) ?>"><?= e($bannerMsg) ?></textarea>
        </label>

        <label class="wt-field">
          <span class="wt-field__label"><?= e(t('admin.upd.banner_until')) ?></span>
          <input class="wt-input" type="datetime-local"
                 data-dt-local
                 data-dt-target="banner_until"
                 data-utc="<?= e($bannerUntil) ?>">
          <input type="hidden" name="banner_until" value="<?= e($bannerUntil) ?>">
          <small class="wt-field__hint"><?= e(t('admin.upd.banner_until_hint')) ?></small>
        </label>

        <button class="wt-btn wt-btn--primary"><?= e(t('common.save')) ?></button>
      </form>
    </section>

    <!-- ============ CONFIGURATION FEED ============ -->
    <details style="margin-bottom:1.5rem">
      <summary style="cursor:pointer;padding:.75rem 1rem;background:var(--wt-bg-soft);border-radius:8px">
        ⚙️ <?= e(t('admin.upd.advanced')) ?>
      </summary>
      <section class="wt-card wt-card--padded" style="margin-top:.75rem">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save_feed_url">
          <label class="wt-field">
            <span class="wt-field__label">🌐 <?= e(t('admin.upd.feed_url')) ?></span>
            <input class="wt-input wt-mono" type="url" name="feed_url"
                   value="<?= e($feedUrl) ?>"
                   placeholder="<?= e(WT_UPDATE_FEED_DEFAULT) ?>">
            <small class="wt-field__hint"><?= e(t('admin.upd.feed_url_hint')) ?></small>
          </label>
          <button class="wt-btn wt-btn--ghost wt-btn--sm"><?= e(t('common.save')) ?></button>
        </form>
      </section>
    </details>

    <!-- ============ HISTORIQUE DES CHECKS ============ -->
    <details>
      <summary style="cursor:pointer;padding:.75rem 1rem;background:var(--wt-bg-soft);border-radius:8px">
        📜 <?= e(t('admin.upd.history')) ?>
      </summary>
      <div class="wt-table-wrap" style="margin-top:.75rem">
        <table class="wt-table">
          <thead>
            <tr>
              <th><?= e(t('admin.upd.col_when')) ?></th>
              <th><?= e(t('admin.upd.col_status')) ?></th>
              <th><?= e(t('admin.upd.col_current')) ?></th>
              <th><?= e(t('admin.upd.col_latest')) ?></th>
              <th><?= e(t('admin.upd.col_message')) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($history)): ?>
              <tr><td colspan="5" style="text-align:center;opacity:.5;padding:1rem">
                <?= e(t('admin.upd.history_empty')) ?>
              </td></tr>
            <?php else: foreach ($history as $h): ?>
              <tr>
                <td><?= e(wt_format_datetime($h['checked_at'])) ?></td>
                <td>
                  <?php
                  $statusEmoji = ['ok' => '✅', 'network_error' => '🌐❌', 'parse_error' => '📄❌', 'disabled' => '⏸'][$h['status']] ?? '?';
                  ?>
                  <?= $statusEmoji ?> <?= e($h['status']) ?>
                </td>
                <td class="wt-mono"><?= e($h['current_ver']) ?></td>
                <td class="wt-mono"><?= e((string)($h['latest_ver'] ?? '—')) ?></td>
                <td style="opacity:.7;font-size:.85rem"><?= e((string)($h['error_message'] ?? '—')) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </details>

  </section>
  </div>
</main>

<script src="<?= e(wt_url('/media/wintaskly/js/wt-datetime-utc.js')) ?>?v=<?= e(WT_VERSION) ?>"></script>
<?php include __DIR__ . '/../footer.php'; ?>
