<?php
/**
 * /tasks/faucet/transition.php — Page transition pub (étape 2) — V8
 *
 * Logique inchangée :
 *   - Vérifie session token (open + non expirée)
 *   - Met à jour step2_at
 *   - Affiche bannières publicitaires haute + basse
 *   - Compte à rebours (12s configurable)
 *   - À la fin, JS dévoile le bouton vers /verify.php
 *
 * Améliorations V8 :
 *   - Stepper visuel (étape 2 highlightée)
 *   - Cercle SVG de progression au lieu d'un nombre brut
 *   - Rappel de la récompense ("Tu vas gagner X Coins")
 *   - Countdown live du temps de session global (anti-éjection)
 */
declare(strict_types=1);
require __DIR__ . '/../../includes/init.php';

$user = require_auth();

$token = (string)($_GET['t'] ?? '');
if ($token === '') {
    header('Location: ' . wt_url('/tasks/faucet/'));
    exit;
}

$db   = db();
$stmt = $db->prepare(
    "SELECT id, user_id, expires_at, status
       FROM faucet_sessions
      WHERE token = ? LIMIT 1"
);
$stmt->bind_param('s', $token);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$session
    || (int)$session['user_id'] !== (int)$user['id']
    || $session['status'] !== 'open'
    || strtotime($session['expires_at'] . ' UTC') < time()
) {
    header('Location: ' . wt_url('/tasks/faucet/'));
    exit;
}

/* Marque step2_at au passage */
$stmt = $db->prepare("UPDATE faucet_sessions SET step2_at = NOW() WHERE id = ?");
$stmt->bind_param('i', $session['id']);
$stmt->execute();
$stmt->close();

$transitionSecs = (int) cfg('faucet_transition_seconds', 12);
$reward         = (float) cfg('faucet_reward_coins', '25');
$rewardXp       = (int)   cfg('faucet_reward_xp', '10');

/* Bannières */
$ads = [
    'faucet_transition_top'    => wt_ad_zone('faucet_transition_top'),
    'faucet_transition_bottom' => wt_ad_zone('faucet_transition_bottom'),
];

$fmt = static function (float $n): string {
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
};

$pageTitle = t('faucet.transition');
include __DIR__ . '/../../header.php';
?>

<main class="wt-main wt-faucet-v2">
  <div class="wt-faucet-v2__wrap wt-faucet-v2__wrap--narrow">

    <?php
      $stepperHere = 2;
      include __DIR__ . '/_stepper.php';
    ?>

    <section class="wt-faucet-v2__transition" data-reveal>
      <h1 class="wt-faucet-v2__title"><?= e(t('faucet.transition')) ?></h1>
      <p class="wt-faucet-v2__lead"><?= e(t('faucet.transition_lead')) ?></p>

      <!-- Rappel de la récompense pour maintenir la motivation -->
      <div class="wt-faucet-v2__reward wt-faucet-v2__reward--compact">
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

      <!-- Bannière pub haute -->
      <div class="wt-ad-slot">
        <?php if (!empty($ads['faucet_transition_top'])): ?>
          <?= $ads['faucet_transition_top'] ?>
        <?php else: ?>
          <span class="wt-ad-slot__placeholder"><?= e(t('faucet.ad_placeholder')) ?></span>
        <?php endif; ?>
      </div>

      <!-- Compteur circulaire au centre -->
      <div class="wt-transition-v2"
           data-transition-count
           data-seconds="<?= (int)$transitionSecs ?>">
        <svg class="wt-transition-v2__ring" viewBox="0 0 120 120" aria-hidden="true">
          <circle class="wt-transition-v2__track" cx="60" cy="60" r="52"/>
          <circle class="wt-transition-v2__bar"   cx="60" cy="60" r="52"
                  data-transition-bar
                  stroke-dasharray="326.7"
                  stroke-dashoffset="326.7"/>
        </svg>
        <div class="wt-transition-v2__num" data-transition-num><?= (int)$transitionSecs ?></div>
        <div class="wt-transition-v2__label"><?= e(t('faucet.seconds_remaining')) ?></div>
      </div>

      <!-- Bannière pub basse -->
      <div class="wt-ad-slot">
        <?php if (!empty($ads['faucet_transition_bottom'])): ?>
          <?= $ads['faucet_transition_bottom'] ?>
        <?php else: ?>
          <span class="wt-ad-slot__placeholder"><?= e(t('faucet.ad_placeholder')) ?></span>
        <?php endif; ?>
      </div>

      <a href="<?= e(wt_url('/tasks/faucet/verify.php?t=' . urlencode($token))) ?>"
         class="wt-btn wt-btn--primary wt-btn--lg wt-faucet-v2__cta"
         data-transition-continue>
        <?= e(t('faucet.continue_to_verify')) ?> →
      </a>
    </section>

  </div>
</main>

<?php include __DIR__ . '/../../footer.php'; ?>
