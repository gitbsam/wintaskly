<?php
/**
 * Wintaskly — /legal/mentions.php
 *
 * Mentions légales (obligatoires en France/UE pour un site édité à titre
 * professionnel). Toutes les informations sont éditables via
 * /admin/settings.php → onglet « Légal » (clés de config legal.*).
 *
 * Si une information n'est pas encore renseignée, un libellé neutre
 * « à compléter » s'affiche (pour ne jamais laisser un champ vide en prod).
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

$pageTitle = t('legal.mentions_title');
$siteName  = (string) cfg('site_name', 'Wintaskly');

// Récupération des informations légales (config legal.*)
$todo = (string) t('legal.mentions_todo');
$L = [
    'editor_name'         => (string) cfg('legal.editor_name', ''),
    'editor_status'       => (string) cfg('legal.editor_status', ''),
    'editor_address'      => (string) cfg('legal.editor_address', ''),
    'editor_email'        => (string) cfg('legal.editor_email', (string) cfg('email.contact_to', '')),
    'editor_siret'        => (string) cfg('legal.editor_siret', ''),
    'publication_director'=> (string) cfg('legal.publication_director', ''),
    'host_name'           => (string) cfg('legal.host_name', ''),
    'host_address'        => (string) cfg('legal.host_address', ''),
    'host_contact'        => (string) cfg('legal.host_contact', ''),
];

// Helper d'affichage : valeur ou placeholder « à compléter »
$show = static function (string $v) use ($todo): string {
    return $v !== '' ? nl2br(e($v)) : '<em class="wt-muted">' . e($todo) . '</em>';
};

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-legal-v2" data-reveal>
  <div class="wt-legal-v2__wrap">

    <header class="wt-legal-v2__header">
      <span class="wt-eyebrow">⚖️ <?= e(t('legal.eyebrow')) ?></span>
      <h1 class="wt-legal-v2__title"><?= e(t('legal.mentions_title')) ?></h1>
      <p class="wt-legal-v2__updated"><?= e(t('legal.mentions_intro')) ?></p>
    </header>

    <div class="wt-legal-v2__content">

      <!-- 1. Éditeur du site -->
      <section class="wt-legal-v2__section">
        <h2>1. <?= e(t('legal.mentions_h_editor')) ?></h2>
        <p><?= e(t('legal.mentions_editor_lead', ['site' => $siteName])) ?></p>
        <ul class="wt-legal-v2__list">
          <li><strong><?= e(t('legal.mentions_editor_name')) ?> :</strong> <?= $show($L['editor_name']) ?></li>
          <li><strong><?= e(t('legal.mentions_editor_status')) ?> :</strong> <?= $show($L['editor_status']) ?></li>
          <li><strong><?= e(t('legal.mentions_editor_address')) ?> :</strong> <?= $show($L['editor_address']) ?></li>
          <li><strong><?= e(t('legal.mentions_editor_email')) ?> :</strong> <?= $show($L['editor_email']) ?></li>
          <?php if ($L['editor_siret'] !== ''): ?>
            <li><strong><?= e(t('legal.mentions_editor_siret')) ?> :</strong> <?= e($L['editor_siret']) ?></li>
          <?php endif; ?>
        </ul>
      </section>

      <!-- 2. Directeur de la publication -->
      <section class="wt-legal-v2__section">
        <h2>2. <?= e(t('legal.mentions_h_director')) ?></h2>
        <p><?= $show($L['publication_director']) ?></p>
      </section>

      <!-- 3. Hébergement -->
      <section class="wt-legal-v2__section">
        <h2>3. <?= e(t('legal.mentions_h_host')) ?></h2>
        <p><?= e(t('legal.mentions_host_lead')) ?></p>
        <ul class="wt-legal-v2__list">
          <li><strong><?= e(t('legal.mentions_host_name')) ?> :</strong> <?= $show($L['host_name']) ?></li>
          <li><strong><?= e(t('legal.mentions_host_address')) ?> :</strong> <?= $show($L['host_address']) ?></li>
          <li><strong><?= e(t('legal.mentions_host_contact')) ?> :</strong> <?= $show($L['host_contact']) ?></li>
        </ul>
      </section>

      <!-- 4. Propriété intellectuelle -->
      <section class="wt-legal-v2__section">
        <h2>4. <?= e(t('legal.mentions_h_ip')) ?></h2>
        <p><?= e(t('legal.mentions_ip_text', ['site' => $siteName])) ?></p>
      </section>

      <!-- 5. Données personnelles -->
      <section class="wt-legal-v2__section">
        <h2>5. <?= e(t('legal.mentions_h_data')) ?></h2>
        <p>
          <?= e(t('legal.mentions_data_text')) ?>
          <a href="<?= e(wt_url('/legal/privacy.php')) ?>"><?= e(t('legal.mentions_data_link')) ?></a>.
        </p>
      </section>

      <!-- 6. Cookies -->
      <section class="wt-legal-v2__section">
        <h2>6. <?= e(t('legal.mentions_h_cookies')) ?></h2>
        <p>
          <?= e(t('legal.mentions_cookies_text')) ?>
          <a href="<?= e(wt_url('/legal/cookies.php')) ?>"><?= e(t('legal.mentions_cookies_link')) ?></a>.
        </p>
      </section>

      <!-- 7. Contact -->
      <section class="wt-legal-v2__section">
        <h2>7. <?= e(t('legal.mentions_h_contact')) ?></h2>
        <p>
          <?= e(t('legal.mentions_contact_text')) ?>
          <a href="<?= e(wt_url('/help/contact.php')) ?>"><?= e(t('legal.mentions_contact_link')) ?></a>.
        </p>
      </section>

    </div>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
