<?php
/**
 * Wintaskly — Page Achievements (galerie utilisateur)
 *
 * Affiche TOUS les succès : débloqués (avec date) et à venir (avec barre
 * de progression). Regroupés visuellement par rang (tier).
 *
 * Données fournies par wt_ach_user_list() qui calcule la progression de
 * chaque métrique une seule fois (groupée).
 */
declare(strict_types=1);
require __DIR__ . '/includes/init.php';
require_auth();

$u = current_user();

// Filet de sécurité : vérifie les déblocages au chargement (en plus du
// temps réel via award_user). Capture les éventuels nouveaux badges.
if (function_exists('wt_ach_check')) {
    wt_ach_check((int)$u['id'], $u);
}

$pageTitle = t('ach.page_title');

// Système désactivé → message simple
if (!wt_ach_enabled()) {
    include __DIR__ . '/header.php';
    echo '<main class="wt-main"><div class="wt-card wt-card--padded" style="text-align:center;margin:2rem auto;max-width:500px">'
       . '<h1>🏆 ' . e(t('ach.page_title')) . '</h1>'
       . '<p class="wt-muted">' . e(t('ach.disabled')) . '</p></div></main>';
    include __DIR__ . '/footer.php';
    exit;
}

$list = wt_ach_user_list((int)$u['id'], $u);
$total = count($list);
$unlocked = count(array_filter($list, fn($x) => $x['unlocked']));
$percent = $total > 0 ? (int) floor(($unlocked / $total) * 100) : 0;

include __DIR__ . '/header.php';
?>

<main class="wt-main wt-ach-page">
  <div class="wt-ach-page__wrap">

    <!-- En-tête avec progression globale -->
    <header class="wt-ach-page__header" data-reveal>
      <div>
        <span class="wt-eyebrow">🏆 <?= e(t('ach.eyebrow')) ?></span>
        <h1 class="wt-ach-page__title"><?= e(t('ach.page_title')) ?></h1>
        <p class="wt-muted"><?= e(t('ach.page_lead')) ?></p>
      </div>
      <div class="wt-ach-page__progress">
        <div class="wt-ach-page__progress-ring" style="--pct:<?= $percent ?>">
          <span class="wt-ach-page__progress-num"><?= $unlocked ?>/<?= $total ?></span>
        </div>
        <span class="wt-ach-page__progress-label"><?= e(sprintf((string) t('ach.unlocked_count'), $percent)) ?></span>
      </div>
    </header>

    <!-- Grille des badges -->
    <div class="wt-ach-grid" data-reveal>
      <?php foreach ($list as $item):
        $a        = $item['ach'];
        $unlocked = $item['unlocked'];
        $percent  = $item['percent'];
        $tier     = $a['tier'];
        $icon     = $a['icon'] ?: '🏅';
      ?>
        <article class="wt-ach-card wt-ach-card--<?= e($tier) ?> <?= $unlocked ? 'is-unlocked' : 'is-locked' ?>">
          <div class="wt-ach-card__icon"><?= e($icon) ?></div>
          <div class="wt-ach-card__body">
            <h3 class="wt-ach-card__title"><?= e($a['title']) ?></h3>
            <?php if (!empty($a['description'])): ?>
              <p class="wt-ach-card__desc"><?= e($a['description']) ?></p>
            <?php endif; ?>

            <?php if ($unlocked): ?>
              <div class="wt-ach-card__unlocked">
                ✓ <?= e(t('ach.unlocked_on')) ?> <?= e(wt_format_datetime($item['unlocked_at'], 'd/m/Y')) ?>
              </div>
            <?php else: ?>
              <div class="wt-ach-card__progress">
                <div class="wt-ach-card__bar">
                  <div class="wt-ach-card__bar-fill" style="width:<?= $percent ?>%"></div>
                </div>
                <span class="wt-ach-card__progress-txt">
                  <?= e(wt_format_coins($item['current'])) ?> / <?= e(wt_format_coins($item['threshold'])) ?>
                </span>
              </div>
            <?php endif; ?>

            <div class="wt-ach-card__reward">
              <?php if ((float)$a['reward_coins'] > 0): ?>
                💰 <?= e(wt_format_coins((float)$a['reward_coins'])) ?>
              <?php endif; ?>
              <?php if ((int)$a['reward_xp'] > 0): ?>
                <span style="opacity:.7">· ⭐ <?= (int)$a['reward_xp'] ?> XP</span>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($unlocked): ?>
            <div class="wt-ach-card__badge-check">✓</div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>

  </div>
</main>

<?php include __DIR__ . '/footer.php'; ?>
