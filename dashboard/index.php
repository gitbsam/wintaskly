<?php
/**
 * Wintaskly — Tableau de bord utilisateur (V8 modernisé).
 *
 * Page d'accueil de la zone connectée. Affiche :
 *   - 3 KPI cards (balance / level + XP / referrals) avec halos colorés
 *   - Quick actions : 4 raccourcis vers les pages les plus utilisées
 *   - Chart : évolution des gains sur les 7 derniers jours
 *   - Card de parrainage avec lien + bouton copier
 *   - Activité récente : 20 dernières transactions, en cards
 *
 * Tous les hooks JS existants sont préservés :
 *   - data-copy="#wt-ref-url" (handler dans wintaskly.js)
 *   - data-fmt-time sur les timestamps
 */
require __DIR__ . '/../includes/init.php';
require_auth();

$u  = current_user();
$db = db();

$pageTitle = t('nav.dashboard');

/* --- Progression XP / niveau --- */
$prog = xp_progress((int) $u['xp']);

/* --- Lien de parrainage --- */
$base   = rtrim($GLOBALS['WT_CONFIG']['base_url'] ?? '', '/');
$refUrl = $base . '/auth/register.php?ref=' . urlencode($u['referral_code']);

/* --- Compte filleuls --- */
$nbReferrals = 0;
$stmt = $db->prepare("SELECT COUNT(*) c FROM users WHERE referrer_id = ?");
$stmt->bind_param('i', $u['id']);
$stmt->execute();
$nbReferrals = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

/* --- Total gains today (pour KPI) --- */
$todayGains = 0.0;
$stmt = $db->prepare(
    "SELECT COALESCE(SUM(coins), 0) total
       FROM transactions
      WHERE user_id = ? AND coins > 0
        AND created_at >= UTC_DATE()"
);
$stmt->bind_param('i', $u['id']);
$stmt->execute();
$todayGains = (float) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

/* --- Activité 7 derniers jours pour le chart --- */
$stmt = $db->prepare(
    "SELECT DATE(created_at) d, COALESCE(SUM(GREATEST(coins, 0)), 0) gains
       FROM transactions
      WHERE user_id = ?
        AND created_at >= UTC_DATE() - INTERVAL " . WT_PERIOD_CHART_DAYS . " DAY
      GROUP BY DATE(created_at)
      ORDER BY DATE(created_at) ASC"
);
$stmt->bind_param('i', $u['id']);
$stmt->execute();
$res = $stmt->get_result();
$byDate = [];
while ($r = $res->fetch_assoc()) {
    $byDate[$r['d']] = (float)$r['gains'];
}
$stmt->close();

/* Remplir tous les 7 jours (même si pas d'activité) */
$chartData = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $chartData[] = [
        'date'  => $d,
        'label' => date('D', strtotime($d)),
        'gains' => $byDate[$d] ?? 0,
    ];
}

/* --- Historique 20 dernières transactions --- */
$history = [];
$stmt = $db->prepare(
    "SELECT type, coins, created_at
       FROM transactions
      WHERE user_id = ?
      ORDER BY id DESC
      LIMIT 20"
);
$stmt->bind_param('i', $u['id']);
$stmt->execute();
$res = $stmt->get_result();
$history = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Mapping type → icône + label affiché */
$txMeta = [
    'faucet'      => ['icon' => '💧', 'color' => 'cyan'],
    'shortlink'   => ['icon' => '🔗', 'color' => 'violet'],
    'ptc'         => ['icon' => '📺', 'color' => 'orange'],
    'offerwall'   => ['icon' => '🎁', 'color' => 'green'],
    'referral'    => ['icon' => '🤝', 'color' => 'pink'],
    'withdraw'    => ['icon' => '💸', 'color' => 'red'],
    'admin'       => ['icon' => '🛡️', 'color' => 'gray'],
    'leaderboard' => ['icon' => '🏆', 'color' => 'gold'],
    'bonus'       => ['icon' => '🎁', 'color' => 'gold'],
];

$dashActive = 'overview';
include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-dash">
  <div class="wt-dash__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
    <section class="wt-dash__content wt-dash-v2__content">

      <!-- ============ HEADER ============ -->
      <header class="wt-dash-v2__header" data-reveal>
        <div>
          <span class="wt-eyebrow">📊 <?= e(t('dash.eyebrow')) ?></span>
          <h1 class="wt-dash-v2__title">
            <?= e(t('dash.welcome', ['name' => $u['username']])) ?>
          </h1>
          <p class="wt-muted"><?= e(t('dash.subtitle')) ?></p>
        </div>
        <?php if ($todayGains > 0): ?>
          <div class="wt-dash-v2__today">
            <span class="wt-dash-v2__today-icon" aria-hidden="true">📈</span>
            <div>
              <small><?= e(t('dash.today_gains')) ?></small>
              <strong>+<?= e(rtrim(rtrim(number_format($todayGains, 4, '.', ''), '0'), '.')) ?></strong>
            </div>
          </div>
        <?php endif; ?>
      </header>

      <!-- ============ KPI CARDS ============ -->
      <section class="wt-dash-v2__kpis" data-reveal>
        <article class="wt-dash-v2__kpi wt-dash-v2__kpi--balance" style="--idx:0">
          <div class="wt-dash-v2__kpi-glow" aria-hidden="true"></div>
          <span class="wt-dash-v2__kpi-icon" aria-hidden="true">💰</span>
          <div class="wt-dash-v2__kpi-body">
            <small class="wt-dash-v2__kpi-label"><?= e(t('dash.balance')) ?></small>
            <strong class="wt-dash-v2__kpi-value">
              <?= e(rtrim(rtrim(number_format((float)$u['coins'], 4, '.', ''), '0'), '.')) ?>
              <em><?= e(t('common.coins')) ?></em>
            </strong>
            <a class="wt-dash-v2__kpi-cta" href="<?= $base ?>/dashboard/withdraw.php">
              <?= e(t('dash.kpi.withdraw_cta')) ?> →
            </a>
          </div>
        </article>

        <article class="wt-dash-v2__kpi wt-dash-v2__kpi--level" style="--idx:1">
          <div class="wt-dash-v2__kpi-glow" aria-hidden="true"></div>
          <span class="wt-dash-v2__kpi-icon" aria-hidden="true">⚡</span>
          <div class="wt-dash-v2__kpi-body">
            <small class="wt-dash-v2__kpi-label"><?= e(t('dash.level')) ?></small>
            <strong class="wt-dash-v2__kpi-value">
              <?= (int)$u['level'] ?>
              <em><?= e(sprintf((string)t('dash.kpi.next_level'), $prog['next_level'])) ?></em>
            </strong>
            <div class="wt-dash-v2__kpi-progress">
              <div class="wt-dash-v2__kpi-progress-fill" style="width:<?= (int)$prog['percent'] ?>%"></div>
            </div>
            <small class="wt-dash-v2__kpi-cta">
              <?= (int)$prog['current_xp'] ?> / <?= (int)$prog['xp_for_next'] ?> XP
            </small>
          </div>
        </article>

        <article class="wt-dash-v2__kpi wt-dash-v2__kpi--refs" style="--idx:2">
          <div class="wt-dash-v2__kpi-glow" aria-hidden="true"></div>
          <span class="wt-dash-v2__kpi-icon" aria-hidden="true">🤝</span>
          <div class="wt-dash-v2__kpi-body">
            <small class="wt-dash-v2__kpi-label"><?= e(t('dash.referrals')) ?></small>
            <strong class="wt-dash-v2__kpi-value">
              <?= (int)$nbReferrals ?>
              <em><?= e(t('dash.kpi.invitees')) ?></em>
            </strong>
            <a class="wt-dash-v2__kpi-cta" href="<?= $base ?>/dashboard/referrals.php">
              <?= e(t('dash.kpi.refs_cta')) ?> →
            </a>
          </div>
        </article>
      </section>

      <!-- ============ QUICK ACTIONS ============ -->
      <section class="wt-dash-v2__actions" data-reveal>
        <h2 class="wt-dash-v2__section-title"><?= e(t('dash.quick_actions')) ?></h2>
        <div class="wt-dash-v2__actions-grid">
          <a class="wt-dash-v2__action" href="<?= $base ?>/tasks/" style="--idx:0">
            <span class="wt-dash-v2__action-icon" aria-hidden="true">🎯</span>
            <span class="wt-dash-v2__action-label"><?= e(t('dash.action.tasks')) ?></span>
          </a>
          <a class="wt-dash-v2__action" href="<?= $base ?>/dashboard/withdraw.php" style="--idx:1">
            <span class="wt-dash-v2__action-icon" aria-hidden="true">💸</span>
            <span class="wt-dash-v2__action-label"><?= e(t('dash.action.withdraw')) ?></span>
          </a>
          <a class="wt-dash-v2__action" href="<?= $base ?>/dashboard/referrals.php" style="--idx:2">
            <span class="wt-dash-v2__action-icon" aria-hidden="true">🤝</span>
            <span class="wt-dash-v2__action-label"><?= e(t('dash.action.refs')) ?></span>
          </a>
          <a class="wt-dash-v2__action" href="<?= $base ?>/leaderboard/" style="--idx:3">
            <span class="wt-dash-v2__action-icon" aria-hidden="true">🏆</span>
            <span class="wt-dash-v2__action-label"><?= e(t('dash.action.leaderboard')) ?></span>
          </a>
        </div>
      </section>

      <!-- ============ CHART 7 DAYS ============ -->
      <section class="wt-dash-v2__chart-card" data-reveal>
        <header class="wt-dash-v2__chart-head">
          <div>
            <h2 class="wt-dash-v2__section-title"><?= e(t('dash.chart_title')) ?></h2>
            <small class="wt-muted"><?= e(t('dash.chart_subtitle')) ?></small>
          </div>
        </header>

        <?php
          $maxGain = max(array_column($chartData, 'gains'));
          if ($maxGain == 0) $maxGain = 1; // avoid div by 0
        ?>
        <div class="wt-dash-v2__chart" data-chart-bar>
          <?php foreach ($chartData as $i => $d):
            $pct = ($d['gains'] / $maxGain) * 100;
            $isToday = $d['date'] === date('Y-m-d');
          ?>
            <div class="wt-dash-v2__chart-col<?= $isToday ? ' is-today' : '' ?>"
                 title="<?= e($d['date']) ?> : <?= e(number_format($d['gains'], 2)) ?> coins"
                 style="--idx:<?= $i ?>">
              <div class="wt-dash-v2__chart-bar-wrap">
                <div class="wt-dash-v2__chart-bar"
                     style="height:<?= max(2, $pct) ?>%"
                     data-value="<?= e(number_format($d['gains'], 2)) ?>"></div>
              </div>
              <small class="wt-dash-v2__chart-label"><?= e($d['label']) ?></small>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <!-- ============ PARRAINAGE ============ -->
      <section class="wt-dash-v2__referral-card" data-reveal>
        <div class="wt-dash-v2__referral-icon" aria-hidden="true">🎁</div>
        <div class="wt-dash-v2__referral-body">
          <h2 class="wt-dash-v2__section-title"><?= e(t('dash.referral')) ?></h2>
          <p class="wt-muted"><?= e(t('dash.referral.intro')) ?></p>
          <div class="wt-dash-v2__referral-link">
            <input class="wt-input" type="text" readonly value="<?= e($refUrl) ?>" id="wt-ref-url">
            <button class="wt-btn wt-btn--primary wt-btn--xs"
                    type="button"
                    data-copy-target="#wt-ref-url"
                    data-copy-label="<?= e(t('admin.cron.copied')) ?>">
              📋 <?= e(t('dash.copy')) ?>
            </button>
          </div>
        </div>
      </section>

      <!-- ============ ACTIVITÉ RÉCENTE ============ -->
      <section class="wt-dash-v2__history" data-reveal>
        <h2 class="wt-dash-v2__section-title"><?= e(t('dash.history')) ?></h2>

        <?php if (!$history): ?>
          <div class="wt-dash-v2__empty">
            <span class="wt-dash-v2__empty-icon" aria-hidden="true">📭</span>
            <p><?= e(t('dash.no_tx')) ?></p>
            <a class="wt-btn wt-btn--primary wt-btn--xs" href="<?= $base ?>/tasks/">
              🎯 <?= e(t('dash.action.tasks')) ?>
            </a>
          </div>
        <?php else: ?>
          <ul class="wt-dash-v2__tx-list">
            <?php foreach ($history as $i => $tx):
              $meta  = $txMeta[$tx['type']] ?? ['icon' => '💼', 'color' => 'gray'];
              $coins = (float) $tx['coins'];
              $isNeg = $coins < 0;
              $typeKey = 'tx.type.' . $tx['type'];
              // t() retourne la clé si manquante, pas false → vrai fallback via isset()
              $typeLabel = isset($GLOBALS['WT_LANG'][$typeKey])
                  ? t($typeKey)
                  : ucfirst((string) $tx['type']);
            ?>
              <li class="wt-dash-v2__tx wt-dash-v2__tx--<?= e($meta['color']) ?>"
                  style="--idx:<?= (int)$i ?>">
                <span class="wt-dash-v2__tx-icon" aria-hidden="true"><?= $meta['icon'] ?></span>
                <div class="wt-dash-v2__tx-info">
                  <strong><?= e($typeLabel) ?></strong>
                  <small>
                    <span data-fmt-time data-utc="<?= e($tx['created_at']) ?>" data-format="relative">
                      <?= e(wt_format_datetime($tx['created_at'])) ?>
                    </span>
                  </small>
                </div>
                <div class="wt-dash-v2__tx-amount <?= $isNeg ? 'is-neg' : 'is-pos' ?>">
                  <strong>
                    <?= ($isNeg ? '' : '+') . e(rtrim(rtrim(number_format($coins, 4, '.', ''), '0'), '.')) ?>
                  </strong>
                  <small><?= e(t('common.coins')) ?></small>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

    </section>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
