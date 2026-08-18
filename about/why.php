<?php
/**
 * Wintaskly — /about/why.php
 * ---------------------------------------------------------------------------
 * Page « Pourquoi nous existons ».
 *
 * Pourquoi une page dédiée plutôt qu'une simple section dans /about ?
 * Parce qu'elle répond à quatre questions distinctes qui méritent chacune
 * du développement — origine, problème résolu, objectifs, valeurs — et
 * qu'une page dédiée est adressable, référençable et citable, là qu'une
 * section noyée dans une autre page ne l'est pas.
 *
 * ⚠️ La section « Pourquoi Wintaskly existe » de /about/index.php a été
 * réduite à un résumé renvoyant ici : deux textes développant la même chose
 * seraient du contenu dupliqué, ce que Google pénalise.
 *
 * Aucune promesse chiffrée, aucun engagement qui ne pourrait être tenu :
 * cette page perd toute valeur si elle survend.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

$pageTitle       = t('why.title');
$pageDescription = t('seo.desc.why');

wt_schema_add(wt_schema_breadcrumb([
    ['name' => (string) t('site_name'),   'url' => wt_url('/')],
    ['name' => (string) t('about.title'), 'url' => wt_url('/about/')],
    ['name' => (string) t('why.title'),   'url' => wt_url('/about/why.php')],
]));

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-legal-v2">
  <div class="wt-legal-v2__wrap">

    <nav class="wt-legal-v2__breadcrumb" aria-label="<?= e(t('common.breadcrumb')) ?>">
      <a href="<?= e(wt_url('/')) ?>"><?= e(t('nav.home')) ?></a>
      <span aria-hidden="true">›</span>
      <a href="<?= e(wt_url('/about/')) ?>"><?= e(t('about.nav')) ?></a>
      <span aria-hidden="true">›</span>
      <span><?= e(t('why.title')) ?></span>
    </nav>

    <header class="wt-legal-v2__header">
      <h1 class="wt-legal-v2__title"><?= e(t('why.title')) ?></h1>
      <p class="wt-legal-v2__lead"><?= e(t('why.lead')) ?></p>
    </header>

    <div class="wt-legal-v2__content">

      <section class="wt-legal-v2__section">
        <h2><?= e(t('why.h_origin')) ?></h2>
        <p><?= e(t('why.origin_p1')) ?></p>
        <p><?= e(t('why.origin_p2')) ?></p>
        <p><?= e(t('why.origin_p3')) ?></p>
      </section>

      <section class="wt-legal-v2__section">
        <h2><?= e(t('why.h_problem')) ?></h2>
        <p><?= e(t('why.problem_intro')) ?></p>
        <ul class="wt-legal-v2__list">
          <li><strong><?= e(t('why.problem_1_t')) ?></strong> — <?= e(t('why.problem_1_d')) ?></li>
          <li><strong><?= e(t('why.problem_2_t')) ?></strong> — <?= e(t('why.problem_2_d')) ?></li>
          <li><strong><?= e(t('why.problem_3_t')) ?></strong> — <?= e(t('why.problem_3_d')) ?></li>
          <li><strong><?= e(t('why.problem_4_t')) ?></strong> — <?= e(t('why.problem_4_d')) ?></li>
        </ul>
        <p><?= e(t('why.problem_concl')) ?></p>
      </section>

      <section class="wt-legal-v2__section">
        <h2><?= e(t('why.h_values')) ?></h2>
        <p><?= e(t('why.values_intro')) ?></p>

        <h3><?= e(t('why.value_1_t')) ?></h3>
        <p><?= e(t('why.value_1_d')) ?></p>

        <h3><?= e(t('why.value_2_t')) ?></h3>
        <p><?= e(t('why.value_2_d')) ?></p>

        <h3><?= e(t('why.value_3_t')) ?></h3>
        <p><?= e(t('why.value_3_d')) ?></p>

        <h3><?= e(t('why.value_4_t')) ?></h3>
        <p><?= e(t('why.value_4_d')) ?></p>
      </section>

      <section class="wt-legal-v2__section">
        <h2><?= e(t('why.h_goals')) ?></h2>
        <p><?= e(t('why.goals_intro')) ?></p>
        <ul class="wt-legal-v2__list">
          <li><?= e(t('why.goal_1')) ?></li>
          <li><?= e(t('why.goal_2')) ?></li>
          <li><?= e(t('why.goal_3')) ?></li>
          <li><?= e(t('why.goal_4')) ?></li>
        </ul>
        <p><?= e(t('why.goals_note')) ?></p>
      </section>

      <section class="wt-legal-v2__section">
        <h2><?= e(t('why.h_verify')) ?></h2>
        <p><?= e(t('why.verify_intro')) ?></p>
        <p class="wt-legal-v2__links">
          <a href="<?= e(wt_url('/help/antifraud.php')) ?>"><?= e(t('why.link_antifraud')) ?></a>
          <span aria-hidden="true">·</span>
          <a href="<?= e(wt_url('/about/editorial.php')) ?>"><?= e(t('why.link_editorial')) ?></a>
          <span aria-hidden="true">·</span>
          <a href="<?= e(wt_url('/legal/cgu.php')) ?>"><?= e(t('why.link_cgu')) ?></a>
          <span aria-hidden="true">·</span>
          <a href="<?= e(wt_url('/blog')) ?>"><?= e(t('why.link_blog')) ?></a>
        </p>
      </section>

    </div>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
