<?php
/**
 * Wintaskly — /tasks/bingo/index.php
 *
 * Page joueur du Bingo (cycle de 7 jours).
 *
 * Affiche :
 *   - L'état de la partie (jackpot, jour, numéros tirés)
 *   - Les cartons du joueur en bande horizontale responsive (style témoignages)
 *   - En-tête B.I.N.G.O coloré + 25 cases (argentées si non activé, numéros
 *     si activé)
 *   - Boutons Activer / Acheter / Réclamer selon l'état
 *   - Notification visuelle si la partie est en fin (ending)
 *
 * Accès : réservé aux utilisateurs connectés ET autorisés (mode test =
 * admins seulement, ou jeu lancé publiquement).
 */
declare(strict_types=1);
require __DIR__ . '/../../includes/init.php';
$u = require_auth();

// Le bingo est-il jouable pour cet utilisateur ?
if (!function_exists('wt_bingo_visible_for') || !wt_bingo_visible_for($u)) {
    // Pas autorisé → redirige vers la liste des tâches
    header('Location: ' . wt_url('/tasks/'));
    exit;
}

// Fait avancer le jeu (lazy) : ouvre/tire/règle si besoin
$round = wt_bingo_tick();

$pageTitle = 'Bingo';
$pageDescription = t('seo.desc.bingo');
$dashActive = 'tasks';

// Données de la partie
$roundId = $round ? (int) $round['id'] : 0;
$cards = $roundId ? wt_bingo_user_cards($roundId, (int) $u['id']) : [];
$allDrawn = $roundId ? wt_bingo_all_drawn($roundId) : [];
$todayDrawn = $roundId ? wt_bingo_today_drawn($roundId) : [];
$todaySet = array_flip($todayDrawn);
$drawnSet = array_flip($allDrawn);

$isEnding = $round && $round['status'] === 'ending';
$isTestMode = function_exists('wt_bingo_is_test_mode') && wt_bingo_is_test_mode();

// L'utilisateur a-t-il déjà réclamé ?
$hasClaimed = false;
foreach ($cards as $c) {
    if ($c['status'] === 'claimed') { $hasClaimed = true; break; }
}

// Combien de cartons actifs (active/claimed) ? Au-delà d'un seul carton en
// jeu (donc un carton payant acheté), la bande du haut affiche les 99
// numéros (tirés colorés + non tirés éteints) au lieu des seuls tirés.
$activeCardCount = 0;
foreach ($cards as $c) {
    if ($c['status'] === 'active' || $c['status'] === 'claimed') { $activeCardCount++; }
}
$showFullBoard = $activeCardCount > 1;

// Lettres B-I-N-G-O avec leurs couleurs
$bingoLetters = [
    ['l' => 'B', 'c' => '#ef4444'],
    ['l' => 'I', 'c' => '#f59e0b'],
    ['l' => 'N', 'c' => '#22c55e'],
    ['l' => 'G', 'c' => '#3b82f6'],
    ['l' => 'O', 'c' => '#a855f7'],
];

include __DIR__ . '/../../header.php';
?>

<main class="wt-main wt-bingo-page">
  <div class="wt-bingo-page__wrap">

    <!-- En-tête + jackpot -->
    <header class="wt-bingo-page__header" data-reveal>
      <div class="wt-bingo-page__title-row">
        <span class="wt-eyebrow">🎰 <?= e(t('bingo.eyebrow')) ?></span>
        <?php if ($isTestMode): ?>
          <span class="wt-bingo-badge-test">MODE TEST</span>
        <?php endif; ?>
      </div>
      <h1 class="wt-bingo-page__title">Bingo</h1>

      <?php if ($round): ?>
        <div class="wt-bingo-jackpot">
          <span class="wt-bingo-jackpot__label"><?= e(t('bingo.jackpot')) ?></span>
          <span class="wt-bingo-jackpot__amount"><?= e(number_format((int)$round['jackpot'], 0, ',', ' ')) ?></span>
          <span class="wt-bingo-jackpot__unit"><?= e(t('common.coins')) ?></span>
        </div>

        <div class="wt-bingo-meta">
          <span>📅 <?= e(sprintf((string) t('bingo.day_of'), (int)$round['days_drawn'], (int)$round['max_days'])) ?></span>
          <span>🔢 <?= e(sprintf((string) t('bingo.numbers_drawn'), count($allDrawn))) ?></span>
        </div>
      <?php endif; ?>
    </header>

    <?php $_ad = wt_ad_zone('bingo_top'); if ($_ad !== ''): ?>
      <div class="wt-ad-zone wt-ad-zone--top" style="margin:1.5rem 0;text-align:center">
        <?= $_ad ?>
      </div>
    <?php endif; ?>

    <!-- Notification de fin de partie -->
    <?php if ($isEnding): ?>
      <div class="wt-bingo-ending" data-reveal>
        <span class="wt-bingo-ending__icon">🏁</span>
        <div>
          <strong><?= e(t('bingo.ending_title')) ?></strong>
          <p><?= e(t('bingo.ending_body')) ?></p>
        </div>
      </div>
    <?php endif; ?>

    <!-- Numéros tirés -->
    <?php if (!empty($allDrawn)): ?>
      <section class="wt-bingo-drawn" data-reveal>
        <h2 class="wt-bingo-drawn__title">
          <?= $showFullBoard ? e(t('bingo.board_title')) : e(t('bingo.drawn_title')) ?>
        </h2>
        <div class="wt-bingo-drawn__legend">
          <span><span class="wt-bingo-dot wt-bingo-dot--today"></span> <?= e(t('bingo.legend_today')) ?></span>
          <?php if ($showFullBoard): ?>
            <span><span class="wt-bingo-dot wt-bingo-dot--old"></span> <?= e(t('bingo.legend_old')) ?></span>
            <span><span class="wt-bingo-dot wt-bingo-dot--none"></span> <?= e(t('bingo.legend_none')) ?></span>
          <?php endif; ?>
        </div>

        <?php if ($showFullBoard):
          // Carton payant supplémentaire actif : vue complète 1..number_max
          // (tirés colorés + non tirés éteints), historique complet visible.
          $numberMax = (int) ($round['number_max'] ?? 99);
        ?>
          <div class="wt-bingo-board">
            <?php for ($n = 1; $n <= $numberMax; $n++):
              if (isset($todaySet[$n]))      { $cls = 'wt-bingo-ball--today'; }
              elseif (isset($drawnSet[$n]))  { $cls = 'wt-bingo-ball--old'; }
              else                           { $cls = 'wt-bingo-ball--none'; }
            ?>
              <span class="wt-bingo-ball <?= $cls ?>"><?= $n ?></span>
            <?php endfor; ?>
          </div>
        <?php else:
          // Pas de carton payant supplémentaire : seuls les numéros tirés
          // AUJOURD'HUI sont visibles — l'historique des jours précédents
          // reste verrouillé tant qu'un carton supplémentaire n'est pas acheté.
        ?>
          <?php if (!empty($todayDrawn)): ?>
            <div class="wt-bingo-drawn__balls">
              <?php foreach ($todayDrawn as $n): ?>
                <span class="wt-bingo-ball wt-bingo-ball--today">
                  <?= (int)$n ?>
                </span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if (count($allDrawn) > count($todayDrawn)): ?>
            <p class="wt-bingo-drawn__locked-hint"><?= e(t('bingo.locked_history_hint')) ?></p>
          <?php endif; ?>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <!-- Bande horizontale des cartons -->
    <section class="wt-bingo-cards" data-reveal>
      <h2 class="wt-bingo-cards__title"><?= e(t('bingo.my_cards')) ?></h2>
      <p class="wt-bingo-cards__hint"><?= e(t('bingo.cards_hint')) ?></p>

      <div class="wt-bingo-cards__track">
        <?php foreach ($cards as $idx => $card):
          $isActive = $card['status'] === 'active';
          $isClaimed = $card['status'] === 'claimed';
          $isVoid = $card['status'] === 'void';
          $isLocked = $card['status'] === 'locked';
          $cardNums = array_map('intval', explode(',', $card['numbers']));
          $marks = array_flip($card['marks']);
          $markedCount = count($card['marks']);
        ?>
          <article class="wt-bingo-card wt-bingo-card--<?= e($card['status']) ?>" style="--idx:<?= (int)$idx ?>" data-card-id="<?= (int)$card['id'] ?>">

            <!-- En-tête B.I.N.G.O -->
            <div class="wt-bingo-card__head">
              <?php foreach ($bingoLetters as $bl): ?>
                <span class="wt-bingo-card__letter" style="--lc:<?= e($bl['c']) ?>"><?= e($bl['l']) ?></span>
              <?php endforeach; ?>
            </div>

            <!-- Corps : 25 cases (5x5) -->
            <div class="wt-bingo-card__grid">
              <?php for ($i = 0; $i < 25; $i++):
                $num = $cardNums[$i] ?? 0;
                $isMarked = isset($marks[$num]);
                /* Numéros cochables : même règle que le tableau au-dessus.
                 *
                 * Sans carton payant supplémentaire, seul le tirage DU JOUR
                 * est visible — l'historique des jours précédents reste
                 * verrouillé. Le carton utilisait ici $drawnSet (tous les
                 * numéros depuis le début de la partie), ce qui révélait
                 * l'historique complet en bleu et permettait de cocher des
                 * numéros anciens : cela contournait entièrement l'intérêt
                 * du carton payant.
                 *
                 * Les cases déjà cochées ($isMarked) restent vertes quoi
                 * qu'il arrive : elles ont été validées par le joueur. */
                $visibleSet = $showFullBoard ? $drawnSet : $todaySet;
                $isDrawn = isset($visibleSet[$num]);
                // Carton non activé : cases argentées vides
                if ($isLocked):
              ?>
                <span class="wt-bingo-cell wt-bingo-cell--empty"></span>
              <?php else: ?>
                <button type="button"
                        class="wt-bingo-cell <?= $isMarked ? 'is-marked' : '' ?> <?= ($isDrawn && !$isMarked && $isActive) ? 'is-drawable' : '' ?>"
                        data-number="<?= (int)$num ?>"
                        <?= ($isActive && $isDrawn && !$isMarked) ? '' : 'disabled' ?>>
                  <?= (int)$num ?>
                </button>
              <?php endif; endfor; ?>
            </div>

            <!-- Pied : statut + action -->
            <div class="wt-bingo-card__foot">
              <?php if ($isLocked): ?>
                <?php
                  // 1er carton (slot 0 ou si aucun gratuit utilisé) = gratuit
                  $freeUsed = 0;
                  foreach ($cards as $cc) { if ((int)$cc['is_free'] === 1) $freeUsed++; }
                  $willBeFree = $freeUsed < wt_bingo_cfg('free_cards', 1);
                  $price = wt_bingo_cfg('card_price_coins', 5000);
                ?>
                <?php if ($willBeFree): ?>
                  <button type="button" class="wt-bingo-btn wt-bingo-btn--activate" data-action="activate" data-card-id="<?= (int)$card['id'] ?>">
                    🎁 <?= e(t('bingo.btn_activate')) ?>
                  </button>
                <?php else: ?>
                  <button type="button" class="wt-bingo-btn wt-bingo-btn--buy" data-action="activate" data-card-id="<?= (int)$card['id'] ?>">
                    🛒 <?= e(sprintf((string) t('bingo.btn_buy'), number_format($price, 0, ',', ' '))) ?>
                  </button>
                <?php endif; ?>
              <?php elseif ($isActive): ?>
                <div class="wt-bingo-card__progress">
                  <span class="wt-bingo-card__count"><?= $markedCount ?>/25</span>
                  <?php if ($markedCount >= 25 && !$hasClaimed && !$isEnding): ?>
                    <button type="button" class="wt-bingo-btn wt-bingo-btn--claim" data-action="claim" data-card-id="<?= (int)$card['id'] ?>">
                      🏆 <?= e(t('bingo.btn_claim')) ?>
                    </button>
                  <?php elseif ($markedCount >= 25 && $isEnding): ?>
                    <button type="button" class="wt-bingo-btn wt-bingo-btn--claim" data-action="claim" data-card-id="<?= (int)$card['id'] ?>">
                      🏆 <?= e(t('bingo.btn_claim')) ?>
                    </button>
                  <?php endif; ?>
                </div>
              <?php elseif ($isClaimed): ?>
                <span class="wt-bingo-card__status wt-bingo-card__status--claimed">
                  ✅ <?= e(t('bingo.status_claimed')) ?>
                </span>
              <?php elseif ($isVoid): ?>
                <span class="wt-bingo-card__status wt-bingo-card__status--void">
                  <?= e(t('bingo.status_void')) ?>
                </span>
              <?php endif; ?>
            </div>

          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <?php
      /* Raccourcis vers les autres tâches : évite de repasser par /tasks/
         pour changer d'activité. La tâche courante est exclue de la liste,
         et le Bingo n'apparaît que s'il est réellement jouable. */
      $wtCurrentTask = 'bingo';
      $wtSwitchTasks = [
        'faucet'     => ['url' => '/tasks/faucet/',     'icon' => '💧', 'label' => 'nav.faucet'],
        'shortlinks' => ['url' => '/tasks/shortlinks/', 'icon' => '🔗', 'label' => 'nav.shortlinks'],
        'ptc'        => ['url' => '/tasks/ptc/',        'icon' => '📺', 'label' => 'nav.ptc'],
        'offerwalls' => ['url' => '/tasks/offerwalls/', 'icon' => '🎁', 'label' => 'nav.offerwalls'],
      ];
      if (function_exists('wt_bingo_visible_for') && wt_bingo_visible_for($u ?? null)) {
        $wtSwitchTasks['bingo'] = ['url' => '/tasks/bingo/', 'icon' => '🎲', 'label' => 'nav.bingo'];
      }
      unset($wtSwitchTasks[$wtCurrentTask]);
    ?>
    <nav class="wt-task-switch" aria-label="<?= e(t('tasks.switch_title')) ?>">
      <span class="wt-task-switch__label"><?= e(t('tasks.switch_title')) ?></span>
      <div class="wt-task-switch__links">
        <?php foreach ($wtSwitchTasks as $wtSw): ?>
          <a class="wt-task-switch__link" href="<?= e(wt_url($wtSw['url'])) ?>">
            <span aria-hidden="true"><?= $wtSw['icon'] ?></span>
            <?= e(t($wtSw['label'])) ?>
          </a>
        <?php endforeach; ?>
        <a class="wt-task-switch__link wt-task-switch__link--all" href="<?= e(wt_url('/tasks/')) ?>">
          <span aria-hidden="true">🎯</span> <?= e(t('tasks.switch_all')) ?>
        </a>
      </div>
    </nav>

    <section class="wt-task-how wt-task-how--bingo" data-reveal>
      <h2 class="wt-task-how__title"><?= e(t('bingo.how_title')) ?></h2>
      <div class="wt-task-how__steps">
        <div class="wt-task-how__step">
          <span class="wt-task-how__num">1</span>
          <div>
            <strong><?= e(t('bingo.how_step1_t')) ?></strong>
            <p><?= e(t('bingo.how_step1_d')) ?></p>
          </div>
        </div>
        <div class="wt-task-how__step">
          <span class="wt-task-how__num">2</span>
          <div>
            <strong><?= e(t('bingo.how_step2_t')) ?></strong>
            <p><?= e(t('bingo.how_step2_d')) ?></p>
          </div>
        </div>
        <div class="wt-task-how__step">
          <span class="wt-task-how__num">3</span>
          <div>
            <strong><?= e(t('bingo.how_step3_t')) ?></strong>
            <p><?= e(t('bingo.how_step3_d')) ?></p>
          </div>
        </div>
      </div>
      <p class="wt-task-how__tip">💡 <?= e(t('bingo.how_tip')) ?></p>
    </section>

  </div>
</main>

<script>
window.WT_BINGO = {
  apiUrl: <?= json_encode(wt_url('/api/bingo_action.php')) ?>,
  csrf: <?= json_encode(csrf_token()) ?>,
  i18n: {
    err: <?= json_encode((string) t('common.error')) ?>,
    claimed: <?= json_encode((string) t('bingo.toast_claimed')) ?>,
    bought: <?= json_encode((string) t('bingo.toast_bought')) ?>,
    activated: <?= json_encode((string) t('bingo.toast_activated')) ?>
  }
};
</script>
<script src="<?= e(wt_url('/media/wintaskly/js/bingo.js')) ?>?v=<?= e(WT_VERSION) ?>"></script>

<?php include __DIR__ . '/../../footer.php'; ?>
