<?php
/**
 * Wintaskly — /tasks/shortlinks/gateway.php  (V8 modernisé)
 *
 * Passerelle interne avant redirection vers l'URL externe.
 *
 * Logique inchangée :
 *   1) Vérifier le shortlink (actif, hors cooldown)
 *   2) Créer une tentative `en_attente` avec token unique
 *   3) Bannière publicitaire + countdown
 *   4) Au bout du décompte : révéler le bouton "Continuer vers le lien"
 *      → URL externe avec token concaténé
 *
 * Améliorations V8 :
 *   - Stepper 2 étapes (Patience → Ouverture)
 *   - Cercle SVG circular progress
 *   - Rappel récompense (motivation maintenue)
 *   - Footer informatif sur ce qui va se passer
 */
declare(strict_types=1);
require __DIR__ . '/../../includes/init.php';
require_auth();

$pageTitle = t('shortlinks.gateway');
$u  = current_user();
$db = db();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: ' . wt_url('/tasks/shortlinks/'));
    exit;
}

/* 1) Shortlink + cooldown utilisateur */
$stmt = $db->prepare(
    "SELECT s.id, s.name, s.mode,
            s.destination_url, s.api_endpoint, s.api_token, s.callback_key,
            s.gateway_seconds, s.active,
            s.reward_coins, s.reward_xp,
            c.available_at
       FROM shortlinks s
       LEFT JOIN shortlink_cooldowns c
         ON c.shortlink_id = s.id AND c.user_id = ?
      WHERE s.id = ?
      LIMIT 1"
);
$stmt->bind_param('ii', $u['id'], $id);
$stmt->execute();
$sl = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$sl || (int) $sl['active'] !== 1) {
    header('Location: ' . wt_url('/tasks/shortlinks/'));
    exit;
}
if (!empty($sl['available_at']) && strtotime($sl['available_at'] . ' UTC') > time()) {
    header('Location: ' . wt_url('/tasks/shortlinks/'));
    exit;
}

/* 2) Tentative en attente */
$token = bin2hex(random_bytes(32));
$ipBin = wt_ip_bin();
$stmt = $db->prepare(
    "INSERT INTO shortlink_attempts (user_id, shortlink_id, token, ip)
     VALUES (?, ?, ?, ?)"
);
$stmt->bind_param('iiss', $u['id'], $sl['id'], $token, $ipBin);
$stmt->execute();
$stmt->close();

/* 3) Pub + construction URL finale selon le mode du shortlink
   ─────────────────────────────────────────────────────────────────────
   - Mode 'manual' : l'admin a déjà créé le lien court chez le provider
     et collé l'URL dans destination_url. On ajoute juste ?wt=TOKEN pour
     identifier le retour (utile si le provider supporte le pass-through).

   - Mode 'api' : on appelle l'API du provider en lui passant comme URL
     cible NOTRE callback (avec le token). Le provider nous renvoie un
     lien court fraîchement généré. À chaque clic = nouveau lien court,
     impossible à partager/réutiliser entre users → sécurité maximale.
   ───────────────────────────────────────────────────────────────────── */
$adZone   = $db->query("SELECT code FROM ad_zones WHERE k='shortlink_gateway' AND active=1 LIMIT 1");
$adCode   = $adZone ? (string)($adZone->fetch_assoc()['code'] ?? '') : '';
$delay    = max(3, (int) $sl['gateway_seconds']);

$slMode = (string) ($sl['mode'] ?? 'manual');
$apiError = null;

if ($slMode === 'api'
    && !empty($sl['api_endpoint'])
    && !empty($sl['api_token'])
    && !empty($sl['callback_key'])) {

    // URL de notre callback que le provider redirigera l'user après le shortlink
    $callbackUrl = wt_url('/api/shortlink_callback.php')
                 . '?token=' . urlencode($token)
                 . '&key=' . urlencode($sl['callback_key']);

    // Appel API provider pour générer le lien court
    $shortUrl = wt_shortlink_create_via_api(
        (string) $sl['api_endpoint'],
        (string) $sl['api_token'],
        $callbackUrl
    );

    if ($shortUrl !== null) {
        $finalUrl = $shortUrl;
    } else {
        // API provider injoignable / token invalide / quota dépassé.
        // On invalide la tentative pour ne pas la garder pendante.
        $stmt = $db->prepare(
            "UPDATE shortlink_attempts SET status='rejete' WHERE token = ?"
        );
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $stmt->close();
        $apiError = t('shortlinks.api_error');
        $finalUrl = ''; // pas d'URL → on affichera un message d'erreur
    }
} else {
    // Mode manual : URL pré-collée + on suffixe avec notre token Wintaskly
    $separator = (strpos($sl['destination_url'], '?') !== false) ? '&' : '?';
    $finalUrl = $sl['destination_url'] . $separator . 'wt=' . urlencode($token);
}

$fmt = static function (float $n): string {
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
};

/* Petit composant stepper interne (2 étapes : patience → ouverture) */
$slSteps = [
    1 => ['icon' => '⏱️', 'label' => t('shortlinks.gw.step_wait')],
    2 => ['icon' => '🚀', 'label' => t('shortlinks.gw.step_open')],
];
$slHere = 1;  // on est sur l'étape patience pendant tout le countdown

include __DIR__ . '/../../header.php';
?>

<main class="wt-main wt-sl-v2">
  <div class="wt-sl-v2__wrap wt-sl-v2__wrap--narrow">

    <!-- ====== STEPPER 2 ÉTAPES (réutilise le composant générique) ====== -->
    <ol class="wt-stepper" aria-label="<?= e(t('shortlinks.gw.progress')) ?>">
      <?php foreach ($slSteps as $n => $s):
          $state = $n < $slHere ? 'done'
                 : ($n === $slHere ? 'current' : 'todo');
      ?>
        <li class="wt-stepper__item wt-stepper__item--<?= $state ?>">
          <span class="wt-stepper__bullet" aria-hidden="true">
            <span class="wt-stepper__bullet-icon"><?= e($s['icon']) ?></span>
          </span>
          <div class="wt-stepper__meta">
            <small><?= sprintf((string) t('faucet.step_n'), $n) ?></small>
            <strong><?= e($s['label']) ?></strong>
          </div>
        </li>
      <?php endforeach; ?>
    </ol>

    <section class="wt-sl-gw-v2" data-reveal>
      <span class="wt-eyebrow">🔗 <?= e($sl['name']) ?></span>
      <h1 class="wt-sl-v2__title"><?= e(t('shortlinks.gateway')) ?></h1>
      <p class="wt-sl-v2__lead"><?= e(t('shortlinks.gateway.intro')) ?></p>

      <!-- Rappel récompense -->
      <div class="wt-faucet-v2__reward wt-faucet-v2__reward--compact">
        <span class="wt-faucet-v2__reward-icon" aria-hidden="true">🎁</span>
        <div class="wt-faucet-v2__reward-text">
          <small><?= e(t('faucet.you_earn')) ?></small>
          <strong>
            <?= e($fmt((float)$sl['reward_coins'])) ?>
            <span><?= e(t('common.coins')) ?></span>
            <?php if ((int)$sl['reward_xp'] > 0): ?>
              <em>+ <?= (int)$sl['reward_xp'] ?> XP</em>
            <?php endif; ?>
          </strong>
        </div>
      </div>

      <!-- Bannière publicitaire -->
      <div class="wt-ad-slot">
        <?php if ($adCode): ?>
          <?= $adCode /* HTML AdSense géré par l'admin, non échappé */ ?>
        <?php else: ?>
          <span class="wt-ad-slot__placeholder"><?= e(t('faucet.ad_placeholder')) ?></span>
        <?php endif; ?>
      </div>

      <!-- Cercle SVG circular progress -->
      <div class="wt-transition-v2"
           data-sl-gateway-count
           data-seconds="<?= (int)$delay ?>">
        <svg class="wt-transition-v2__ring" viewBox="0 0 120 120" aria-hidden="true">
          <circle class="wt-transition-v2__track" cx="60" cy="60" r="52"/>
          <circle class="wt-transition-v2__bar"   cx="60" cy="60" r="52"
                  data-sl-gateway-bar
                  stroke-dasharray="326.7"
                  stroke-dashoffset="326.7"/>
        </svg>
        <div class="wt-transition-v2__num" data-sl-gateway-num><?= (int)$delay ?></div>
        <div class="wt-transition-v2__label"><?= e(t('faucet.seconds_remaining')) ?></div>
      </div>

      <?php if ($apiError !== null): ?>
        <!-- Erreur API provider (mode 'api' uniquement) -->
        <div class="wt-alert wt-alert--error" style="margin-top:1rem">
          ⚠ <?= e($apiError) ?>
        </div>
        <a class="wt-btn wt-btn--ghost" href="<?= e(wt_url('/tasks/shortlinks/')) ?>"
           style="margin-top:.75rem">← <?= e(t('common.back')) ?></a>
      <?php else: ?>
      <!-- Bouton final caché jusqu'à fin du countdown -->
      <a class="wt-btn wt-btn--primary wt-btn--lg wt-sl-v2__cta is-hidden"
         href="<?= e($finalUrl) ?>"
         rel="noopener noreferrer"
         data-sl-gateway-go>
        🚀 <?= e(t('shortlinks.gateway.go')) ?>
      </a>
      <?php endif; ?>

      <!-- Info de bas de page -->
      <p class="wt-sl-v2__footnote">
        ℹ️ <?= e(t('shortlinks.gw.external_notice')) ?>
      </p>
    </section>

  </div>
</main>

<?php include __DIR__ . '/../../footer.php'; ?>
