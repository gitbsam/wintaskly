<?php
/**
 * Wintaskly — Politique de confidentialité (V8 modernisée).
 *
 * Conforme RGPD/CNIL. Contenu entièrement en i18n.
 * Sections couvrant l'ensemble des modules : auth, paiements,
 * anti-fraude, sous-traitants, droits utilisateur.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

$pageTitle = t('legal.privacy');
$updatedAt = '2026-05-23';
$siteName  = (string) cfg('site_name', 'Wintaskly');

// Cache public 1h (page rarement modifiée).
wt_static_cache_headers(3600, 'privacy-' . $updatedAt . '-' . ($GLOBALS['WT_LANG_CODE'] ?? 'fr'));

$toc = [
    'controller'   => 'legal.privacy_h_controller',
    'data'         => 'legal.privacy_h_data',
    'purposes'     => 'legal.privacy_h_purposes',
    'legal_basis'  => 'legal.privacy_h_legal_basis',
    'retention'    => 'legal.privacy_h_retention',
    'subprocessors' => 'legal.privacy_h_subprocessors',
    'transfers'    => 'legal.privacy_h_transfers',
    'cookies_link' => 'legal.privacy_h_cookies',
    'rights'       => 'legal.privacy_h_rights',
    'security'     => 'legal.privacy_h_security',
    'minors'       => 'legal.privacy_h_minors',
    'contact'      => 'legal.privacy_h_contact',
];

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-legal-v2" data-reveal>
  <div class="wt-legal-v2__wrap">

    <!-- ============ HEADER + NAV ============ -->
    <header class="wt-legal-v2__header">
      <span class="wt-eyebrow">🔐 <?= e(t('legal.eyebrow')) ?></span>
      <h1 class="wt-legal-v2__title"><?= e(t('legal.privacy')) ?></h1>
      <p class="wt-legal-v2__lead">
        <?= e(t('legal.privacy_lead', ['site' => $siteName])) ?>
      </p>

      <div class="wt-legal-v2__meta">
        <span class="wt-legal-v2__updated">
          🕐 <?= e(sprintf((string) t('legal.last_updated'), $updatedAt)) ?>
        </span>
      </div>

      <nav class="wt-legal-v2__nav" aria-label="<?= e(t('legal.nav_label')) ?>">
        <a class="wt-legal-v2__nav-link"
           href="<?= e(wt_url('/legal/cgu.php')) ?>"><?= e(t('legal.cgu')) ?></a>
        <a class="wt-legal-v2__nav-link is-active"
           href="<?= e(wt_url('/legal/privacy.php')) ?>"><?= e(t('legal.privacy')) ?></a>
        <a class="wt-legal-v2__nav-link"
           href="<?= e(wt_url('/legal/cookies.php')) ?>"><?= e(t('legal.cookies_title')) ?></a>
      </nav>
    </header>

    <!-- ============ LAYOUT 2-COL ============ -->
    <div class="wt-legal-v2__grid">

      <aside class="wt-legal-v2__toc" aria-label="<?= e(t('legal.toc_label')) ?>">
        <strong class="wt-legal-v2__toc-title">📑 <?= e(t('legal.toc_title')) ?></strong>
        <ol class="wt-legal-v2__toc-list">
          <?php foreach ($toc as $id => $key): ?>
            <li><a href="#<?= e($id) ?>"><?= e(t($key)) ?></a></li>
          <?php endforeach; ?>
        </ol>
      </aside>

      <article class="wt-legal-v2__article">

        <section id="controller">
          <h2>1. <?= e(t('legal.privacy_h_controller')) ?></h2>
          <p><?= nl2br(e(t('legal.privacy_p_controller', ['site' => $siteName]))) ?></p>
        </section>

        <section id="data">
          <h2>2. <?= e(t('legal.privacy_h_data')) ?></h2>
          <p><?= e(t('legal.privacy_p_data_intro')) ?></p>
          <ul>
            <li><strong><?= e(t('legal.privacy_data_account_label')) ?> :</strong>
                <?= e(t('legal.privacy_data_account_text')) ?></li>
            <li><strong><?= e(t('legal.privacy_data_activity_label')) ?> :</strong>
                <?= e(t('legal.privacy_data_activity_text')) ?></li>
            <li><strong><?= e(t('legal.privacy_data_payment_label')) ?> :</strong>
                <?= e(t('legal.privacy_data_payment_text')) ?></li>
            <li><strong><?= e(t('legal.privacy_data_tech_label')) ?> :</strong>
                <?= e(t('legal.privacy_data_tech_text')) ?></li>
            <li><strong><?= e(t('legal.privacy_data_prefs_label')) ?> :</strong>
                <?= e(t('legal.privacy_data_prefs_text')) ?></li>
            <li><strong><?= e(t('legal.privacy_data_support_label')) ?> :</strong>
                <?= e(t('legal.privacy_data_support_text')) ?></li>
          </ul>
        </section>

        <section id="purposes">
          <h2>3. <?= e(t('legal.privacy_h_purposes')) ?></h2>
          <ul>
            <li><?= e(t('legal.privacy_purpose_1')) ?></li>
            <li><?= e(t('legal.privacy_purpose_2')) ?></li>
            <li><?= e(t('legal.privacy_purpose_3')) ?></li>
            <li><?= e(t('legal.privacy_purpose_4')) ?></li>
            <li><?= e(t('legal.privacy_purpose_5')) ?></li>
            <li><?= e(t('legal.privacy_purpose_6')) ?></li>
          </ul>
        </section>

        <section id="legal_basis">
          <h2>4. <?= e(t('legal.privacy_h_legal_basis')) ?></h2>
          <p><?= nl2br(e(t('legal.privacy_p_legal_basis'))) ?></p>
        </section>

        <section id="retention">
          <h2>5. <?= e(t('legal.privacy_h_retention')) ?></h2>
          <p><?= e(t('legal.privacy_p_retention_intro')) ?></p>
          <ul>
            <li><strong><?= e(t('legal.privacy_retention_account_label')) ?> :</strong>
                <?= e(t('legal.privacy_retention_account_text')) ?></li>
            <li><strong><?= e(t('legal.privacy_retention_logs_label')) ?> :</strong>
                <?= e(t('legal.privacy_retention_logs_text')) ?></li>
            <li><strong><?= e(t('legal.privacy_retention_payment_label')) ?> :</strong>
                <?= e(t('legal.privacy_retention_payment_text')) ?></li>
            <li><strong><?= e(t('legal.privacy_retention_support_label')) ?> :</strong>
                <?= e(t('legal.privacy_retention_support_text')) ?></li>
          </ul>
        </section>

        <section id="subprocessors">
          <h2>6. <?= e(t('legal.privacy_h_subprocessors')) ?></h2>
          <p><?= e(t('legal.privacy_p_subprocessors_intro')) ?></p>
          <div class="wt-table-wrap">
            <table class="wt-table wt-legal-v2__table">
              <thead>
                <tr>
                  <th><?= e(t('legal.privacy_sub_col_name')) ?></th>
                  <th><?= e(t('legal.privacy_sub_col_purpose')) ?></th>
                  <th><?= e(t('legal.privacy_sub_col_location')) ?></th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td><strong>Stripe</strong></td>
                  <td><?= e(t('legal.privacy_sub_purpose_stripe')) ?></td>
                  <td>UE / US</td>
                </tr>
                <tr>
                  <td><strong>PayPal</strong></td>
                  <td><?= e(t('legal.privacy_sub_purpose_paypal')) ?></td>
                  <td>UE / US</td>
                </tr>
                <tr>
                  <td><strong>Google AdSense</strong></td>
                  <td><?= e(t('legal.privacy_sub_purpose_adsense')) ?></td>
                  <td>UE / US</td>
                </tr>
                <tr>
                  <td><strong>SMTP / Email</strong></td>
                  <td><?= e(t('legal.privacy_sub_purpose_smtp')) ?></td>
                  <td>UE</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section id="transfers">
          <h2>7. <?= e(t('legal.privacy_h_transfers')) ?></h2>
          <p><?= nl2br(e(t('legal.privacy_p_transfers'))) ?></p>
        </section>

        <section id="cookies_link">
          <h2>8. <?= e(t('legal.privacy_h_cookies')) ?></h2>
          <p>
            <?= e(t('legal.privacy_p_cookies')) ?>
            <a href="<?= e(wt_url('/legal/cookies.php')) ?>">
              <?= e(t('legal.cookies_title')) ?>
            </a>.
          </p>
        </section>

        <section id="rights">
          <h2>9. <?= e(t('legal.privacy_h_rights')) ?></h2>
          <p><?= e(t('legal.privacy_p_rights_intro')) ?></p>
          <ul>
            <li><strong><?= e(t('legal.privacy_right_access')) ?></strong> —
                <?= e(t('legal.privacy_right_access_text')) ?></li>
            <li><strong><?= e(t('legal.privacy_right_rectify')) ?></strong> —
                <?= e(t('legal.privacy_right_rectify_text')) ?></li>
            <li><strong><?= e(t('legal.privacy_right_erase')) ?></strong> —
                <?= e(t('legal.privacy_right_erase_text')) ?></li>
            <li><strong><?= e(t('legal.privacy_right_portability')) ?></strong> —
                <?= e(t('legal.privacy_right_portability_text')) ?></li>
            <li><strong><?= e(t('legal.privacy_right_opposition')) ?></strong> —
                <?= e(t('legal.privacy_right_opposition_text')) ?></li>
            <li><strong><?= e(t('legal.privacy_right_limit')) ?></strong> —
                <?= e(t('legal.privacy_right_limit_text')) ?></li>
          </ul>
          <p><?= nl2br(e(t('legal.privacy_p_rights_exercise'))) ?></p>
        </section>

        <section id="security">
          <h2>10. <?= e(t('legal.privacy_h_security')) ?></h2>
          <p><?= e(t('legal.privacy_p_security_intro')) ?></p>
          <ul>
            <li><?= e(t('legal.privacy_security_1')) ?></li>
            <li><?= e(t('legal.privacy_security_2')) ?></li>
            <li><?= e(t('legal.privacy_security_3')) ?></li>
            <li><?= e(t('legal.privacy_security_4')) ?></li>
            <li><?= e(t('legal.privacy_security_5')) ?></li>
          </ul>
        </section>

        <section id="minors">
          <h2>11. <?= e(t('legal.privacy_h_minors')) ?></h2>
          <p><?= nl2br(e(t('legal.privacy_p_minors'))) ?></p>
        </section>

        <section id="contact">
          <h2>12. <?= e(t('legal.privacy_h_contact')) ?></h2>
          <p>
            <?= e(t('legal.privacy_p_contact')) ?>
            <a href="<?= e(wt_url('/help/contact.php')) ?>">
              <?= e(t('legal.contact_us')) ?>
            </a>.
          </p>
        </section>

        <footer class="wt-legal-v2__footer">
          <p><?= e(t('legal.contact_question')) ?>
             <a href="<?= e(wt_url('/help/contact.php')) ?>">
               <?= e(t('legal.contact_us')) ?> →
             </a>
          </p>
        </footer>

      </article>
    </div>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
