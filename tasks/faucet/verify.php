<?php
/**
 * /tasks/faucet/verify.php — Validation finale (étape 3) — V8
 *
 * Logique inchangée :
 *   - Vérifie session token
 *   - Charge captcha (5 icônes + cible)
 *   - Captcha + checkbox + honeypot
 *   - Submit Ajax → /api/faucet_validate.php
 *
 * Améliorations V8 :
 *   - Stepper visuel (étape 3 highlightée)
 *   - Countdown LIVE du temps de session (data-countdown attribute
 *     réutilisé du wintaskly-ui.js) → l'utilisateur voit son temps
 *     restant fondre en direct, ne se fait plus éjecter en aveugle
 *   - Cadre captcha repensé, icônes plus grandes et plus aérées
 *   - Bandeau récompense maintenu pour la motivation
 *   - i18n complet
 */
declare(strict_types=1);
require __DIR__ . '/../../includes/init.php';

$user  = require_auth();
$token = (string)($_GET['t'] ?? '');
if ($token === '') {
    header('Location: ' . wt_url('/tasks/faucet/'));
    exit;
}

$db   = db();
$stmt = $db->prepare(
    "SELECT id, user_id, expires_at, status, captcha_target, captcha_order
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

$stmt = $db->prepare(
    "UPDATE faucet_sessions
        SET step3_at = COALESCE(step3_at, NOW())
      WHERE id = ?"
);
$stmt->bind_param('i', $session['id']);
$stmt->execute();
$stmt->close();

$order      = json_decode($session['captcha_order'], true) ?: [];
$targetSlug = $session['captcha_target'];

/* Charge les SVG des icônes captcha */
$icons = [];
if ($order) {
    $placeholders = implode(',', array_fill(0, count($order), '?'));
    $types = str_repeat('s', count($order));
    $stmt = $db->prepare("SELECT slug, name, svg FROM captcha_icons WHERE slug IN ($placeholders)");
    $stmt->bind_param($types, ...$order);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) { $icons[$r['slug']] = $r; }
    $stmt->close();
}
$targetName = $icons[$targetSlug]['name'] ?? $targetSlug;

/* Pub centrale */
$adCode = wt_ad_zone('faucet_verify_center');

$expiresIso = gmdate('c', strtotime($session['expires_at'] . ' UTC'));
$reward     = (float) cfg('faucet_reward_coins', '25');
$rewardXp   = (int)   cfg('faucet_reward_xp', '10');

$fmt = static function (float $n): string {
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
};

$pageTitle = t('faucet.verify');
include __DIR__ . '/../../header.php';
?>

<main class="wt-main wt-faucet-v2">
  <div class="wt-faucet-v2__wrap wt-faucet-v2__wrap--narrow">

    <?php
      $stepperHere = 3;
      include __DIR__ . '/_stepper.php';
    ?>

    <section class="wt-faucet-v2__verify" data-reveal>
      <h1 class="wt-faucet-v2__title"><?= e(t('faucet.verify')) ?></h1>
      <p class="wt-faucet-v2__lead"><?= e(t('faucet.verify_lead')) ?></p>

      <!-- Session timer LIVE : countdown qui descend en direct -->
      <div class="wt-faucet-v2__session-timer">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="10"/>
          <polyline points="12 6 12 12 16 14"/>
        </svg>
        <span><?= e(t('faucet.session_remaining')) ?></span>
        <strong data-countdown
                data-target="<?= e($session['expires_at']) ?>"
                data-label-ready="<?= e(t('faucet.session_expired')) ?>">…</strong>
      </div>

      <!-- Rappel récompense -->
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

      <!-- Bannière publicitaire centrale 300×250 -->
      <div class="wt-ad-slot wt-ad-slot--300x250">
        <?php if ($adCode): ?>
          <?= $adCode ?>
        <?php else: ?>
          <span class="wt-ad-slot__placeholder">[ <?= e(t('faucet.ad_300x250')) ?> ]</span>
        <?php endif; ?>
      </div>

      <form data-faucet-verify-form
            data-token="<?= e($token) ?>"
            data-target-slug="<?= e($targetSlug) ?>"
            autocomplete="off"
            novalidate
            class="wt-faucet-v2__form">

        <!-- Honeypot -->
        <div class="wt-honeypot" aria-hidden="true">
          <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
          <label>Address line 2 <input type="text" name="address2" tabindex="-1" autocomplete="off"></label>
        </div>

        <!-- Checkbox anti-bot -->
        <label class="wt-checkbox wt-faucet-v2__notrobot">
          <input type="checkbox" data-not-robot>
          <span><?= e(t('faucet.not_robot')) ?></span>
        </label>

        <!-- Instruction captcha (mise en valeur) -->
        <div class="wt-faucet-v2__captcha-instruction">
          <span class="wt-faucet-v2__captcha-prompt"><?= e(t('faucet.captcha_click_on')) ?></span>
          <strong class="wt-faucet-v2__captcha-target"><?= e($targetName) ?></strong>
        </div>

        <!-- 5 icônes captcha -->
        <div class="wt-faucet-v2__captcha-grid">
          <?php foreach ($order as $slug):
                $ic = $icons[$slug] ?? null;
                if (!$ic) continue; ?>
            <button type="button"
                    class="wt-faucet-v2__captcha-icon"
                    data-slug="<?= e($slug) ?>"
                    aria-label="<?= e($ic['name']) ?>">
              <?= $ic['svg'] ?>
            </button>
          <?php endforeach; ?>
        </div>

        <button type="button" class="wt-faucet-v2__captcha-reset" data-captcha-reset>
          ↺ <?= e(t('faucet.reset')) ?>
        </button>

        <button type="submit"
                class="wt-btn wt-btn--primary wt-btn--lg wt-faucet-v2__cta"
                data-claim-btn>
          🎉 <?= e(t('faucet.claim')) ?>
        </button>
      </form>
    </section>

  </div>
</main>

<?php include __DIR__ . '/../../footer.php'; ?>
