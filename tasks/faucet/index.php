<?php
/**
 * /tasks/faucet/index.php — Carrefour Faucet (étape 1) — V8 modernisé
 *
 * Comportement (inchangé) :
 *   - Auth obligatoire
 *   - Si claim récent → countdown jusqu'à next_claim_at
 *   - Sinon → bouton "Commencer la réclamation" qui POST vers
 *     /api/faucet_start.php pour créer une session
 *
 * Améliorations V8 :
 *   - Logo Wintaskly (light/dark) au lieu du "W" texte
 *   - Stepper visuel 1-2-3 en haut
 *   - Récap utilisateur : solde, claims totaux, streak
 *   - i18n complet (plus de strings en dur)
 *   - Countdown plus immersif (h/m/s avec progression circulaire)
 */
declare(strict_types=1);
require __DIR__ . '/../../includes/init.php';

$user = require_auth();
$db   = db();

/* État courant */
$stmt = $db->prepare(
    "SELECT next_claim_at FROM faucet_claims
      WHERE user_id = ?
      ORDER BY id DESC LIMIT 1"
);
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$last = $stmt->get_result()->fetch_assoc();
$stmt->close();

$nextAt     = $last ? strtotime($last['next_claim_at'] . ' UTC') : 0;
$onCooldown = $nextAt > time();

/* Stats utilisateur pour le récap */
$claimStats = ['total' => 0, 'today' => 0, 'streak' => 0];
$stmt = $db->prepare(
    "SELECT COUNT(*) total,
            SUM(CASE WHEN DATE(claimed_at) = UTC_DATE() THEN 1 ELSE 0 END) today
       FROM faucet_claims WHERE user_id = ?"
);
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$claimStats['total'] = (int)($row['total'] ?? 0);
$claimStats['today'] = (int)($row['today'] ?? 0);

/* Streak : nombre de jours consécutifs avec ≥ 1 claim */
$stmt = $db->prepare(
    "SELECT DATE(claimed_at) d FROM faucet_claims
      WHERE user_id = ?
      GROUP BY DATE(claimed_at)
      ORDER BY d DESC
      LIMIT 30"
);
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$dates = [];
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $dates[] = $r['d'];
$stmt->close();

if (!empty($dates)) {
    $streak = 1;
    $cursor = new DateTime($dates[0], new DateTimeZone('UTC'));
    for ($i = 1; $i < count($dates); $i++) {
        $prev = new DateTime($dates[$i], new DateTimeZone('UTC'));
        $diff = (int)$cursor->diff($prev)->days;
        if ($diff === 1) { $streak++; $cursor = $prev; }
        else break;
    }
    $claimStats['streak'] = $streak;
}

$reward     = (float) cfg('faucet_reward_coins', '25');
$rewardXp   = (int)   cfg('faucet_reward_xp', '10');
$sessionTtl = (int)   cfg('faucet_session_ttl_seconds', 300);

$fmt = static function (float $n): string {
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
};

$pageTitle = t('faucet.title');
include __DIR__ . '/../../header.php';
?>

<main class="wt-main wt-faucet-v2">
  <div class="wt-faucet-v2__wrap">

    <?php
      $stepperHere = 1;
      include __DIR__ . '/_stepper.php';
    ?>

    <section class="wt-faucet-v2__hero" data-reveal>
      <picture class="wt-faucet-v2__logo" aria-hidden="true">
        <source srcset="<?= e(wt_url('/media/wintaskly/img/logo-dark-192.png')) ?>"
                media="(prefers-color-scheme: dark)">
        <img    src="<?= e(wt_url('/media/wintaskly/img/logo-light-192.png')) ?>"
                alt="" width="80" height="80" decoding="async">
      </picture>

      <span class="wt-eyebrow">💧 <?= e(t('faucet.eyebrow')) ?></span>
      <h1 class="wt-faucet-v2__title"><?= e(t('faucet.title')) ?></h1>
      <p class="wt-faucet-v2__lead"><?= e(t('faucet.intro')) ?></p>

      <div class="wt-faucet-v2__reward">
        <span class="wt-faucet-v2__reward-icon" aria-hidden="true">🎁</span>
        <div class="wt-faucet-v2__reward-text">
          <small><?= e(t('faucet.you_earn')) ?></small>
          <strong>
            <?= e($fmt($reward)) ?>
            <span><?= e(t('common.coins')) ?></span>
            <em>+ <?= (int)$rewardXp ?> XP</em>
          </strong>
        </div>
      </div>

      <?php if ($onCooldown): ?>
        <div class="wt-faucet-v2__cd"
             data-faucet-countdown
             data-end-iso="<?= gmdate('c', $nextAt) ?>"
             data-cooldown-seconds="<?= (int) cfg('faucet_cooldown_seconds', '10800') ?>">
          <svg class="wt-faucet-v2__cd-ring" viewBox="0 0 140 140" aria-hidden="true">
            <circle class="wt-faucet-v2__cd-track" cx="70" cy="70" r="62"/>
            <circle class="wt-faucet-v2__cd-bar"   cx="70" cy="70" r="62"
                    data-progress-circle
                    stroke-dasharray="389.56"
                    stroke-dashoffset="389.56"/>
          </svg>
          <div class="wt-faucet-v2__cd-digits">
            <div class="wt-faucet-v2__cd-row">
              <span class="wt-faucet-v2__cd-num" data-h>00</span>
              <span class="wt-faucet-v2__cd-num" data-m>00</span>
              <span class="wt-faucet-v2__cd-num" data-s>00</span>
            </div>
            <div class="wt-faucet-v2__cd-labels">
              <span><?= e(t('faucet.hours')) ?></span>
              <span><?= e(t('faucet.minutes')) ?></span>
              <span><?= e(t('faucet.seconds')) ?></span>
            </div>
          </div>
        </div>
        <p class="wt-faucet-v2__cd-hint"><?= e(t('faucet.next_in')) ?></p>

        <button type="button"
                class="wt-btn wt-btn--primary wt-btn--lg wt-hidden"
                data-faucet-start>
          <?= e(t('faucet.start')) ?>
        </button>
      <?php else: ?>
        <button type="button"
                class="wt-btn wt-btn--primary wt-btn--lg wt-faucet-v2__cta"
                data-faucet-start>
          🚀 <?= e(t('faucet.start')) ?>
        </button>
        <p class="wt-faucet-v2__warning">
          ⏱ <?= e(sprintf((string) t('faucet.session_warning'), (int)($sessionTtl / 60))) ?>
        </p>
      <?php endif; ?>
    </section>

    <section class="wt-faucet-v2__stats" data-reveal>
      <div class="wt-faucet-v2__stat">
        <span class="wt-faucet-v2__stat-icon" aria-hidden="true">💰</span>
        <strong><?= e($fmt((float)$user['coins'])) ?></strong>
        <small><?= e(t('tasks.balance')) ?></small>
      </div>
      <div class="wt-faucet-v2__stat">
        <span class="wt-faucet-v2__stat-icon" aria-hidden="true">📦</span>
        <strong><?= (int)$claimStats['total'] ?></strong>
        <small><?= e(t('faucet.total_claims')) ?></small>
      </div>
      <div class="wt-faucet-v2__stat">
        <span class="wt-faucet-v2__stat-icon" aria-hidden="true">🔥</span>
        <strong><?= (int)$claimStats['streak'] ?></strong>
        <small><?= e(t('faucet.streak')) ?></small>
      </div>
    </section>

    <p class="wt-faucet-v2__bonus">
      <?= e(t('faucet.referral_bonus')) ?>
      <a href="<?= e(wt_url('/dashboard/referrals.php')) ?>"><?= e(t('faucet.referral_link')) ?> →</a>
    </p>

  </div>
</main>

<?php include __DIR__ . '/../../footer.php'; ?>
