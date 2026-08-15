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

      <section class="wt-legal-v2__section">
        <h2>1. <?= e(t('about.h_mission')) ?></h2>
        <p><?= e(t('about.mission_p1')) ?></p>
        <p><?= e(t('about.mission_p2')) ?></p>
      </section>

      <section class="wt-legal-v2__section">
        <h2>2. <?= e(t('about.h_how')) ?></h2>
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
        <h2>3. <?= e(t('about.h_values')) ?></h2>
        <ul class="wt-legal-v2__list">
          <li><strong><?= e(t('about.value1_t')) ?> :</strong> <?= e(t('about.value1_d')) ?></li>
          <li><strong><?= e(t('about.value2_t')) ?> :</strong> <?= e(t('about.value2_d')) ?></li>
          <li><strong><?= e(t('about.value3_t')) ?> :</strong> <?= e(t('about.value3_d')) ?></li>
          <li><strong><?= e(t('about.value4_t')) ?> :</strong> <?= e(t('about.value4_d')) ?></li>
        </ul>
      </section>

      <?php if ($statsUsers > 0): ?>
      <section class="wt-legal-v2__section">
        <h2>4. <?= e(t('about.h_numbers')) ?></h2>
        <p><?= e(sprintf((string) t('about.numbers_p'), number_format($statsUsers, 0, '.', ' '))) ?></p>
      </section>
      <?php endif; ?>

      <section class="wt-legal-v2__section">
        <h2>5. <?= e(t('about.h_future')) ?></h2>
        <p><?= e(t('about.future_p')) ?></p>
      </section>

      <section class="wt-legal-v2__section">
        <h2>6. <?= e(t('about.h_model')) ?></h2>
        <p><?= e(t('about.model_p1')) ?></p>
        <p><?= e(t('about.model_p2')) ?></p>
        <p><?= e(t('about.model_p3')) ?></p>
      </section>

      <section class="wt-legal-v2__section">
        <h2>7. <?= e(t('about.h_expect')) ?></h2>
        <p><?= e(t('about.expect_p1')) ?></p>
        <ul>
          <li><?= e(t('about.expect_li1')) ?></li>
          <li><?= e(t('about.expect_li2')) ?></li>
          <li><?= e(t('about.expect_li3')) ?></li>
        </ul>
        <p><?= e(t('about.expect_p2')) ?></p>
      </section>

      <section class="wt-legal-v2__section">
        <h2>8. <?= e(t('about.h_contact')) ?></h2>
        <p>
          <?= e(t('about.contact_p')) ?>
          <a href="<?= e(wt_url('/help/contact.php')) ?>"><?= e(t('about.contact_link')) ?></a>.
        </p>
      </section>

    </div>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
