<?php
/**
 * Wintaskly — /tasks/index.php  (V8 — Hub modernisé)
 *
 * Hub central des tâches rémunératrices. Versions :
 *  - visiteur anonyme : hero + CTA signup, 4 cards d'overview, tips
 *  - utilisateur connecté : récap personnel (solde + gains du jour),
 *    bloc "Best Action Now" (prochaine action recommandée),
 *    4 cards riches avec état dynamique (countdown faucet live),
 *    stratégie gagnante, mini-stats des gains du jour par module.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

$pageTitle = t('tasks.hub_title');
$u  = current_user();
$db = db();

/* === Config des récompenses (lue depuis la table config) ============ */
$faucetCoins = (float) cfg('faucet_reward_coins', '25.0000');
$faucetXp    = (int)   cfg('faucet_reward_xp',    '10');
$faucetCD    = (int)   cfg('faucet_cooldown_seconds', '10800');

/* === Compteurs publics (visibles à tous) ============================ */
$shortlinksCount = (int)(db_one("SELECT COUNT(*) c FROM shortlinks WHERE active=1")['c'] ?? 0);
$ptcCount        = (int)(db_one("SELECT COUNT(*) c FROM ptc_ads    WHERE active=1")['c'] ?? 0);
$offerwallsCount = (int)(db_one("SELECT COUNT(*) c FROM offerwalls WHERE active=1")['c'] ?? 0);

/* Récompenses moyennes (calculées depuis la DB pour des chiffres crédibles) */
$slAvg  = (float) (db_one("SELECT COALESCE(AVG(reward_coins), 5) a FROM shortlinks WHERE active=1")['a'] ?? 5);
$ptcAvg = (float) (db_one("SELECT COALESCE(AVG(reward_coins), 2) a FROM ptc_ads    WHERE active=1")['a'] ?? 2);

/* === État dynamique pour l'utilisateur connecté ==================== */
$faucetReadyAt   = null;
$faucetReady     = false;
$todayEarnings   = ['faucet' => 0.0, 'shortlink' => 0.0, 'ptc' => 0.0, 'offerwall' => 0.0];
$todayTotal      = 0.0;
$todayXp         = 0;
$todayTaskCount  = 0;

if ($u) {
    /* Prochain claim faucet possible */
    $stmt = $db->prepare("SELECT MAX(next_claim_at) n FROM faucet_claims WHERE user_id = ?");
    $stmt->bind_param('i', $u['id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $faucetReadyAt = $row['n'] ?? null;
    $faucetReady   = !$faucetReadyAt || strtotime($faucetReadyAt . ' UTC') <= time();

    /* Gains du jour ventilés par type */
    $stmt = $db->prepare(
        "SELECT type, COALESCE(SUM(coins),0) c, COALESCE(SUM(xp),0) x, COUNT(*) n
           FROM transactions
          WHERE user_id = ?
            AND coins > 0
            AND DATE(created_at) = UTC_DATE()
          GROUP BY type"
    );
    $stmt->bind_param('i', $u['id']);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $t = (string) $r['type'];
        if (isset($todayEarnings[$t])) $todayEarnings[$t] = (float) $r['c'];
        $todayTotal     += (float) $r['c'];
        $todayXp        += (int)   $r['x'];
        $todayTaskCount += (int)   $r['n'];
    }
    $stmt->close();
}

/* === Détermine la "Best Action Now" pour l'utilisateur ==============
 * Heuristique simple :
 *   1) Si faucet prêt → faucet (gain immédiat, simple)
 *   2) Sinon si offerwalls > 0 → offerwalls (gros gains)
 *   3) Sinon si shortlinks > 0 → shortlinks
 *   4) Sinon ptc
 */
$bestAction = null;
if ($u) {
    if ($faucetReady) {
        $bestAction = [
            'k'     => 'faucet',
            'href'  => wt_url('/tasks/faucet/'),
            'title' => t('tasks.best.faucet_title'),
            'body'  => sprintf((string) t('tasks.best.faucet_body'),
                       rtrim(rtrim(number_format($faucetCoins, 2, '.', ''), '0'), '.')),
            'cta'   => t('tasks.best.faucet_cta'),
            'icon'  => '💧',
        ];
    } elseif ($offerwallsCount > 0) {
        $bestAction = [
            'k'     => 'offerwall',
            'href'  => wt_url('/tasks/offerwalls/'),
            'title' => t('tasks.best.offerwalls_title'),
            'body'  => sprintf((string) t('tasks.best.offerwalls_body'), $offerwallsCount),
            'cta'   => t('tasks.best.offerwalls_cta'),
            'icon'  => '🎯',
        ];
    } elseif ($shortlinksCount > 0) {
        $bestAction = [
            'k'     => 'shortlink',
            'href'  => wt_url('/tasks/shortlinks/'),
            'title' => t('tasks.best.shortlinks_title'),
            'body'  => sprintf((string) t('tasks.best.shortlinks_body'),
                       $shortlinksCount,
                       rtrim(rtrim(number_format($slAvg, 2, '.', ''), '0'), '.')),
            'cta'   => t('tasks.best.shortlinks_cta'),
            'icon'  => '🔗',
        ];
    } elseif ($ptcCount > 0) {
        $bestAction = [
            'k'     => 'ptc',
            'href'  => wt_url('/tasks/ptc/'),
            'title' => t('tasks.best.ptc_title'),
            'body'  => sprintf((string) t('tasks.best.ptc_body'), $ptcCount),
            'cta'   => t('tasks.best.ptc_cta'),
            'icon'  => '📺',
        ];
    }
}

/* === Helper format compact (réutilisé sur toute la page) ============ */
$fmt = static function (float $n, int $dec = 2): string {
    return rtrim(rtrim(number_format($n, $dec, '.', ' '), '0'), '.');
};

/* === Bingo : visibilité (jouable / teaser "bientôt") =============== */
$bingoPlayable = function_exists('wt_bingo_visible_for') ? wt_bingo_visible_for($u) : false;
$bingoTeaser   = function_exists('wt_bingo_show_teaser')  ? wt_bingo_show_teaser($u)  : false;
$bingoLaunchTs = function_exists('wt_bingo_launch_ts')    ? wt_bingo_launch_ts()       : 0;
$bingoTestMode = function_exists('wt_bingo_is_test_mode') ? wt_bingo_is_test_mode()    : false;

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-tasks-v2">

  <?php $_ad = wt_ad_zone('tasks_index_top'); if ($_ad !== ''): ?>
    <div class="wt-ad-zone wt-ad-zone--top" style="margin-bottom:1.5rem;text-align:center"><?= $_ad ?></div>
  <?php endif; ?>

  <!-- =============== HEADER + RÉCAP UTILISATEUR =============== -->
  <header class="wt-tasks-v2__header" data-reveal>
    <div class="wt-tasks-v2__intro">
      <span class="wt-eyebrow">💎 <?= e(t('tasks.hub_eyebrow')) ?></span>
      <h1 class="wt-tasks-v2__title"><?= e(t('tasks.hub_title')) ?></h1>
      <p class="wt-tasks-v2__lead"><?= e(t('tasks.hub_lead')) ?></p>

      <?php if (!$u): ?>
        <div class="wt-tasks-v2__cta">
          <a class="wt-btn wt-btn--primary wt-btn--lg" href="<?= e(wt_url('/auth/signup.php')) ?>">
            <?= e(t('common.create_account')) ?>
          </a>
          <a class="wt-btn wt-btn--ghost" href="<?= e(wt_url('/auth/login.php')) ?>">
            <?= e(t('common.login')) ?>
          </a>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($u): ?>
      <!-- Récap personnel : solde / gains du jour / xp / tâches -->
      <aside class="wt-tasks-v2__recap" aria-label="<?= e(t('tasks.recap_title')) ?>">
        <div class="wt-tasks-v2__recap-item wt-tasks-v2__recap-item--main">
          <small><?= e(t('tasks.balance')) ?></small>
          <strong>
            <?= e($fmt((float)$u['coins'])) ?>
            <span class="wt-tasks-v2__recap-unit"><?= e(t('common.coins')) ?></span>
          </strong>
        </div>
        <div class="wt-tasks-v2__recap-grid">
          <div class="wt-tasks-v2__recap-item">
            <small><?= e(t('tasks.today_coins')) ?></small>
            <strong>+<?= e($fmt($todayTotal)) ?></strong>
          </div>
          <div class="wt-tasks-v2__recap-item">
            <small><?= e(t('tasks.today_xp')) ?></small>
            <strong>+<?= (int)$todayXp ?> XP</strong>
          </div>
          <div class="wt-tasks-v2__recap-item">
            <small><?= e(t('tasks.today_tasks')) ?></small>
            <strong><?= (int)$todayTaskCount ?></strong>
          </div>
        </div>
      </aside>
    <?php endif; ?>
  </header>

  <!-- =============== BEST ACTION NOW (utilisateur connecté) =============== -->
  <?php if ($u && $bestAction): ?>
    <section class="wt-bestaction wt-bestaction--<?= e($bestAction['k']) ?>" data-reveal>
      <div class="wt-bestaction__halo" aria-hidden="true"></div>
      <div class="wt-bestaction__icon" aria-hidden="true"><?= e($bestAction['icon']) ?></div>
      <div class="wt-bestaction__content">
        <span class="wt-bestaction__eyebrow">
          <span class="wt-bestaction__pulse" aria-hidden="true"></span>
          <?= e(t('tasks.best.eyebrow')) ?>
        </span>
        <h2 class="wt-bestaction__title"><?= e($bestAction['title']) ?></h2>
        <p class="wt-bestaction__body"><?= e($bestAction['body']) ?></p>
      </div>
      <a class="wt-btn wt-btn--primary wt-btn--lg wt-bestaction__cta" href="<?= e($bestAction['href']) ?>">
        <?= e($bestAction['cta']) ?> →
      </a>
    </section>
  <?php endif; ?>

  <!-- =============== LES 4 TÂCHES (grille riche) =============== -->
  <section class="wt-tasks-v2__grid" data-reveal>

    <!-- ============ FAUCET ============ -->
    <article class="wt-task-card wt-task-card--faucet wt-task-card--rich"
             style="--idx:0">
      <header class="wt-task-card__header">
        <div class="wt-task-card__icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 2v6M12 8a4 4 0 0 1 4 4v3H8v-3a4 4 0 0 1 4-4zM6 15h12v3a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2v-3zM10 22h4"/>
          </svg>
        </div>
        <h2 class="wt-task-card__title"><?= e(t('nav.faucet')) ?></h2>
        <?php if ($u && $faucetReady): ?>
          <span class="wt-task-card__pill wt-task-card__pill--ready"><?= e(t('tasks.ready_now')) ?></span>
        <?php endif; ?>
      </header>

      <p class="wt-task-card__desc"><?= e(t('tasks.faucet_desc')) ?></p>

      <div class="wt-task-card__price">
        <span class="wt-task-card__price-amount">+<?= e($fmt($faucetCoins)) ?></span>
        <span class="wt-task-card__price-unit"><?= e(t('common.coins')) ?></span>
        <span class="wt-task-card__price-bonus">+ <?= (int)$faucetXp ?> XP</span>
      </div>

      <ul class="wt-task-card__meta">
        <li>
          <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          <?= sprintf((string)t('tasks.every'), (int)($faucetCD / 3600) . 'h') ?>
        </li>
        <?php if ($u && !$faucetReady && $faucetReadyAt): ?>
          <li class="wt-task-card__meta--countdown">
            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
            <?= e(t('tasks.ready_in')) ?>
            <strong data-countdown
                    data-target="<?= e($faucetReadyAt) ?>"
                    data-label-ready="<?= e(t('tasks.ready_now')) ?>">
              …
            </strong>
          </li>
        <?php endif; ?>
      </ul>

      <?php if ($u && $faucetReady): ?>
        <a class="wt-btn wt-btn--primary wt-task-card__cta" href="<?= e(wt_url('/tasks/faucet/')) ?>">
          <?= e(t('tasks.claim_now')) ?>
        </a>
      <?php elseif ($u): ?>
        <a class="wt-btn wt-btn--ghost wt-task-card__cta" href="<?= e(wt_url('/tasks/faucet/')) ?>">
          <?= e(t('common.view')) ?>
        </a>
      <?php else: ?>
        <a class="wt-btn wt-btn--ghost wt-task-card__cta" href="<?= e(wt_url('/auth/signup.php')) ?>">
          <?= e(t('common.start')) ?>
        </a>
      <?php endif; ?>
    </article>

    <!-- ============ SHORTLINKS ============ -->
    <article class="wt-task-card wt-task-card--shortlinks wt-task-card--rich"
             style="--idx:1">
      <header class="wt-task-card__header">
        <div class="wt-task-card__icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
          </svg>
        </div>
        <h2 class="wt-task-card__title"><?= e(t('nav.shortlinks')) ?></h2>
        <?php if ($shortlinksCount > 0): ?>
          <span class="wt-task-card__pill"><?= (int)$shortlinksCount ?> <?= e(t('tasks.active')) ?></span>
        <?php endif; ?>
      </header>

      <p class="wt-task-card__desc"><?= e(t('tasks.shortlinks_desc')) ?></p>

      <div class="wt-task-card__price">
        <span class="wt-task-card__price-amount">+<?= e($fmt($slAvg)) ?></span>
        <span class="wt-task-card__price-unit"><?= e(t('common.coins')) ?></span>
        <span class="wt-task-card__price-bonus"><?= e(t('home.tasks.per_link')) ?></span>
      </div>

      <ul class="wt-task-card__meta">
        <li>
          <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          ≈ 15 s <?= e(t('tasks.per_link')) ?>
        </li>
        <li>
          <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          <?= e(t('tasks.no_limit')) ?>
        </li>
      </ul>

      <?php if ($u): ?>
        <a class="wt-btn wt-btn--primary wt-task-card__cta" href="<?= e(wt_url('/tasks/shortlinks/')) ?>">
          <?= e(t('tasks.see_links')) ?>
        </a>
      <?php else: ?>
        <a class="wt-btn wt-btn--ghost wt-task-card__cta" href="<?= e(wt_url('/auth/signup.php')) ?>">
          <?= e(t('common.start')) ?>
        </a>
      <?php endif; ?>
    </article>

    <!-- ============ PTC ============ -->
    <article class="wt-task-card wt-task-card--ptc wt-task-card--rich"
             style="--idx:2">
      <header class="wt-task-card__header">
        <div class="wt-task-card__icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="2" y="4" width="20" height="15" rx="2"/>
            <polygon points="10 9 15 11.5 10 14" fill="currentColor"/>
          </svg>
        </div>
        <h2 class="wt-task-card__title"><?= e(t('nav.ptc')) ?></h2>
        <?php if ($ptcCount > 0): ?>
          <span class="wt-task-card__pill"><?= (int)$ptcCount ?> <?= e(t('tasks.active')) ?></span>
        <?php endif; ?>
      </header>

      <p class="wt-task-card__desc"><?= e(t('tasks.ptc_desc')) ?></p>

      <div class="wt-task-card__price">
        <span class="wt-task-card__price-amount">+<?= e($fmt($ptcAvg)) ?></span>
        <span class="wt-task-card__price-unit"><?= e(t('common.coins')) ?></span>
        <span class="wt-task-card__price-bonus"><?= e(t('home.tasks.per_ad')) ?></span>
      </div>

      <ul class="wt-task-card__meta">
        <li>
          <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          15-30 s <?= e(t('tasks.per_ad')) ?>
        </li>
        <li>
          <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
          <?= e(t('tasks.auto_validated')) ?>
        </li>
      </ul>

      <?php if ($u): ?>
        <a class="wt-btn wt-btn--primary wt-task-card__cta" href="<?= e(wt_url('/tasks/ptc/')) ?>">
          <?= e(t('tasks.see_ads')) ?>
        </a>
      <?php else: ?>
        <a class="wt-btn wt-btn--ghost wt-task-card__cta" href="<?= e(wt_url('/auth/signup.php')) ?>">
          <?= e(t('common.start')) ?>
        </a>
      <?php endif; ?>
    </article>

    <!-- ============ OFFERWALLS ============ -->
    <article class="wt-task-card wt-task-card--offerwalls wt-task-card--rich"
             style="--idx:3">
      <header class="wt-task-card__header">
        <div class="wt-task-card__icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 2 2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
          </svg>
        </div>
        <h2 class="wt-task-card__title"><?= e(t('nav.offerwalls')) ?></h2>
        <?php if ($offerwallsCount > 0): ?>
          <span class="wt-task-card__pill wt-task-card__pill--gold"><?= e(t('tasks.high_payout')) ?></span>
        <?php endif; ?>
      </header>

      <p class="wt-task-card__desc"><?= e(t('tasks.offerwalls_desc')) ?></p>

      <div class="wt-task-card__price">
        <span class="wt-task-card__price-amount">1-50</span>
        <span class="wt-task-card__price-unit"><?= e(t('common.coins')) ?></span>
        <span class="wt-task-card__price-bonus"><?= e(t('home.tasks.per_offer')) ?></span>
      </div>

      <ul class="wt-task-card__meta">
        <li>
          <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/></svg>
          <?= (int)$offerwallsCount ?> <?= e(t('tasks.partners_active')) ?>
        </li>
        <li>
          <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1l3 6 6 1-4 5 1 7-6-3-6 3 1-7-4-5 6-1z"/></svg>
          <?= e(t('tasks.best_payouts')) ?>
        </li>
      </ul>

      <?php if ($u): ?>
        <a class="wt-btn wt-btn--primary wt-task-card__cta" href="<?= e(wt_url('/tasks/offerwalls/')) ?>">
          <?= e(t('tasks.see_offerwalls')) ?>
        </a>
      <?php else: ?>
        <a class="wt-btn wt-btn--ghost wt-task-card__cta" href="<?= e(wt_url('/auth/signup.php')) ?>">
          <?= e(t('common.start')) ?>
        </a>
      <?php endif; ?>
    </article>

    <?php /* ============ BINGO ============ */ ?>
    <?php if ($bingoPlayable): ?>
      <!-- Bingo jouable (admin en test, ou lancé publiquement) -->
      <article class="wt-task-card wt-task-card--bingo wt-task-card--rich" style="--idx:4">
        <header class="wt-task-card__header">
          <div class="wt-task-card__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="3" width="18" height="18" rx="2"/>
              <path d="M3 9h18M3 15h18M9 3v18M15 3v18"/>
            </svg>
          </div>
          <h2 class="wt-task-card__title">Bingo</h2>
          <?php if ($bingoTestMode): ?>
            <span class="wt-task-card__pill" style="background:#a855f7;color:#fff">TEST</span>
          <?php endif; ?>
        </header>

        <p class="wt-task-card__desc"><?= e(t('bingo.tasks_desc')) ?></p>

        <div class="wt-task-card__price">
          <span class="wt-task-card__price-amount">🎰 <?= e(t('bingo.tasks_jackpot')) ?></span>
        </div>

        <ul class="wt-task-card__meta">
          <li><?= e(t('bingo.tasks_meta_free')) ?></li>
          <li><?= e(t('bingo.tasks_meta_daily')) ?></li>
        </ul>

        <a class="wt-task-card__cta" href="<?= e(wt_url('/tasks/bingo/')) ?>">
          <?= e(t('common.start')) ?>
        </a>
      </article>
    <?php elseif ($bingoTeaser): ?>
      <!-- Teaser "bientôt disponible" avec compte à rebours -->
      <article class="wt-task-card wt-task-card--bingo wt-task-card--soon" style="--idx:4">
        <header class="wt-task-card__header">
          <div class="wt-task-card__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="3" width="18" height="18" rx="2"/>
              <path d="M3 9h18M3 15h18M9 3v18M15 3v18"/>
            </svg>
          </div>
          <h2 class="wt-task-card__title">Bingo</h2>
          <span class="wt-task-card__pill wt-task-card__pill--soon"><?= e(t('bingo.coming_soon')) ?></span>
        </header>

        <p class="wt-task-card__desc"><?= e(t('bingo.teaser_desc')) ?></p>

        <?php if ($bingoLaunchTs > 0): ?>
          <div class="wt-bingo-countdown" data-launch="<?= (int)$bingoLaunchTs ?>" aria-label="<?= e(t('bingo.countdown_label')) ?>">
            <div class="wt-bingo-countdown__unit">
              <span class="wt-bingo-countdown__num" data-cd="days">--</span>
              <span class="wt-bingo-countdown__lbl"><?= e(t('bingo.cd_days')) ?></span>
            </div>
            <div class="wt-bingo-countdown__unit">
              <span class="wt-bingo-countdown__num" data-cd="hours">--</span>
              <span class="wt-bingo-countdown__lbl"><?= e(t('bingo.cd_hours')) ?></span>
            </div>
            <div class="wt-bingo-countdown__unit">
              <span class="wt-bingo-countdown__num" data-cd="mins">--</span>
              <span class="wt-bingo-countdown__lbl"><?= e(t('bingo.cd_mins')) ?></span>
            </div>
            <div class="wt-bingo-countdown__unit">
              <span class="wt-bingo-countdown__num" data-cd="secs">--</span>
              <span class="wt-bingo-countdown__lbl"><?= e(t('bingo.cd_secs')) ?></span>
            </div>
          </div>
        <?php endif; ?>

        <span class="wt-task-card__cta wt-task-card__cta--disabled" aria-disabled="true">
          <?= e(t('bingo.coming_soon')) ?>
        </span>
      </article>
    <?php endif; ?>

  </section>

  <!-- =============== TES GAINS DU JOUR (utilisateur connecté + données) =============== -->
  <?php if ($u && $todayTotal > 0): ?>
    <section class="wt-tasks-v2__daily" data-reveal>
      <header class="wt-tasks-v2__daily-head">
        <span class="wt-eyebrow">📊 <?= e(t('tasks.daily_eyebrow')) ?></span>
        <h2 class="wt-section__title"><?= e(sprintf((string)t('tasks.daily_title'), $fmt($todayTotal))) ?></h2>
        <p class="wt-section__lead"><?= e(t('tasks.daily_lead')) ?></p>
      </header>

      <?php
        /* Bar chart compact : pour chaque type avec gain > 0, on calcule
         * sa part en % du total et on rend une barre proportionnelle. */
        $typeLabels = [
            'faucet'    => t('nav.faucet'),
            'shortlink' => t('nav.shortlinks'),
            'ptc'       => t('nav.ptc'),
            'offerwall' => t('nav.offerwalls'),
        ];
      ?>
      <div class="wt-tasks-v2__daily-bars">
        <?php foreach ($todayEarnings as $type => $amount):
            if ($amount <= 0) continue;
            $pct = $todayTotal > 0 ? ($amount / $todayTotal) * 100 : 0;
        ?>
          <div class="wt-tasks-v2__daily-bar wt-task-card--<?= e($type) ?>">
            <div class="wt-tasks-v2__daily-bar-label">
              <span><?= e($typeLabels[$type] ?? $type) ?></span>
              <strong>+<?= e($fmt($amount)) ?></strong>
            </div>
            <div class="wt-tasks-v2__daily-bar-track">
              <div class="wt-tasks-v2__daily-bar-fill" style="--pct:<?= number_format($pct, 1, '.', '') ?>%"></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- =============== STRATÉGIE GAGNANTE (4 conseils illustrés) =============== -->
  <section class="wt-tasks-v2__tips" data-reveal>
    <header class="wt-tasks-v2__tips-head">
      <span class="wt-eyebrow">💡 <?= e(t('tasks.tips_eyebrow')) ?></span>
      <h2 class="wt-section__title"><?= e(t('tasks.tips_title')) ?></h2>
    </header>

    <div class="wt-tasks-v2__tips-grid">
      <?php
        $tips = [
            ['n' => '01', 'icon' => '⏰', 'text' => t('tasks.tip_1')],
            ['n' => '02', 'icon' => '🔁', 'text' => t('tasks.tip_2')],
            ['n' => '03', 'icon' => '🎯', 'text' => t('tasks.tip_3')],
            ['n' => '04', 'icon' => '🚀', 'text' => t('tasks.tip_4')],
        ];
        foreach ($tips as $i => $tip):
      ?>
        <article class="wt-tasks-v2__tip" style="--idx:<?= (int)$i ?>">
          <span class="wt-tasks-v2__tip-num"><?= e($tip['n']) ?></span>
          <span class="wt-tasks-v2__tip-icon" aria-hidden="true"><?= e($tip['icon']) ?></span>
          <p class="wt-tasks-v2__tip-text"><?= e($tip['text']) ?></p>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

</main>

<script>
(function () {
  var cd = document.querySelector('.wt-bingo-countdown');
  if (!cd) return;
  var launch = parseInt(cd.getAttribute('data-launch'), 10) * 1000;
  if (!launch) return;

  var elD = cd.querySelector('[data-cd="days"]');
  var elH = cd.querySelector('[data-cd="hours"]');
  var elM = cd.querySelector('[data-cd="mins"]');
  var elS = cd.querySelector('[data-cd="secs"]');

  function pad(n) { return n < 10 ? '0' + n : '' + n; }

  function tick() {
    var diff = launch - Date.now();
    if (diff <= 0) {
      // Lancement atteint : on recharge pour révéler le jeu
      elD.textContent = '00'; elH.textContent = '00';
      elM.textContent = '00'; elS.textContent = '00';
      clearInterval(timer);
      setTimeout(function () { window.location.reload(); }, 1500);
      return;
    }
    var s = Math.floor(diff / 1000);
    var d = Math.floor(s / 86400); s -= d * 86400;
    var h = Math.floor(s / 3600);  s -= h * 3600;
    var m = Math.floor(s / 60);    s -= m * 60;
    elD.textContent = pad(d);
    elH.textContent = pad(h);
    elM.textContent = pad(m);
    elS.textContent = pad(s);
  }

  tick();
  var timer = setInterval(tick, 1000);
})();
</script>

<?php include __DIR__ . '/../footer.php'; ?>
