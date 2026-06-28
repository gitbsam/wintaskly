<?php
/**
 * Wintaskly — /tasks/shortlinks/index.php  (V8 modernisé)
 *
 * Liste des shortlinks disponibles pour l'utilisateur connecté.
 *
 * Améliorations V8 :
 *   - Header riche avec récap user (gains shortlinks aujourd'hui, total)
 *   - Filtre rapide : Tous / Disponibles / En cooldown
 *   - Cards riches (wt-task-card--rich) avec pill d'état + countdown live
 *   - Tri par récompense décroissante (les meilleurs payants d'abord)
 *   - Footer bonus parrainage (cohérence avec faucet)
 */
declare(strict_types=1);
require __DIR__ . '/../../includes/init.php';
require_auth();

$pageTitle = t('shortlinks.title');
$u  = current_user();
$db = db();

/* Filtre via query string : ?f=all|ready|locked */
$filter = (string)($_GET['f'] ?? 'all');
if (!in_array($filter, ['all', 'ready', 'locked'], true)) $filter = 'all';

/* Tous les shortlinks actifs + état de cooldown utilisateur */
$rows = [];
$sql = "SELECT s.id, s.name, s.reward_coins, s.reward_xp, s.cooldown_hours,
               s.gateway_seconds,
               c.available_at
          FROM shortlinks s
          LEFT JOIN shortlink_cooldowns c
            ON c.shortlink_id = s.id AND c.user_id = ?
         WHERE s.active = 1
         ORDER BY s.reward_coins DESC, s.id DESC";
$stmt = $db->prepare($sql);
$stmt->bind_param('i', $u['id']);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $locked   = !empty($row['available_at']) && strtotime($row['available_at'] . ' UTC') > time();
    $row['locked']   = $locked;
    $row['secsLeft'] = $locked ? max(0, strtotime($row['available_at'] . ' UTC') - time()) : 0;
    $rows[] = $row;
}
$stmt->close();

/* Compte par état pour les pills de filtre */
$totalAll    = count($rows);
$totalReady  = count(array_filter($rows, static fn ($r) => !$r['locked']));
$totalLocked = $totalAll - $totalReady;

/* Application du filtre actif */
$visibleRows = match ($filter) {
    'ready'  => array_filter($rows, static fn ($r) => !$r['locked']),
    'locked' => array_filter($rows, static fn ($r) =>  $r['locked']),
    default  => $rows,
};

/* Récap utilisateur pour shortlinks */
$slStats = ['today' => 0.0, 'total' => 0.0, 'count_today' => 0];
$stmt = $db->prepare(
    "SELECT COALESCE(SUM(CASE WHEN DATE(created_at) = UTC_DATE() THEN coins ELSE 0 END), 0) today,
            COALESCE(SUM(coins), 0) total,
            COALESCE(SUM(CASE WHEN DATE(created_at) = UTC_DATE() THEN 1 ELSE 0 END), 0) count_today
       FROM transactions
      WHERE user_id = ? AND type = 'shortlink' AND coins > 0"
);
$stmt->bind_param('i', $u['id']);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$slStats['today']       = (float)($row['today'] ?? 0);
$slStats['total']       = (float)($row['total'] ?? 0);
$slStats['count_today'] = (int)  ($row['count_today'] ?? 0);

$fmt = static function (float $n): string {
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
};

/* =============================================================================
   Détecte retour callback (mode redirect après complétion du shortlink)
   ============================================================================= */
$callbackToast = null;  // ['kind' => 'success'|'error'|'info', 'text' => '...']

if (!empty($_GET['success'])) {
    $msg      = (string) ($_GET['msg'] ?? '');
    $credited = isset($_GET['credited']) ? (float) $_GET['credited'] : null;
    $xp       = isset($_GET['xp']) ? (int) $_GET['xp'] : null;
    $linkName = trim((string) ($_GET['name'] ?? ''));

    if ($msg === 'credited' && $credited !== null) {
        $callbackToast = [
            'kind' => 'success',
            'text' => sprintf(
                (string) t('shortlinks.cb_credited'),
                $fmt($credited),
                (int) ($xp ?? 0)
            ),
        ];
    } elseif ($msg === 'already_processed') {
        $callbackToast = [
            'kind' => 'info',
            'text' => (string) t('shortlinks.cb_already'),
        ];
    }
} elseif (!empty($_GET['error'])) {
    $msg = (string) ($_GET['msg'] ?? '');
    $errKey = 'shortlinks.cb_err_' . preg_replace('/[^a-z0-9_]/i', '', $msg);
    $errText = (string) t($errKey);
    if ($errText === $errKey) {
        // Pas de clé i18n spécifique → fallback générique
        $errText = (string) t('shortlinks.cb_err_generic');
    }
    $callbackToast = ['kind' => 'error', 'text' => $errText];
}

include __DIR__ . '/../../header.php';
?>

<main class="wt-main wt-sl-v2">
  <div class="wt-sl-v2__wrap">

    <?php if ($callbackToast !== null): ?>
      <!-- Toast retour callback (redirect après complétion d'un shortlink) -->
      <div class="wt-alert wt-alert--<?= e($callbackToast['kind']) ?> wt-sl-v2__callback-toast"
           role="status" data-reveal data-sl-callback-toast>
        <?php if ($callbackToast['kind'] === 'success'): ?>🎉<?php
              elseif ($callbackToast['kind'] === 'error'): ?>⚠<?php
              else: ?>ℹ<?php endif; ?>
        &nbsp;<?= e($callbackToast['text']) ?>
      </div>
    <?php endif; ?>

    <!-- ====== HEADER ====== -->
    <header class="wt-sl-v2__header" data-reveal>
      <div class="wt-sl-v2__intro">
        <span class="wt-eyebrow">🔗 <?= e(t('shortlinks.eyebrow')) ?></span>
        <h1 class="wt-sl-v2__title"><?= e(t('shortlinks.title')) ?></h1>
        <p class="wt-sl-v2__lead"><?= e(t('shortlinks.intro')) ?></p>
      </div>

      <!-- Mini récap shortlinks (3 KPI) -->
      <aside class="wt-sl-v2__recap">
        <div class="wt-sl-v2__recap-item">
          <small><?= e(t('shortlinks.today')) ?></small>
          <strong>+<?= e($fmt($slStats['today'])) ?></strong>
          <em><?= e(t('common.coins')) ?></em>
        </div>
        <div class="wt-sl-v2__recap-item">
          <small><?= e(t('shortlinks.done_today')) ?></small>
          <strong><?= (int)$slStats['count_today'] ?></strong>
          <em><?= e(t('shortlinks.completed')) ?></em>
        </div>
        <div class="wt-sl-v2__recap-item">
          <small><?= e(t('shortlinks.lifetime')) ?></small>
          <strong>+<?= e($fmt($slStats['total'])) ?></strong>
          <em><?= e(t('common.coins')) ?></em>
        </div>
      </aside>
    </header>

    <!-- ====== FILTRE PAR ÉTAT ====== -->
    <?php if ($totalAll > 0): ?>
      <nav class="wt-sl-v2__filters" data-reveal aria-label="<?= e(t('shortlinks.filter_label')) ?>">
        <a class="wt-sl-v2__filter <?= $filter === 'all' ? 'is-active' : '' ?>"
           href="<?= e(wt_url('/tasks/shortlinks/?f=all')) ?>">
          <?= e(t('shortlinks.filter_all')) ?>
          <span class="wt-sl-v2__filter-count"><?= (int)$totalAll ?></span>
        </a>
        <a class="wt-sl-v2__filter <?= $filter === 'ready' ? 'is-active' : '' ?>"
           href="<?= e(wt_url('/tasks/shortlinks/?f=ready')) ?>">
          ✓ <?= e(t('shortlinks.filter_ready')) ?>
          <span class="wt-sl-v2__filter-count wt-sl-v2__filter-count--ready"><?= (int)$totalReady ?></span>
        </a>
        <a class="wt-sl-v2__filter <?= $filter === 'locked' ? 'is-active' : '' ?>"
           href="<?= e(wt_url('/tasks/shortlinks/?f=locked')) ?>">
          ⏱ <?= e(t('shortlinks.filter_locked')) ?>
          <span class="wt-sl-v2__filter-count wt-sl-v2__filter-count--locked"><?= (int)$totalLocked ?></span>
        </a>
      </nav>
    <?php endif; ?>

    <!-- ====== GRILLE DES LIENS ====== -->
    <?php if ($totalAll === 0): ?>
      <div class="wt-sl-v2__empty" data-reveal>
        <span class="wt-sl-v2__empty-icon" aria-hidden="true">🔗</span>
        <h2><?= e(t('shortlinks.empty_title')) ?></h2>
        <p><?= e(t('shortlinks.empty')) ?></p>
        <a class="wt-btn wt-btn--ghost" href="<?= e(wt_url('/tasks/')) ?>">
          ← <?= e(t('shortlinks.back_to_hub')) ?>
        </a>
      </div>
    <?php elseif (empty($visibleRows)): ?>
      <div class="wt-sl-v2__empty" data-reveal>
        <span class="wt-sl-v2__empty-icon" aria-hidden="true">🤷</span>
        <h2><?= e(t('shortlinks.filter_empty_title')) ?></h2>
        <p><?= e(t('shortlinks.filter_empty')) ?></p>
        <a class="wt-btn wt-btn--ghost" href="<?= e(wt_url('/tasks/shortlinks/?f=all')) ?>">
          <?= e(t('shortlinks.see_all')) ?>
        </a>
      </div>
    <?php else: ?>
      <section class="wt-sl-v2__grid" data-reveal>
        <?php foreach (array_values($visibleRows) as $i => $sl): ?>
          <article class="wt-sl-v2__card <?= $sl['locked'] ? 'is-locked' : '' ?>"
                   style="--idx:<?= (int)$i ?>">
            <header class="wt-sl-v2__card-head">
              <div class="wt-sl-v2__card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
                </svg>
              </div>
              <h3 class="wt-sl-v2__card-name"><?= e($sl['name']) ?></h3>
              <?php if ($sl['locked']): ?>
                <span class="wt-sl-v2__pill wt-sl-v2__pill--locked">
                  ⏱ <?= e(t('shortlinks.cooldown')) ?>
                </span>
              <?php else: ?>
                <span class="wt-sl-v2__pill wt-sl-v2__pill--ready">
                  ✓ <?= e(t('shortlinks.ready')) ?>
                </span>
              <?php endif; ?>
            </header>

            <!-- Pricing en gros -->
            <div class="wt-sl-v2__card-price">
              <span class="wt-sl-v2__card-amount">+<?= e($fmt((float)$sl['reward_coins'])) ?></span>
              <span class="wt-sl-v2__card-unit"><?= e(t('common.coins')) ?></span>
              <?php if ((int)$sl['reward_xp'] > 0): ?>
                <span class="wt-sl-v2__card-xp">+ <?= (int)$sl['reward_xp'] ?> XP</span>
              <?php endif; ?>
            </div>

            <ul class="wt-sl-v2__card-meta">
              <li>
                <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                ≈ <?= max(3, (int)$sl['gateway_seconds']) ?> s
              </li>
              <li>
                <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 0 1 18 0"/><path d="M3 12a9 9 0 0 0 18 0"/></svg>
                <?= sprintf((string)t('shortlinks.cooldown_hours'), (int)$sl['cooldown_hours']) ?>
              </li>
            </ul>

            <!-- État : countdown locked OU bouton GO -->
            <?php if ($sl['locked']): ?>
              <div class="wt-sl-v2__card-cd">
                <small><?= e(t('shortlinks.available_in')) ?></small>
                <strong data-countdown
                        data-target="<?= e($sl['available_at']) ?>"
                        data-label-ready="<?= e(t('shortlinks.ready_now')) ?>">
                  …
                </strong>
              </div>
            <?php else: ?>
              <a class="wt-btn wt-btn--primary wt-btn--block wt-sl-v2__card-cta"
                 href="<?= e(wt_url('/tasks/shortlinks/gateway.php?id=' . (int)$sl['id'])) ?>">
                <?= e(t('shortlinks.go')) ?> →
              </a>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>

    <!-- ====== FOOTER BONUS PARRAINAGE (cohérence faucet) ====== -->
    <p class="wt-sl-v2__bonus">
      <?= e(t('faucet.referral_bonus')) ?>
      <a href="<?= e(wt_url('/dashboard/referrals.php')) ?>"><?= e(t('faucet.referral_link')) ?> →</a>
    </p>

  </div>
</main>

<?php include __DIR__ . '/../../footer.php'; ?>
