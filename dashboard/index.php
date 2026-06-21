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

      <?php $_ad = wt_ad_zone('dashboard_top'); if ($_ad !== ''): ?>
        <div class="wt-ad-zone wt-ad-zone--top" style="margin-bottom:1.5rem;text-align:center"><?= $_ad ?></div>
      <?php endif; ?>

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

      <?php
      /* ============ BONUS QUOTIDIEN ============
         Widget de réclamation du bonus journalier. Affiché seulement si
         le système est activé en admin. Tout l'état (peut réclamer ?
         streak, prochain palier) vient de wt_daily_state(). */
      $dailyState = wt_daily_state($u);
      if ($dailyState['enabled']):
        $dts = $dailyState['tiers'];
        $curStreak = $dailyState['current_streak'];
      ?>
      <section class="wt-daily" data-reveal data-daily-window="<?= (int)$dailyState['window_hours'] ?>">
        <div class="wt-daily__head">
          <div>
            <h2 class="wt-daily__title">🎁 <?= e(t('daily.title')) ?></h2>
            <p class="wt-daily__sub">
              <?php if ($curStreak > 0): ?>
                <?= e(sprintf((string) t('daily.streak_active'), $curStreak)) ?>
              <?php else: ?>
                <?= e(t('daily.streak_start')) ?>
              <?php endif; ?>
            </p>
          </div>
          <div class="wt-daily__flame <?= $curStreak > 0 ? 'is-active' : '' ?>">
            <span class="wt-daily__flame-icon">🔥</span>
            <span class="wt-daily__flame-count"><?= (int)$curStreak ?></span>
          </div>
        </div>

        <!-- Frise des paliers du cycle -->
        <div class="wt-daily__track">
          <?php foreach ($dts as $tier):
            $d = $tier['streak_day'];
            // État visuel : passé (coché), aujourd'hui (à réclamer), futur
            $isNext = ($d === $dailyState['next_day'] && $dailyState['can_claim']);
            $isPast = ($d < $dailyState['next_day'] && !$dailyState['streak_broken'] && $curStreak >= $d);
            $cls = $isNext ? 'is-next' : ($isPast ? 'is-done' : 'is-future');
          ?>
            <div class="wt-daily__day <?= $cls ?> <?= $tier['is_jackpot'] ? 'is-jackpot' : '' ?>">
              <div class="wt-daily__day-num"><?= e(t('daily.day_short')) ?><?= $d ?></div>
              <div class="wt-daily__day-reward">
                <?php if ($tier['is_jackpot']): ?>🏆<?php endif; ?>
                <?= e(wt_format_coins((float)$tier['coins'])) ?>
              </div>
              <?php if ($isPast): ?><div class="wt-daily__day-check">✓</div><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>

        <!-- Bouton de réclamation / cooldown -->
        <div class="wt-daily__action">
          <?php if ($dailyState['can_claim']): ?>
            <button type="button" class="wt-btn wt-btn--primary wt-daily__claim"
                    data-daily-claim
                    data-csrf="<?= e(csrf_token()) ?>">
              <?= e(sprintf((string) t('daily.claim_btn'), wt_format_coins((float)$dailyState['next_tier']['coins']))) ?>
            </button>
          <?php else: ?>
            <button type="button" class="wt-btn wt-btn--ghost wt-daily__claim" disabled
                    data-daily-cooldown="<?= (int)$dailyState['seconds_left'] ?>">
              ⏳ <span data-daily-timer><?= e(t('daily.come_back')) ?></span>
            </button>
          <?php endif; ?>
        </div>
      </section>
      <?php endif; ?>

      <?php
      /* ============ SUCCÈS (résumé) ============
         Aperçu compact : compteur débloqués + prochains objectifs proches.
         Le détail complet est sur /achievements.php. */
      $achSummary = function_exists('wt_ach_summary') ? wt_ach_summary((int)$u['id'], $u) : ['enabled'=>false];
      if (!empty($achSummary['enabled'])):
      ?>
      <section class="wt-ach-summary" data-reveal>
        <div class="wt-ach-summary__head">
          <div>
            <h2 class="wt-ach-summary__title">🏆 <?= e(t('ach.dash_title')) ?></h2>
            <p class="wt-ach-summary__count">
              <?= e(sprintf((string) t('ach.dash_progress'), $achSummary['unlocked'], $achSummary['total'])) ?>
            </p>
          </div>
          <a href="<?= e(wt_url('/achievements.php')) ?>" class="wt-btn wt-btn--ghost wt-btn--sm">
            <?= e(t('ach.dash_see_all')) ?> →
          </a>
        </div>

        <?php if (!empty($achSummary['recent'])): ?>
          <!-- Badges récemment débloqués -->
          <div class="wt-ach-summary__recent">
            <?php foreach ($achSummary['recent'] as $item):
              $a = $item['ach'];
            ?>
              <div class="wt-ach-summary__badge wt-ach-summary__badge--<?= e($a['tier']) ?>"
                   title="<?= e($a['title']) ?>">
                <?= e($a['icon'] ?: '🏅') ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($achSummary['next'])): ?>
          <!-- Prochains objectifs -->
          <div class="wt-ach-summary__next">
            <span class="wt-ach-summary__next-label"><?= e(t('ach.dash_next')) ?></span>
            <?php foreach ($achSummary['next'] as $item):
              $a = $item['ach'];
            ?>
              <div class="wt-ach-summary__goal">
                <span class="wt-ach-summary__goal-icon"><?= e($a['icon'] ?: '🏅') ?></span>
                <div class="wt-ach-summary__goal-info">
                  <span class="wt-ach-summary__goal-title"><?= e($a['title']) ?></span>
                  <div class="wt-ach-summary__goal-bar">
                    <div class="wt-ach-summary__goal-fill" style="width:<?= (int)$item['percent'] ?>%"></div>
                  </div>
                </div>
                <span class="wt-ach-summary__goal-pct"><?= (int)$item['percent'] ?>%</span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
      <?php endif; ?>

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

<script>
/* ════════════════════════════════════════════════════════════════════
   BONUS QUOTIDIEN — réclamation AJAX + compte à rebours
   ════════════════════════════════════════════════════════════════════ */
(function () {
  const section = document.querySelector('.wt-daily');
  if (!section) return;

  /* --- 1) Réclamation au clic --- */
  const claimBtn = section.querySelector('[data-daily-claim]');
  if (claimBtn) {
    claimBtn.addEventListener('click', async function () {
      claimBtn.disabled = true;
      claimBtn.classList.add('is-loading');

      try {
        const body = new URLSearchParams();
        body.set('_csrf', claimBtn.getAttribute('data-csrf') || '');

        const res = await fetch('<?= e(rtrim($GLOBALS['WT_CONFIG']['base_url'] ?? '', '/')) ?>/api/daily_claim.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
          body: body.toString(),
        });
        const data = await res.json();

        if (data.ok) {
          // Animation de succès : confettis légers + mise à jour visuelle
          wtDailyCelebrate(data);
          // Recharge après un court instant pour rafraîchir l'état complet
          setTimeout(function () { window.location.reload(); }, 1800);
        } else {
          claimBtn.disabled = false;
          claimBtn.classList.remove('is-loading');
          if (data.message) wtDailyToast(data.message, 'error');
        }
      } catch (e) {
        claimBtn.disabled = false;
        claimBtn.classList.remove('is-loading');
        wtDailyToast('<?= e(t('common.error')) ?>', 'error');
      }
    });
  }

  /* --- 2) Compte à rebours si en cooldown --- */
  const cooldownBtn = section.querySelector('[data-daily-cooldown]');
  if (cooldownBtn) {
    let secs = parseInt(cooldownBtn.getAttribute('data-daily-cooldown'), 10) || 0;
    const timerEl = cooldownBtn.querySelector('[data-daily-timer]');

    const tick = function () {
      if (secs <= 0) {
        // Cooldown fini → recharge pour afficher le bouton de claim
        window.location.reload();
        return;
      }
      const h = Math.floor(secs / 3600);
      const m = Math.floor((secs % 3600) / 60);
      const s = secs % 60;
      if (timerEl) {
        timerEl.textContent = (h > 0 ? h + 'h ' : '') +
                              String(m).padStart(2, '0') + 'm ' +
                              String(s).padStart(2, '0') + 's';
      }
      secs--;
    };
    tick();
    setInterval(tick, 1000);
  }

  /* --- Helpers visuels --- */
  function wtDailyCelebrate(data) {
    if (claimBtn) {
      claimBtn.classList.remove('is-loading');
      claimBtn.innerHTML = '✅ +' + data.coins + ' <?= e(t("common.coins")) ?>';
      claimBtn.classList.add('is-success');
    }
    // Confettis simples (emoji qui tombent)
    const emojis = data.jackpot ? ['🏆','🎉','💰','⭐','🔥'] : ['🎉','💰','✨'];
    for (let i = 0; i < (data.jackpot ? 30 : 15); i++) {
      const c = document.createElement('div');
      c.textContent = emojis[Math.floor(Math.random() * emojis.length)];
      c.style.cssText = 'position:fixed;top:-30px;left:' + (Math.random()*100) + 'vw;' +
        'font-size:' + (1 + Math.random()*1.5) + 'rem;z-index:9999;pointer-events:none;' +
        'animation:wtDailyFall ' + (1.5 + Math.random()*1.5) + 's linear forwards;';
      document.body.appendChild(c);
      setTimeout(function () { c.remove(); }, 3200);
    }
  }

  function wtDailyToast(msg, type) {
    const t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;bottom:2rem;left:50%;transform:translateX(-50%);' +
      'background:' + (type === 'error' ? '#ef4444' : '#22c55e') + ';color:#fff;' +
      'padding:.75rem 1.25rem;border-radius:12px;z-index:9999;font-weight:600;' +
      'box-shadow:0 8px 24px rgba(0,0,0,.3);animation:wtDailyToastIn .3s ease';
    document.body.appendChild(t);
    setTimeout(function () { t.style.opacity = '0'; t.style.transition = 'opacity .4s'; }, 3000);
    setTimeout(function () { t.remove(); }, 3500);
  }
})();
</script>
<style>
@keyframes wtDailyFall {
  to { transform: translateY(105vh) rotate(360deg); opacity: .8; }
}
@keyframes wtDailyToastIn {
  from { opacity: 0; transform: translate(-50%, 20px); }
  to   { opacity: 1; transform: translate(-50%, 0); }
}
</style>

<?php include __DIR__ . '/../footer.php'; ?>
