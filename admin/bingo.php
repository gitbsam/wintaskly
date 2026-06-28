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
$error       = null;

/* ====================== ACTIONS POST ====================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['_csrf'] ?? null)) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'toggle_test') {
        // Bascule rapide du mode test
        $cur = (string) cfg('bingo.test_mode', '1') === '1';
        wt_config_set('bingo.test_mode', $cur ? '0' : '1');
        $notice = $cur ? t('admin.bingo.test_off') : t('admin.bingo.test_on');

    } elseif ($action === 'force_draw') {
        // Déclenche manuellement le tirage du jour
        $r = wt_bingo_force_draw();
        if ($r['ok']) {
            $notice = t('admin.bingo.draw_done');
        } else {
            // Messages d'erreur spécifiques
            $map = [
                'already_drawn_or_max' => t('admin.bingo.draw_already'),
                'not_active'           => t('admin.bingo.draw_not_active'),
                'no_round'             => t('admin.bingo.draw_no_round'),
                'disabled'             => t('admin.bingo.draw_disabled'),
            ];
            $error = $map[$r['message']] ?? t('common.error');
        }

    } elseif ($action === 'save_visibility') {
        wt_config_set('bingo.enabled', !empty($_POST['enabled']) ? '1' : '0');
        wt_config_set('bingo.test_mode', !empty($_POST['test_mode']) ? '1' : '0');
        wt_config_set('bingo.coming_soon', !empty($_POST['coming_soon']) ? '1' : '0');
        // Date de lancement : le champ caché porte déjà l'heure en UTC
        // (converti par wt-bingo-launch.js depuis la saisie locale de l'admin).
        // Format attendu : 'Y-m-d\TH:i' ou 'Y-m-d H:i'. On l'interprète en UTC.
        $launch = trim((string)($_POST['launch_at'] ?? ''));
        if ($launch !== '') {
            $launch = str_replace('T', ' ', $launch);
            $ts = strtotime($launch . ' UTC');
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
// Valeur UTC au format datetime-local (Y-m-d\TH:i). Le JS la convertit en
// heure locale pour l'affichage et inversement à la soumission.
$launchUtc = '';
if ($launchRaw !== '') {
    $ts = strtotime($launchRaw . ' UTC');
    if ($ts) { $launchUtc = gmdate('Y-m-d\TH:i', $ts); }
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
      <?php if ($error): ?><div class="wt-alert wt-alert--error"><?= e($error) ?></div><?php endif; ?>

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
          <?php
            // Conversion de fuseau gérée en JS : le serveur stocke/lit en UTC,
            // mais l'admin saisit dans SON fuseau local. data-utc porte la valeur
            // UTC source ; wt-bingo-launch.js remplit le champ visible en heure
            // locale au chargement, et écrit la valeur UTC dans le champ caché à
            // la soumission. $launchUtc = 'Y-m-d\TH:i' UTC (ou '' si non défini).
          ?>
          <input class="wt-input" type="datetime-local"
                 data-dt-local
                 data-dt-target="launch_at"
                 data-utc="<?= e($launchUtc) ?>"
                 style="max-width:280px">
          <input type="hidden" name="launch_at" value="<?= e($launchUtc) ?>">
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

        <?php
          // Numéros déjà tirés sur le cycle en cours
          $rDrawn = wt_bingo_all_drawn((int)$round['id']);
        ?>
        <?php if (!empty($rDrawn)): ?>
          <div style="margin-top:1rem">
            <small class="wt-muted"><?= e(t('admin.bingo.drawn_so_far')) ?> (<?= count($rDrawn) ?>)</small><br>
            <code style="font-size:.8rem;line-height:1.8"><?= e(implode(', ', $rDrawn)) ?></code>
          </div>
        <?php endif; ?>

        <!-- Bouton tirage manuel -->
        <?php if ($round['status'] === 'active'): ?>
          <form method="post" style="margin-top:1rem" onsubmit="return confirm('<?= e(t('admin.bingo.draw_confirm')) ?>');">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="force_draw">
            <button class="wt-btn wt-btn--ghost">🎲 <?= e(t('admin.bingo.btn_force_draw')) ?></button>
            <small class="wt-muted" style="display:block;margin-top:.4rem"><?= e(t('admin.bingo.force_draw_hint')) ?></small>
          </form>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <!-- HISTORIQUE DES PARTIES -->
      <?php
        $history = function_exists('wt_bingo_recent_rounds') ? wt_bingo_recent_rounds(15) : [];
      ?>
      <?php if (!empty($history)): ?>
      <section class="wt-card wt-card--padded" style="margin-bottom:1.5rem">
        <h2 style="margin-top:0">📜 <?= e(t('admin.bingo.history_title')) ?></h2>
        <div style="overflow-x:auto">
          <table class="wt-table" style="width:100%;font-size:.88rem">
            <thead>
              <tr>
                <th>#</th>
                <th><?= e(t('admin.bingo.h_started')) ?></th>
                <th><?= e(t('admin.bingo.h_status')) ?></th>
                <th><?= e(t('admin.bingo.h_draws')) ?></th>
                <th><?= e(t('admin.bingo.h_players')) ?></th>
                <th><?= e(t('admin.bingo.h_jackpot')) ?></th>
                <th><?= e(t('admin.bingo.h_winners')) ?></th>
                <th><?= e(t('admin.bingo.h_reward')) ?></th>
                <th><?= e(t('admin.bingo.h_end')) ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($history as $h):
                $endLabels = [
                    ''          => '—',
                    'max_days'  => t('admin.bingo.end_max_days'),
                    'claim'     => t('admin.bingo.end_claim'),
                    'auto_full' => t('admin.bingo.end_auto_full'),
                ];
                $statusLabels = [
                    'active'  => '🟢 ' . t('admin.bingo.status_active'),
                    'ending'  => '🟠 ' . t('admin.bingo.status_ending'),
                    'settled' => '⚪ ' . t('admin.bingo.status_settled'),
                ];
              ?>
                <tr>
                  <td><strong><?= (int)$h['id'] ?></strong></td>
                  <td><?= e(wt_format_datetime($h['started_on'], 'd/m/Y')) ?></td>
                  <td><?= e($statusLabels[$h['status']] ?? $h['status']) ?></td>
                  <td><?= (int)$h['draws_count'] ?>/<?= (int)$h['max_days'] ?></td>
                  <td><?= (int)$h['players_count'] ?></td>
                  <td><?= e(number_format((int)$h['jackpot'],0,',',' ')) ?></td>
                  <td><?= (int)$h['winners_count'] ?></td>
                  <td><?= $h['reward_each'] > 0 ? e(number_format((int)$h['reward_each'],0,',',' ')) : '—' ?></td>
                  <td><small><?= e($endLabels[$h['end_reason']] ?? '—') ?></small></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
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

<script src="<?= e(wt_url('/media/wintaskly/js/wt-datetime-utc.js')) ?>?v=<?= e(WT_VERSION) ?>"></script>
<?php include __DIR__ . '/../footer.php'; ?>
