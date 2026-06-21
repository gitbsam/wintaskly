<?php
/**
 * Wintaskly — Partial sidebar admin (V8 modernisé).
 *
 * À inclure dans chaque page /admin/* APRES le header global.
 *
 * Variable attendue :
 *   $adminActive ∈ {'dash','users','security','faucet','shortlinks','ptc',
 *                   'offerwalls','withdrawals','leaderboard','testimonials',
 *                   'tickets','messages','homepage','cron'}
 *
 * V8 :
 *   - 5 sections groupées avec section-titles mono uppercase
 *   - Active-state avec liseré gauche dégradé
 *   - Animation cascade par section
 *   - Icônes SVG cohérentes pour chaque lien
 */
$adminActive = $adminActive ?? '';
$base = rtrim($GLOBALS['WT_CONFIG']['base_url'] ?? '', '/');
?>
<!-- Backdrop pour le drawer mobile (≤959px) — caché sur desktop -->
<div class="wt-sidebar-backdrop" data-sidebar-backdrop aria-hidden="true"></div>

<aside class="wt-admin-v2__sidebar wt-sidebar" id="wt-sidebar-drawer"
       aria-label="Menu latéral admin">

  <!-- Brand -->
  <div class="wt-admin-v2__brand">
    <span class="wt-brand__mark">W</span>
    <span><?= e(t('admin.title')) ?></span>
  </div>

  <!-- ====== Section : Vue d'ensemble ====== -->
  <nav class="wt-admin-v2__nav">
    <h3 class="wt-admin-v2__nav-title"><?= e(t('admin.section_overview')) ?></h3>

    <a href="<?= $base ?>/admin/" class="<?= $adminActive==='dash' ? 'is-active' : '' ?>" style="--idx:0">
      <span class="wt-admin-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/>
          <rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/>
        </svg>
      </span>
      <?= e(t('admin.dash')) ?>
    </a>
  </nav>

  <!-- ====== Section : Modération ====== -->
  <nav class="wt-admin-v2__nav">
    <h3 class="wt-admin-v2__nav-title"><?= e(t('admin.section_moderation')) ?></h3>

    <a href="<?= $base ?>/admin/users.php" class="<?= $adminActive==='users' ? 'is-active' : '' ?>" style="--idx:0">
      <span class="wt-admin-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
          <circle cx="9" cy="7" r="4"/>
          <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
          <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
        </svg>
      </span>
      <?= e(t('admin.users')) ?>
    </a>

    <a href="<?= $base ?>/admin/security.php" class="<?= $adminActive==='security' ? 'is-active' : '' ?>" style="--idx:1">
      <span class="wt-admin-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 2L4 7v5c0 5 3.5 9 8 10 4.5-1 8-5 8-10V7l-8-5z"/>
        </svg>
      </span>
      <?= e(t('admin.security')) ?>
    </a>

    <a href="<?= $base ?>/admin/tickets.php" class="<?= $adminActive==='tickets' ? 'is-active' : '' ?>" style="--idx:2">
      <span class="wt-admin-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"/>
          <path d="M4 6v12c0 1.1.9 2 2 2h14v-4"/>
          <path d="M18 12a2 2 0 0 0 0 4h4v-4z"/>
        </svg>
      </span>
      <?= e(t('admin.tickets')) ?>
    </a>

    <a href="<?= $base ?>/admin/messages.php" class="<?= $adminActive==='messages' ? 'is-active' : '' ?>" style="--idx:3">
      <span class="wt-admin-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
      </span>
      <?= e(t('admin.messages')) ?>
    </a>

    <a href="<?= $base ?>/admin/testimonials.php" class="<?= $adminActive==='testimonials' ? 'is-active' : '' ?>" style="--idx:4">
      <span class="wt-admin-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
        </svg>
      </span>
      <?= e(t('admin.testimonials')) ?>
    </a>
  </nav>

  <!-- ====== Section : Tâches (config) ====== -->
  <nav class="wt-admin-v2__nav">
    <h3 class="wt-admin-v2__nav-title"><?= e(t('admin.section_tasks')) ?></h3>

    <a href="<?= $base ?>/admin/faucet.php" class="<?= $adminActive==='faucet' ? 'is-active' : '' ?>" style="--idx:0">
      <span class="wt-admin-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/>
        </svg>
      </span>
      <?= e(t('admin.faucet')) ?>
    </a>

    <a href="<?= $base ?>/admin/shortlinks.php" class="<?= $adminActive==='shortlinks' ? 'is-active' : '' ?>" style="--idx:1">
      <span class="wt-admin-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
          <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
        </svg>
      </span>
      <?= e(t('admin.shortlinks')) ?>
    </a>

    <a href="<?= $base ?>/admin/ptc.php" class="<?= $adminActive==='ptc' ? 'is-active' : '' ?>" style="--idx:2">
      <span class="wt-admin-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
          <line x1="8" y1="21" x2="16" y2="21"/>
          <line x1="12" y1="17" x2="12" y2="21"/>
        </svg>
      </span>
      <?= e(t('admin.ptc')) ?>
    </a>

    <a href="<?= $base ?>/admin/offerwalls.php" class="<?= $adminActive==='offerwalls' ? 'is-active' : '' ?>" style="--idx:3">
      <span class="wt-admin-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="20 12 20 22 4 22 4 12"/>
          <rect x="2" y="7" width="20" height="5"/>
          <line x1="12" y1="22" x2="12" y2="7"/>
          <path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/>
          <path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>
        </svg>
      </span>
      <?= e(t('admin.offerwalls')) ?>
    </a>
  </nav>

  <!-- ====== Section : Finance ====== -->
  <nav class="wt-admin-v2__nav">
    <h3 class="wt-admin-v2__nav-title"><?= e(t('admin.section_finance')) ?></h3>

    <a href="<?= $base ?>/admin/withdrawals.php" class="<?= $adminActive==='withdrawals' ? 'is-active' : '' ?>" style="--idx:0">
      <span class="wt-admin-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="12" y1="1" x2="12" y2="23"/>
          <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
        </svg>
      </span>
      <?= e(t('admin.withdrawals')) ?>
    </a>

    <a href="<?= $base ?>/admin/payment_methods.php" class="<?= $adminActive==='payment_methods' ? 'is-active' : '' ?>" style="--idx:1">
      <span class="wt-admin-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="2" y="6" width="20" height="14" rx="2"/>
          <line x1="2" y1="11" x2="22" y2="11"/>
          <line x1="6" y1="16" x2="10" y2="16"/>
        </svg>
      </span>
      <?= e(t('admin.payment_methods')) ?>
    </a>

    <a href="<?= $base ?>/admin/leaderboard.php" class="<?= $adminActive==='leaderboard' ? 'is-active' : '' ?>" style="--idx:2">
      <span class="wt-admin-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/>
          <path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/>
          <path d="M4 22h16M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/>
          <path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>
        </svg>
      </span>
      <?= e(t('admin.leaderboard')) ?>
    </a>
  </nav>

  <!-- ====== Section : Système ====== -->
  <nav class="wt-admin-v2__nav">
    <h3 class="wt-admin-v2__nav-title"><?= e(t('admin.section_system')) ?></h3>

    <a href="<?= $base ?>/admin/homepage.php" class="<?= $adminActive==='homepage' ? 'is-active' : '' ?>" style="--idx:0">
      <span class="wt-admin-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
          <polyline points="9 22 9 12 15 12 15 22"/>
        </svg>
      </span>
      <?= e(t('admin.homepage')) ?>
    </a>

    <a href="<?= $base ?>/admin/settings.php" class="<?= $adminActive==='settings' ? 'is-active' : '' ?>" style="--idx:1">
      <span class="wt-admin-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="3"/>
          <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
        </svg>
      </span>
      <?= e(t('admin.settings')) ?>
    </a>

    <a href="<?= $base ?>/admin/cron.php" class="<?= $adminActive==='cron' ? 'is-active' : '' ?>" style="--idx:2">
      <span class="wt-admin-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="10"/>
          <polyline points="12 6 12 12 16 14"/>
        </svg>
      </span>
      <?= e(t('admin.cron')) ?>
    </a>

    <a href="<?= $base ?>/admin/updates.php" class="<?= $adminActive==='updates' ? 'is-active' : '' ?>" style="--idx:3">
      <span class="wt-admin-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="23 4 23 10 17 10"/>
          <polyline points="1 20 1 14 7 14"/>
          <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
        </svg>
      </span>
      <?= e(t('admin.updates')) ?>
      <?php if (function_exists('wt_update_has_pending') && wt_update_has_pending()): ?>
        <span style="margin-left:auto;background:<?= wt_update_is_critical() ? 'var(--wt-danger,#ef4444)' : 'var(--wt-accent)' ?>;color:#fff;font-size:.65rem;padding:.15rem .4rem;border-radius:8px;font-weight:700">
          <?= wt_update_is_critical() ? '!' : 'NEW' ?>
        </span>
      <?php endif; ?>
    </a>

    <a href="<?= $base ?>/admin/ads.php" class="<?= $adminActive==='ads' ? 'is-active' : '' ?>" style="--idx:4">
      <span class="wt-admin-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="3" width="18" height="18" rx="2"/>
          <path d="M7 7h10M7 12h10M7 17h6"/>
        </svg>
      </span>
      <?= e(t('admin.ads')) ?>
    </a>

    <a href="<?= $base ?>/admin/daily-bonus.php" class="<?= $adminActive==='daily' ? 'is-active' : '' ?>" style="--idx:5">
      <span class="wt-admin-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="20 12 20 22 4 22 4 12"/>
          <rect x="2" y="7" width="20" height="5"/>
          <line x1="12" y1="22" x2="12" y2="7"/>
          <path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/>
          <path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>
        </svg>
      </span>
      <?= e(t('admin.daily')) ?>
    </a>

    <a href="<?= $base ?>/admin/achievements.php" class="<?= $adminActive==='achievements' ? 'is-active' : '' ?>" style="--idx:6">
      <span class="wt-admin-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="8" r="6"/>
          <path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/>
        </svg>
      </span>
      <?= e(t('admin.achievements')) ?>
    </a>

    <a href="<?= $base ?>/admin/blog.php" class="<?= $adminActive==='blog' ? 'is-active' : '' ?>" style="--idx:7">
      <span class="wt-admin-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
          <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
        </svg>
      </span>
      <?= e(t('admin.blog')) ?>
    </a>

    <a href="<?= $base ?>/admin/antifraud.php" class="<?= $adminActive==='antifraud' ? 'is-active' : '' ?>" style="--idx:8">
      <span class="wt-admin-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
          <path d="M9 12l2 2 4-4"/>
        </svg>
      </span>
      <?= e(t('admin.antifraud')) ?>
    </a>
  </nav>
</aside>
