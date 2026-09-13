<?php
/**
 * Wintaskly — tasks/quiz/index.php
 *
 * WintQuiz, côté joueur.
 *
 * La page fonctionne SANS JavaScript : le formulaire est un POST
 * classique qui bascule entre question et résultat. Le script, quand il
 * est là, fait la même chose sans recharger.
 *
 * Deux écrans qui alternent :
 *   - la question, avec ses quatre propositions ;
 *   - le résultat, avec un délai avant de continuer — le temps que la
 *     publicité ouverte au clic se charge derrière.
 */
declare(strict_types=1);
require __DIR__ . '/../../includes/init.php';

$u = require_auth();

$pageTitle = t('quiz.page_title');

if (!wt_quiz_visible_for($u)) {
    include __DIR__ . '/../../header.php';
    echo '<main class="wt-main wt-quiz"><div class="wt-quiz__wrap">'
       . '<div class="wt-quiz__empty">' . e(t('quiz.err_disabled')) . '</div>'
       . '</div></main>';
    include __DIR__ . '/../../footer.php';
    exit;
}

$userId  = (int) $u['id'];
$attente = wt_quiz_next_available($userId);

/* ------------------------------------------------------------------
 * Repli sans JavaScript : le POST est traité ici.
 * ------------------------------------------------------------------ */
$resultat = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && csrf_check((string) ($_POST['_csrf'] ?? ''))
    && $attente === 0) {
    $resultat = wt_quiz_answer($userId, (string) ($_POST['given'] ?? ''));
    if (!$resultat['ok']) { $resultat = null; }
    $attente = wt_quiz_next_available($userId);
}

$session  = $attente === 0 ? wt_quiz_session($userId) : null;
$question = ($session && !$resultat) ? wt_quiz_pick_question($session) : null;

$goal   = (int) ($session['goal'] ?? cfg('quiz.goal', 5));
$faits  = (int) ($session['correct_cnt'] ?? 0);
$poses  = (int) ($session['asked_cnt'] ?? 0);
$gain   = (float) ($session['reward'] ?? cfg('quiz.reward_coins', 125));
$delai  = max(0, (int) cfg('quiz.ads_seconds', 20));

/* Ancienneté du quiz, affichée au-dessus de la progression. */
$debut = trim((string) cfg('quiz.start_date', ''));
$jours = null;
if ($debut !== '' && ($ts = strtotime($debut . ' UTC'))) {
    $jours = max(0, (int) floor((time() - $ts) / 86400));
}

/* La barre affiche la progression de CAMPAGNE, pas celle de la partie.
   Elle ne compte que les bonnes reponses : une erreur ne la fait pas
   reculer, elle ne la fait simplement pas avancer. */
$camp = wt_quiz_progress($userId);
$pct  = (int) $camp['pct'];

include __DIR__ . '/../../header.php';
?>

<main class="wt-main wt-quiz" data-quiz data-wait="<?= (int) $delai ?>"
      data-label-next="<?= e(t('quiz.next')) ?>"
      data-label-back="<?= e(t('quiz.back_tasks')) ?>"
      data-label-good="<?= e(t('quiz.good_prefix')) ?>">
  <div class="wt-quiz__wrap">

    <header class="wt-quiz__head" data-reveal>
      <h1 class="wt-quiz__title">🧠 <?= e(t('quiz.page_title')) ?></h1>
      <?php if ($jours !== null): ?>
        <p class="wt-quiz__since">
          <?= e(t('quiz.since', ['d' => $jours, 'date' => wt_format_datetime($debut . ' 00:00:00', 'd/m/Y')])) ?>
        </p>
      <?php endif; ?>
    </header>

    <?= wt_ad_zone('quiz_top') ?>

    <?php if ($attente > 0): ?>
      <section class="wt-quiz__cooldown" data-reveal data-quiz-next="<?= (int) $attente ?>">
        <span class="wt-quiz__cooldown-ico" aria-hidden="true">⏳</span>
        <h2><?= e(t('quiz.cooldown_title')) ?></h2>
        <p class="wt-muted"><?= e(t('quiz.cooldown_text')) ?></p>
        <div class="wt-quiz__countdown" data-quiz-countdown>
          <?= e(gmdate('H:i:s', max(0, $attente - time()))) ?>
        </div>
      </section>

    <?php else: ?>

      <?php /* Progression : le pourcentage vers l'objectif, et le nombre
               de questions deja posees — qui peut largement depasser
               l'objectif, puisqu'une erreur ne fait que rallonger. */ ?>
      <section class="wt-quiz__progress" data-reveal>
        <div class="wt-quiz__bar" role="progressbar"
             aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
          <span style="width:<?= $pct ?>%" data-quiz-bar></span>
        </div>
        <p class="wt-quiz__progress-txt">
          <strong data-quiz-pct><?= $pct ?> %</strong>
          · <span data-quiz-correct><?= (int) $camp['correct'] ?></span>/<?= (int) $camp['goal'] ?>
          <?= e(t('quiz.good_answers')) ?>
          · <?= e(t('quiz.campaign_prize', ['n' => number_format((float) $camp['reward'], 0, ',', ' ')])) ?>
        </p>
        <p class="wt-quiz__progress-sub">
          <?= e(t('quiz.round_prize', [
                'g' => $goal,
                'n' => number_format($gain, 0, ',', ' '),
                'f' => $faits,
              ])) ?>
        </p>
      </section>

      <?php
      /* Écran de résultat. Affiché après une réponse, il laisse le temps
         à la publicité de se charger avant de proposer la suite. */
      if ($resultat):
      ?>
        <section class="wt-quiz-card wt-quiz-card--result" data-quiz-result data-reveal>
          <?php if ($resultat['finished']): ?>
            <div class="wt-quiz-result__ico">🏆</div>
            <h2 class="wt-quiz-result__title wt-quiz-result__title--ok">
              <?= e(t('quiz.msg_win', ['n' => number_format($resultat['reward'], 0, ',', ' ')])) ?>
            </h2>
            <a class="wt-btn wt-btn--primary" href="<?= e(wt_url('/tasks/')) ?>">
              <?= e(t('quiz.back_tasks')) ?>
            </a>
          <?php elseif ($resultat['correct']): ?>
            <div class="wt-quiz-result__ico">✅</div>
            <h2 class="wt-quiz-result__title wt-quiz-result__title--ok">
              <?= e(t('quiz.msg_good', ['n' => $resultat['goal'] - $resultat['count']])) ?>
            </h2>
          <?php else: ?>
            <div class="wt-quiz-result__ico">❌</div>
            <h2 class="wt-quiz-result__title wt-quiz-result__title--bad"><?= e(t('quiz.msg_bad')) ?></h2>
            <p class="wt-quiz-result__good">
              <?= e(t('quiz.good_was', ['l' => strtoupper($resultat['good'])])) ?>
            </p>
            <?php if ($resultat['explain'] !== ''): ?>
              <p class="wt-quiz-result__explain"><?= e($resultat['explain']) ?></p>
            <?php endif; ?>
          <?php endif; ?>

          <?php if (!$resultat['finished']): ?>
            <form method="get" style="margin-top:1rem">
              <button class="wt-btn wt-btn--primary" type="submit" data-quiz-continue
                      <?= $delai > 0 ? 'disabled' : '' ?>>
                <?= e(t('quiz.next')) ?><span data-quiz-wait></span>
              </button>
            </form>
          <?php endif; ?>
        </section>

      <?php elseif ($question): ?>
        <section class="wt-quiz-card" data-quiz-question data-reveal>

          <?php /* Les points de progression, en haut a droite : un par
                   bonne reponse attendue, allumes au fur et a mesure. */ ?>
          <div class="wt-quiz-card__dots" aria-hidden="true" data-quiz-dots>
            <?php for ($i = 1; $i <= $goal; $i++): ?>
              <span class="wt-quiz-dot<?= $i <= $faits ? ' is-on' : '' ?>"></span>
            <?php endfor; ?>
          </div>

          <?php if (($question['category'] ?? '') !== ''): ?>
            <span class="wt-quiz-card__cat" data-quiz-cat><?= e($question['category']) ?></span>
          <?php endif; ?>

          <h2 class="wt-quiz-card__q" data-quiz-text><?= e($question['question']) ?></h2>

          <form method="post" data-quiz-form>
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <div class="wt-quiz-options" data-quiz-options>
              <?php foreach (['a', 'b', 'c', 'd'] as $l): ?>
                <label class="wt-quiz-option">
                  <input type="radio" name="given" value="<?= $l ?>" required>
                  <span class="wt-quiz-option__l"><?= strtoupper($l) ?></span>
                  <span class="wt-quiz-option__t" data-quiz-opt="<?= $l ?>"><?= e($question[$l]) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <button class="wt-btn wt-btn--primary wt-quiz-card__submit" type="submit" data-quiz-submit>
              <?= e(t('quiz.validate')) ?>
            </button>
          </form>
        </section>

      <?php else: ?>
        <div class="wt-quiz__empty" data-reveal><?= e(t('quiz.err_empty_bank')) ?></div>
      <?php endif; ?>

    <?php endif; ?>

    <details class="wt-quiz-rules" data-reveal>
      <summary><?= e(t('quiz.rules_title')) ?></summary>
      <ul>
        <li><?= e(t('quiz.rule_goal', ['n' => $goal])) ?></li>
        <li><?= e(t('quiz.rule_wrong')) ?></li>
        <li><?= e(t('quiz.rule_reload')) ?></li>
        <li><?= e(t('quiz.rule_cooldown', ['h' => (int) cfg('quiz.cooldown_hours', 3)])) ?></li>
      </ul>
    </details>

    <?= wt_ad_zone('quiz_bottom') ?>

  </div>
</main>

<?php include __DIR__ . '/../../footer.php'; ?>
