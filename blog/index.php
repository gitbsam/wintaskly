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
// Description : celle de la catégorie active si elle en a une, sinon la
// description du blog configurée en admin, sinon le texte SEO générique.
$pageDescription = ($activeCat && !empty($activeCat['description']))
                   ? (string) $activeCat['description']
                   : ((string) cfg('blog.description', '') ?: (string) t('seo.desc.blog'));

/* Fil d'Ariane structuré : aide Google à afficher le chemin sous le lien
   dans les résultats, et renforce le maillage sémantique. */
$_crumbs = [
    ['name' => (string) t('site_name'),            'url' => wt_url('/')],
    ['name' => (string) cfg('blog.title', 'Blog'), 'url' => wt_url('/blog')],
];
if ($activeCat) {
    $_crumbs[] = ['name' => (string) $activeCat['name'],
                  'url'  => wt_url('/blog/categorie/' . $activeCat['slug'])];
}
wt_schema_add(wt_schema_breadcrumb($_crumbs));
$metaDescription = $activeCat
    ? (string)($activeCat['description'] ?? '')
    : (string) cfg('blog.description', '');

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-blog">
  <div class="wt-blog__wrap">

    <?php $_ad = wt_ad_zone('blog_index_top'); if ($_ad !== ''): ?>
      <div class="wt-ad-zone wt-ad-zone--top" style="margin-bottom:1.5rem;text-align:center">
        <?= $_ad ?>
      </div>
    <?php endif; ?>

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

    <div class="wt-blog__layout">
      <div class="wt-blog__main">
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
                    <?php
                      /* La colonne cover_image n'existe pas en base : on
                         résout la miniature par convention de nom, avec
                         repli sur l'emoji si le fichier n'a pas encore
                         été généré. */
                      $_thumb = wt_blog_cover((string) $post['slug'], 'thumb');
                    ?>
                    <?php if ($_thumb): ?>
                      <img src="<?= e($_thumb) ?>" alt="" loading="lazy"
                           width="600" height="400" class="wt-blog-card__cover-img">
                    <?php else: ?>
                      <span class="wt-blog-card__emoji"><?= e($post['cover_emoji'] ?: '📄') ?></span>
                    <?php endif; ?>
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

      <!-- Sidebar : notice mission + zone pub -->
      <aside class="wt-blog__sidebar">
        <div class="wt-blog__mission" data-reveal>
          <h3 class="wt-blog__mission-title">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            <?= e(t('about.h_mission')) ?>
          </h3>
          <p class="wt-blog__mission-text"><?= e(t('blog.sidebar.mission_text')) ?></p>
          <a href="<?= e(wt_url('/about')) ?>" class="wt-blog__mission-cta">
            <?= e(t('blog.sidebar.mission_cta')) ?> →
          </a>
        </div>

        <?php $_adSide = wt_ad_zone('blog_sidebar'); if ($_adSide !== ''): ?>
          <div class="wt-ad-zone wt-ad-zone--sidebar">
            <?= $_adSide ?>
          </div>
        <?php endif; ?>
      </aside>
    </div>

    <?php $_ad = wt_ad_zone('blog_index_bottom'); if ($_ad !== ''): ?>
      <div class="wt-ad-zone wt-ad-zone--bottom" style="margin-top:1.5rem;text-align:center">
        <?= $_ad ?>
      </div>
    <?php endif; ?>

  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
