<?php
/**
 * Wintaskly — Admin · Édition des blocs de la page d'accueil.
 *
 * Permet de :
 *   - éditer titre/contenu/visibilité de chaque bloc (hero, stats, how, …)
 *   - mettre à jour les statistiques affichées (table config).
 */
require __DIR__ . '/../includes/init.php';
require_admin();

$pageTitle   = t('admin.title') . ' — ' . t('admin.homepage');
$adminActive = 'homepage';
$db          = db();
$notice      = null;

/* ---------- POST : enregistrement -------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['_csrf'] ?? null)) {

    if (!empty($_POST['blocks']) && is_array($_POST['blocks'])) {
        $stmt = $db->prepare(
            "UPDATE homepage_blocks
                SET title = ?, content = ?, visible = ?
              WHERE k = ?"
        );
        foreach ($_POST['blocks'] as $k => $b) {
            $title   = trim((string)($b['title']   ?? ''));
            $content = trim((string)($b['content'] ?? ''));
            $visible = !empty($b['visible']) ? 1 : 0;
            $key     = (string) $k;
            $stmt->bind_param('ssis', $title, $content, $visible, $key);
            $stmt->execute();
        }
        $stmt->close();
    }

    if (!empty($_POST['stats']) && is_array($_POST['stats'])) {
        $cstmt = $db->prepare(
            "INSERT INTO config (k, v) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE v = VALUES(v)"
        );
        foreach ($_POST['stats'] as $k => $v) {
            // stats_mode est string (real|boosted|max), les autres sont des entiers
            if ($k === 'stats_mode') {
                $val = in_array($v, ['real', 'boosted', 'max'], true) ? $v : 'max';
            } elseif (in_array($k, ['stats_users', 'stats_paid', 'stats_tasks_today'], true)) {
                $val = (string) max(0, (int) $v);
            } else {
                continue;  // Clé non whitelistée
            }
            $cstmt->bind_param('ss', $k, $val);
            $cstmt->execute();
        }
        $cstmt->close();
    }

    $notice = t('admin.saved');
}

/* ---------- Lecture ---------------------------------------------- */
$blocks = [];
if ($res = $db->query("SELECT k, title, content, visible FROM homepage_blocks ORDER BY sort_order ASC")) {
    while ($row = $res->fetch_assoc()) $blocks[$row['k']] = $row;
    $res->free();
}
$stats = [
    'stats_mode'        => (string) cfg('stats_mode', 'max'),
    'stats_users'       => (int) cfg('stats_users', '0'),
    'stats_paid'        => (int) cfg('stats_paid', '0'),
    'stats_tasks_today' => (int) cfg('stats_tasks_today', '0'),
];

/* Vraies données BDD (pour l'aperçu temps réel dans l'admin) ----------
   On les affiche en lecture seule à côté des champs boost pour que
   l'admin voit exactement ce que les visiteurs voient avant et après
   application du mode choisi. */
$realStats = [
    'users' => (int) (db_one("SELECT COUNT(*) c FROM users WHERE status='active'")['c'] ?? 0),
    'paid'  => (int) (db_one("SELECT COALESCE(SUM(coins),0) s FROM transactions WHERE type IN ('faucet','shortlink','referral','bonus')")['s'] ?? 0),
    'today' => (int) (db_one("SELECT COUNT(*) c FROM transactions WHERE type IN ('faucet','shortlink') AND created_at >= UTC_DATE()")['c'] ?? 0),
];

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
  <section class="wt-admin-v2__content" data-reveal>
    <header class="wt-admin-v2__page-header">
        <div>
          <span class="wt-eyebrow">🏠 <?= e(t('admin.eyebrow_homepage')) ?></span>
          <h1 class="wt-admin-v2__title"><?= e(t('admin.homepage')) ?></h1>
          <p class="wt-muted"><?= e(t('admin.homepage.lead')) ?></p>
        </div>
      </header>

    <?php if ($notice): ?>
      <div class="wt-alert wt-alert--success"><?= e($notice) ?></div>
    <?php endif; ?>

    <form method="post" class="wt-form">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

      <?php foreach ($blocks as $k => $b): ?>
        <fieldset class="wt-fieldset">
          <legend>Bloc « <?= e($k) ?> »</legend>

          <label class="wt-field">
            <span class="wt-field__label">Titre</span>
            <input class="wt-input" type="text"
                   name="blocks[<?= e($k) ?>][title]"
                   value="<?= e((string)$b['title']) ?>">
          </label>

          <label class="wt-field wt-field--wide">
            <span class="wt-field__label">Contenu</span>
            <textarea class="wt-input wt-textarea" rows="3"
                      name="blocks[<?= e($k) ?>][content]"><?= e((string)($b['content'] ?? '')) ?></textarea>
          </label>

          <label class="wt-field wt-field--check">
            <input type="checkbox" name="blocks[<?= e($k) ?>][visible]" value="1"
                   <?= (int)$b['visible']===1 ? 'checked' : '' ?>>
            <span><?= e(t('common.visible')) ?></span>
          </label>
        </fieldset>
      <?php endforeach; ?>

      <fieldset class="wt-fieldset">
        <legend>📊 <?= e(t('admin.home.stats.legend')) ?></legend>

        <!-- =====================================================================
             Sélecteur de MODE de calcul des stats publiques.

             Le mode détermine comment les chiffres affichés sur la home sont
             calculés à partir des valeurs "boost" (saisies ci-dessous) et des
             vraies données BDD (membres actifs, coins distribués, etc.).
             ===================================================================== -->
        <label class="wt-field">
          <span class="wt-field__label">⚙️ <?= e(t('admin.home.stats.mode')) ?></span>
          <select class="wt-input" name="stats[stats_mode]" data-stats-mode>
            <option value="real"    <?= $stats['stats_mode'] === 'real'    ? 'selected' : '' ?>>
              <?= e(t('admin.home.stats.mode_real')) ?>
            </option>
            <option value="boosted" <?= $stats['stats_mode'] === 'boosted' ? 'selected' : '' ?>>
              <?= e(t('admin.home.stats.mode_boosted')) ?>
            </option>
            <option value="max"     <?= $stats['stats_mode'] === 'max'     ? 'selected' : '' ?>>
              <?= e(t('admin.home.stats.mode_max')) ?>
            </option>
          </select>
          <small class="wt-field__hint">
            <strong data-stats-mode-label></strong>
            <span data-stats-mode-hint></span>
          </small>
        </label>

        <!-- Aperçu temps réel : montre à l'admin EXACTEMENT ce que voient les
             visiteurs selon le mode choisi (mis à jour en JS quand le mode change). -->
        <div class="wt-card" style="background:var(--wt-bg-soft);padding:1rem;margin:.75rem 0;font-size:.9rem">
          <strong>👁 <?= e(t('admin.home.stats.preview')) ?></strong>
          <table style="margin-top:.5rem;width:100%;border-collapse:collapse">
            <thead style="opacity:.6">
              <tr style="text-align:left">
                <th style="padding:.3rem 0"><?= e(t('admin.home.stats.col_metric')) ?></th>
                <th style="padding:.3rem 0;text-align:right"><?= e(t('admin.home.stats.col_real')) ?></th>
                <th style="padding:.3rem 0;text-align:right"><?= e(t('admin.home.stats.col_boost')) ?></th>
                <th style="padding:.3rem 0;text-align:right"><?= e(t('admin.home.stats.col_displayed')) ?></th>
              </tr>
            </thead>
            <tbody style="font-family:var(--wt-font-mono)">
              <tr>
                <td style="padding:.3rem 0">👥 <?= e(t('home.stats.users')) ?></td>
                <td style="text-align:right;padding:.3rem 0" data-real-users><?= number_format($realStats['users'], 0, ',', ' ') ?></td>
                <td style="text-align:right;padding:.3rem 0;opacity:.7" data-boost-users><?= number_format($stats['stats_users'], 0, ',', ' ') ?></td>
                <td style="text-align:right;padding:.3rem 0;font-weight:700;color:var(--wt-accent)" data-displayed-users>—</td>
              </tr>
              <tr>
                <td style="padding:.3rem 0">💰 <?= e(t('home.stats.paid')) ?></td>
                <td style="text-align:right;padding:.3rem 0" data-real-paid><?= number_format($realStats['paid'], 0, ',', ' ') ?></td>
                <td style="text-align:right;padding:.3rem 0;opacity:.7" data-boost-paid><?= number_format($stats['stats_paid'], 0, ',', ' ') ?></td>
                <td style="text-align:right;padding:.3rem 0;font-weight:700;color:var(--wt-accent)" data-displayed-paid>—</td>
              </tr>
              <tr>
                <td style="padding:.3rem 0">⚡ <?= e(t('home.stats.today')) ?></td>
                <td style="text-align:right;padding:.3rem 0" data-real-today><?= number_format($realStats['today'], 0, ',', ' ') ?></td>
                <td style="text-align:right;padding:.3rem 0;opacity:.7" data-boost-today><?= number_format($stats['stats_tasks_today'], 0, ',', ' ') ?></td>
                <td style="text-align:right;padding:.3rem 0;font-weight:700;color:var(--wt-accent)" data-displayed-today>—</td>
              </tr>
            </tbody>
          </table>
        </div>

        <h3 style="margin:1rem 0 .5rem;font-size:.95rem"><?= e(t('admin.home.stats.boost_section')) ?></h3>
        <p class="wt-muted" style="font-size:.85rem;margin-bottom:1rem">
          <?= e(t('admin.home.stats.boost_hint')) ?>
        </p>

        <label class="wt-field">
          <span class="wt-field__label"><?= e(t('home.stats.users')) ?></span>
          <input class="wt-input" type="number" min="0"
                 name="stats[stats_users]" value="<?= (int)$stats['stats_users'] ?>"
                 data-stats-input-users>
        </label>
        <label class="wt-field">
          <span class="wt-field__label"><?= e(t('home.stats.paid')) ?></span>
          <input class="wt-input" type="number" min="0"
                 name="stats[stats_paid]" value="<?= (int)$stats['stats_paid'] ?>"
                 data-stats-input-paid>
        </label>
        <label class="wt-field">
          <span class="wt-field__label"><?= e(t('home.stats.today')) ?></span>
          <input class="wt-input" type="number" min="0"
                 name="stats[stats_tasks_today]" value="<?= (int)$stats['stats_tasks_today'] ?>"
                 data-stats-input-today>
        </label>
      </fieldset>

      <div class="wt-form__actions">
        <button type="submit" class="wt-btn wt-btn--primary"><?= e(t('common.save')) ?></button>
      </div>
    </form>
  </section>
</div>
</main>

<script>
/* ════════════════════════════════════════════════════════════════════
   APERÇU TEMPS RÉEL des stats publiques

   Met à jour la colonne "Affiché" du tableau d'aperçu en fonction :
   - Du mode sélectionné (real / boosted / max)
   - Des valeurs boost saisies (modifiées en direct par l'admin)
   - Des vraies données BDD (figées au chargement de la page)

   Comme ça l'admin voit IMMÉDIATEMENT ce que verront les visiteurs sans
   avoir à sauvegarder + ouvrir la home dans un autre onglet.
   ════════════════════════════════════════════════════════════════════ */
(function () {
  const modeSelect = document.querySelector('[data-stats-mode]');
  if (!modeSelect) return;

  // Vraies valeurs BDD (figées au load — affichées dans la colonne "Réel")
  const real = {
    users: <?= (int) $realStats['users'] ?>,
    paid:  <?= (int) $realStats['paid']  ?>,
    today: <?= (int) $realStats['today'] ?>,
  };

  // Textes i18n pour les hints du sélecteur
  const MODE_LABELS = {
    real:    <?= json_encode(t('admin.home.stats.mode_real_label')) ?>,
    boosted: <?= json_encode(t('admin.home.stats.mode_boosted_label')) ?>,
    max:     <?= json_encode(t('admin.home.stats.mode_max_label')) ?>,
  };
  const MODE_HINTS = {
    real:    <?= json_encode(t('admin.home.stats.mode_real_hint')) ?>,
    boosted: <?= json_encode(t('admin.home.stats.mode_boosted_hint')) ?>,
    max:     <?= json_encode(t('admin.home.stats.mode_max_hint')) ?>,
  };

  // Formate un nombre avec séparateur d'espace insécable (style français)
  function fmt(n) {
    return Number(n).toLocaleString('fr-FR').replace(/,/g, ' ');
  }

  // Calcule la valeur affichée selon le mode + boost + real
  function computeDisplayed(mode, real, boost) {
    if (mode === 'real')    return real;
    if (mode === 'boosted') return real + boost;
    return Math.max(real, boost);  // mode 'max' (default)
  }

  function update() {
    const mode = modeSelect.value || 'max';
    const boost = {
      users: parseInt(document.querySelector('[data-stats-input-users]').value, 10) || 0,
      paid:  parseInt(document.querySelector('[data-stats-input-paid]').value, 10)  || 0,
      today: parseInt(document.querySelector('[data-stats-input-today]').value, 10) || 0,
    };

    document.querySelector('[data-displayed-users]').textContent = fmt(computeDisplayed(mode, real.users, boost.users));
    document.querySelector('[data-displayed-paid]').textContent  = fmt(computeDisplayed(mode, real.paid,  boost.paid));
    document.querySelector('[data-displayed-today]').textContent = fmt(computeDisplayed(mode, real.today, boost.today));

    // Met à jour le hint du sélecteur
    const labelEl = document.querySelector('[data-stats-mode-label]');
    const hintEl  = document.querySelector('[data-stats-mode-hint]');
    if (labelEl) labelEl.textContent = MODE_LABELS[mode] + ' — ';
    if (hintEl)  hintEl.textContent  = MODE_HINTS[mode];
  }

  modeSelect.addEventListener('change', update);
  ['[data-stats-input-users]', '[data-stats-input-paid]', '[data-stats-input-today]'].forEach(function (sel) {
    const el = document.querySelector(sel);
    if (el) el.addEventListener('input', update);
  });
  update();
})();
</script>

<?php include __DIR__ . '/../footer.php'; ?>
