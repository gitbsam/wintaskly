<?php
/**
 * Wintaskly — tasks/gift/index.php
 *
 * Boîte à cadeaux, côté joueur.
 *
 * La grille affiche N boîtes. Celles déjà ouvertes sont grisées et
 * portent leur lot : voir ce que les autres ont trouvé donne envie de
 * jouer, et cela prouve accessoirement que la grille distribue vraiment.
 *
 * Le contenu des boîtes fermées n'est jamais envoyé au navigateur. Il se
 * lirait dans le code source, et on choisirait la meilleure.
 */
declare(strict_types=1);
require __DIR__ . '/../../includes/init.php';

$u = require_auth();

$pageTitle = t('gift.page_title');

if (!wt_gift_visible_for($u)) {
    include __DIR__ . '/../../header.php';
    echo '<main class="wt-main wt-gift"><div class="wt-gift__wrap">'
       . '<div class="wt-gift__empty">' . e(t('gift.err_disabled')) . '</div>'
       . '</div></main>';
    include __DIR__ . '/../../footer.php';
    exit;
}

$userId = (int) $u['id'];
$round  = wt_gift_current_round();
$boxes  = [];

if ($round) {
    try {
        /* On ne lit QUE ce qui peut être montré. `coins` et `xp` ne
           sortent de la base que pour les boîtes déjà ouvertes ; pour
           les autres, la requête ne les rend même pas. */
        $res = db()->query(
            "SELECT id, position, opened_by,
                    IF(opened_by IS NULL, NULL, kind)       AS kind,
                    IF(opened_by IS NULL, NULL, coins_paid) AS coins_paid,
                    IF(opened_by IS NULL, NULL, xp)         AS xp
               FROM gift_boxes
              WHERE round_id = " . (int) $round['id'] . "
              ORDER BY position"
        );
        while ($b = $res->fetch_assoc()) { $boxes[] = $b; }
        $res->free();
    } catch (Throwable $e) {
        error_log('[Wintaskly gift] ' . $e->getMessage());
    }
}

$solde   = wt_tickets_balance($userId);
$libelle = wt_instant_ticket_label();
$jackpot = (float) cfg('gift.jackpot', 0);
$ouvertes = 0;
foreach ($boxes as $b) { if ($b['opened_by'] !== null) { $ouvertes++; } }

include __DIR__ . '/../../header.php';
?>

<main class="wt-main wt-gift" data-gift
      data-label-open="<?= e(t('gift.opening')) ?>"
      data-label-wait="<?= e(t('gift.wait_tab')) ?>"
      data-label-back="<?= e(t('gift.came_back')) ?>"
      data-label-boost="<?= e(t('gift.boost_btn')) ?>"
      data-label-keep="<?= e(t('gift.keep_btn')) ?>"
      data-label-close="<?= e(t('common.close')) ?>"
      data-label-retry="<?= e(t('gift.retry')) ?>"
      data-label-offer="<?= e(t('gift.offer_prefix')) ?>"
      data-label-popup="<?= e(t('gift.popup_blocked')) ?>">
  <div class="wt-gift__wrap">

    <header class="wt-gift__head" data-reveal>
      <h1 class="wt-gift__title">🎁 <?= e(t('gift.page_title')) ?></h1>
      <p class="wt-muted"><?= e(t('gift.page_lead')) ?></p>
      <?php if ((string) cfg('gift.test_mode', '1') === '1'): ?>
        <p class="wt-gift__test"><?= e(t('gift.test_badge')) ?></p>
      <?php endif; ?>
    </header>

    <?= wt_ad_zone('gift_top') ?>

    <?php if (!$round || !$boxes): ?>
      <div class="wt-gift__empty" data-reveal><?= e(t('gift.none')) ?></div>
    <?php else: ?>

      <section class="wt-gift__bar" data-reveal>
        <div>
          <strong><?= $ouvertes ?>/<?= count($boxes) ?></strong>
          <small class="wt-muted"><?= e(t('gift.opened')) ?></small>
        </div>
        <?php if ($jackpot > 0): ?>
          <div>
            <strong><?= e(number_format($jackpot, 0, ',', ' ')) ?></strong>
            <small class="wt-muted"><?= e(t('gift.jackpot')) ?></small>
          </div>
        <?php endif; ?>
        <div>
          <strong data-gift-tickets><?= (int) $solde ?></strong>
          <small class="wt-muted"><?= e($libelle) ?>s</small>
        </div>
      </section>

      <div class="wt-gift-grid" data-reveal>
        <?php foreach ($boxes as $b):
          $ouverte = ($b['opened_by'] !== null);
          $mienne  = ((int) $b['opened_by'] === $userId);
        ?>
          <button type="button"
                  class="wt-gift-box<?= $ouverte ? ' is-open' : '' ?><?= $mienne ? ' is-mine' : '' ?>"
                  data-gift-box="<?= (int) $b['id'] ?>"
                  <?= $ouverte ? 'disabled' : '' ?>>
            <?php if (!$ouverte): ?>
              <span class="wt-gift-box__n"><?= (int) $b['position'] ?></span>
            <?php elseif ((string) $b['kind'] === 'xp'): ?>
              <span class="wt-gift-box__v">+<?= (int) $b['xp'] ?></span>
              <span class="wt-gift-box__u">XP</span>
            <?php else: ?>
              <span class="wt-gift-box__v<?= in_array((string) $b['kind'], ['big','jackpot'], true) ? ' is-big' : '' ?>">
                <?= e(number_format((float) $b['coins_paid'], 0, ',', ' ')) ?>
              </span>
              <span class="wt-gift-box__u">c</span>
            <?php endif; ?>
          </button>
        <?php endforeach; ?>
      </div>

      <?php /* Surimpression du resultat. Construite vide : son contenu
               vient du serveur, jamais de la page. */ ?>
      <div class="wt-gift-overlay" data-gift-overlay hidden>
        <div class="wt-gift-overlay__card" role="dialog" aria-modal="true"
             aria-labelledby="giftTitle">
          <button type="button" class="wt-gift-overlay__x" data-gift-close
                  aria-label="<?= e(t('common.close')) ?>">×</button>
          <div class="wt-gift-overlay__body" data-gift-body>
            <h2 id="giftTitle" class="wt-gift-overlay__title" data-gift-title></h2>
            <p class="wt-gift-overlay__msg" data-gift-msg></p>
            <div class="wt-gift-overlay__timer" data-gift-timer hidden></div>
            <div class="wt-gift-overlay__actions" data-gift-actions></div>
          </div>
        </div>
      </div>

    <?php endif; ?>

    <details class="wt-gift-rules" data-reveal>
      <summary><?= e(t('gift.rules_title')) ?></summary>
      <ul>
        <li><?= e(t('gift.rule_all')) ?></li>
        <li><?= e(t('gift.rule_ad')) ?></li>
        <li><?= e(t('gift.rule_race')) ?></li>
        <li><?= e(t('gift.rule_boost', ['label' => $libelle])) ?></li>
        <li><?= e(t('gift.rule_jackpot')) ?></li>
      </ul>
    </details>

    <?= wt_ad_zone('gift_bottom') ?>

  </div>
</main>

<?php include __DIR__ . '/../../footer.php'; ?>
