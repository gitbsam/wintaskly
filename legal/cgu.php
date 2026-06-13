<?php
/**
 * Wintaskly — Conditions Générales d'Utilisation (V8 modernisée).
 *
 * Contenu juridique entièrement passé en i18n (clés legal.cgu_*).
 * Sections enrichies pour couvrir l'ensemble des modules actifs :
 *   PTC, Offerwalls, Retraits, Leaderboard, 2FA, âge minimum,
 *   résiliation, modification des CGU.
 *
 * Layout V8 : header avec navigation entre les 3 pages légales,
 * table des matières sticky desktop, article scrollable.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

$pageTitle = t('legal.cgu');
$updatedAt = '2026-05-23';
$siteName  = (string) cfg('site_name', 'Wintaskly');

// Cache public 1h (page rarement modifiée). ETag basé sur la date de
// mise à jour + langue active pour invalider correctement au changement.
wt_static_cache_headers(3600, 'cgu-' . $updatedAt . '-' . ($GLOBALS['WT_LANG_CODE'] ?? 'fr'));

/* Table des matières : [id => clé i18n du titre] */
$toc = [
    'object'        => 'legal.cgu_h_object',
    'account'       => 'legal.cgu_h_account',
    'age'           => 'legal.cgu_h_age',
    'faucet'        => 'legal.cgu_h_faucet',
    'shortlinks'    => 'legal.cgu_h_shortlinks',
    'ptc'           => 'legal.cgu_h_ptc',
    'offerwalls'    => 'legal.cgu_h_offerwalls',
    'leaderboard'   => 'legal.cgu_h_leaderboard',
    'referral'      => 'legal.cgu_h_referral',
    'withdrawals'   => 'legal.cgu_h_withdrawals',
    'security'      => 'legal.cgu_h_security',
    'forbidden'     => 'legal.cgu_h_forbidden',
    'coins'         => 'legal.cgu_h_coins',
    'termination'   => 'legal.cgu_h_termination',
    'modifications' => 'legal.cgu_h_modifications',
    'data'          => 'legal.cgu_h_data',
    'law'           => 'legal.cgu_h_law',
];

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-legal-v2" data-reveal>
  <div class="wt-legal-v2__wrap">

    <!-- ============ HEADER + NAV ============ -->
    <header class="wt-legal-v2__header">
      <span class="wt-eyebrow">📜 <?= e(t('legal.eyebrow')) ?></span>
      <h1 class="wt-legal-v2__title"><?= e(t('legal.cgu')) ?></h1>
      <p class="wt-legal-v2__lead">
        <?= e(t('legal.cgu_lead', ['site' => $siteName])) ?>
      </p>

      <div class="wt-legal-v2__meta">
        <span class="wt-legal-v2__updated">
          🕐 <?= e(sprintf((string) t('legal.last_updated'), $updatedAt)) ?>
        </span>
      </div>

      <nav class="wt-legal-v2__nav" aria-label="<?= e(t('legal.nav_label')) ?>">
        <a class="wt-legal-v2__nav-link is-active"
           href="<?= e(wt_url('/legal/cgu.php')) ?>"><?= e(t('legal.cgu')) ?></a>
        <a class="wt-legal-v2__nav-link"
           href="<?= e(wt_url('/legal/privacy.php')) ?>"><?= e(t('legal.privacy')) ?></a>
        <a class="wt-legal-v2__nav-link"
           href="<?= e(wt_url('/legal/cookies.php')) ?>"><?= e(t('legal.cookies_title')) ?></a>
      </nav>
    </header>

    <!-- ============ LAYOUT 2-COL : TOC + ARTICLE ============ -->
    <div class="wt-legal-v2__grid">

      <!-- TOC -->
      <aside class="wt-legal-v2__toc" aria-label="<?= e(t('legal.toc_label')) ?>">
        <strong class="wt-legal-v2__toc-title">📑 <?= e(t('legal.toc_title')) ?></strong>
        <ol class="wt-legal-v2__toc-list">
          <?php foreach ($toc as $id => $key): ?>
            <li>
              <a href="#<?= e($id) ?>"><?= e(t($key)) ?></a>
            </li>
          <?php endforeach; ?>
        </ol>
      </aside>

      <!-- Article -->
      <article class="wt-legal-v2__article">

        <section id="object">
          <h2>1. <?= e(t('legal.cgu_h_object')) ?></h2>
          <p><?= nl2br(e(t('legal.cgu_p_object', ['site' => $siteName]))) ?></p>
        </section>

        <section id="account">
          <h2>2. <?= e(t('legal.cgu_h_account')) ?></h2>
          <p><?= nl2br(e(t('legal.cgu_p_account'))) ?></p>
        </section>

        <section id="age">
          <h2>3. <?= e(t('legal.cgu_h_age')) ?></h2>
          <p><?= nl2br(e(t('legal.cgu_p_age'))) ?></p>
        </section>

        <section id="faucet">
          <h2>4. <?= e(t('legal.cgu_h_faucet')) ?></h2>
          <p><?= nl2br(e(t('legal.cgu_p_faucet'))) ?></p>
        </section>

        <section id="shortlinks">
          <h2>5. <?= e(t('legal.cgu_h_shortlinks')) ?></h2>
          <p><?= nl2br(e(t('legal.cgu_p_shortlinks'))) ?></p>
        </section>

        <section id="ptc">
          <h2>6. <?= e(t('legal.cgu_h_ptc')) ?></h2>
          <p><?= nl2br(e(t('legal.cgu_p_ptc'))) ?></p>
        </section>

        <section id="offerwalls">
          <h2>7. <?= e(t('legal.cgu_h_offerwalls')) ?></h2>
          <p><?= nl2br(e(t('legal.cgu_p_offerwalls'))) ?></p>
        </section>

        <section id="leaderboard">
          <h2>8. <?= e(t('legal.cgu_h_leaderboard')) ?></h2>
          <p><?= nl2br(e(t('legal.cgu_p_leaderboard'))) ?></p>
        </section>

        <section id="referral">
          <h2>9. <?= e(t('legal.cgu_h_referral')) ?></h2>
          <p><?= nl2br(e(t('legal.cgu_p_referral'))) ?></p>
        </section>

        <section id="withdrawals">
          <h2>10. <?= e(t('legal.cgu_h_withdrawals')) ?></h2>
          <p><?= nl2br(e(t('legal.cgu_p_withdrawals'))) ?></p>
        </section>

        <section id="security">
          <h2>11. <?= e(t('legal.cgu_h_security')) ?></h2>
          <p><?= nl2br(e(t('legal.cgu_p_security'))) ?></p>
        </section>

        <section id="forbidden">
          <h2>12. <?= e(t('legal.cgu_h_forbidden')) ?></h2>
          <p><?= e(t('legal.cgu_p_forbidden_intro')) ?></p>
          <ul>
            <li><?= e(t('legal.cgu_p_forbidden_1')) ?></li>
            <li><?= e(t('legal.cgu_p_forbidden_2')) ?></li>
            <li><?= e(t('legal.cgu_p_forbidden_3')) ?></li>
            <li><?= e(t('legal.cgu_p_forbidden_4')) ?></li>
            <li><?= e(t('legal.cgu_p_forbidden_5')) ?></li>
          </ul>
          <p><?= e(t('legal.cgu_p_forbidden_consequence')) ?></p>
        </section>

        <section id="coins">
          <h2>13. <?= e(t('legal.cgu_h_coins')) ?></h2>
          <p><?= nl2br(e(t('legal.cgu_p_coins'))) ?></p>
        </section>

        <section id="termination">
          <h2>14. <?= e(t('legal.cgu_h_termination')) ?></h2>
          <p><?= nl2br(e(t('legal.cgu_p_termination'))) ?></p>
        </section>

        <section id="modifications">
          <h2>15. <?= e(t('legal.cgu_h_modifications')) ?></h2>
          <p><?= nl2br(e(t('legal.cgu_p_modifications'))) ?></p>
        </section>

        <section id="data">
          <h2>16. <?= e(t('legal.cgu_h_data')) ?></h2>
          <p>
            <?= e(t('legal.cgu_p_data')) ?>
            <a href="<?= e(wt_url('/legal/privacy.php')) ?>"><?= e(t('legal.privacy')) ?></a>.
          </p>
        </section>

        <section id="law">
          <h2>17. <?= e(t('legal.cgu_h_law')) ?></h2>
          <p><?= nl2br(e(t('legal.cgu_p_law'))) ?></p>
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
