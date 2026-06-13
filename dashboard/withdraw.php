<?php
/**
 * Wintaskly — /dashboard/withdraw (V8 modernisé).
 *
 * Permet à l'utilisateur de soumettre une demande de retrait.
 * Le calcul de l'équivalent monétaire est fait en JS en temps réel
 * (voir wintaskly.js, bloc Withdrawal real-time conversion).
 * La soumission passe par /api/withdraw_submit.php.
 *
 * Améliorations V8 :
 *   - Header avec balance card hero
 *   - Methods en cards radio cliquables (au lieu de fieldset radio classique)
 *   - Form en card avec layout aéré
 *   - Historique en cards par statut avec badge dot
 *
 * Compat 100% : tous les hooks JS préservés :
 *   - data-wd-form, data-wd-method, data-wd-address-label,
 *     data-wd-address-input, data-wd-payout
 *   - Tous les data-* sur les inputs radio (ratio, currency, min, etc.)
 */
require __DIR__ . '/../includes/init.php';
require_auth();

$pageTitle = t('wd.title');
$u  = current_user();
$db = db();

/* Méthodes actives */
$methods = [];
if ($res = $db->query(
    "SELECT id, label, currency, coins_per_unit, min_coins, address_label, address_placeholder
       FROM withdrawal_methods
      WHERE active = 1
      ORDER BY sort_order ASC, id ASC"
)) {
    $methods = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();
}

/* Historique */
$history = [];
$stmt = $db->prepare(
    "SELECT w.*, m.label AS method_label
       FROM withdrawals w
       JOIN withdrawal_methods m ON m.id = w.method_id
      WHERE w.user_id = ?
      ORDER BY w.id DESC
      LIMIT 20"
);
$stmt->bind_param('i', $u['id']);
$stmt->execute();
$res = $stmt->get_result();
$history = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Messages d'erreur / succès depuis l'API */
$err = $_GET['err'] ?? null;
$ok  = isset($_GET['ok']);
$errMap = [
    'min'     => t('wd.error.min'),
    'max'     => t('wd.error.max'),
    'balance' => t('wd.error.balance'),
    'address' => t('wd.error.address'),
    'suspect' => t('wd.error.suspect'),
    'server'  => t('common.error'),
];

$dashActive = 'withdraw';
include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-dash">
  <div class="wt-dash__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
    <section class="wt-dash__content wt-dash-v2__content">

      <header class="wt-dash-v2__page-header" data-reveal>
        <span class="wt-eyebrow">💸 <?= e(t('wd.eyebrow')) ?></span>
        <h1 class="wt-dash-v2__title"><?= e(t('wd.title')) ?></h1>
        <p class="wt-muted"><?= e(t('wd.lead')) ?></p>
      </header>

      <!-- Hero balance card -->
      <section class="wt-wd-v2__balance-hero" data-reveal>
        <span class="wt-wd-v2__balance-icon" aria-hidden="true">💰</span>
        <div>
          <small><?= e(t('wd.balance')) ?></small>
          <strong>
            <?= e(rtrim(rtrim(number_format((float)$u['coins'], 4, '.', ''), '0'), '.')) ?>
            <em><?= e(t('common.coins')) ?></em>
          </strong>
        </div>
      </section>

      <?php if ($ok): ?>
        <div class="wt-alert wt-alert--success" data-reveal>
          ✅ <?= e(t('wd.ok')) ?>
        </div>
      <?php endif; ?>
      <?php if ($err && isset($errMap[$err])): ?>
        <div class="wt-alert wt-alert--error" data-reveal>
          ⚠️ <?= e($errMap[$err]) ?>
        </div>
      <?php endif; ?>

      <!-- Formulaire de retrait -->
      <section class="wt-wd-v2__form-card" data-reveal>
        <form method="post"
              action="<?= e(wt_url('/api/withdraw_submit.php')) ?>"
              class="wt-form"
              data-wd-form>
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

          <!-- Choix de la méthode -->
          <fieldset class="wt-wd-v2__fieldset">
            <legend class="wt-wd-v2__legend"><?= e(t('wd.method')) ?></legend>

            <div class="wt-wd-v2__methods">
              <?php foreach ($methods as $i => $m): ?>
                <label class="wt-wd-v2__method" data-wd-method>
                  <input type="radio"
                         name="method_id"
                         value="<?= (int)$m['id'] ?>"
                         data-ratio="<?= e($m['coins_per_unit']) ?>"
                         data-currency="<?= e($m['currency']) ?>"
                         data-min="<?= e($m['min_coins']) ?>"
                         data-address-label="<?= e($m['address_label']) ?>"
                         data-address-placeholder="<?= e((string)($m['address_placeholder'] ?? '')) ?>"
                         <?= $i === 0 ? 'checked' : '' ?>>
                  <div class="wt-wd-v2__method-card">
                    <span class="wt-wd-v2__method-check" aria-hidden="true">
                      <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3">
                        <polyline points="20 6 9 17 4 12"/>
                      </svg>
                    </span>
                    <strong class="wt-wd-v2__method-label"><?= e($m['label']) ?></strong>
                    <small class="wt-wd-v2__method-min">
                      <?= e(t('wd.min')) ?> :
                      <?= e(rtrim(rtrim(number_format((float)$m['min_coins'], 4, '.', ''), '0'), '.')) ?> Coins
                    </small>
                  </div>
                </label>
              <?php endforeach; ?>
            </div>
          </fieldset>

          <div class="wt-wd-v2__row">
            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('wd.amount')) ?></span>
              <input class="wt-input"
                     type="number"
                     step="0.0001" min="0"
                     name="coins_amount"
                     placeholder="0.0000"
                     required>
            </label>

            <label class="wt-field">
              <span class="wt-field__label" data-wd-address-label>
                <?= e($methods[0]['address_label'] ?? t('wd.address')) ?>
              </span>
              <input class="wt-input"
                     type="text"
                     name="payout_address"
                     required
                     placeholder="<?= e((string)($methods[0]['address_placeholder'] ?? '')) ?>"
                     data-wd-address-input>
            </label>
          </div>

          <!-- Récap conversion live -->
          <div class="wt-wd-v2__summary">
            <span><?= e(t('wd.payout')) ?></span>
            <strong data-wd-payout>0 USD</strong>
          </div>

          <button type="submit" class="wt-btn wt-btn--primary wt-btn--lg wt-btn--block"
                  <?= $methods ? '' : 'disabled' ?>>
            ✈️ <?= e(t('wd.submit')) ?>
          </button>
        </form>
      </section>

      <!-- Historique -->
      <section data-reveal>
        <h2 class="wt-dash-v2__section-title">📋 <?= e(t('wd.history')) ?></h2>

        <?php if (!$history): ?>
          <div class="wt-dash-v2__empty">
            <span class="wt-dash-v2__empty-icon" aria-hidden="true">📭</span>
            <p><?= e(t('common.empty')) ?></p>
          </div>
        <?php else: ?>
          <ul class="wt-wd-v2__history">
            <?php foreach ($history as $i => $h):
              $statusClass = match ($h['status']) {
                  'completed' => 'completed',
                  'refused'   => 'refused',
                  default     => 'pending',
              };
            ?>
              <li class="wt-wd-v2__entry wt-wd-v2__entry--<?= e($statusClass) ?>"
                  style="--idx:<?= (int)$i ?>">
                <div class="wt-wd-v2__entry-icon-wrap">
                  <span class="wt-wd-v2__entry-icon" aria-hidden="true">
                    <?= $statusClass === 'completed' ? '✅' : ($statusClass === 'refused' ? '❌' : '⏳') ?>
                  </span>
                </div>
                <div class="wt-wd-v2__entry-info">
                  <strong><?= e($h['method_label']) ?></strong>
                  <small>
                    <span data-fmt-time data-utc="<?= e($h['created_at']) ?>" data-format="relative">
                      <?= e(wt_format_datetime($h['created_at'])) ?>
                    </span>
                  </small>
                  <?php if ($h['status'] === 'refused' && !empty($h['refused_reason'])): ?>
                    <small class="wt-wd-v2__entry-reason">
                      ⚠️ <?= e($h['refused_reason']) ?>
                    </small>
                  <?php endif; ?>
                </div>
                <div class="wt-wd-v2__entry-amounts">
                  <strong>
                    -<?= e(rtrim(rtrim(number_format((float)$h['coins_amount'], 4, '.', ''), '0'), '.')) ?>
                  </strong>
                  <small>
                    →
                    <?= e(rtrim(rtrim(number_format((float)$h['payout_amount'], 6, '.', ''), '0'), '.')) ?>
                    <?= e($h['payout_currency']) ?>
                  </small>
                  <span class="wt-wd-v2__entry-status">
                    <?= e(t('wd.status.' . $h['status'])) ?>
                  </span>
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
// Update address label/placeholder when method changes (V7 préservé)
(function(){
  var form = document.querySelector('[data-wd-form]');
  if (!form) return;
  var labelEl = form.querySelector('[data-wd-address-label]');
  var input   = form.querySelector('[data-wd-address-input]');
  form.querySelectorAll('input[name="method_id"]').forEach(function(r){
    r.addEventListener('change', function(){
      if (labelEl) labelEl.textContent = r.getAttribute('data-address-label') || '';
      if (input)   input.placeholder    = r.getAttribute('data-address-placeholder') || '';
    });
  });
})();
</script>

<?php include __DIR__ . '/../footer.php'; ?>
