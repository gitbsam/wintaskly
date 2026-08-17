<?php
/**
 * Wintaskly — /about/ (À propos)
 *
 * Page de confiance recommandée pour l'éligibilité AdSense : raconte la
 * mission, le fonctionnement et les engagements de la plateforme.
 * Contenu textuel géré via i18n (about.*), pas de données admin ici —
 * page purement éditoriale, cohérente avec le reste de la page d'accueil
 * (sections "Pourquoi Wintaskly" / "Partenaires").
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

$pageTitle = t('about.title');
$pageDescription = t('seo.desc.about');

/* Fil d'Ariane structuré : Google l'affiche sous le lien dans les résultats
   et il clarifie la place de la page dans le site. */
wt_schema_add(wt_schema_breadcrumb([
    ['name' => (string) t('site_name'), 'url' => wt_url('/')],
    ['name' => (string) t('about.title'), 'url' => wt_url('/about/')],
]));
$db        = db();

// Chiffre public simple et honnête (comptage réel, sans le boost marketing
// utilisé sur la page d'accueil — ici on veut juste ancrer le propos).
$statsUsers = (int) ($db->query("SELECT COUNT(*) c FROM users WHERE status='active'")?->fetch_assoc()['c'] ?? 0);

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-legal-v2" data-reveal>
  <div class="wt-legal-v2__wrap">

    <header class="wt-legal-v2__header">
      <span class="wt-eyebrow">🧭 <?= e(t('about.eyebrow')) ?></span>
      <h1 class="wt-legal-v2__title"><?= e(t('about.title')) ?></h1>
      <p class="wt-legal-v2__updated"><?= e(t('about.intro')) ?></p>
    </header>

    <div class="wt-legal-v2__content">

      <?php
        /* Numérotation automatique des sections.
           La section « chiffres » est conditionnelle : quand elle ne
           s'affiche pas (aucun membre encore), des numéros écrits en dur
           laissaient un trou visible — la page passait de 3 à 5. */
        $n = 0;
      ?>
      <section class="wt-legal-v2__section">
        <h2><?= ++$n ?>. <?= e(t('about.h_mission')) ?></h2>
        <p><?= e(t('about.mission_p1')) ?></p>
        <p><?= e(t('about.mission_p2')) ?></p>
      </section>

      <?php /* Origine du projet — la question « pourquoi ce site existe-t-il ? »
               est celle qu'un visiteur méfiant se pose en premier, et celle
               qu'aucune plateforme de ce secteur ne traite. Y répondre est le
               signal d'expertise le plus direct qu'une page « À propos »
               puisse porter. */ ?>
      <section class="wt-legal-v2__section">
        <h2><?= ++$n ?>. <?= e(t('about.h_origin')) ?></h2>
        <p><?= e(t('about.origin_p1')) ?></p>
        <p><?= e(t('about.origin_p2')) ?></p>
        <p><?= e(t('about.origin_p3')) ?></p>
      </section>

      <section class="wt-legal-v2__section">
        <h2><?= ++$n ?>. <?= e(t('about.h_how')) ?></h2>
        <p><?= e(t('about.how_p1')) ?></p>
        <ul class="wt-legal-v2__list">
          <li><?= e(t('about.how_li1')) ?></li>
          <li><?= e(t('about.how_li2')) ?></li>
          <li><?= e(t('about.how_li3')) ?></li>
          <li><?= e(t('about.how_li4')) ?></li>
        </ul>
        <p>
          <a href="<?= e(wt_url('/help/')) ?>"><?= e(t('about.how_link')) ?> →</a>
        </p>
      </section>

      <section class="wt-legal-v2__section">
        <h2><?= ++$n ?>. <?= e(t('about.h_values')) ?></h2>
        <ul class="wt-legal-v2__list">
          <li><strong><?= e(t('about.value1_t')) ?> :</strong> <?= e(t('about.value1_d')) ?></li>
          <li><strong><?= e(t('about.value2_t')) ?> :</strong> <?= e(t('about.value2_d')) ?></li>
          <li><strong><?= e(t('about.value3_t')) ?> :</strong> <?= e(t('about.value3_d')) ?></li>
          <li><strong><?= e(t('about.value4_t')) ?> :</strong> <?= e(t('about.value4_d')) ?></li>
        </ul>
      </section>

      <?php if ($statsUsers > 0): ?>
      <section class="wt-legal-v2__section">
        <h2><?= ++$n ?>. <?= e(t('about.h_numbers')) ?></h2>
        <p><?= e(sprintf((string) t('about.numbers_p'), number_format($statsUsers, 0, '.', ' '))) ?></p>
      </section>
      <?php endif; ?>

      <section class="wt-legal-v2__section">
        <h2><?= ++$n ?>. <?= e(t('about.h_future')) ?></h2>
        <p><?= e(t('about.future_p')) ?></p>
      </section>

      <section class="wt-legal-v2__section">
        <h2><?= ++$n ?>. <?= e(t('about.h_model')) ?></h2>
        <p><?= e(t('about.model_p1')) ?></p>
        <p><?= e(t('about.model_p2')) ?></p>
        <p><?= e(t('about.model_p3')) ?></p>
      </section>

      <section class="wt-legal-v2__section">
        <h2><?= ++$n ?>. <?= e(t('about.h_expect')) ?></h2>
        <p><?= e(t('about.expect_p1')) ?></p>
        <ul>
          <li><?= e(t('about.expect_li1')) ?></li>
          <li><?= e(t('about.expect_li2')) ?></li>
          <li><?= e(t('about.expect_li3')) ?></li>
        </ul>
        <p><?= e(t('about.expect_p2')) ?></p>
      </section>

      <section class="wt-legal-v2__section">
        <h2><?= ++$n ?>. <?= e(t('about.h_contact')) ?></h2>
        <p>
          <?= e(t('about.contact_p')) ?>
          <a href="<?= e(wt_url('/help/contact.php')) ?>"><?= e(t('about.contact_link')) ?></a>.
        </p>
      </section>

    </div>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
