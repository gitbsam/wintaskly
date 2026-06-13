<?php
/**
 * Wintaskly — Partial sidebar du dashboard (V8 modernisé).
 *
 * À inclure dans chaque page /dashboard/* APRES le header global et
 * AVANT l'ouverture du <section class="wt-dash__content">.
 *
 * Variable attendue :
 *   $dashActive ∈ {'overview','messages','notifications','referrals',
 *                  'withdraw','tasks','leaderboard','account','settings'}
 *
 * Améliorations V8 :
 *   - Profile card avec progression XP intégrée
 *   - Sections groupées (Vue d'ensemble / Compte / Préférences)
 *   - Hover/active states plus marqués avec liserés
 *   - Animation cascade des items au reveal
 */
$dashActive = $dashActive ?? '';
$base = rtrim($GLOBALS['WT_CONFIG']['base_url'] ?? '', '/');
$u    = current_user();

/* Compteurs en badge */
$msgUnread = $u ? wt_messages_unread_count((int)$u['id'])      : 0;
$notUnread = $u ? wt_notifications_unread_count((int)$u['id']) : 0;

/* Progression XP — pour la mini-jauge dans le profile card */
$xpProg = $u ? xp_progress((int) $u['xp']) : ['percent' => 0, 'current_xp' => 0, 'xp_for_next' => 1, 'next_level' => 1];
?>
<!-- Backdrop pour le drawer mobile (≤959px) — caché sur desktop -->
<div class="wt-sidebar-backdrop" data-sidebar-backdrop aria-hidden="true"></div>

<aside class="wt-dash-v2__sidebar wt-sidebar" id="wt-sidebar-drawer" data-reveal
       aria-label="Menu latéral utilisateur">

  <!-- ====== Profile card avec XP ====== -->
  <?php if ($u): ?>
    <div class="wt-dash-v2__profile-card">
      <div class="wt-avatar wt-avatar--lg"
           data-hash-color="<?= e($u['username']) ?>"><?= wt_avatar_inner($u) ?></div>
      <div class="wt-dash-v2__profile-info">
        <strong><?= e($u['username']) ?></strong>
        <span class="wt-dash-v2__level-pill">
          ⚡ <?= e(t('common.level')) ?> <?= (int)$u['level'] ?>
        </span>
      </div>

      <div class="wt-dash-v2__xp-mini" aria-label="XP">
        <div class="wt-dash-v2__xp-bar">
          <div class="wt-dash-v2__xp-fill" style="width: <?= (int)$xpProg['percent'] ?>%"></div>
        </div>
        <small><?= (int)$xpProg['current_xp'] ?> / <?= (int)$xpProg['xp_for_next'] ?> XP</small>
      </div>
    </div>
  <?php endif; ?>

  <!-- ====== Section : Vue d'ensemble ====== -->
  <nav class="wt-dash-v2__nav">
    <h3 class="wt-dash-v2__nav-title"><?= e(t('dash.section_main')) ?></h3>

    <a href="<?= $base ?>/dashboard/"
       class="<?= $dashActive === 'overview' ? 'is-active' : '' ?>"
       style="--idx:0">
      <span class="wt-dash-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="3" width="7" height="9"/>
          <rect x="14" y="3" width="7" height="5"/>
          <rect x="14" y="12" width="7" height="9"/>
          <rect x="3" y="16" width="7" height="5"/>
        </svg>
      </span>
      <?= e(t('dash.overview')) ?>
    </a>

    <a href="<?= $base ?>/tasks/"
       class="<?= $dashActive === 'tasks' ? 'is-active' : '' ?>"
       style="--idx:1">
      <span class="wt-dash-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
        </svg>
      </span>
      <?= e(t('dash.tasks')) ?>
    </a>

    <a href="<?= $base ?>/leaderboard/"
       class="<?= $dashActive === 'leaderboard' ? 'is-active' : '' ?>"
       style="--idx:2">
      <span class="wt-dash-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/>
          <path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/>
          <path d="M4 22h16M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/>
          <path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>
        </svg>
      </span>
      <?= e(t('nav.leaderboard')) ?>
    </a>
  </nav>

  <!-- ====== Section : Communication ====== -->
  <nav class="wt-dash-v2__nav">
    <h3 class="wt-dash-v2__nav-title"><?= e(t('dash.section_comm')) ?></h3>

    <a href="<?= $base ?>/dashboard/messages.php"
       class="<?= $dashActive === 'messages' ? 'is-active' : '' ?>"
       style="--idx:0">
      <span class="wt-dash-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
      </span>
      <?= e(t('dash.messages')) ?>
      <?php if ($msgUnread > 0): ?>
        <span class="wt-pill-badge wt-pill-badge--inline"><?= e(wt_badge_count($msgUnread)) ?></span>
      <?php endif; ?>
    </a>

    <a href="<?= $base ?>/dashboard/notifications.php"
       class="<?= $dashActive === 'notifications' ? 'is-active' : '' ?>"
       style="--idx:1">
      <span class="wt-dash-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/>
          <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>
        </svg>
      </span>
      <?= e(t('dash.notifications')) ?>
      <?php if ($notUnread > 0): ?>
        <span class="wt-pill-badge wt-pill-badge--inline"><?= e(wt_badge_count($notUnread)) ?></span>
      <?php endif; ?>
    </a>
  </nav>

  <!-- ====== Section : Finance ====== -->
  <nav class="wt-dash-v2__nav">
    <h3 class="wt-dash-v2__nav-title"><?= e(t('dash.section_money')) ?></h3>

    <a href="<?= $base ?>/dashboard/referrals.php"
       class="<?= $dashActive === 'referrals' ? 'is-active' : '' ?>"
       style="--idx:0">
      <span class="wt-dash-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
          <circle cx="9" cy="7" r="4"/>
          <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
          <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
        </svg>
      </span>
      <?= e(t('dash.referrals')) ?>
    </a>

    <a href="<?= $base ?>/dashboard/withdraw.php"
       class="<?= $dashActive === 'withdraw' ? 'is-active' : '' ?>"
       style="--idx:1">
      <span class="wt-dash-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="12" y1="1" x2="12" y2="23"/>
          <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
        </svg>
      </span>
      <?= e(t('dash.withdraw')) ?>
    </a>
  </nav>

  <!-- ====== Section : Compte ====== -->
  <nav class="wt-dash-v2__nav">
    <h3 class="wt-dash-v2__nav-title"><?= e(t('dash.section_account')) ?></h3>

    <a href="<?= $base ?>/dashboard/account.php"
       class="<?= $dashActive === 'account' ? 'is-active' : '' ?>"
       style="--idx:0">
      <span class="wt-dash-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
          <circle cx="12" cy="7" r="4"/>
        </svg>
      </span>
      <?= e(t('dash.account')) ?>
    </a>

    <a href="<?= $base ?>/dashboard/settings.php"
       class="<?= $dashActive === 'settings' ? 'is-active' : '' ?>"
       style="--idx:1">
      <span class="wt-dash-v2__nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="3"/>
          <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
        </svg>
      </span>
      <?= e(t('dash.settings')) ?>
    </a>
  </nav>

  <?php if (($u['role'] ?? '') === 'admin'): ?>
    <div class="wt-dash-v2__admin-link">
      <a href="<?= $base ?>/admin/" class="wt-btn wt-btn--xs wt-btn--ghost wt-btn--block">
        🛡️ <?= e(t('admin.title')) ?>
      </a>
    </div>
  <?php endif; ?>
</aside>
