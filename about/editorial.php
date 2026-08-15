<?php
/**
 * Wintaskly — /about/editorial.php (Notre ligne éditoriale)
 *
 * Page de confiance (E-E-A-T) : explique comment le contenu du blog est
 * produit, vérifié et corrigé, et sur quels principes il repose. Sur des
 * sujets financiers (YMYL), les moteurs cherchent à savoir qui répond du
 * contenu et selon quelles règles — cette page répond à cette question
 * sans exiger d'informations personnelles, l'éditeur étant déjà identifié
 * dans les mentions légales.
 *
 * Contenu 100 % i18n (editorial.*), aucune donnée admin : page purement
 * éditoriale, même structure que /about/.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

$pageTitle = t('editorial.title');
$pageDescription = t('seo.desc.editorial');

// Fil d'Ariane structuré (cohérent avec le blog et l'aide)
wt_schema_add(wt_schema_breadcrumb([
    ['name' => (string) t('site_name'),        'url' => wt_url('/')],
    ['name' => (string) t('about.title'),      'url' => wt_url('/about/')],
    ['name' => (string) t('editorial.title'),  'url' => wt_url('/about/editorial.php')],
]));

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-legal-v2" data-reveal>
  <div class="wt-legal-v2__wrap">

    <header class="wt-legal-v2__header">
      <span class="wt-eyebrow">✍️ <?= e(t('editorial.eyebrow')) ?></span>
      <h1 class="wt-legal-v2__title"><?= e(t('editorial.title')) ?></h1>
      <p class="wt-legal-v2__updated"><?= e(t('editorial.intro')) ?></p>
    </header>

    <div class="wt-legal-v2__content">

      <section class="wt-legal-v2__section">
        <h2>1. <?= e(t('editorial.h_who')) ?></h2>
        <p><?= e(t('editorial.who_p1')) ?></p>
        <p><?= e(t('editorial.who_p2')) ?></p>
      </section>

      <section class="wt-legal-v2__section">
        <h2>2. <?= e(t('editorial.h_purpose')) ?></h2>
        <p><?= e(t('editorial.purpose_p1')) ?></p>
        <ul>
          <li><?= e(t('editorial.purpose_li1')) ?></li>
          <li><?= e(t('editorial.purpose_li2')) ?></li>
        </ul>
      </section>

      <section class="wt-legal-v2__section">
        <h2>3. <?= e(t('editorial.h_rules')) ?></h2>
        <p><?= e(t('editorial.rules_p1')) ?></p>
        <ul>
          <li><?= e(t('editorial.rules_li1')) ?></li>
          <li><?= e(t('editorial.rules_li2')) ?></li>
          <li><?= e(t('editorial.rules_li3')) ?></li>
          <li><?= e(t('editorial.rules_li4')) ?></li>
        </ul>
      </section>

      <section class="wt-legal-v2__section">
        <h2>4. <?= e(t('editorial.h_finance')) ?></h2>
        <p><?= e(t('editorial.finance_p1')) ?></p>
        <p><?= e(t('editorial.finance_p2')) ?></p>
      </section>

      <section class="wt-legal-v2__section">
        <h2>5. <?= e(t('editorial.h_ads')) ?></h2>
        <p><?= e(t('editorial.ads_p1')) ?></p>
        <p><?= e(t('editorial.ads_p2')) ?></p>
      </section>

      <section class="wt-legal-v2__section">
        <h2>6. <?= e(t('editorial.h_updates')) ?></h2>
        <p><?= e(t('editorial.updates_p1')) ?></p>
        <p><?= e(t('editorial.updates_p2')) ?></p>
      </section>

      <section class="wt-legal-v2__section">
        <h2>7. <?= e(t('editorial.h_contact')) ?></h2>
        <p><?= e(t('editorial.contact_p1')) ?></p>
        <p>
          <a class="wt-btn wt-btn--primary" href="<?= e(wt_url('/help/contact.php')) ?>">
            <?= e(t('editorial.contact_btn')) ?> →
          </a>
        </p>
      </section>

    </div>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
