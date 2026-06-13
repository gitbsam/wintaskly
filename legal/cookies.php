<?php
/**
 * Wintaskly — Politique de cookies (V8 modernisée).
 *
 * Page CNIL-compliant : identification du responsable, liste des
 * cookies, finalités, durées, retrait du consentement.
 *
 * Le contenu était déjà entièrement en i18n (legal.cookies.*) avant
 * cette V8. La refonte modernise uniquement le layout pour cohérence
 * visuelle avec /legal/cgu.php et /legal/privacy.php.
 *
 * Compat : conserve l'attribut [data-cookie-reopen] qui déclenche la
 * réouverture de la bannière de consentement.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

$pageTitle = t('legal.cookies_title');
$updatedAt = '2026-05-23';
$siteName  = (string) cfg('site_name', 'Wintaskly');

// Cache public 1h (page rarement modifiée).
wt_static_cache_headers(3600, 'cookies-' . $updatedAt . '-' . ($GLOBALS['WT_LANG_CODE'] ?? 'fr'));

$toc = [
    'what'      => 'legal.cookies.h_what_title',
    'types'     => 'legal.cookies.h_types_title',
    'manage'    => 'legal.cookies.h_manage_title',
    'rights'    => 'legal.cookies.h_rights_title',
    'contact'   => 'legal.cookies.h_contact_title',
];

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-legal-v2" data-reveal>
  <div class="wt-legal-v2__wrap">

    <!-- ============ HEADER + NAV ============ -->
    <header class="wt-legal-v2__header">
      <span class="wt-eyebrow">🍪 <?= e(t('legal.eyebrow')) ?></span>
      <h1 class="wt-legal-v2__title"><?= e(t('legal.cookies_title')) ?></h1>
      <p class="wt-legal-v2__lead"><?= e(t('legal.cookies_lead')) ?></p>

      <div class="wt-legal-v2__meta">
        <span class="wt-legal-v2__updated">
          🕐 <?= e(sprintf((string) t('legal.last_updated'), $updatedAt)) ?>
        </span>
      </div>

      <nav class="wt-legal-v2__nav" aria-label="<?= e(t('legal.nav_label')) ?>">
        <a class="wt-legal-v2__nav-link"
           href="<?= e(wt_url('/legal/cgu.php')) ?>"><?= e(t('legal.cgu')) ?></a>
        <a class="wt-legal-v2__nav-link"
           href="<?= e(wt_url('/legal/privacy.php')) ?>"><?= e(t('legal.privacy')) ?></a>
        <a class="wt-legal-v2__nav-link is-active"
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

        <section id="what">
          <h2>1. <?= e(t('legal.cookies.h_what_title')) ?></h2>
          <p><?= e(t('legal.cookies.h_what_body')) ?></p>
        </section>

        <section id="types">
          <h2>2. <?= e(t('legal.cookies.h_types_title')) ?></h2>

          <h3 class="wt-legal-v2__sub">🔧 <?= e(t('legal.cookies.essential_title')) ?></h3>
          <p><?= e(t('legal.cookies.essential_body')) ?></p>
          <div class="wt-table-wrap">
            <table class="wt-table wt-legal-v2__table">
              <thead>
                <tr>
                  <th><?= e(t('legal.cookies.col_name')) ?></th>
                  <th><?= e(t('legal.cookies.col_purpose')) ?></th>
                  <th><?= e(t('legal.cookies.col_duration')) ?></th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td><code>PHPSESSID</code></td>
                  <td><?= e(t('legal.cookies.purpose_session')) ?></td>
                  <td><?= e(t('legal.cookies.dur_session')) ?></td>
                </tr>
                <tr>
                  <td><code>wt_remember</code></td>
                  <td><?= e(t('legal.cookies.purpose_remember')) ?></td>
                  <td>60 <?= e(t('common.days')) ?></td>
                </tr>
                <tr>
                  <td><code>wt_lang</code></td>
                  <td><?= e(t('legal.cookies.purpose_lang')) ?></td>
                  <td>1 <?= e(t('common.year')) ?></td>
                </tr>
                <tr>
                  <td><code>wt_theme</code></td>
                  <td><?= e(t('legal.cookies.purpose_theme')) ?></td>
                  <td>1 <?= e(t('common.year')) ?></td>
                </tr>
                <tr>
                  <td><code>wt_tz</code></td>
                  <td><?= e(t('legal.cookies.purpose_tz')) ?></td>
                  <td>1 <?= e(t('common.year')) ?></td>
                </tr>
                <tr>
                  <td><code>wt_consent</code></td>
                  <td><?= e(t('legal.cookies.purpose_consent')) ?></td>
                  <td>6 <?= e(t('common.months')) ?></td>
                </tr>
              </tbody>
            </table>
          </div>

          <h3 class="wt-legal-v2__sub">📊 <?= e(t('legal.cookies.analytics_title')) ?></h3>
          <p><?= e(t('legal.cookies.analytics_body')) ?></p>

          <h3 class="wt-legal-v2__sub">📢 <?= e(t('legal.cookies.ads_title')) ?></h3>
          <p><?= e(t('legal.cookies.ads_body')) ?></p>
        </section>

        <section id="manage">
          <h2>3. <?= e(t('legal.cookies.h_manage_title')) ?></h2>
          <p><?= e(t('legal.cookies.h_manage_body')) ?></p>
          <p>
            <button type="button" class="wt-btn wt-btn--primary" data-cookie-reopen>
              ⚙️ <?= e(t('legal.cookies.reopen_prefs')) ?>
            </button>
          </p>
        </section>

        <section id="rights">
          <h2>4. <?= e(t('legal.cookies.h_rights_title')) ?></h2>
          <p><?= e(t('legal.cookies.h_rights_body')) ?></p>
        </section>

        <section id="contact">
          <h2>5. <?= e(t('legal.cookies.h_contact_title')) ?></h2>
          <p>
            <?= e(t('legal.cookies.h_contact_body')) ?>
            <a href="<?= e(wt_url('/help/contact.php')) ?>"><?= e(t('nav.help')) ?></a>.
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
