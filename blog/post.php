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

    <!-- En-tête de l'article -->
    <header class="wt-article__header" data-reveal>
      <?php if (!empty($post['cover_emoji'])): ?>
        <div class="wt-article__emoji"><?= e($post['cover_emoji']) ?></div>
      <?php endif; ?>
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
      <?= $post['body'] /* HTML éditorial géré par l'admin */ ?>
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

<?php include __DIR__ . '/../footer.php'; ?>
