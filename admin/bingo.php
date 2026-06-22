<?php
/**
 * Wintaskly — Admin · Bingo
 *
 * Pilotage du jeu Bingo :
 *   - Mode test (visible admins seulement) ← bouton Activer/Désactiver
 *   - Lancement programmé (date) + teaser "bientôt"
 *   - Configuration complète (cartons, prix, jackpot, tirage)
 *   - État de la partie en cours + statistiques
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_admin();

$pageTitle   = t('admin.title') . ' — Bingo';
$adminActive = 'bingo';
$notice      = null;

/* ====================== ACTIONS POST ====================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['_csrf'] ?? null)) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'toggle_test') {
        // Bascule rapide du mode test
        $cur = (string) cfg('bingo.test_mode', '1') === '1';
        wt_config_set('bingo.test_mode', $cur ? '0' : '1');
        $notice = $cur ? t('admin.bingo.test_off') : t('admin.bingo.test_on');

    } elseif ($action === 'save_visibility') {
        wt_config_set('bingo.enabled', !empty($_POST['enabled']) ? '1' : '0');
        wt_config_set('bingo.test_mode', !empty($_POST['test_mode']) ? '1' : '0');
        wt_config_set('bingo.coming_soon', !empty($_POST['coming_soon']) ? '1' : '0');
        // Date de lancement : champ datetime-local → 'Y-m-d H:i' UTC
        $launch = trim((string)($_POST['launch_at'] ?? ''));
        if ($launch !== '') {
            $ts = strtotime($launch);
            $launch = $ts !== false ? gmdate('Y-m-d H:i', $ts) : '';
        }
        wt_config_set('bingo.launch_at', $launch);
        $notice = t('admin.bingo.saved');

    } elseif ($action === 'save_config') {
        wt_config_set('bingo.max_days', (string) max(1, min(30, (int)($_POST['max_days'] ?? 7))));
        wt_config_set('bingo.cards_per_user', (string) max(1, min(20, (int)($_POST['cards_per_user'] ?? 5))));
        wt_config_set('bingo.free_cards', (string) max(0, min(10, (int)($_POST['free_cards'] ?? 1))));
        wt_config_set('bingo.card_price_coins', (string) max(0, (int)($_POST['card_price_coins'] ?? 5000)));
        wt_config_set('bingo.draw_count', (string) max(1, min(50, (int)($_POST['draw_count'] ?? 14))));
        wt_config_set('bingo.number_max', (string) max(25, min(99, (int)($_POST['number_max'] ?? 99))));
        wt_config_set('bingo.jackpot_base', (string) max(0, (int)($_POST['jackpot_base'] ?? 30000)));
        wt_config_set('bingo.jackpot_growth_pct', (string) max(0, min(1000, (int)($_POST['jackpot_growth_pct'] ?? 25))));
        wt_config_set('bingo.jackpot_carryover', !empty($_POST['jackpot_carryover']) ? '1' : '0');
        $notice = t('admin.bingo.saved');
    }
}

/* ====================== LECTURE ====================== */
$testMode   = (string) cfg('bingo.test_mode', '1') === '1';
$enabled    = (string) cfg('bingo.enabled', '0') === '1';
$comingSoon = (string) cfg('bingo.coming_soon', '0') === '1';
$launchRaw  = (string) cfg('bingo.launch_at', '');
$launchLocal = '';
if ($launchRaw !== '') {
    $ts = strtotime($launchRaw . ' UTC');
    if ($ts) { $launchLocal = date('Y-m-d\TH:i', $ts); }
}

$cfgVals = [
    'max_days'           => wt_bingo_cfg('max_days', 7),
    'cards_per_user'     => wt_bingo_cfg('cards_per_user', 5),
    'free_cards'         => wt_bingo_cfg('free_cards', 1),
    'card_price_coins'   => wt_bingo_cfg('card_price_coins', 5000),
    'draw_count'         => wt_bingo_cfg('draw_count', 14),
    'number_max'         => wt_bingo_cfg('number_max', 99),
    'jackpot_base'       => wt_bingo_cfg('jackpot_base', 30000),
    'jackpot_growth_pct' => wt_bingo_cfg('jackpot_growth_pct', 25),
    'jackpot_carryover'  => wt_bingo_cfg('jackpot_carryover', 1),
];

// Partie en cours + stats
$round = function_exists('wt_bingo_current_round') ? wt_bingo_current_round() : null;
$stats = $round ? wt_bingo_round_stats((int)$round['id']) : null;

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
    <section class="wt-admin-v2__content" data-reveal>

      <header class="wt-admin-v2__page-header">
        <div>
          <span class="wt-eyebrow">🎰 Bingo</span>
          <h1 class="wt-admin-v2__title">Bingo</h1>
          <p class="wt-muted"><?= e(t('admin.bingo.lead')) ?></p>
        </div>
      </header>

      <?php if ($notice): ?><div class="wt-alert wt-alert--success"><?= e($notice) ?></div><?php endif; ?>

      <!-- ÉTAT + BASCULE MODE TEST -->
      <section class="wt-card wt-card--padded" style="margin-bottom:1.5rem">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
          <div>
            <h2 style="margin:0 0 .35rem">
              <?= $testMode ? '🔒 ' . e(t('admin.bingo.mode_test')) : '🌍 ' . e(t('admin.bingo.mode_public')) ?>
            </h2>
            <p class="wt-muted" style="margin:0;font-size:.9rem">
              <?= $testMode ? e(t('admin.bingo.test_desc')) : e(t('admin.bingo.public_desc')) ?>
            </p>
          </div>
          <form method="post" style="margin:0">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="toggle_test">
            <button class="wt-btn <?= $testMode ? 'wt-btn--primary' : 'wt-btn--ghost' ?>">
              <?= $testMode ? '🌍 ' . e(t('admin.bingo.btn_go_public')) : '🔒 ' . e(t('admin.bingo.btn_go_test')) ?>
            </button>
          </form>
        </div>
      </section>

      <!-- VISIBILITÉ & LANCEMENT -->
      <form method="post" class="wt-card wt-card--padded" style="margin-bottom:1.5rem">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_visibility">
        <h2 style="margin-top:0">👁️ <?= e(t('admin.bingo.visibility_title')) ?></h2>

        <label class="wt-checkbox" style="display:flex;gap:.75rem;align-items:flex-start;margin-bottom:1rem">
          <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?> style="margin-top:.3rem;transform:scale(1.4)">
          <span><strong><?= e(t('admin.bingo.enable')) ?></strong><br><small class="wt-muted"><?= e(t('admin.bingo.enable_hint')) ?></small></span>
        </label>

        <label class="wt-checkbox" style="display:flex;gap:.75rem;align-items:flex-start;margin-bottom:1rem">
          <input type="checkbox" name="test_mode" value="1" <?= $testMode ? 'checked' : '' ?> style="margin-top:.3rem;transform:scale(1.4)">
          <span><strong><?= e(t('admin.bingo.f_test_mode')) ?></strong><br><small class="wt-muted"><?= e(t('admin.bingo.f_test_mode_hint')) ?></small></span>
        </label>

        <label class="wt-checkbox" style="display:flex;gap:.75rem;align-items:flex-start;margin-bottom:1rem">
          <input type="checkbox" name="coming_soon" value="1" <?= $comingSoon ? 'checked' : '' ?> style="margin-top:.3rem;transform:scale(1.4)">
          <span><strong><?= e(t('admin.bingo.f_coming_soon')) ?></strong><br><small class="wt-muted"><?= e(t('admin.bingo.f_coming_soon_hint')) ?></small></span>
        </label>

        <label class="wt-field">
          <span class="wt-field__label"><?= e(t('admin.bingo.f_launch')) ?></span>
          <input class="wt-input" type="datetime-local" name="launch_at" value="<?= e($launchLocal) ?>" style="max-width:280px">
          <small class="wt-field__hint"><?= e(t('admin.bingo.f_launch_hint')) ?></small>
        </label>

        <button class="wt-btn wt-btn--primary" style="margin-top:1rem"><?= e(t('common.save')) ?></button>
      </form>

      <!-- ÉTAT DE LA PARTIE -->
      <?php if ($round): ?>
      <section class="wt-card wt-card--padded" style="margin-bottom:1.5rem">
        <h2 style="margin-top:0">🎲 <?= e(t('admin.bingo.current_game')) ?></h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:1rem">
          <div><small class="wt-muted"><?= e(t('admin.bingo.st_status')) ?></small><br><strong><?= e($round['status']) ?></strong></div>
          <div><small class="wt-muted"><?= e(t('admin.bingo.st_day')) ?></small><br><strong><?= (int)$round['days_drawn'] ?>/<?= (int)$round['max_days'] ?></strong></div>
          <div><small class="wt-muted"><?= e(t('admin.bingo.st_jackpot')) ?></small><br><strong><?= e(number_format((int)$round['jackpot'],0,',',' ')) ?></strong></div>
          <?php if ($stats): ?>
          <div><small class="wt-muted"><?= e(t('admin.bingo.st_players')) ?></small><br><strong><?= (int)$stats['players'] ?></strong></div>
          <div><small class="wt-muted"><?= e(t('admin.bingo.st_paid')) ?></small><br><strong><?= (int)$stats['cards_paid'] ?></strong></div>
          <div><small class="wt-muted"><?= e(t('admin.bingo.st_claims')) ?></small><br><strong><?= (int)$stats['claims'] ?></strong></div>
          <?php endif; ?>
        </div>
      </section>
      <?php endif; ?>

      <!-- CONFIGURATION -->
      <form method="post" class="wt-card wt-card--padded">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_config">
        <h2 style="margin-top:0">⚙️ <?= e(t('admin.bingo.config_title')) ?></h2>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem">
          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.bingo.f_max_days')) ?></span>
            <input class="wt-input" type="number" min="1" max="30" name="max_days" value="<?= (int)$cfgVals['max_days'] ?>">
          </label>
          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.bingo.f_cards')) ?></span>
            <input class="wt-input" type="number" min="1" max="20" name="cards_per_user" value="<?= (int)$cfgVals['cards_per_user'] ?>">
          </label>
          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.bingo.f_free')) ?></span>
            <input class="wt-input" type="number" min="0" max="10" name="free_cards" value="<?= (int)$cfgVals['free_cards'] ?>">
          </label>
          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.bingo.f_price')) ?></span>
            <input class="wt-input" type="number" min="0" name="card_price_coins" value="<?= (int)$cfgVals['card_price_coins'] ?>">
            <small class="wt-field__hint"><?= e(t('admin.bingo.f_price_hint')) ?></small>
          </label>
          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.bingo.f_draw')) ?></span>
            <input class="wt-input" type="number" min="1" max="50" name="draw_count" value="<?= (int)$cfgVals['draw_count'] ?>">
          </label>
          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.bingo.f_number_max')) ?></span>
            <input class="wt-input" type="number" min="25" max="99" name="number_max" value="<?= (int)$cfgVals['number_max'] ?>">
          </label>
          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.bingo.f_jackpot_base')) ?></span>
            <input class="wt-input" type="number" min="0" name="jackpot_base" value="<?= (int)$cfgVals['jackpot_base'] ?>">
          </label>
          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.bingo.f_growth')) ?></span>
            <input class="wt-input" type="number" min="0" max="1000" name="jackpot_growth_pct" value="<?= (int)$cfgVals['jackpot_growth_pct'] ?>">
            <small class="wt-field__hint"><?= e(t('admin.bingo.f_growth_hint')) ?></small>
          </label>
        </div>

        <label class="wt-checkbox" style="display:flex;gap:.75rem;align-items:center;margin-top:1rem">
          <input type="checkbox" name="jackpot_carryover" value="1" <?= $cfgVals['jackpot_carryover'] ? 'checked' : '' ?> style="transform:scale(1.3)">
          <span><strong><?= e(t('admin.bingo.f_carryover')) ?></strong></span>
        </label>

        <button class="wt-btn wt-btn--primary" style="margin-top:1rem"><?= e(t('common.save')) ?></button>
      </form>

    </section>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
