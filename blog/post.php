<?php
/**
 * Wintaskly — /blog/post.php
 *
 * Affiche un article de blog complet. Page de contenu riche, publique,
 * indexable — c'est LE type de page qui satisfait les exigences AdSense
 * de "contenu original et substantiel".
 *
 * Inclut : meta SEO (title + description + Open Graph), fil d'Ariane,
 * corps de l'article, articles liés, et 2 zones publicitaires
 * contextuelles (in-article).
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

$slug = isset($_GET['slug']) ? preg_replace('/[^a-z0-9-]/', '', (string)$_GET['slug']) : '';

$post = $slug !== '' ? wt_blog_post($slug) : null;

// Article introuvable → 404
if (!$post || !wt_blog_enabled()) {
    http_response_code(404);
    $pageTitle = '404';
    include __DIR__ . '/../header.php';
    echo '<main class="wt-main"><div style="text-align:center;padding:4rem 1rem"><h1>404</h1>'
       . '<p class="wt-muted">' . e(t('blog.not_found')) . '</p>'
       . '<a href="' . e(wt_url('/blog')) . '" class="wt-btn wt-btn--primary" style="margin-top:1rem">'
       . e(t('blog.back_to_list')) . '</a></div></main>';
    include __DIR__ . '/../footer.php';
    exit;
}

// Incrémente les vues (best-effort)
wt_blog_increment_views((int)$post['id']);

// Articles liés
$related = wt_blog_related((int)$post['id'], $post['category_id'] !== null ? (int)$post['category_id'] : null, 3);

// SEO — meta personnalisées si définies, sinon fallback sur le contenu
$pageTitle = $post['meta_title'] ?: $post['title'];
$metaDescription = $post['meta_description'] ?: ($post['excerpt'] ?: '');

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-article">
  <div class="wt-article__wrap">

    <!-- Fil d'Ariane -->
    <nav class="wt-article__breadcrumb" aria-label="breadcrumb">
      <a href="<?= e(wt_url('/blog')) ?>"><?= e(t('blog.eyebrow')) ?></a>
      <?php if (!empty($post['category_name'])): ?>
        <span>›</span>
        <a href="<?= e(wt_url('/blog/categorie/' . $post['category_slug'])) ?>"><?= e($post['category_name']) ?></a>
      <?php endif; ?>
    </nav>

    <!-- Bannière SVG générée (légère, pas d'image stockée) -->
    <div class="wt-article__banner" data-reveal>
      <?= wt_blog_banner_svg($post) ?>
    </div>

    <!-- En-tête de l'article -->
    <header class="wt-article__header" data-reveal>
      <h1 class="wt-article__title"><?= e($post['title']) ?></h1>
      <div class="wt-article__meta">
        <span>✍️ <?= e($post['author_name'] ?: 'Wintaskly') ?></span>
        <span>📅 <?= e(wt_format_datetime($post['published_at'], 'd/m/Y')) ?></span>
        <span>⏱️ <?= (int)$post['reading_minutes'] ?> <?= e(t('blog.min_read')) ?></span>
      </div>
    </header>

    <!-- Zone pub haut d'article -->
    <?php $_ad = wt_ad_zone('blog_article_top'); if ($_ad !== ''): ?>
      <div class="wt-ad-zone wt-ad-zone--article" style="margin:1.5rem 0;text-align:center"><?= $_ad ?></div>
    <?php endif; ?>

    <!-- Corps de l'article (HTML géré en admin) -->
    <div class="wt-article__body">
      <?php
        // Remplacement de placeholders dynamiques dans le corps de l'article.
        // {{BINGO_COUNTDOWN}} → widget de compte à rebours vers le lancement
        // du Bingo (lit la config bingo.launch_at, source unique de vérité).
        $articleBody = $post['body'];
        if (strpos($articleBody, '{{BINGO_COUNTDOWN}}') !== false) {
            $launchTs = function_exists('wt_bingo_launch_ts') ? wt_bingo_launch_ts() : 0;
            ob_start();
            if ($launchTs > 0):
        ?>
        <div class="wt-bingo-countdown wt-bingo-countdown--article" data-launch="<?= (int)$launchTs ?>" aria-label="<?= e(t('bingo.countdown_label')) ?>">
          <div class="wt-bingo-countdown__unit">
            <span class="wt-bingo-countdown__num" data-cd="days">--</span>
            <span class="wt-bingo-countdown__lbl"><?= e(t('bingo.cd_days')) ?></span>
          </div>
          <div class="wt-bingo-countdown__unit">
            <span class="wt-bingo-countdown__num" data-cd="hours">--</span>
            <span class="wt-bingo-countdown__lbl"><?= e(t('bingo.cd_hours')) ?></span>
          </div>
          <div class="wt-bingo-countdown__unit">
            <span class="wt-bingo-countdown__num" data-cd="mins">--</span>
            <span class="wt-bingo-countdown__lbl"><?= e(t('bingo.cd_mins')) ?></span>
          </div>
          <div class="wt-bingo-countdown__unit">
            <span class="wt-bingo-countdown__num" data-cd="secs">--</span>
            <span class="wt-bingo-countdown__lbl"><?= e(t('bingo.cd_secs')) ?></span>
          </div>
        </div>
        <?php else: ?>
        <p style="text-align:center;font-weight:700;color:#a855f7"><?= e(t('bingo.coming_soon')) ?></p>
        <?php endif;
            $cdHtml = ob_get_clean();
            $articleBody = str_replace('{{BINGO_COUNTDOWN}}', $cdHtml, $articleBody);
        }
        echo $articleBody; /* HTML éditorial géré par l'admin */
      ?>
    </div>

    <!-- Partage sur les réseaux sociaux -->
    <?php
      // URL absolue de l'article (pour le partage)
      $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
      $host    = $_SERVER['HTTP_HOST'] ?? 'wintaskly.com';
      $absUrl  = $scheme . '://' . $host . wt_url('/blog/' . $post['slug']);
      $shareU  = rawurlencode($absUrl);
      $shareT  = rawurlencode($post['title']);
    ?>
    <div class="wt-article__share" data-reveal>
      <span class="wt-article__share-label">📢 <?= e(t('blog.share')) ?></span>
      <div class="wt-article__share-btns">
        <a class="wt-share wt-share--fb" href="https://www.facebook.com/sharer/sharer.php?u=<?= $shareU ?>" target="_blank" rel="noopener" aria-label="Facebook">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.96.93-1.96 1.89v2.25h3.33l-.53 3.49h-2.8V24C19.61 23.1 24 18.1 24 12.07z"/></svg>
        </a>
        <a class="wt-share wt-share--x" href="https://twitter.com/intent/tweet?url=<?= $shareU ?>&text=<?= $shareT ?>" target="_blank" rel="noopener" aria-label="X">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M18.9 1.15h3.68l-8.04 9.19L24 22.85h-7.41l-5.8-7.58-6.64 7.58H.46l8.6-9.83L0 1.15h7.59l5.24 6.93zM17.6 20.64h2.04L6.49 3.24H4.3z"/></svg>
        </a>
        <a class="wt-share wt-share--wa" href="https://api.whatsapp.com/send?text=<?= $shareT ?>%20<?= $shareU ?>" target="_blank" rel="noopener" aria-label="WhatsApp">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M17.5 14.4c-.3-.15-1.77-.87-2.04-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.95 1.17-.17.2-.35.22-.65.07-.3-.15-1.26-.46-2.4-1.48-.9-.8-1.5-1.77-1.67-2.07-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.07-.15-.67-1.62-.92-2.22-.24-.58-.49-.5-.67-.51-.17-.01-.37-.01-.57-.01-.2 0-.52.07-.8.37-.27.3-1.05 1.02-1.05 2.49 0 1.47 1.07 2.89 1.22 3.09.15.2 2.11 3.22 5.1 4.51.71.31 1.27.49 1.7.63.72.23 1.37.2 1.88.12.57-.08 1.77-.72 2.02-1.42.25-.7.25-1.3.17-1.42-.07-.13-.27-.2-.57-.35zM12.05 21.6c-1.67 0-3.31-.45-4.74-1.29l-.34-.2-3.52.92.94-3.43-.22-.35a9.5 9.5 0 01-1.46-5.07c0-5.26 4.29-9.54 9.56-9.54 2.55 0 4.95.99 6.75 2.8a9.46 9.46 0 012.79 6.75c0 5.26-4.29 9.54-9.56 9.54zm8.13-17.67A11.4 11.4 0 0012.05.6C5.74.6.6 5.73.6 12.04c0 2.02.53 3.99 1.53 5.73L.5 24l6.37-1.67a11.43 11.43 0 005.18 1.32h.01c6.31 0 11.45-5.13 11.45-11.44 0-3.06-1.19-5.93-3.35-8.09z"/></svg>
        </a>
        <a class="wt-share wt-share--tg" href="https://t.me/share/url?url=<?= $shareU ?>&text=<?= $shareT ?>" target="_blank" rel="noopener" aria-label="Telegram">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M23.07 3.5c-.28-.23-.72-.26-1.36-.01L1.9 11.3c-.6.24-1.27.6-1.22 1.1.03.3.24.55.86.74l4.9 1.53 1.9 5.78c.24.67.42 1.04.93 1.04.4 0 .63-.18.92-.46l2.4-2.32 4.86 3.6c.6.33 1.07.16 1.25-.55l3.3-15.5c.18-.85.05-1.32-.34-1.64zm-13.7 11.2l-.7 4.55-.02-4.79 9.3-8.4c.2-.18-.04-.27-.3-.1L7.5 12.9z"/></svg>
        </a>
        <a class="wt-share wt-share--li" href="https://www.linkedin.com/sharing/share-offsite/?url=<?= $shareU ?>" target="_blank" rel="noopener" aria-label="LinkedIn">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M20.45 20.45h-3.56v-5.57c0-1.33-.02-3.04-1.85-3.04-1.85 0-2.14 1.45-2.14 2.94v5.66H9.35V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28zM5.34 7.43a2.07 2.07 0 110-4.13 2.07 2.07 0 010 4.13zm1.78 13.02H3.56V9h3.56v11.45zM22.22 0H1.77C.8 0 0 .78 0 1.74v20.5C0 23.2.8 24 1.77 24h20.45c.98 0 1.78-.8 1.78-1.76V1.74C24 .78 23.2 0 22.22 0z"/></svg>
        </a>
        <button type="button" class="wt-share wt-share--copy" data-copy-url="<?= e($absUrl) ?>" aria-label="<?= e(t('blog.share_copy')) ?>" title="<?= e(t('blog.share_copy')) ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
        </button>
      </div>
    </div>

    <!-- Zone pub bas d'article -->
    <?php $_ad = wt_ad_zone('blog_article_bottom'); if ($_ad !== ''): ?>
      <div class="wt-ad-zone wt-ad-zone--article" style="margin:2rem 0;text-align:center"><?= $_ad ?></div>
    <?php endif; ?>

    <!-- CTA vers l'inscription (conversion) -->
    <div class="wt-article__cta" data-reveal>
      <h3><?= e(t('blog.cta_title')) ?></h3>
      <p><?= e(t('blog.cta_lead')) ?></p>
      <a href="<?= e(wt_url('/auth/signup.php')) ?>" class="wt-btn wt-btn--primary wt-btn--lg">
        <?= e(t('blog.cta_btn')) ?>
      </a>
    </div>

    <!-- Articles liés -->
    <?php if (!empty($related)): ?>
      <section class="wt-article__related" data-reveal>
        <h2 class="wt-article__related-title"><?= e(t('blog.related')) ?></h2>
        <div class="wt-article__related-grid">
          <?php foreach ($related as $r): ?>
            <a href="<?= e(wt_url('/blog/' . $r['slug'])) ?>" class="wt-article__related-card">
              <span class="wt-article__related-emoji"><?= e($r['cover_emoji'] ?: '📄') ?></span>
              <span class="wt-article__related-name"><?= e($r['title']) ?></span>
              <span class="wt-article__related-time">⏱️ <?= (int)$r['reading_minutes'] ?> <?= e(t('blog.min_read')) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

  </div>
</main>

<script>
(function () {
  var btn = document.querySelector('.wt-share--copy');
  if (!btn) return;
  btn.addEventListener('click', function () {
    var url = btn.getAttribute('data-copy-url') || window.location.href;
    var done = function () {
      var old = btn.innerHTML;
      btn.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
      btn.classList.add('is-copied');
      setTimeout(function () { btn.innerHTML = old; btn.classList.remove('is-copied'); }, 1800);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(done).catch(function () { done(); });
    } else {
      // Fallback : champ temporaire
      var t = document.createElement('textarea');
      t.value = url; document.body.appendChild(t); t.select();
      try { document.execCommand('copy'); } catch (e) {}
      document.body.removeChild(t); done();
    }
  });
})();
</script>

<script src="<?= e(wt_url('/media/wintaskly/js/bingo-countdown.js')) ?>?v=<?= e(WT_VERSION) ?>"></script>

<?php include __DIR__ . '/../footer.php'; ?>
