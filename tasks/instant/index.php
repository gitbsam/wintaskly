<?php
/**
 * Wintaskly — tasks/instant/index.php
 *
 * Instant Gagnant, côté joueur.
 *
 * Deux voies selon le réglage du jeu :
 *   - parcours publicitaire : un onglet s'ouvre, un chrono tourne
 *     PENDANT que cette page est en arrière-plan, et le résultat se
 *     révèle au retour ;
 *   - mise de tickets : le joueur choisit combien miser, le résultat
 *     est immédiat.
 *
 * Le compteur partagé n'est jamais affiché avant de jouer. Le montrer
 * permettrait d'attendre qu'il tombe à 1 : les gains iraient à ceux qui
 * surveillent plutôt qu'à ceux qui participent. Il n'apparaît qu'après
 * une défaite, comme information gagnée.
 */
declare(strict_types=1);
require __DIR__ . '/../../includes/init.php';

$u = require_auth();

$pageTitle = t('instant.page_title');

$games = [];
try {
    if (wt_instant_enabled()) {
        $res = db()->query("SELECT * FROM instant_games WHERE active = 1 ORDER BY sort_order, id");
        while ($g = $res->fetch_assoc()) {
            if (wt_instant_visible_for($g, $u)) { $games[] = $g; }
        }
        $res->free();
    }
} catch (Throwable $e) {
    /* Tables absentes : la page s'affiche vide plutôt que de planter. */
    error_log('[Wintaskly instant] ' . $e->getMessage());
}

$solde   = wt_tickets_balance((int) $u['id']);
$libelle = wt_instant_ticket_label();
$estAdmin = (($u['role'] ?? '') === 'admin');

include __DIR__ . '/../../header.php';
?>

<main class="wt-main wt-instant">
  <div class="wt-instant__wrap">

    <header class="wt-instant__head" data-reveal>
      <h1 class="wt-instant__title">⚡ <?= e(t('instant.page_title')) ?></h1>
      <p class="wt-muted"><?= e(t('instant.page_lead')) ?></p>
      <?php if ($solde > 0): ?>
        <p class="wt-instant__balance">
          <?= e(t('instant.balance', ['n' => $solde, 'label' => $libelle])) ?>
        </p>
      <?php endif; ?>
    </header>

    <?= wt_ad_zone('tasks_index_top') ?>

    <?php if (!$games): ?>
      <div class="wt-instant__empty" data-reveal>
        <p><?= e(t('instant.none')) ?></p>
      </div>
    <?php else: ?>

      <?php foreach ($games as $g):
        $mode    = (string) $g['mode'];
        $gain    = (float) $g['reward_coins'];
        $enTest  = ((int) $g['test_mode'] === 1);
        /* Mises réellement proposables : on n'affiche que celles que le
           joueur peut payer. Proposer une option qu'il ne peut pas
           choisir n'informe pas, ça frustre. */
        $mises = array_values(array_filter(
            array_map('intval', explode(',', (string) $g['ticket_options'])),
            static fn(int $v): bool => $v > 0 && $v <= $solde
        ));
      ?>
        <article class="wt-instant-card" data-instant-game="<?= (int) $g['id'] ?>" data-reveal>

          <?php if ($enTest && $estAdmin): ?>
            <div class="wt-instant-card__test"><?= e(t('instant.test_badge')) ?></div>
          <?php endif; ?>

          <header class="wt-instant-card__head">
            <h2 class="wt-instant-card__name"><?= e((string) $g['name']) ?></h2>
            <div class="wt-instant-card__prize">
              <strong><?= e(number_format($gain, 0, ',', ' ')) ?></strong>
              <span><?= e(t('common.coins')) ?></span>
            </div>
          </header>

          <?php if ($mode === 'ads'): ?>
            <p class="wt-instant-card__how"><?= e(t('instant.how_ads')) ?></p>

            <div class="wt-instant-card__actions">
              <button type="button" class="wt-btn wt-btn--primary wt-instant-btn"
                      data-instant-verify><?= e(t('instant.btn_verify')) ?></button>

              <button type="button" class="wt-btn wt-btn--ghost wt-instant-reset" hidden
                      data-instant-reset
                      title="<?= e(t('instant.btn_reset')) ?>"
                      aria-label="<?= e(t('instant.btn_reset')) ?>">↻</button>

              <span class="wt-instant-card__timer" data-instant-timer hidden></span>
            </div>

            <p class="wt-instant-card__msg" data-instant-msg role="status" hidden></p>

          <?php else: ?>
            <p class="wt-instant-card__how">
              <?= e(t('instant.how_tickets', ['label' => $libelle])) ?>
            </p>

            <?php if (!$mises): ?>
              <p class="wt-instant-card__msg wt-instant-card__msg--warn">
                <?= e(t('instant.no_tickets', ['label' => $libelle])) ?>
              </p>
            <?php else: ?>
              <div class="wt-instant-card__actions">
                <label class="wt-field__label" for="mise-<?= (int) $g['id'] ?>">
                  <?= e(t('instant.stake_label', ['label' => $libelle])) ?>
                </label>
                <select class="wt-input wt-instant-card__select" id="mise-<?= (int) $g['id'] ?>"
                        data-instant-stake>
                  <?php foreach ($mises as $m): ?>
                    <option value="<?= (int) $m ?>"><?= (int) $m ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="button" class="wt-btn wt-btn--primary"
                        data-instant-play><?= e(t('instant.btn_play')) ?></button>
              </div>
              <p class="wt-instant-card__msg" data-instant-msg role="status" hidden></p>
            <?php endif; ?>
          <?php endif; ?>

        </article>
      <?php endforeach; ?>

    <?php endif; ?>

    <?php /* Les règles, écrites clairement. Trois d'entre elles ne se
             devinent pas : le compteur partagé, le fait qu'une partie
             perdue consomme tout de même une participation, et que le
             compteur ne se montre qu'après une défaite. */ ?>
    <details class="wt-instant-rules" data-reveal>
      <summary><?= e(t('instant.rules_title')) ?></summary>
      <ul>
        <li><?= e(t('instant.rule_counter')) ?></li>
        <li><?= e(t('instant.rule_shared')) ?></li>
        <li><?= e(t('instant.rule_reveal')) ?></li>
        <li><?= e(t('instant.rule_ads')) ?></li>
        <li><?= e(t('instant.rule_tickets', ['label' => $libelle])) ?></li>
      </ul>
    </details>

    <?= wt_ad_zone('tasks_index_mid') ?>

  </div>
</main>

<?php include __DIR__ . '/../../footer.php'; ?>
