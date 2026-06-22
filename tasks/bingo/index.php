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

$pageTitle = 'Bingo — ' . t('site_name');
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
          <span><span class="wt-bingo-dot wt-bingo-dot--old"></span> <?= e(t('bingo.legend_old')) ?></span>
          <?php if ($showFullBoard): ?>
            <span><span class="wt-bingo-dot wt-bingo-dot--none"></span> <?= e(t('bingo.legend_none')) ?></span>
          <?php endif; ?>
        </div>

        <?php if ($showFullBoard):
          // Vue complète 1..number_max : tirés colorés, non tirés éteints
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
          // Vue simple : seulement les numéros tirés
        ?>
          <div class="wt-bingo-drawn__balls">
            <?php foreach ($allDrawn as $n): ?>
              <span class="wt-bingo-ball <?= isset($todaySet[$n]) ? 'wt-bingo-ball--today' : 'wt-bingo-ball--old' ?>">
                <?= (int)$n ?>
              </span>
            <?php endforeach; ?>
          </div>
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
                $isDrawn = isset($drawnSet[$num]);
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
