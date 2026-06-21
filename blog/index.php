<?php
/**
 * Wintaskly — /blog/index.php
 *
 * Liste publique des articles de blog. Indexable par les moteurs.
 * Supporte le filtrage par catégorie (?cat=slug) et la pagination (?p=N).
 *
 * Objectif SEO/AdSense : page de contenu riche, publique, sans login.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

// Blog désactivé → 404 propre
if (!wt_blog_enabled()) {
    http_response_code(404);
    $pageTitle = '404';
    include __DIR__ . '/../header.php';
    echo '<main class="wt-main"><div style="text-align:center;padding:4rem 1rem"><h1>404</h1><p class="wt-muted">'
       . e(t('blog.disabled')) . '</p></div></main>';
    include __DIR__ . '/../footer.php';
    exit;
}

$perPage = 9;
$page    = max(1, (int)($_GET['p'] ?? 1));
$offset  = ($page - 1) * $perPage;

// Filtre catégorie
$catSlug = isset($_GET['cat']) ? preg_replace('/[^a-z0-9-]/', '', (string)$_GET['cat']) : null;
$activeCat = null;
$categoryId = null;
if ($catSlug) {
    foreach (wt_blog_categories() as $c) {
        if ($c['slug'] === $catSlug) { $activeCat = $c; $categoryId = (int)$c['id']; break; }
    }
}

$posts      = wt_blog_posts($perPage, $offset, $categoryId);
$totalPosts = wt_blog_count($categoryId);
$totalPages = max(1, (int)ceil($totalPosts / $perPage));
$categories = wt_blog_categories();

// SEO
$blogTitle = (string) cfg('blog.title', 'Blog');
$pageTitle = $activeCat ? ($activeCat['name'] . ' — ' . $blogTitle) : $blogTitle;
$metaDescription = $activeCat
    ? (string)($activeCat['description'] ?? '')
    : (string) cfg('blog.description', '');

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-blog">
  <div class="wt-blog__wrap">

    <!-- En-tête -->
    <header class="wt-blog__header" data-reveal>
      <span class="wt-eyebrow">📰 <?= e(t('blog.eyebrow')) ?></span>
      <h1 class="wt-blog__title"><?= e($activeCat ? $activeCat['name'] : $blogTitle) ?></h1>
      <p class="wt-blog__lead">
        <?= e($activeCat ? (string)($activeCat['description'] ?? '') : (string) cfg('blog.description', '')) ?>
      </p>
    </header>

    <!-- Catégories (navigation) -->
    <nav class="wt-blog__cats" data-reveal>
      <a href="<?= e(wt_url('/blog')) ?>" class="wt-blog__cat <?= !$activeCat ? 'is-active' : '' ?>">
        <?= e(t('blog.all')) ?>
      </a>
      <?php foreach ($categories as $c): if ((int)$c['post_count'] === 0) continue; ?>
        <a href="<?= e(wt_url('/blog/categorie/' . $c['slug'])) ?>"
           class="wt-blog__cat <?= ($activeCat && $activeCat['slug'] === $c['slug']) ? 'is-active' : '' ?>">
          <?= e($c['name']) ?> <span class="wt-blog__cat-count"><?= (int)$c['post_count'] ?></span>
        </a>
      <?php endforeach; ?>
    </nav>

    <?php if (empty($posts)): ?>
      <div class="wt-blog__empty" data-reveal>
        <div style="font-size:3rem">📝</div>
        <p class="wt-muted"><?= e(t('blog.empty')) ?></p>
      </div>
    <?php else: ?>
      <!-- Grille d'articles -->
      <div class="wt-blog__grid" data-reveal>
        <?php foreach ($posts as $post): ?>
          <article class="wt-blog-card">
            <a href="<?= e(wt_url('/blog/' . $post['slug'])) ?>" class="wt-blog-card__link">
              <div class="wt-blog-card__cover">
                <span class="wt-blog-card__emoji"><?= e($post['cover_emoji'] ?: '📄') ?></span>
              </div>
              <div class="wt-blog-card__body">
                <?php if (!empty($post['category_name'])): ?>
                  <span class="wt-blog-card__cat"><?= e($post['category_name']) ?></span>
                <?php endif; ?>
                <h2 class="wt-blog-card__title"><?= e($post['title']) ?></h2>
                <?php if (!empty($post['excerpt'])): ?>
                  <p class="wt-blog-card__excerpt"><?= e($post['excerpt']) ?></p>
                <?php endif; ?>
                <div class="wt-blog-card__meta">
                  <span>📅 <?= e(wt_format_datetime($post['published_at'], 'd/m/Y')) ?></span>
                  <span>⏱️ <?= (int)$post['reading_minutes'] ?> <?= e(t('blog.min_read')) ?></span>
                </div>
              </div>
            </a>
          </article>
        <?php endforeach; ?>
      </div>

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
        <nav class="wt-blog__pagination" data-reveal>
          <?php
          $baseUrl = $activeCat ? wt_url('/blog/categorie/' . $activeCat['slug']) : wt_url('/blog');
          $sep = strpos($baseUrl, '?') !== false ? '&' : '?';
          ?>
          <?php if ($page > 1): ?>
            <a href="<?= e($baseUrl . $sep . 'p=' . ($page - 1)) ?>" class="wt-blog__page-btn">← <?= e(t('blog.prev')) ?></a>
          <?php endif; ?>
          <span class="wt-blog__page-info"><?= e(sprintf((string) t('blog.page_of'), $page, $totalPages)) ?></span>
          <?php if ($page < $totalPages): ?>
            <a href="<?= e($baseUrl . $sep . 'p=' . ($page + 1)) ?>" class="wt-blog__page-btn"><?= e(t('blog.next')) ?> →</a>
          <?php endif; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>

  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
