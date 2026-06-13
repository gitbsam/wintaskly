<?php
/**
 * Wintaskly — /leaderboard  (V8 modernisé)
 *
 * Classement mensuel des Coins :
 *   - Bandeau cagnotte du mois (montant + countdown)
 *   - Podium 2-1-3 avec la part de chaque rang affichée
 *   - Liste élite 4-10 avec la part de chaque rang
 *   - Bloc "Toi" (utilisateur connecté) avec simulateur de gain
 *   - Sélecteur d'archives en pills
 *
 * Source des gains : transactions(type IN faucet/shortlink/ptc/
 * offerwall/referral/bonus) bornées au mois UTC courant.
 * Cache : table leaderboard_cache (15 min).
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

$pageTitle = t('lb.title');
$u         = current_user();
$db        = db();

/* Période (par défaut courante) */
$period = (string)($_GET['period'] ?? wt_lb_period());
if (!preg_match('/^\d{4}-\d{2}$/', $period)) $period = wt_lb_period();
$isCurrent = ($period === wt_lb_period());

if ($isCurrent) {
    $top = wt_lb_get_top();
} else {
    $stmt = $db->prepare(
        "SELECT h.`rank`, h.user_id, h.username, h.coins_month,
                h.reward_coins, h.reward_xp,
                u.avatar_url, u.level
           FROM leaderboard_history h
           LEFT JOIN users u ON u.id = h.user_id
          WHERE h.period_ym = ?
          ORDER BY h.`rank` ASC"
    );
    $stmt->bind_param('s', $period);
    $stmt->execute();
    $res = $stmt->get_result();
    $top = [];
    while ($r = $res->fetch_assoc()) {
        $top[] = [
            'rank'        => (int)   $r['rank'],
            'user_id'     => (int)   $r['user_id'],
            'username'    => (string)$r['username'],
            'avatar_url'  => $r['avatar_url'],
            'level'       => (int)($r['level'] ?? 1),
            'coins_month' => (float) $r['coins_month'],
            'reward_coins'=> (float) $r['reward_coins'],
            'reward_xp'   => (int)   $r['reward_xp'],
        ];
    }
    $stmt->close();
}

/* Grille de récompenses (active uniquement pour le mois courant) */
$rewardsGrid = wt_lb_rewards_grid();
$prizePool   = array_sum($rewardsGrid);
$usePool     = (string) cfg('leaderboard.use_prize_pool', '0') === '1';

/* Rang de l'utilisateur */
$myInfo = null;
$myInTop = false;
if ($u && $isCurrent) {
    $myInfo = wt_lb_user_rank((int)$u['id']);
    foreach ($top as $row) {
        if ($row['user_id'] === (int)$u['id']) { $myInTop = true; break; }
    }
}

/* Périodes archivées disponibles */
$archivedPeriods = [];
if ($res = $db->query("SELECT DISTINCT period_ym FROM leaderboard_history ORDER BY period_ym DESC LIMIT 12")) {
    while ($r = $res->fetch_assoc()) $archivedPeriods[] = $r['period_ym'];
    $res->free();
}

/* Top 3 / 4-10 */
$podium = [];
$elite  = [];
foreach ($top as $row) {
    if ($row['rank'] <= 3) $podium[$row['rank']] = $row;
    else                   $elite[] = $row;
}

/* Helper format compact */
$fmt = static function (float $n, int $dec = 2): string {
    return rtrim(rtrim(number_format($n, $dec, '.', ' '), '0'), '.');
};

/* Countdown vers la fin du mois (UTC) */
$nextStart = wt_lb_next_period_start()->getTimestamp();
$endIso    = gmdate('Y-m-d H:i:s', $nextStart);

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-lb-v2">
  <div class="wt-lb-v2__wrap">

    <!-- ====== HEADER ====== -->
    <header class="wt-lb-v2__header" data-reveal>
      <span class="wt-eyebrow">🏆 <?= e(t('lb.eyebrow')) ?></span>
      <h1 class="wt-lb-v2__title">
        <?= e(t('lb.title')) ?>
      </h1>
      <p class="wt-lb-v2__lead">
        <?= $isCurrent
              ? e(t('lb.lead_current'))
              : sprintf((string)t('lb.lead_archive'), e($period)) ?>
      </p>

      <?php if ($archivedPeriods): ?>
        <!-- Sélecteur d'archives en pills (plus joli qu'un <select>) -->
        <nav class="wt-lb-v2__periods" aria-label="<?= e(t('lb.period_label')) ?>">
          <a class="wt-lb-v2__period <?= $isCurrent ? 'is-active' : '' ?>"
             href="<?= e(wt_url('/leaderboard/')) ?>">
            <?= e(t('lb.current_month')) ?>
          </a>
          <?php foreach ($archivedPeriods as $p): ?>
            <a class="wt-lb-v2__period <?= $p === $period ? 'is-active' : '' ?>"
               href="<?= e(wt_url('/leaderboard/?period=' . urlencode($p))) ?>"><?= e($p) ?></a>
          <?php endforeach; ?>
        </nav>
      <?php endif; ?>
    </header>

    <!-- ====== BANDEAU CAGNOTTE (mois courant uniquement) ====== -->
    <?php if ($isCurrent && $prizePool > 0): ?>
      <section class="wt-lb-v2__prize" data-reveal>
        <div class="wt-lb-v2__prize-halo" aria-hidden="true"></div>

        <div class="wt-lb-v2__prize-main">
          <small class="wt-lb-v2__prize-label">
            💰 <?= e(t('lb.prize.title')) ?>
          </small>
          <strong class="wt-lb-v2__prize-amount">
            <?= e($fmt($prizePool)) ?>
            <span><?= e(t('common.coins')) ?></span>
          </strong>
          <small class="wt-lb-v2__prize-meta">
            <?= e(sprintf((string)t('lb.prize.split'), count(array_filter($rewardsGrid)))) ?>
          </small>
        </div>

        <div class="wt-lb-v2__prize-countdown">
          <small><?= e(t('lb.prize.ends_in')) ?></small>
          <div class="wt-lb-v2__prize-timer"
               data-countdown
               data-target="<?= e($endIso) ?>"
               data-label-ready="<?= e(t('lb.prize.ended')) ?>">
            …
          </div>
        </div>
      </section>

      <!-- Grille des parts par rang -->
      <details class="wt-lb-v2__grid-detail">
        <summary class="wt-lb-v2__grid-summary">
          <span><?= e(t('lb.prize.see_split')) ?></span>
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </summary>
        <div class="wt-lb-v2__grid-list">
          <?php foreach ($rewardsGrid as $rank => $coins):
                if ($coins <= 0) continue; ?>
            <div class="wt-lb-v2__grid-item">
              <span class="wt-lb-v2__grid-rank">#<?= $rank ?></span>
              <span class="wt-lb-v2__grid-coins">
                +<?= e($fmt($coins)) ?>
                <small><?= e(t('common.coins')) ?></small>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endif; ?>

    <!-- ====== PODIUM ====== -->
    <?php if (!empty($podium)): ?>
      <section class="wt-lb-v2__podium" data-reveal>
        <canvas class="wt-lb-v2__confetti" data-confetti aria-hidden="true"></canvas>

        <?php
          // Ordre visuel : 2-1-3 (gauche / centre / droite)
          $order = [2, 1, 3];
          foreach ($order as $rank):
              if (empty($podium[$rank])) continue;
              $p = $podium[$rank];
              $isMe = $u && $p['user_id'] === (int)$u['id'];
              $tier = $rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : 'bronze');
              // Récompense : pour mois courant on prend la grille (preview),
              // pour archive on prend le reward_coins stocké.
              $reward = $isCurrent
                      ? (float)($rewardsGrid[$rank] ?? 0)
                      : (float)($p['reward_coins'] ?? 0);
        ?>
          <article class="wt-lb-v2__step wt-lb-v2__step--<?= $tier ?> <?= $isMe ? 'is-me' : '' ?>">
            <?php if ($rank === 1): ?>
              <span class="wt-lb-v2__crown" aria-hidden="true">👑</span>
            <?php endif; ?>

            <div class="wt-lb-v2__step-medal" aria-hidden="true">
              <?= $rank === 1 ? '🥇' : ($rank === 2 ? '🥈' : '🥉') ?>
            </div>

            <div class="wt-avatar wt-avatar--lg wt-lb-v2__step-avatar"
                 data-hash-color="<?= e($p['username']) ?>"
                 aria-hidden="true"><?= wt_avatar_inner($p) ?></div>

            <h3 class="wt-lb-v2__step-name">
              <?= e($p['username']) ?>
              <?php if ($isMe): ?>
                <span class="wt-lb-v2__step-you"><?= e(t('lb.you')) ?></span>
              <?php endif; ?>
            </h3>

            <span class="wt-lb-v2__step-level">
              Lv <?= (int)$p['level'] ?>
            </span>

            <div class="wt-lb-v2__step-coins">
              <span class="wt-lb-v2__step-amount"><?= e($fmt((float)$p['coins_month'])) ?></span>
              <small><?= e(t('common.coins')) ?></small>
            </div>

            <?php if ($reward > 0): ?>
              <div class="wt-lb-v2__step-prize">
                🎁 +<?= e($fmt($reward)) ?>
                <small><?= e(t('common.coins')) ?></small>
              </div>
            <?php endif; ?>

            <div class="wt-lb-v2__step-base wt-lb-v2__step-base--<?= $tier ?>">
              <span>#<?= $rank ?></span>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>

    <!-- ====== LISTE ÉLITE (4-10) ====== -->
    <?php if (!empty($elite)): ?>
      <section class="wt-lb-v2__list" data-reveal>
        <h2 class="wt-section__title"><?= e(t('lb.elite_title')) ?></h2>
        <ul class="wt-lb-v2__rows">
          <?php foreach ($elite as $i => $row):
                $isMe = $u && $row['user_id'] === (int)$u['id'];
                $reward = $isCurrent
                        ? (float)($rewardsGrid[$row['rank']] ?? 0)
                        : (float)($row['reward_coins'] ?? 0);
          ?>
            <li class="wt-lb-v2__row <?= $isMe ? 'is-me' : '' ?>"
                style="--idx:<?= (int)$i ?>">
              <span class="wt-lb-v2__row-rank">#<?= (int)$row['rank'] ?></span>

              <div class="wt-avatar wt-avatar--sm"
                   data-hash-color="<?= e($row['username']) ?>"
                   aria-hidden="true"><?= wt_avatar_inner($row) ?></div>

              <div class="wt-lb-v2__row-user">
                <strong><?= e($row['username']) ?></strong>
                <?php if ($isMe): ?>
                  <span class="wt-lb-v2__row-you-pill"><?= e(t('lb.defend')) ?></span>
                <?php endif; ?>
                <small>Lv <?= (int)$row['level'] ?></small>
              </div>

              <span class="wt-lb-v2__row-coins">
                <?= e($fmt((float)$row['coins_month'])) ?>
                <small><?= e(t('common.coins')) ?></small>
              </span>

              <?php if ($reward > 0): ?>
                <span class="wt-lb-v2__row-prize" title="<?= e(t('lb.prize.this_rank')) ?>">
                  🎁 +<?= e($fmt($reward)) ?>
                </span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php elseif (empty($podium)): ?>
      <div class="wt-lb-v2__empty" data-reveal>
        <span class="wt-lb-v2__empty-icon" aria-hidden="true">🏁</span>
        <h2><?= e(t('lb.empty_title')) ?></h2>
        <p><?= e(t('lb.empty')) ?></p>
      </div>
    <?php endif; ?>

    <!-- ====== BLOC "VOUS" — hors Top 10 ====== -->
    <?php if ($u && $isCurrent && !$myInTop && $myInfo): ?>
      <section class="wt-lb-v2__you" data-reveal>
        <header class="wt-lb-v2__you-head">
          <div class="wt-avatar wt-avatar--lg"
               data-hash-color="<?= e($u['username']) ?>"
               aria-hidden="true"><?= wt_avatar_inner($u) ?></div>

          <div class="wt-lb-v2__you-info">
            <small><?= e(t('lb.your_position')) ?></small>
            <strong class="wt-lb-v2__you-position">
              <?= $myInfo['rank'] ? '#' . (int)$myInfo['rank'] : '—' ?>
            </strong>
            <span><?= e($fmt((float)$myInfo['coins_month'])) ?> <?= e(t('common.coins')) ?> <?= e(t('lb.this_month')) ?></span>
          </div>
        </header>

        <?php
          $gap = wt_lb_gap_to_top((int)$u['id']);
          // Simulateur : pour 3 paliers (top 10, top 5, top 3), montre le gain potentiel
          $sims = [];
          foreach ([10, 5, 3] as $target) {
              $tg = $rewardsGrid[$target] ?? 0;
              if ($tg > 0) $sims[$target] = $tg;
          }
        ?>

        <?php if ($gap > 0): ?>
          <div class="wt-lb-v2__you-motivation">
            🎯 <?= sprintf(
              (string)t('lb.motivation_gap'),
              '<strong>' . e($fmt($gap)) . '</strong> ' . e(t('common.coins'))
            ) ?>
          </div>
        <?php elseif (!$myInfo['rank']): ?>
          <div class="wt-lb-v2__you-motivation">
            ✨ <?= e(t('lb.motivation_zero')) ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($sims)): ?>
          <div class="wt-lb-v2__you-sim">
            <small><?= e(t('lb.sim.title')) ?></small>
            <div class="wt-lb-v2__you-sim-grid">
              <?php foreach ($sims as $target => $amount): ?>
                <div class="wt-lb-v2__you-sim-item">
                  <span class="wt-lb-v2__you-sim-target"><?= e(sprintf((string)t('lb.sim.top_n'), $target)) ?></span>
                  <strong class="wt-lb-v2__you-sim-amount">+<?= e($fmt($amount)) ?></strong>
                  <small><?= e(t('common.coins')) ?></small>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="wt-lb-v2__you-cta">
          <a class="wt-btn wt-btn--primary" href="<?= e(wt_url('/tasks/faucet/')) ?>">💧 <?= e(t('nav.faucet')) ?></a>
          <a class="wt-btn wt-btn--ghost"   href="<?= e(wt_url('/tasks/shortlinks/')) ?>">🔗 <?= e(t('nav.shortlinks')) ?></a>
          <a class="wt-btn wt-btn--ghost"   href="<?= e(wt_url('/tasks/offerwalls/')) ?>">🎯 <?= e(t('nav.offerwalls')) ?></a>
        </div>
      </section>
    <?php endif; ?>

  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
