<?php
/**
 * Wintaskly — Admin · Classement & récompenses mensuelles.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
$adminUser   = require_admin();

$pageTitle   = t('admin.title') . ' — ' . t('admin.leaderboard');
$adminActive = 'leaderboard';
$db          = db();
$notice      = null;
$error       = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['_csrf'] ?? null)) {
    $action = (string)($_POST['action'] ?? '');

    // Wrapper s[] pour contourner le bug PHP qui convertit les "." en "_"
    // dans les noms POST de premier niveau.
    $postValues = $_POST['s'] ?? [];

    if ($action === 'rewards_save') {
        // V8 : on accepte aussi les clés cagnotte
        $keys = [
            'leaderboard.cache_minutes',
            'leaderboard.rewards_enabled',
            'leaderboard.use_prize_pool',
            'leaderboard.prize_pool',
            'leaderboard.prize_pool_split',
        ];
        for ($r = 1; $r <= 10; $r++) {
            $keys[] = 'leaderboard.reward_coins_' . $r;
            $keys[] = 'leaderboard.reward_xp_'    . $r;
        }
        $stmt = $db->prepare(
            "INSERT INTO config (k, v) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE v = VALUES(v)"
        );
        foreach ($keys as $k) {
            if (!array_key_exists($k, $postValues)) continue;
            $v = (string) $postValues[$k];
            $stmt->bind_param('ss', $k, $v);
            $stmt->execute();
        }
        $stmt->close();
        // Invalide le cache cfg() en mémoire (process suivant rechargera)
        unset($GLOBALS['__wt_cfg_cache']);
        $notice = t('admin.saved');
    } elseif ($action === 'refresh_cache') {
        wt_lb_refresh_cache(wt_lb_period());
        $notice = 'Cache régénéré.';
    } elseif ($action === 'force_archive') {
        // Force l'archivage immédiat du mois précédent
        $stmt = $db->prepare(
            "INSERT INTO config (k, v) VALUES ('leaderboard.last_archived_period', '')
             ON DUPLICATE KEY UPDATE v = ''"
        );
        $stmt->execute();
        $stmt->close();
        wt_lb_maybe_archive_previous();
        $notice = 'Archivage forcé du mois précédent.';
    }
}

/* Lecture des paramètres actuels (bypass du cache statique) */
$current = [];
if ($res = $db->query("SELECT k, v FROM config WHERE k LIKE 'leaderboard.%'")) {
    while ($r = $res->fetch_assoc()) $current[$r['k']] = $r['v'];
    $res->free();
}

/* Liste des périodes archivées + détail du Top 10 sélectionné */
$periods = [];
if ($res = $db->query("SELECT DISTINCT period_ym FROM leaderboard_history ORDER BY period_ym DESC LIMIT 36")) {
    while ($r = $res->fetch_assoc()) $periods[] = $r['period_ym'];
    $res->free();
}

$selectedPeriod = (string)($_GET['period'] ?? ($periods[0] ?? ''));
$history = [];
if ($selectedPeriod !== '') {
    $stmt = $db->prepare(
        "SELECT h.*, u.username AS current_username
           FROM leaderboard_history h
           LEFT JOIN users u ON u.id = h.user_id
          WHERE h.period_ym = ?
          ORDER BY h.`rank` ASC"
    );
    $stmt->bind_param('s', $selectedPeriod);
    $stmt->execute();
    $res = $stmt->get_result();
    $history = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

/* Top 10 du mois en cours (live) */
$live = wt_lb_get_top();

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
  <section class="wt-admin-v2__content" data-reveal>
    <header class="wt-admin-v2__page-header">
        <div>
          <span class="wt-eyebrow">🏆 <?= e(t('admin.eyebrow_leaderboard')) ?></span>
          <h1 class="wt-admin-v2__title"><?= e(t('admin.leaderboard')) ?></h1>
          <p class="wt-muted"><?= e(t('admin.leaderboard.lead')) ?></p>
        </div>
      </header>

    <?php if ($notice): ?><div class="wt-alert wt-alert--success"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error):  ?><div class="wt-alert wt-alert--error"><?= e($error)   ?></div><?php endif; ?>

    <!-- ===================== LIVE ===================== -->
    <h2 class="wt-section__title" style="margin-top:1rem">
      Live — <?= e(wt_lb_period()) ?>
    </h2>

    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.5rem">
      <form method="post" style="display:inline">
        <input type="hidden" name="_csrf"  value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="refresh_cache">
        <button class="wt-btn wt-btn--xs wt-btn--ghost">⟳ Régénérer le cache</button>
      </form>
      <form method="post" style="display:inline"
            onsubmit="return confirm('Forcer l\'archivage du mois précédent ?')">
        <input type="hidden" name="_csrf"  value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="force_archive">
        <button class="wt-btn wt-btn--xs wt-btn--danger">↻ Forcer archivage</button>
      </form>
    </div>

    <div class="wt-table-wrap">
      <table class="wt-table">
        <thead>
          <tr>
            <th>#</th><th>Utilisateur</th><th class="wt-text-right">Coins du mois</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$live): ?>
            <tr><td colspan="3" class="wt-muted">Aucune activité ce mois.</td></tr>
          <?php else: foreach ($live as $row): ?>
            <tr>
              <td>#<?= (int)$row['rank'] ?></td>
              <td><?= e($row['username']) ?>
                <span class="wt-muted" style="font-size:.75rem">· Niv. <?= (int)$row['level'] ?></span>
              </td>
              <td class="wt-text-right"><?= e(number_format($row['coins_month'], 2, '.', ' ')) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- ===================== HISTORIQUE ===================== -->
    <h2 class="wt-section__title" style="margin-top:2rem">Historique</h2>

    <?php if (!$periods): ?>
      <p class="wt-muted">Aucun mois encore archivé.</p>
    <?php else: ?>
      <form method="get" style="margin-bottom:1rem">
        <label class="wt-field" style="display:inline-flex;gap:.5rem;align-items:center">
          <span class="wt-muted">Mois :</span>
          <select class="wt-input wt-input--sm" name="period" onchange="this.form.submit()">
            <?php foreach ($periods as $p): ?>
              <option value="<?= e($p) ?>" <?= $p === $selectedPeriod ? 'selected' : '' ?>><?= e($p) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </form>

      <div class="wt-table-wrap">
        <table class="wt-table">
          <thead>
            <tr>
              <th>#</th><th>Utilisateur</th>
              <th class="wt-text-right">Coins gagnés</th>
              <th class="wt-text-right">Bonus Coins</th>
              <th class="wt-text-right">Bonus XP</th>
              <th>Archivé le</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$history): ?>
              <tr><td colspan="6" class="wt-muted">Aucune entrée pour ce mois.</td></tr>
            <?php else: foreach ($history as $h): ?>
              <tr>
                <td>#<?= (int)$h['rank'] ?></td>
                <td>
                  <strong><?= e($h['username']) ?></strong>
                  <?php if (!empty($h['current_username']) && $h['current_username'] !== $h['username']): ?>
                    <span class="wt-muted" style="font-size:.72rem">
                      (aujourd'hui : <?= e($h['current_username']) ?>)
                    </span>
                  <?php endif; ?>
                </td>
                <td class="wt-text-right"><?= e(number_format((float)$h['coins_month'], 2, '.', ' ')) ?></td>
                <td class="wt-text-right">
                  <?= ((float)$h['reward_coins']) > 0 ? '+ ' . e(number_format((float)$h['reward_coins'], 2, '.', ' ')) : '—' ?>
                </td>
                <td class="wt-text-right">
                  <?= ((int)$h['reward_xp']) > 0 ? '+ ' . (int)$h['reward_xp'] : '—' ?>
                </td>
                <td>
                  <span data-fmt-time data-utc="<?= e($h['archived_at']) ?>">
                    <?= e(wt_format_datetime($h['archived_at'])) ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <!-- ===================== RÉCOMPENSES ===================== -->
    <h2 class="wt-section__title" style="margin-top:2rem">Récompenses & cache</h2>

    <form method="post" class="wt-form wt-form--grid">
      <input type="hidden" name="_csrf"  value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="rewards_save">

      <label class="wt-field">
        <span class="wt-field__label">Activer les récompenses auto.</span>
        <select class="wt-input" name="s[leaderboard.rewards_enabled]">
          <option value="1" <?= ($current['leaderboard.rewards_enabled'] ?? '1')==='1' ? 'selected' : '' ?>>Oui</option>
          <option value="0" <?= ($current['leaderboard.rewards_enabled'] ?? '1')==='0' ? 'selected' : '' ?>>Non</option>
        </select>
      </label>

      <label class="wt-field">
        <span class="wt-field__label">Cache (minutes)</span>
        <input class="wt-input" type="number" min="1" max="240"
               name="s[leaderboard.cache_minutes]"
               value="<?= e($current['leaderboard.cache_minutes'] ?? '15') ?>">
      </label>

      <!-- ============ V8 : Mode CAGNOTTE ============ -->
      <div class="wt-field wt-field--wide">
        <span class="wt-field__label">Mode de récompense</span>
        <div style="display:flex;flex-direction:column;gap:.5rem;margin-top:.35rem">
          <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
            <input type="radio" name="s[leaderboard.use_prize_pool]" value="1"
                   <?= ($current['leaderboard.use_prize_pool'] ?? '0') === '1' ? 'checked' : '' ?>>
            <span>
              <strong>Cagnotte mensuelle</strong>
              <span class="wt-muted" style="display:block;font-size:.78rem">
                Définis un montant total + des pourcentages par rang. La grille
                ci-dessous (Bonus Coins) est ignorée.
              </span>
            </span>
          </label>
          <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
            <input type="radio" name="s[leaderboard.use_prize_pool]" value="0"
                   <?= ($current['leaderboard.use_prize_pool'] ?? '0') === '0' ? 'checked' : '' ?>>
            <span>
              <strong>Montants fixes par rang</strong>
              <span class="wt-muted" style="display:block;font-size:.78rem">
                Utilise la grille ci-dessous, valeur en Coins par rang (mode classique).
              </span>
            </span>
          </label>
        </div>
      </div>

      <label class="wt-field">
        <span class="wt-field__label">Cagnotte totale (Coins)</span>
        <input class="wt-input" type="number" step="0.01" min="0"
               name="s[leaderboard.prize_pool]"
               value="<?= e($current['leaderboard.prize_pool'] ?? '5000') ?>"
               placeholder="5000">
        <small class="wt-muted" style="font-size:.72rem;margin-top:.2rem">
          Distribué chaque mois selon les pourcentages ci-dessous.
        </small>
      </label>

      <label class="wt-field">
        <span class="wt-field__label">Répartition (% par rang, CSV)</span>
        <input class="wt-input" type="text"
               name="s[leaderboard.prize_pool_split]"
               value="<?= e($current['leaderboard.prize_pool_split'] ?? '40,20,12,8,6,5,4,3,1.5,0.5') ?>"
               placeholder="40,20,12,8,6,5,4,3,1.5,0.5">
        <small class="wt-muted" style="font-size:.72rem;margin-top:.2rem">
          Valeurs séparées par virgule, ordre du rang 1 au rang 10. La somme
          devrait faire 100 %.
        </small>
      </label>

      <?php
        // Calcul de prévisualisation de la cagnotte
        $previewSplit = array_map('trim', explode(',', (string)($current['leaderboard.prize_pool_split'] ?? '40,20,12,8,6,5,4,3,1.5,0.5')));
        $previewPool  = (float)($current['leaderboard.prize_pool'] ?? '5000');
        $previewSum   = array_sum(array_map('floatval', $previewSplit));
      ?>
      <div class="wt-field wt-field--wide">
        <span class="wt-field__label">Aperçu de la cagnotte</span>
        <div class="wt-table-wrap" style="margin-top:.35rem">
          <table class="wt-table" style="font-size:.85rem">
            <thead><tr><th>Rang</th><th>%</th><th class="wt-text-right">Coins</th></tr></thead>
            <tbody>
              <?php for ($r = 1; $r <= 10; $r++):
                $pct = (float)($previewSplit[$r - 1] ?? 0);
                $val = round(($previewPool * $pct) / 100.0, 4);
              ?>
                <tr>
                  <td>#<?= $r ?></td>
                  <td><?= e(rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.')) ?> %</td>
                  <td class="wt-text-right"><?= $val > 0 ? '+ ' . e(number_format($val, 2, '.', ' ')) : '—' ?></td>
                </tr>
              <?php endfor; ?>
              <tr style="border-top:2px solid var(--wt-border);font-weight:700">
                <td colspan="2">Total</td>
                <td class="wt-text-right">
                  <?= e(number_format($previewSum, 2, '.', '')) ?> %
                  <?php if (abs($previewSum - 100) > 0.01): ?>
                    <span class="wt-muted">⚠️</span>
                  <?php endif; ?>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="wt-field wt-field--wide">
        <span class="wt-field__label">Barème par rang (mode classique uniquement)</span>
        <div class="wt-table-wrap" style="margin-top:.5rem">
          <table class="wt-table">
            <thead><tr><th>Rang</th><th>Bonus Coins</th><th>Bonus XP</th></tr></thead>
            <tbody>
              <?php for ($r = 1; $r <= 10; $r++):
                $c = (string)($current['leaderboard.reward_coins_' . $r] ?? '0');
                $x = (string)($current['leaderboard.reward_xp_'    . $r] ?? '0');
              ?>
                <tr>
                  <td><strong>#<?= $r ?></strong></td>
                  <td>
                    <input class="wt-input wt-input--sm" type="number" step="0.0001" min="0"
                           name="s[leaderboard.reward_coins_<?= $r ?>]"
                           value="<?= e($c) ?>">
                  </td>
                  <td>
                    <input class="wt-input wt-input--sm" type="number" step="1" min="0"
                           name="s[leaderboard.reward_xp_<?= $r ?>]"
                           value="<?= e($x) ?>">
                  </td>
                </tr>
              <?php endfor; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="wt-form__actions">
        <button class="wt-btn wt-btn--primary">Enregistrer</button>
      </div>
    </form>
  </section>
</div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
