<?php
/**
 * Wintaskly — Admin · Vue d'ensemble (V8 modernisé).
 *
 * Tableau de bord admin avec :
 *   - 4 KPI cards (utilisateurs / 7j claims / 7j shortlinks / coins distribués)
 *   - Comparaison 7j vs 7j précédents (variation %)
 *   - Stats tickets ouverts + retraits en attente (alertes actionnables)
 *   - Liste rapide des 5 derniers utilisateurs inscrits
 *   - Liste rapide des 5 derniers retraits demandés
 */
require __DIR__ . '/../includes/init.php';
require_admin();

$pageTitle  = t('admin.title') . ' — ' . t('admin.dash');
$adminActive = 'dash';
$db = db();

// Init arrays remplis ci-dessous
$kpis = ['users' => 0, 'claims' => 0, 'shortlinks' => 0, 'coins' => 0.0];
$prev = ['users' => 0, 'claims' => 0, 'shortlinks' => 0, 'coins' => 0.0];

/* ----------------------------------------------------------------------
 * KPI 7d + comparaison 14d->7d en une seule passe par table (4 queries
 * au lieu de 8). On utilise des agrégations conditionnelles SUM(CASE...)
 * qui exploitent les indexes range sur les colonnes datetime.
 *
 * Gains : -50% de queries dashboard + un seul accès par table.
 * ---------------------------------------------------------------------- */
$dashD = WT_PERIOD_DASHBOARD_DAYS;
$compD = WT_PERIOD_COMPARISON_DAYS;

// 1) users : actifs total + nouveaux 7d + nouveaux 14d-7d
$row = db_one(
    "SELECT
        COUNT(*) AS active_total,
        SUM(CASE WHEN created_at >= UTC_TIMESTAMP() - INTERVAL {$dashD} DAY THEN 1 ELSE 0 END) AS new_7d,
        SUM(CASE WHEN created_at >= UTC_TIMESTAMP() - INTERVAL {$compD} DAY
                  AND created_at <  UTC_TIMESTAMP() - INTERVAL {$dashD} DAY THEN 1 ELSE 0 END) AS new_prev_7d
       FROM users
      WHERE status = 'active'"
) ?? [];

$kpis['users']   = (int) ($row['active_total'] ?? 0);
$prev['users']   = (int) ($row['new_prev_7d']  ?? 0);
$newUsers7d      = (int) ($row['new_7d']       ?? 0);

// 2) faucet_claims : 7d + 14d-7d en une passe (couvert par idx_claimed_at)
$row = db_one(
    "SELECT
        SUM(CASE WHEN claimed_at >= UTC_TIMESTAMP() - INTERVAL {$dashD} DAY THEN 1 ELSE 0 END) AS d7,
        SUM(CASE WHEN claimed_at >= UTC_TIMESTAMP() - INTERVAL {$compD} DAY
                  AND claimed_at <  UTC_TIMESTAMP() - INTERVAL {$dashD} DAY THEN 1 ELSE 0 END) AS prev_d7
       FROM faucet_claims
      WHERE claimed_at >= UTC_TIMESTAMP() - INTERVAL {$compD} DAY"
) ?? [];
$kpis['claims'] = (int) ($row['d7']      ?? 0);
$prev['claims'] = (int) ($row['prev_d7'] ?? 0);

// 3) shortlink_attempts (status='valide') 7d + 14d-7d (couvert par idx_status_completed)
$row = db_one(
    "SELECT
        SUM(CASE WHEN completed_at >= UTC_TIMESTAMP() - INTERVAL {$dashD} DAY THEN 1 ELSE 0 END) AS d7,
        SUM(CASE WHEN completed_at >= UTC_TIMESTAMP() - INTERVAL {$compD} DAY
                  AND completed_at <  UTC_TIMESTAMP() - INTERVAL {$dashD} DAY THEN 1 ELSE 0 END) AS prev_d7
       FROM shortlink_attempts
      WHERE status = 'valide'
        AND completed_at >= UTC_TIMESTAMP() - INTERVAL {$compD} DAY"
) ?? [];
$kpis['shortlinks'] = (int) ($row['d7']      ?? 0);
$prev['shortlinks'] = (int) ($row['prev_d7'] ?? 0);

// 4) transactions positives : sum 7d + sum 14d-7d (couvert par idx_created_type)
$row = db_one(
    "SELECT
        COALESCE(SUM(CASE WHEN created_at >= UTC_TIMESTAMP() - INTERVAL {$dashD} DAY THEN coins ELSE 0 END), 0) AS d7,
        COALESCE(SUM(CASE WHEN created_at >= UTC_TIMESTAMP() - INTERVAL {$compD} DAY
                           AND created_at <  UTC_TIMESTAMP() - INTERVAL {$dashD} DAY THEN coins ELSE 0 END), 0) AS prev_d7
       FROM transactions
      WHERE coins > 0
        AND created_at >= UTC_TIMESTAMP() - INTERVAL {$compD} DAY"
) ?? [];
$kpis['coins'] = (float) ($row['d7']      ?? 0);
$prev['coins'] = (float) ($row['prev_d7'] ?? 0);

/* Alertes actionnables */
$_w = db_one("SELECT COUNT(*) c FROM withdrawals WHERE status='pending'");
$pendingWithdrawals = (int) ($_w['c'] ?? 0);
$_t = db_one("SELECT COUNT(*) c FROM support_tickets WHERE status='open'");
$openTickets = (int) ($_t['c'] ?? 0);
$pendingTestimonials = 0;
$res = $db->query("SHOW TABLES LIKE 'testimonials'");
if ($res && $res->num_rows > 0) {
    $_pt = db_one("SELECT COUNT(*) c FROM testimonials WHERE status='pending'");
    $pendingTestimonials = (int) ($_pt['c'] ?? 0);
}

/* 5 derniers utilisateurs */
$lastUsers = [];
if ($res = $db->query("SELECT id, username, email, created_at FROM users ORDER BY id DESC LIMIT 5")) {
    $lastUsers = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();
}

/* 5 derniers retraits pendants */
$lastWithdrawals = [];
$sql = "SELECT w.id, w.coins_amount, w.payout_amount, w.payout_currency, w.created_at,
               w.status, u.username
          FROM withdrawals w
          JOIN users u ON u.id = w.user_id
         ORDER BY w.id DESC LIMIT 5";
if ($res = $db->query($sql)) {
    $lastWithdrawals = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();
}

/* Helper variation % */
$variation = static function (float $now, float $prev): array {
    if ($prev == 0.0) {
        return $now > 0 ? ['sign' => '+', 'pct' => '∞', 'class' => 'pos'] : ['sign' => '', 'pct' => '0', 'class' => 'neutral'];
    }
    $delta = (($now - $prev) / $prev) * 100;
    return [
        'sign'  => $delta >= 0 ? '+' : '',
        'pct'   => number_format(abs($delta), 1, '.', ''),
        'class' => $delta > 0 ? 'pos' : ($delta < 0 ? 'neg' : 'neutral'),
    ];
};

$base = rtrim($GLOBALS['WT_CONFIG']['base_url'] ?? '', '/');

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>

    <section class="wt-admin-v2__content" data-reveal>

      <header class="wt-admin-v2__page-header">
        <div>
          <span class="wt-eyebrow">📊 <?= e(t('admin.eyebrow_dash')) ?></span>
          <h1 class="wt-admin-v2__title"><?= e(t('admin.dash')) ?></h1>
          <p class="wt-muted"><?= e(t('admin.dash.lead')) ?></p>
        </div>
      </header>

      <!-- Alertes actionnables -->
      <?php if ($pendingWithdrawals + $openTickets + $pendingTestimonials > 0): ?>
        <section class="wt-admin-v2__alerts" data-reveal>
          <?php if ($pendingWithdrawals > 0): ?>
            <a class="wt-admin-v2__alert wt-admin-v2__alert--warn" href="<?= $base ?>/admin/withdrawals.php" style="--idx:0">
              <span class="wt-admin-v2__alert-icon">⏳</span>
              <div>
                <strong><?= (int)$pendingWithdrawals ?></strong>
                <small><?= e(t('admin.alert.pending_withdrawals')) ?></small>
              </div>
              <span class="wt-admin-v2__alert-arrow">→</span>
            </a>
          <?php endif; ?>
          <?php if ($openTickets > 0): ?>
            <a class="wt-admin-v2__alert wt-admin-v2__alert--info" href="<?= $base ?>/admin/tickets.php" style="--idx:1">
              <span class="wt-admin-v2__alert-icon">🎫</span>
              <div>
                <strong><?= (int)$openTickets ?></strong>
                <small><?= e(t('admin.alert.open_tickets')) ?></small>
              </div>
              <span class="wt-admin-v2__alert-arrow">→</span>
            </a>
          <?php endif; ?>
          <?php if ($pendingTestimonials > 0): ?>
            <a class="wt-admin-v2__alert wt-admin-v2__alert--info" href="<?= $base ?>/admin/testimonials.php" style="--idx:2">
              <span class="wt-admin-v2__alert-icon">⭐</span>
              <div>
                <strong><?= (int)$pendingTestimonials ?></strong>
                <small><?= e(t('admin.alert.pending_testimonials')) ?></small>
              </div>
              <span class="wt-admin-v2__alert-arrow">→</span>
            </a>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <!-- KPI cards avec variation 7d -->
      <section class="wt-admin-v2__kpis-grid" data-reveal>
        <article class="wt-admin-v2__kpi-card" style="--idx:0">
          <header>
            <span class="wt-admin-v2__kpi-card-icon">👥</span>
            <small><?= e(t('admin.kpi.users')) ?></small>
          </header>
          <strong><?= number_format((int)$kpis['users'], 0, '.', ' ') ?></strong>
          <footer>
            <span class="wt-admin-v2__variation">+<?= (int)$newUsers7d ?> <?= e(t('admin.kpi.this_week')) ?></span>
          </footer>
        </article>

        <?php $v = $variation((float)$kpis['claims'], (float)$prev['claims']); ?>
        <article class="wt-admin-v2__kpi-card" style="--idx:1">
          <header>
            <span class="wt-admin-v2__kpi-card-icon">💧</span>
            <small><?= e(t('admin.kpi.claims')) ?></small>
          </header>
          <strong><?= number_format((int)$kpis['claims'], 0, '.', ' ') ?></strong>
          <footer>
            <span class="wt-admin-v2__variation wt-admin-v2__variation--<?= e($v['class']) ?>">
              <?= e($v['sign']) ?><?= e($v['pct']) ?>% <?= e(t('admin.kpi.vs_prev_week')) ?>
            </span>
          </footer>
        </article>

        <?php $v = $variation((float)$kpis['shortlinks'], (float)$prev['shortlinks']); ?>
        <article class="wt-admin-v2__kpi-card" style="--idx:2">
          <header>
            <span class="wt-admin-v2__kpi-card-icon">🔗</span>
            <small><?= e(t('admin.kpi.shortlinks')) ?></small>
          </header>
          <strong><?= number_format((int)$kpis['shortlinks'], 0, '.', ' ') ?></strong>
          <footer>
            <span class="wt-admin-v2__variation wt-admin-v2__variation--<?= e($v['class']) ?>">
              <?= e($v['sign']) ?><?= e($v['pct']) ?>% <?= e(t('admin.kpi.vs_prev_week')) ?>
            </span>
          </footer>
        </article>

        <?php $v = $variation((float)$kpis['coins'], (float)$prev['coins']); ?>
        <article class="wt-admin-v2__kpi-card" style="--idx:3">
          <header>
            <span class="wt-admin-v2__kpi-card-icon">💰</span>
            <small><?= e(t('admin.kpi.coins')) ?></small>
          </header>
          <strong><?= e(rtrim(rtrim(number_format((float)$kpis['coins'], 2, '.', ''), '0'), '.')) ?></strong>
          <footer>
            <span class="wt-admin-v2__variation wt-admin-v2__variation--<?= e($v['class']) ?>">
              <?= e($v['sign']) ?><?= e($v['pct']) ?>% <?= e(t('admin.kpi.vs_prev_week')) ?>
            </span>
          </footer>
        </article>
      </section>

      <!-- 2 col : derniers users + derniers retraits -->
      <section class="wt-admin-v2__quick-grid" data-reveal>
        <article class="wt-admin-v2__card">
          <header class="wt-admin-v2__card-head">
            <span class="wt-admin-v2__card-icon">👥</span>
            <div>
              <h2><?= e(t('admin.dash.last_users')) ?></h2>
              <small class="wt-muted"><?= e(t('admin.dash.last_users_lead')) ?></small>
            </div>
            <a class="wt-btn wt-btn--xs wt-btn--ghost" href="<?= $base ?>/admin/users.php"
               style="margin-left:auto"><?= e(t('common.see_all')) ?> →</a>
          </header>

          <?php if (!$lastUsers): ?>
            <div class="wt-admin-v2__empty">
              <p><?= e(t('common.empty')) ?></p>
            </div>
          <?php else: ?>
            <ul class="wt-admin-v2__mini-list">
              <?php foreach ($lastUsers as $i => $u): ?>
                <li style="--idx:<?= (int)$i ?>">
                  <div class="wt-avatar wt-avatar--sm"
                       data-hash-color="<?= e($u['username']) ?>" aria-hidden="true">
                    <?= e(mb_strtoupper(mb_substr($u['username'], 0, 1)) . mb_strtoupper(mb_substr($u['username'], 1, 1))) ?>
                  </div>
                  <div>
                    <strong><?= e($u['username']) ?></strong>
                    <small><?= e($u['email']) ?></small>
                  </div>
                  <time class="wt-muted"
                        data-fmt-time data-utc="<?= e($u['created_at']) ?>" data-format="relative">
                    <?= e(wt_format_datetime($u['created_at'])) ?>
                  </time>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </article>

        <article class="wt-admin-v2__card">
          <header class="wt-admin-v2__card-head">
            <span class="wt-admin-v2__card-icon">💸</span>
            <div>
              <h2><?= e(t('admin.dash.last_withdrawals')) ?></h2>
              <small class="wt-muted"><?= e(t('admin.dash.last_withdrawals_lead')) ?></small>
            </div>
            <a class="wt-btn wt-btn--xs wt-btn--ghost" href="<?= $base ?>/admin/withdrawals.php"
               style="margin-left:auto"><?= e(t('common.see_all')) ?> →</a>
          </header>

          <?php if (!$lastWithdrawals): ?>
            <div class="wt-admin-v2__empty">
              <p><?= e(t('common.empty')) ?></p>
            </div>
          <?php else: ?>
            <ul class="wt-admin-v2__mini-list">
              <?php foreach ($lastWithdrawals as $i => $w):
                $statusClass = match ($w['status']) {
                    'completed' => 'pos',
                    'refused'   => 'neg',
                    default     => 'pending',
                };
              ?>
                <li style="--idx:<?= (int)$i ?>">
                  <span class="wt-admin-v2__mini-dot wt-admin-v2__mini-dot--<?= e($statusClass) ?>"></span>
                  <div>
                    <strong><?= e($w['username']) ?></strong>
                    <small>
                      -<?= e(rtrim(rtrim(number_format((float)$w['coins_amount'], 4, '.', ''), '0'), '.')) ?>
                      → <?= e(rtrim(rtrim(number_format((float)$w['payout_amount'], 6, '.', ''), '0'), '.')) ?>
                      <?= e($w['payout_currency']) ?>
                    </small>
                  </div>
                  <time class="wt-muted"
                        data-fmt-time data-utc="<?= e($w['created_at']) ?>" data-format="relative">
                    <?= e(wt_format_datetime($w['created_at'])) ?>
                  </time>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </article>
      </section>

    </section>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
