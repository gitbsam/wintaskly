<?php
/**
 * Wintaskly — /about/partners.php
 * ---------------------------------------------------------------------------
 * Page « Nos partenaires ».
 *
 * PRINCIPE : la page explique les RÔLES de chaque type de partenaire, et
 * n'affiche des NOMS que ceux déjà présents dans la base — offerwalls actifs,
 * fournisseurs de liens, méthodes de retrait. Rien n'est écrit en dur.
 *
 * Deux raisons à ce choix :
 *   1. Ce que vos accords permettent d'afficher relève de vous, pas d'un
 *      fichier de code. Vous contrôlez la liste depuis l'administration :
 *      désactiver un partenaire le retire d'ici automatiquement.
 *   2. Une liste écrite en dur deviendrait fausse au premier changement,
 *      et une page « partenaires » inexacte fait plus de mal que pas de page.
 *
 * Si aucun partenaire n'est configuré, les sections de noms disparaissent
 * mais les explications de rôle restent : c'est cette partie qui a de la
 * valeur pour un lecteur, et pour l'évaluation du site.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

$pageTitle       = t('partners.page_title');
$pageDescription = t('seo.desc.partners');

wt_schema_add(wt_schema_breadcrumb([
    ['name' => (string) t('site_name'),           'url' => wt_url('/')],
    ['name' => (string) t('about.title'),         'url' => wt_url('/about/')],
    ['name' => (string) t('partners.page_title'), 'url' => wt_url('/about/partners.php')],
]));

$partners = function_exists('wt_partners_real') ? wt_partners_real() : ['offers'=>[],'links'=>[],'pay'=>[]];

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-legal-v2">
  <div class="wt-legal-v2__wrap">

    <nav class="wt-legal-v2__breadcrumb" aria-label="<?= e(t('common.breadcrumb')) ?>">
      <a href="<?= e(wt_url('/')) ?>"><?= e(t('nav.home')) ?></a>
      <span aria-hidden="true">›</span>
      <a href="<?= e(wt_url('/about/')) ?>"><?= e(t('about.nav')) ?></a>
      <span aria-hidden="true">›</span>
      <span><?= e(t('partners.page_title')) ?></span>
    </nav>

    <header class="wt-legal-v2__header">
      <h1 class="wt-legal-v2__title"><?= e(t('partners.page_title')) ?></h1>
      <p class="wt-legal-v2__lead"><?= e(t('partners.page_lead')) ?></p>
    </header>

    <div class="wt-legal-v2__content">

      <section class="wt-legal-v2__section">
        <h2><?= e(t('partners.h_chain')) ?></h2>
        <p><?= e(t('partners.chain_p1')) ?></p>
        <p><?= e(t('partners.chain_p2')) ?></p>
      </section>

      <?php
        /* Trois familles de partenaires. Chaque bloc affiche son explication
           de rôle, puis les noms réellement configurés — s'il y en a. */
        $families = [
            ['k' => 'offers', 'names' => $partners['offers'] ?? []],
            ['k' => 'links',  'names' => $partners['links']  ?? []],
            ['k' => 'pay',    'names' => $partners['pay']    ?? []],
        ];
      ?>
      <?php foreach ($families as $f): ?>
        <section class="wt-legal-v2__section">
          <h2><?= e(t('partners.h_' . $f['k'])) ?></h2>
          <p><?= e(t('partners.' . $f['k'] . '_role')) ?></p>
          <p><?= e(t('partners.' . $f['k'] . '_note')) ?></p>

          <?php if ($f['names']): ?>
            <h3><?= e(t('partners.current')) ?></h3>
            <ul class="wt-partners-page__names">
              <?php foreach ($f['names'] as $n): ?>
                <li><?= e($n) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </section>
      <?php endforeach; ?>

      <section class="wt-legal-v2__section">
        <h2><?= e(t('partners.h_limits')) ?></h2>
        <p><?= e(t('partners.limits_p1')) ?></p>
        <p><?= e(t('partners.limits_p2')) ?></p>
      </section>

      <section class="wt-legal-v2__section">
        <h2><?= e(t('partners.h_data')) ?></h2>
        <p><?= e(t('partners.data_p1')) ?></p>
        <p class="wt-legal-v2__links">
          <a href="<?= e(wt_url('/legal/privacy.php')) ?>"><?= e(t('partners.link_privacy')) ?></a>
          <span aria-hidden="true">·</span>
          <a href="<?= e(wt_url('/legal/cookies.php')) ?>"><?= e(t('partners.link_cookies')) ?></a>
          <span aria-hidden="true">·</span>
          <a href="<?= e(wt_url('/help/antifraud.php')) ?>"><?= e(t('partners.link_antifraud')) ?></a>
        </p>
      </section>

    </div>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
