<?php
/**
 * Wintaskly — Admin · Configuration du Faucet (V8 modernisé).
 *
 * Permet d'éditer en direct les valeurs de la table `config` :
 *   - faucet_reward_coins        (récompense)
 *   - faucet_reward_xp           (récompense)
 *   - faucet_cooldown_seconds    (3h par défaut)
 *   - faucet_transition_seconds  (10-15s)
 *   - faucet_session_ttl_seconds (5 min strict)
 *
 * V8 :
 *   - Form découpé en 2 cards "Récompense" + "Sécurité & Timing"
 *   - Hints sous chaque champ pour expliquer son rôle
 *   - Préview en pill du cooldown converti en h/m
 *   - Animation reveal cards
 */
require __DIR__ . '/../includes/init.php';
require_admin();

$pageTitle    = t('admin.title') . ' — ' . t('admin.faucet');
$adminActive  = 'faucet';
$db           = db();
$notice       = null;

$keys = [
    'faucet_reward_coins'        => ['min' => 0,   'step' => '0.0001'],
    'faucet_reward_xp'           => ['min' => 0,   'step' => '1'],
    'faucet_cooldown_seconds'    => ['min' => 60,  'step' => '60'],
    'faucet_transition_seconds'  => ['min' => 5,   'step' => '1'],
    'faucet_session_ttl_seconds' => ['min' => 60,  'step' => '30'],
];

/* ---------- POST : sauvegarde ----------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['_csrf'] ?? null)) {
    $stmt = $db->prepare(
        "INSERT INTO config (k,v) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE v = VALUES(v)"
    );
    foreach (array_keys($keys) as $k) {
        if (!array_key_exists($k, $_POST)) continue;
        $val = trim((string) $_POST[$k]);
        if ($val === '') continue;
        // Cast numérique défensif
        if ($k === 'faucet_reward_coins') {
            $val = number_format((float) $val, 4, '.', '');
        } else {
            $val = (string) max(0, (int) $val);
        }
        $stmt->bind_param('ss', $k, $val);
        $stmt->execute();
    }
    $stmt->close();
    $notice = t('admin.saved');
}

/* ---------- Lecture valeurs courantes --------------------------------- */
$values = [];
foreach (array_keys($keys) as $k) {
    $values[$k] = (string) cfg($k, '');
}

/* Helper : convertir secondes en "Xh Ym" pour preview */
$humanize = static function (int $s): string {
    if ($s <= 0) return '—';
    $h = (int) floor($s / 3600);
    $m = (int) floor(($s % 3600) / 60);
    if ($h > 0 && $m > 0) return $h . 'h ' . $m . 'min';
    if ($h > 0)           return $h . 'h';
    return $m . 'min';
};

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>

    <section class="wt-admin-v2__content" data-reveal>

      <!-- ====== HEADER ====== -->
      <header class="wt-admin-v2__page-header">
        <span class="wt-eyebrow">💧 <?= e(t('admin.eyebrow_faucet')) ?></span>
        <h1 class="wt-admin-v2__title"><?= e(t('admin.faucet')) ?></h1>
        <p class="wt-muted"><?= e(t('admin.faucet.lead')) ?></p>
      </header>

      <?php if ($notice): ?>
        <div class="wt-alert wt-alert--success" data-reveal>
          ✓ <?= e($notice) ?>
        </div>
      <?php endif; ?>

      <form method="post" class="wt-admin-v2__form">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

        <!-- ====== Card 1 : Récompense ====== -->
        <article class="wt-admin-v2__card" data-reveal style="--idx:0">
          <header class="wt-admin-v2__card-head">
            <span class="wt-admin-v2__card-icon" aria-hidden="true">🎁</span>
            <div>
              <h2><?= e(t('admin.faucet.section_reward')) ?></h2>
              <small class="wt-muted"><?= e(t('admin.faucet.section_reward_lead')) ?></small>
            </div>
          </header>

          <div class="wt-admin-v2__grid-2">
            <label class="wt-field">
              <span class="wt-field__label">💰 <?= e(t('admin.faucet.reward_coins')) ?></span>
              <input class="wt-input"
                     type="number"
                     name="faucet_reward_coins"
                     min="<?= e((string)$keys['faucet_reward_coins']['min']) ?>"
                     step="<?= e((string)$keys['faucet_reward_coins']['step']) ?>"
                     value="<?= e($values['faucet_reward_coins']) ?>"
                     required>
              <small class="wt-field__hint"><?= e(t('admin.faucet.reward_coins_hint')) ?></small>
            </label>

            <label class="wt-field">
              <span class="wt-field__label">⚡ <?= e(t('admin.faucet.reward_xp')) ?></span>
              <input class="wt-input"
                     type="number"
                     name="faucet_reward_xp"
                     min="<?= e((string)$keys['faucet_reward_xp']['min']) ?>"
                     step="<?= e((string)$keys['faucet_reward_xp']['step']) ?>"
                     value="<?= e($values['faucet_reward_xp']) ?>"
                     required>
              <small class="wt-field__hint"><?= e(t('admin.faucet.reward_xp_hint')) ?></small>
            </label>
          </div>
        </article>

        <!-- ====== Card 2 : Sécurité & Timing ====== -->
        <article class="wt-admin-v2__card" data-reveal style="--idx:1">
          <header class="wt-admin-v2__card-head">
            <span class="wt-admin-v2__card-icon" aria-hidden="true">⏱</span>
            <div>
              <h2><?= e(t('admin.faucet.section_timing')) ?></h2>
              <small class="wt-muted"><?= e(t('admin.faucet.section_timing_lead')) ?></small>
            </div>
          </header>

          <div class="wt-admin-v2__grid-3">
            <label class="wt-field">
              <span class="wt-field__label">
                🕐 <?= e(t('admin.faucet.cooldown')) ?>
                <span class="wt-admin-v2__preview">
                  ≈ <?= e($humanize((int) $values['faucet_cooldown_seconds'])) ?>
                </span>
              </span>
              <input class="wt-input"
                     type="number"
                     name="faucet_cooldown_seconds"
                     min="<?= e((string)$keys['faucet_cooldown_seconds']['min']) ?>"
                     step="<?= e((string)$keys['faucet_cooldown_seconds']['step']) ?>"
                     value="<?= e($values['faucet_cooldown_seconds']) ?>"
                     required>
              <small class="wt-field__hint"><?= e(t('admin.faucet.cooldown_hint')) ?></small>
            </label>

            <label class="wt-field">
              <span class="wt-field__label">⏳ <?= e(t('admin.faucet.transition')) ?></span>
              <input class="wt-input"
                     type="number"
                     name="faucet_transition_seconds"
                     min="<?= e((string)$keys['faucet_transition_seconds']['min']) ?>"
                     step="<?= e((string)$keys['faucet_transition_seconds']['step']) ?>"
                     value="<?= e($values['faucet_transition_seconds']) ?>"
                     required>
              <small class="wt-field__hint"><?= e(t('admin.faucet.transition_hint')) ?></small>
            </label>

            <label class="wt-field">
              <span class="wt-field__label">
                🔒 <?= e(t('admin.faucet.ttl')) ?>
                <span class="wt-admin-v2__preview">
                  ≈ <?= e($humanize((int) $values['faucet_session_ttl_seconds'])) ?>
                </span>
              </span>
              <input class="wt-input"
                     type="number"
                     name="faucet_session_ttl_seconds"
                     min="<?= e((string)$keys['faucet_session_ttl_seconds']['min']) ?>"
                     step="<?= e((string)$keys['faucet_session_ttl_seconds']['step']) ?>"
                     value="<?= e($values['faucet_session_ttl_seconds']) ?>"
                     required>
              <small class="wt-field__hint"><?= e(t('admin.faucet.ttl_hint')) ?></small>
            </label>
          </div>
        </article>

        <!-- ====== Submit ====== -->
        <div class="wt-admin-v2__form-actions">
          <button type="submit" class="wt-btn wt-btn--primary wt-btn--lg">
            💾 <?= e(t('common.save')) ?>
          </button>
          <a class="wt-btn wt-btn--ghost"
             href="<?= e(wt_url('/tasks/faucet/')) ?>" target="_blank" rel="noopener">
            👁 <?= e(t('admin.preview_public')) ?>
          </a>
        </div>
      </form>

    </section>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
