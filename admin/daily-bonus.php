<?php
/**
 * Wintaskly — Admin · Bonus quotidien
 *
 * Configuration complète du système de bonus quotidien :
 *   - Activation on/off
 *   - Fenêtre de réclamation (heures) + délai de reset du streak
 *   - Mode de cycle (repeat / hold)
 *   - Édition des paliers (jour → coins + xp + jackpot)
 *   - Ajout / suppression de paliers
 *
 * Tout est stocké en BDD (config + daily_bonus_tiers).
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_admin();

$pageTitle   = t('admin.title') . ' — ' . t('admin.daily');
$adminActive = 'daily';
$db          = db();
$notice      = null;
$error       = null;

/* ====================== ACTIONS POST ====================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['_csrf'] ?? null)) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_config') {
        wt_config_set('daily_bonus.enabled', !empty($_POST['enabled']) ? '1' : '0');
        wt_config_set('daily_bonus.window_hours', (string) max(1, (int)($_POST['window_hours'] ?? 24)));
        wt_config_set('daily_bonus.reset_hours', (string) max(1, (int)($_POST['reset_hours'] ?? 48)));
        $mode = ($_POST['cycle_mode'] ?? 'repeat') === 'hold' ? 'hold' : 'repeat';
        wt_config_set('daily_bonus.cycle_mode', $mode);
        $notice = t('admin.daily.saved');

    } elseif ($action === 'save_tiers' && !empty($_POST['tiers']) && is_array($_POST['tiers'])) {
        // Met à jour les paliers existants
        $stmt = $db->prepare(
            "UPDATE daily_bonus_tiers SET coins = ?, xp = ?, label = ?, is_jackpot = ? WHERE id = ?"
        );
        foreach ($_POST['tiers'] as $id => $t) {
            $coins   = max(0, (float)($t['coins'] ?? 0));
            $xp      = max(0, (int)($t['xp'] ?? 0));
            $label   = trim((string)($t['label'] ?? ''));
            $label   = $label === '' ? null : $label;
            $jackpot = !empty($t['is_jackpot']) ? 1 : 0;
            $tid     = (int) $id;
            $stmt->bind_param('disii', $coins, $xp, $label, $jackpot, $tid);
            $stmt->execute();
        }
        $stmt->close();
        $notice = t('admin.daily.tiers_saved');

    } elseif ($action === 'add_tier') {
        // Ajoute un palier au jour suivant le dernier
        $row = db_one("SELECT COALESCE(MAX(streak_day),0)+1 AS next_day FROM daily_bonus_tiers");
        $nextDay = (int) ($row['next_day'] ?? 1);
        $stmt = $db->prepare("INSERT INTO daily_bonus_tiers (streak_day, coins, xp) VALUES (?, 10, 5)");
        $stmt->bind_param('i', $nextDay);
        $stmt->execute();
        $stmt->close();
        $notice = sprintf((string) t('admin.daily.tier_added'), $nextDay);

    } elseif ($action === 'del_tier') {
        $tid = (int)($_POST['tier_id'] ?? 0);
        // On garde toujours au moins 1 palier
        $cnt = db_one("SELECT COUNT(*) c FROM daily_bonus_tiers");
        if ((int)($cnt['c'] ?? 0) > 1) {
            $stmt = $db->prepare("DELETE FROM daily_bonus_tiers WHERE id = ?");
            $stmt->bind_param('i', $tid);
            $stmt->execute();
            $stmt->close();
            // Renumérote les jours pour rester séquentiel (1,2,3...)
            $db->query("SET @r := 0");
            $db->query("UPDATE daily_bonus_tiers SET streak_day = (@r := @r + 1) ORDER BY streak_day ASC");
            $notice = t('admin.daily.tier_deleted');
        } else {
            $error = t('admin.daily.tier_min');
        }
    }
}

/* ====================== LECTURE ÉTAT ====================== */
$enabled     = (string) cfg('daily_bonus.enabled', '0') === '1';
$windowHours = (int) cfg('daily_bonus.window_hours', '24');
$resetHours  = (int) cfg('daily_bonus.reset_hours', '48');
$cycleMode   = (string) cfg('daily_bonus.cycle_mode', 'repeat');

$tiers = [];
if ($res = $db->query("SELECT id, streak_day, coins, xp, label, is_jackpot FROM daily_bonus_tiers ORDER BY streak_day ASC")) {
    while ($r = $res->fetch_assoc()) { $tiers[] = $r; }
    $res->free();
}

// Stats : combien de claims au total, combien aujourd'hui
$totalClaims = (int) (db_one("SELECT COUNT(*) c FROM daily_bonus_claims")['c'] ?? 0);
$todayClaims = (int) (db_one("SELECT COUNT(*) c FROM daily_bonus_claims WHERE claimed_at >= UTC_DATE()")['c'] ?? 0);
$maxStreak   = (int) (db_one("SELECT COALESCE(MAX(daily_streak),0) m FROM users")['m'] ?? 0);

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
    <section class="wt-admin-v2__content" data-reveal>

      <header class="wt-admin-v2__page-header">
        <div>
          <span class="wt-eyebrow">🎁 <?= e(t('admin.daily.eyebrow')) ?></span>
          <h1 class="wt-admin-v2__title"><?= e(t('admin.daily')) ?></h1>
          <p class="wt-muted"><?= e(t('admin.daily.lead')) ?></p>
        </div>
      </header>

      <?php if ($notice): ?><div class="wt-alert wt-alert--success"><?= e($notice) ?></div><?php endif; ?>
      <?php if ($error):  ?><div class="wt-alert wt-alert--error"><?= e($error)   ?></div><?php endif; ?>

      <!-- Stats rapides -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1rem;margin-bottom:1.5rem">
        <div class="wt-card wt-card--padded" style="text-align:center">
          <div style="font-size:1.8rem;font-weight:800;color:var(--wt-accent)"><?= number_format($totalClaims, 0, '.', ' ') ?></div>
          <div class="wt-muted" style="font-size:.85rem"><?= e(t('admin.daily.stat_total')) ?></div>
        </div>
        <div class="wt-card wt-card--padded" style="text-align:center">
          <div style="font-size:1.8rem;font-weight:800;color:#22c55e"><?= number_format($todayClaims, 0, '.', ' ') ?></div>
          <div class="wt-muted" style="font-size:.85rem"><?= e(t('admin.daily.stat_today')) ?></div>
        </div>
        <div class="wt-card wt-card--padded" style="text-align:center">
          <div style="font-size:1.8rem;font-weight:800;color:#ff6b35">🔥 <?= $maxStreak ?></div>
          <div class="wt-muted" style="font-size:.85rem"><?= e(t('admin.daily.stat_maxstreak')) ?></div>
        </div>
      </div>

      <!-- ============ CONFIGURATION ============ -->
      <section class="wt-card wt-card--padded" style="margin-bottom:1.5rem">
        <h2 style="margin-top:0">⚙️ <?= e(t('admin.daily.config_title')) ?></h2>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save_config">

          <label class="wt-checkbox" style="margin-bottom:1.25rem;display:flex;gap:.75rem;align-items:flex-start">
            <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>
                   style="margin-top:.3rem;transform:scale(1.4)">
            <span>
              <strong><?= e(t('admin.daily.enable')) ?></strong>
              <small class="wt-muted" style="display:block;margin-top:.3rem"><?= e(t('admin.daily.enable_hint')) ?></small>
            </span>
          </label>

          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem">
            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.daily.window')) ?></span>
              <input class="wt-input" type="number" min="1" max="168" name="window_hours" value="<?= $windowHours ?>">
              <small class="wt-field__hint"><?= e(t('admin.daily.window_hint')) ?></small>
            </label>

            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.daily.reset')) ?></span>
              <input class="wt-input" type="number" min="1" max="336" name="reset_hours" value="<?= $resetHours ?>">
              <small class="wt-field__hint"><?= e(t('admin.daily.reset_hint')) ?></small>
            </label>

            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.daily.cycle')) ?></span>
              <select class="wt-input" name="cycle_mode">
                <option value="repeat" <?= $cycleMode === 'repeat' ? 'selected' : '' ?>><?= e(t('admin.daily.cycle_repeat')) ?></option>
                <option value="hold"   <?= $cycleMode === 'hold'   ? 'selected' : '' ?>><?= e(t('admin.daily.cycle_hold')) ?></option>
              </select>
              <small class="wt-field__hint"><?= e(t('admin.daily.cycle_hint')) ?></small>
            </label>
          </div>

          <button class="wt-btn wt-btn--primary" style="margin-top:1rem"><?= e(t('common.save')) ?></button>
        </form>
      </section>

      <!-- ============ PALIERS ============ -->
      <section class="wt-card wt-card--padded">
        <h2 style="margin-top:0">🪜 <?= e(t('admin.daily.tiers_title')) ?></h2>
        <p class="wt-muted" style="font-size:.9rem"><?= e(t('admin.daily.tiers_lead')) ?></p>

        <form method="post">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save_tiers">

          <div class="wt-table-wrap">
            <table class="wt-table">
              <thead>
                <tr>
                  <th><?= e(t('admin.daily.col_day')) ?></th>
                  <th><?= e(t('admin.daily.col_coins')) ?></th>
                  <th><?= e(t('admin.daily.col_xp')) ?></th>
                  <th><?= e(t('admin.daily.col_label')) ?></th>
                  <th><?= e(t('admin.daily.col_jackpot')) ?></th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($tiers as $t): ?>
                  <tr>
                    <td style="font-family:var(--wt-font-mono);font-weight:700">
                      <?= e(t('daily.day_short')) ?><?= (int)$t['streak_day'] ?>
                    </td>
                    <td>
                      <input class="wt-input" type="number" step="0.0001" min="0"
                             name="tiers[<?= (int)$t['id'] ?>][coins]"
                             value="<?= e(rtrim(rtrim(number_format((float)$t['coins'],4,'.',''),'0'),'.')) ?>"
                             style="max-width:110px">
                    </td>
                    <td>
                      <input class="wt-input" type="number" min="0"
                             name="tiers[<?= (int)$t['id'] ?>][xp]"
                             value="<?= (int)$t['xp'] ?>" style="max-width:90px">
                    </td>
                    <td>
                      <input class="wt-input" type="text" maxlength="60"
                             name="tiers[<?= (int)$t['id'] ?>][label]"
                             value="<?= e((string)($t['label'] ?? '')) ?>"
                             placeholder="<?= e(t('admin.daily.label_ph')) ?>" style="max-width:140px">
                    </td>
                    <td style="text-align:center">
                      <input type="checkbox" name="tiers[<?= (int)$t['id'] ?>][is_jackpot]" value="1"
                             <?= (int)$t['is_jackpot'] === 1 ? 'checked' : '' ?> style="transform:scale(1.3)">
                    </td>
                    <td>
                      <button type="submit" name="action" value="del_tier"
                              formnovalidate
                              onclick="this.form.querySelector('[name=tier_id]')?.remove();var i=document.createElement('input');i.type='hidden';i.name='tier_id';i.value='<?= (int)$t['id'] ?>';this.form.appendChild(i);"
                              class="wt-btn wt-btn--ghost wt-btn--xs" style="color:#ef4444">
                        🗑
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <input type="hidden" name="tier_id" value="">

          <div style="display:flex;gap:.75rem;margin-top:1rem;flex-wrap:wrap">
            <button type="submit" name="action" value="save_tiers" class="wt-btn wt-btn--primary">
              <?= e(t('common.save')) ?>
            </button>
            <button type="submit" name="action" value="add_tier" formnovalidate class="wt-btn wt-btn--ghost">
              ➕ <?= e(t('admin.daily.add_tier')) ?>
            </button>
          </div>
        </form>
      </section>

    </section>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
