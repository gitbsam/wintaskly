<?php
/**
 * Wintaskly — /help/antifraud.php (Anti-fraude — page publique)
 *
 * Page de confiance recommandée pour l'éligibilité AdSense et la
 * crédibilité générale du site. Décrit les PRINCIPES de la politique
 * anti-fraude (pourquoi, quelles catégories de vérifications existent,
 * que se passe-t-il en cas de détection) — volontairement SANS détailler
 * les seuils, algorithmes ou signaux précis utilisés en interne
 * (includes/fraud.php, ptc_heartbeat, etc.) : les révéler donnerait une
 * feuille de route aux personnes cherchant à contourner le système.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

$pageTitle = t('antifraud.title');
$pageDescription = t('seo.desc.antifraud');

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-legal-v2" data-reveal>
  <div class="wt-legal-v2__wrap">

    <header class="wt-legal-v2__header">
      <span class="wt-eyebrow">🛡️ <?= e(t('antifraud.eyebrow')) ?></span>
      <h1 class="wt-legal-v2__title"><?= e(t('antifraud.title')) ?></h1>
      <p class="wt-legal-v2__updated"><?= e(t('antifraud.intro')) ?></p>
    </header>

    <div class="wt-legal-v2__content">

      <section class="wt-legal-v2__section">
        <h2>1. <?= e(t('antifraud.h_why')) ?></h2>
        <p><?= e(t('antifraud.why_p')) ?></p>
      </section>

      <section class="wt-legal-v2__section">
        <h2>2. <?= e(t('antifraud.h_what')) ?></h2>
        <p><?= e(t('antifraud.what_p')) ?></p>
        <ul class="wt-legal-v2__list">
          <li><strong><?= e(t('antifraud.what_li1_t')) ?> :</strong> <?= e(t('antifraud.what_li1_d')) ?></li>
          <li><strong><?= e(t('antifraud.what_li2_t')) ?> :</strong> <?= e(t('antifraud.what_li2_d')) ?></li>
          <li><strong><?= e(t('antifraud.what_li3_t')) ?> :</strong> <?= e(t('antifraud.what_li3_d')) ?></li>
          <li><strong><?= e(t('antifraud.what_li4_t')) ?> :</strong> <?= e(t('antifraud.what_li4_d')) ?></li>
        </ul>
      </section>

      <section class="wt-legal-v2__section">
        <h2>3. <?= e(t('antifraud.h_consequences')) ?></h2>
        <p><?= e(t('antifraud.consequences_p')) ?></p>
        <ul class="wt-legal-v2__list">
          <li><?= e(t('antifraud.consequences_li1')) ?></li>
          <li><?= e(t('antifraud.consequences_li2')) ?></li>
          <li><?= e(t('antifraud.consequences_li3')) ?></li>
        </ul>
      </section>

      <section class="wt-legal-v2__section">
        <h2>4. <?= e(t('antifraud.h_appeal')) ?></h2>
        <p>
          <?= e(t('antifraud.appeal_p')) ?>
          <a href="<?= e(wt_url('/help/contact.php')) ?>"><?= e(t('antifraud.appeal_link')) ?></a>.
        </p>
      </section>

      <section class="wt-legal-v2__section">
        <h2>5. <?= e(t('antifraud.h_report')) ?></h2>
        <p>
          <?= e(t('antifraud.report_p')) ?>
          <a href="<?= e(wt_url('/help/contact.php')) ?>"><?= e(t('antifraud.report_link')) ?></a>.
        </p>
      </section>

      <section class="wt-legal-v2__section">
        <h2>6. <?= e(t('antifraud.h_protect')) ?></h2>
        <p><?= e(t('antifraud.protect_p1')) ?></p>
        <ul>
          <li><?= e(t('antifraud.protect_li1')) ?></li>
          <li><?= e(t('antifraud.protect_li2')) ?></li>
          <li><?= e(t('antifraud.protect_li3')) ?></li>
          <li><?= e(t('antifraud.protect_li4')) ?></li>
        </ul>
      </section>

      <section class="wt-legal-v2__section">
        <h2>7. <?= e(t('antifraud.h_legit')) ?></h2>
        <p><?= e(t('antifraud.legit_p1')) ?></p>
        <p><?= e(t('antifraud.legit_p2')) ?></p>
      </section>

      <section class="wt-legal-v2__section">
        <h2>8. <?= e(t('antifraud.h_transparency')) ?></h2>
        <p><?= e(t('antifraud.transparency_p')) ?></p>
      </section>

    </div>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
