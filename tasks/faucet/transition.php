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

      <?php
        /* Article de blog pendant l'attente.
         *
         * Cette page était auparavant une simple page d'attente portant
         * DEUX bannières et aucun contenu propre — la définition même
         * d'une page conçue pour la publicité. On remplace l'emplacement
         * bas par un article à lire : le temps d'attente devient utile,
         * la page a un contenu réel, et il ne reste qu'une seule bannière.
         *
         * L'article change à chaque passage (rotation aléatoire parmi les
         * plus récents), pour ne pas lasser un utilisateur qui réclame le
         * faucet plusieurs fois par jour. */
        $_tPosts = function_exists('wt_blog_posts') ? wt_blog_posts(6) : [];
        $_tPost  = $_tPosts ? $_tPosts[array_rand($_tPosts)] : null;
      ?>
      <?php if ($_tPost): ?>
        <a class="wt-transition-read" href="<?= e(wt_url('/blog/' . $_tPost['slug'])) ?>">
          <span class="wt-transition-read__eyebrow"><?= e(t('faucet.read_while_waiting')) ?></span>
          <span class="wt-transition-read__body">
            <span class="wt-transition-read__icon" aria-hidden="true"><?= e($_tPost['cover_emoji'] ?: '📄') ?></span>
            <span>
              <strong class="wt-transition-read__title"><?= e($_tPost['title']) ?></strong>
              <?php if (!empty($_tPost['excerpt'])): ?>
                <span class="wt-transition-read__excerpt"><?= e($_tPost['excerpt']) ?></span>
              <?php endif; ?>
              <span class="wt-transition-read__meta">
                ⏱️ <?= (int)$_tPost['reading_minutes'] ?> <?= e(t('blog.min_read')) ?>
              </span>
            </span>
          </span>
        </a>
      <?php elseif (!empty($ads['faucet_transition_bottom'])): ?>
        <?php /* Aucun article publié : on retombe sur l'emplacement d'origine */ ?>
        <div class="wt-ad-slot"><?= $ads['faucet_transition_bottom'] ?></div>
      <?php endif; ?>

      <a href="<?= e(wt_url('/tasks/faucet/verify.php?t=' . urlencode($token))) ?>"
         class="wt-btn wt-btn--primary wt-btn--lg wt-faucet-v2__cta"
         data-transition-continue>
        <?= e(t('faucet.continue_to_verify')) ?> →
      </a>
    </section>

  </div>
</main>

<?php include __DIR__ . '/../../footer.php'; ?>
