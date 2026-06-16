<?php
/**
 * Wintaskly — /tasks/ptc/index.php  (V8 modernisé)
 *
 * Liste des annonces PTC (Paid To Click).
 *
 * IMPORTANT — Compat JS :
 *   Les attributs suivants sont consommés par wintaskly.js (moteur PTC)
 *   et NE DOIVENT PAS être modifiés :
 *     - [data-ptc-list]        sur la liste
 *     - [data-ptc-card]        sur chaque card
 *     - [data-ptc-id]          sur card + bouton start
 *     - [data-ptc-start]       sur le bouton de démarrage
 *     - <html data-ptc-*>      strings exposées au JS pour le moteur
 *
 *   Le markup interne (couleurs, layout, stepper, pills) peut évoluer
 *   librement tant que ces hooks restent en place.
 *
 * Améliorations V8 :
 *   - Header 2-col avec récap utilisateur (gains PTC du jour + total)
 *   - Filtres : Toutes / Disponibles / Cooldown / Limite atteinte
 *   - Cards riches (variant wt-task-card--rich + accent orange)
 *   - Countdown live ([data-countdown][data-target]) sur cards lockées
 *   - Empty state soigné + footer bonus parrainage
 */
declare(strict_types=1);
require __DIR__ . '/../../includes/init.php';
require_auth();

$pageTitle = t('ptc.title');
$u  = current_user();
$db = db();

/* Filtre via query string : ?f=all|ready|locked|limited */
$filter = (string)($_GET['f'] ?? 'all');
if (!in_array($filter, ['all', 'ready', 'locked', 'limited'], true)) $filter = 'all';

/* ----- annonces + état utilisateur ----------------------------------- */
$rows = [];
$sql = "SELECT a.id, a.title, a.description, a.reward_coins, a.reward_xp,
               a.duration_seconds, a.cooldown_hours, a.daily_view_limit,
               (SELECT MAX(v.next_view_at)
                  FROM ptc_views v
                 WHERE v.user_id = ? AND v.ptc_id = a.id) AS next_view_at,
               (SELECT COUNT(*) FROM ptc_views v2
                 WHERE v2.ptc_id = a.id
                   AND v2.viewed_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY) AS views_today
          FROM ptc_ads a
         WHERE a.active = 1
         ORDER BY a.reward_coins DESC, a.id DESC";
$stmt = $db->prepare($sql);
$stmt->bind_param('i', $u['id']);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $locked  = !empty($row['next_view_at']) && strtotime($row['next_view_at'] . ' UTC') > time();
    $limited = (int)$row['views_today'] >= (int)$row['daily_view_limit'];
    $row['locked']  = $locked;
    $row['limited'] = $limited;
    $row['ready']   = !$locked && !$limited;
    $rows[] = $row;
}
$stmt->close();

/* Compte par état pour les pills de filtre */
$totalAll     = count($rows);
$totalReady   = count(array_filter($rows, static fn ($r) => $r['ready']));
$totalLocked  = count(array_filter($rows, static fn ($r) => $r['locked'] && !$r['limited']));
$totalLimited = count(array_filter($rows, static fn ($r) => $r['limited']));

/* Application du filtre */
$visibleRows = match ($filter) {
    'ready'   => array_filter($rows, static fn ($r) => $r['ready']),
    'locked'  => array_filter($rows, static fn ($r) => $r['locked'] && !$r['limited']),
    'limited' => array_filter($rows, static fn ($r) => $r['limited']),
    default   => $rows,
};

/* Récap utilisateur — gains PTC */
$ptcStats = ['today' => 0.0, 'total' => 0.0, 'count_today' => 0];
$stmt = $db->prepare(
    "SELECT COALESCE(SUM(CASE WHEN DATE(created_at) = UTC_DATE() THEN coins ELSE 0 END), 0) today,
            COALESCE(SUM(coins), 0) total,
            COALESCE(SUM(CASE WHEN DATE(created_at) = UTC_DATE() THEN 1 ELSE 0 END), 0) count_today
       FROM transactions
      WHERE user_id = ? AND type = 'ptc' AND coins > 0"
);
$stmt->bind_param('i', $u['id']);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$ptcStats['today']       = (float)($row['today'] ?? 0);
$ptcStats['total']       = (float)($row['total'] ?? 0);
$ptcStats['count_today'] = (int)  ($row['count_today'] ?? 0);

$fmt = static function (float $n): string {
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
};

include __DIR__ . '/../../header.php';
?>

<!-- ===== Strings exposées au JS (moteur PTC — conservé tel quel) ===== -->
<script>
(function(){
  var h = document.documentElement;
  h.dataset.ptcTabClosed    = <?= json_encode(t('ptc.tab_closed')) ?>;
  h.dataset.ptcRunning      = <?= json_encode(t('ptc.running')) ?>;
  h.dataset.ptcTitleRunning = <?= json_encode(t('ptc.title_running')) ?>;
  h.dataset.ptcTitleReady   = <?= json_encode(t('ptc.title_ready')) ?>;
  h.dataset.ptcSuccess      = <?= json_encode(t('ptc.modal.success')) ?>;
  h.dataset.ptcModalTitle   = <?= json_encode(t('ptc.modal.title')) ?>;
  h.dataset.ptcModalIntro   = <?= json_encode(t('ptc.modal.intro')) ?>;
  h.dataset.ptcCaptcha      = <?= json_encode(t('faucet.captcha')) ?>;
})();
</script>

<main class="wt-main wt-ptc-v2">
  <div class="wt-ptc-v2__wrap">

    <?php $_ad = wt_ad_zone('ptc_chrono_top'); if ($_ad !== ''): ?>
      <div class="wt-ad-zone wt-ad-zone--top" style="margin-bottom:1.5rem;text-align:center"><?= $_ad ?></div>
    <?php endif; ?>

    <!-- ====== HEADER 2-col ====== -->
    <header class="wt-ptc-v2__header" data-reveal>
      <div class="wt-ptc-v2__intro">
        <span class="wt-eyebrow">📺 <?= e(t('ptc.eyebrow')) ?></span>
        <h1 class="wt-ptc-v2__title"><?= e(t('ptc.title')) ?></h1>
        <p class="wt-ptc-v2__lead"><?= e(t('ptc.intro')) ?></p>
      </div>

      <aside class="wt-ptc-v2__recap">
        <div class="wt-ptc-v2__recap-item">
          <small><?= e(t('shortlinks.today')) ?></small>
          <strong>+<?= e($fmt($ptcStats['today'])) ?></strong>
          <em><?= e(t('common.coins')) ?></em>
        </div>
        <div class="wt-ptc-v2__recap-item">
          <small><?= e(t('ptc.viewed_today')) ?></small>
          <strong><?= (int)$ptcStats['count_today'] ?></strong>
          <em><?= e(t('ptc.ads')) ?></em>
        </div>
        <div class="wt-ptc-v2__recap-item">
          <small><?= e(t('shortlinks.lifetime')) ?></small>
          <strong>+<?= e($fmt($ptcStats['total'])) ?></strong>
          <em><?= e(t('common.coins')) ?></em>
        </div>
      </aside>
    </header>

    <!-- ====== FILTRES ====== -->
    <?php if ($totalAll > 0): ?>
      <nav class="wt-ptc-v2__filters" data-reveal aria-label="<?= e(t('ptc.filter_label')) ?>">
        <a class="wt-ptc-v2__filter <?= $filter === 'all' ? 'is-active' : '' ?>"
           href="<?= e(wt_url('/tasks/ptc/?f=all')) ?>">
          <?= e(t('ptc.filter_all')) ?>
          <span class="wt-ptc-v2__filter-count"><?= (int)$totalAll ?></span>
        </a>
        <a class="wt-ptc-v2__filter <?= $filter === 'ready' ? 'is-active' : '' ?>"
           href="<?= e(wt_url('/tasks/ptc/?f=ready')) ?>">
          ✓ <?= e(t('ptc.filter_ready')) ?>
          <span class="wt-ptc-v2__filter-count wt-ptc-v2__filter-count--ready"><?= (int)$totalReady ?></span>
        </a>
        <a class="wt-ptc-v2__filter <?= $filter === 'locked' ? 'is-active' : '' ?>"
           href="<?= e(wt_url('/tasks/ptc/?f=locked')) ?>">
          ⏱ <?= e(t('ptc.filter_locked')) ?>
          <span class="wt-ptc-v2__filter-count wt-ptc-v2__filter-count--locked"><?= (int)$totalLocked ?></span>
        </a>
        <a class="wt-ptc-v2__filter <?= $filter === 'limited' ? 'is-active' : '' ?>"
           href="<?= e(wt_url('/tasks/ptc/?f=limited')) ?>">
          🚫 <?= e(t('ptc.filter_limited')) ?>
          <span class="wt-ptc-v2__filter-count wt-ptc-v2__filter-count--limited"><?= (int)$totalLimited ?></span>
        </a>
      </nav>
    <?php endif; ?>

    <!-- ====== GRILLE ====== -->
    <?php if ($totalAll === 0): ?>
      <div class="wt-ptc-v2__empty" data-reveal>
        <span class="wt-ptc-v2__empty-icon" aria-hidden="true">📺</span>
        <h2><?= e(t('ptc.empty_title')) ?></h2>
        <p><?= e(t('ptc.empty')) ?></p>
        <a class="wt-btn wt-btn--ghost" href="<?= e(wt_url('/tasks/')) ?>">
          ← <?= e(t('shortlinks.back_to_hub')) ?>
        </a>
      </div>
    <?php elseif (empty($visibleRows)): ?>
      <div class="wt-ptc-v2__empty" data-reveal>
        <span class="wt-ptc-v2__empty-icon" aria-hidden="true">🤷</span>
        <h2><?= e(t('shortlinks.filter_empty_title')) ?></h2>
        <p><?= e(t('shortlinks.filter_empty')) ?></p>
        <a class="wt-btn wt-btn--ghost" href="<?= e(wt_url('/tasks/ptc/?f=all')) ?>">
          <?= e(t('shortlinks.see_all')) ?>
        </a>
      </div>
    <?php else: ?>
      <ul class="wt-ptc-v2__grid" data-reveal data-ptc-list>
        <?php foreach (array_values($visibleRows) as $i => $ad):
              $state = $ad['limited'] ? 'limited' : ($ad['locked'] ? 'locked' : 'ready');
        ?>
          <li class="wt-ptc-v2__card wt-ptc-v2__card--<?= $state ?>"
              style="--idx:<?= (int)$i ?>"
              data-ptc-card
              data-ptc-id="<?= (int)$ad['id'] ?>">

            <header class="wt-ptc-v2__card-head">
              <div class="wt-ptc-v2__card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="2" y="4" width="20" height="15" rx="2"/>
                  <polygon points="10 9 15 11.5 10 14" fill="currentColor"/>
                </svg>
              </div>
              <h3 class="wt-ptc-v2__card-title"><?= e($ad['title']) ?></h3>

              <?php if ($state === 'limited'): ?>
                <span class="wt-ptc-v2__pill wt-ptc-v2__pill--limited">
                  🚫 <?= e(t('ptc.limit_reached')) ?>
                </span>
              <?php elseif ($state === 'locked'): ?>
                <span class="wt-ptc-v2__pill wt-ptc-v2__pill--locked">
                  ⏱ <?= e(t('shortlinks.cooldown')) ?>
                </span>
              <?php else: ?>
                <span class="wt-ptc-v2__pill wt-ptc-v2__pill--ready">
                  ✓ <?= e(t('shortlinks.ready')) ?>
                </span>
              <?php endif; ?>
            </header>

            <?php if (!empty($ad['description'])): ?>
              <p class="wt-ptc-v2__card-desc"><?= e($ad['description']) ?></p>
            <?php endif; ?>

            <!-- Pricing en gros -->
            <div class="wt-ptc-v2__card-price">
              <span class="wt-ptc-v2__card-amount">+<?= e($fmt((float)$ad['reward_coins'])) ?></span>
              <span class="wt-ptc-v2__card-unit"><?= e(t('common.coins')) ?></span>
              <?php if ((int)$ad['reward_xp'] > 0): ?>
                <span class="wt-ptc-v2__card-xp">+ <?= (int)$ad['reward_xp'] ?> XP</span>
              <?php endif; ?>
            </div>

            <ul class="wt-ptc-v2__card-meta">
              <li>
                <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <?= e(sprintf((string)t('ptc.meta_duration'), (int)$ad['duration_seconds'])) ?>
              </li>
              <li>
                <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <?= e(sprintf((string)t('ptc.meta_views'), (int)$ad['views_today'], (int)$ad['daily_view_limit'])) ?>
              </li>
            </ul>

            <!-- État : message / countdown / bouton -->
            <?php if ($state === 'limited'): ?>
              <div class="wt-ptc-v2__card-status">
                <small><?= e(t('ptc.try_tomorrow')) ?></small>
              </div>
            <?php elseif ($state === 'locked'): ?>
              <div class="wt-ptc-v2__card-cd">
                <small><?= e(t('ptc.next_view_in')) ?></small>
                <strong data-countdown
                        data-target="<?= e($ad['next_view_at']) ?>"
                        data-label-ready="<?= e(t('shortlinks.ready_now')) ?>">
                  …
                </strong>
              </div>
            <?php else: ?>
              <button type="button"
                      class="wt-btn wt-btn--primary wt-btn--block wt-ptc-v2__card-cta"
                      data-ptc-start
                      data-ptc-id="<?= (int)$ad['id'] ?>">
                ▶ <?= e(t('ptc.visit')) ?>
              </button>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <!-- ====== FOOTER BONUS PARRAINAGE ====== -->
    <p class="wt-ptc-v2__bonus">
      <?= e(t('faucet.referral_bonus')) ?>
      <a href="<?= e(wt_url('/dashboard/referrals.php')) ?>"><?= e(t('faucet.referral_link')) ?> →</a>
    </p>

  </div>
</main>

<?php include __DIR__ . '/../../footer.php'; ?>
