<?php
/**
 * Wintaskly — Admin · Blog
 *
 * Gestion complète des articles : création, édition, publication,
 * suppression. Gestion des catégories. SEO par article.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_admin();

$pageTitle   = t('admin.title') . ' — ' . t('admin.blog');
$adminActive = 'blog';
$db          = db();
$notice      = null;
$error       = null;

/* ====================== ACTIONS POST ====================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['_csrf'] ?? null)) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_config') {
        wt_config_set('blog.enabled', !empty($_POST['enabled']) ? '1' : '0');
        wt_config_set('blog.title', trim((string)($_POST['blog_title'] ?? '')));
        wt_config_set('blog.description', trim((string)($_POST['blog_description'] ?? '')));
        $notice = t('admin.blog.saved');

    } elseif ($action === 'create' || $action === 'update') {
        $title   = trim((string)($_POST['title'] ?? ''));
        $slug    = trim((string)($_POST['slug'] ?? ''));
        if ($slug === '' && $title !== '') {
            $slug = wt_blog_slugify($title);
        } else {
            $slug = wt_blog_slugify($slug);
        }
        $catId   = (int)($_POST['category_id'] ?? 0) ?: null;
        $excerpt = trim((string)($_POST['excerpt'] ?? ''));
        $body    = (string)($_POST['body'] ?? '');
        $emoji   = trim((string)($_POST['cover_emoji'] ?? ''));
        $author  = trim((string)($_POST['author_name'] ?? '')) ?: 'Équipe Wintaskly';
        $metaT   = trim((string)($_POST['meta_title'] ?? '')) ?: null;
        $metaD   = trim((string)($_POST['meta_description'] ?? '')) ?: null;
        $status  = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
        $reading = max(1, (int)($_POST['reading_minutes'] ?? 3));

        $errors = [];
        if ($title === '') $errors[] = t('admin.blog.err_title');
        if ($body === '')  $errors[] = t('admin.blog.err_body');

        if (!empty($errors)) {
            $error = implode(' ', $errors);
        } else {
            try {
                if ($action === 'create') {
                    // published_at = maintenant si publié directement
                    $pubAt = $status === 'published' ? gmdate('Y-m-d H:i:s') : null;
                    $stmt = $db->prepare(
                        "INSERT INTO blog_posts
                           (slug, category_id, title, excerpt, body, cover_emoji,
                            author_name, meta_title, meta_description, status,
                            reading_minutes, published_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    // Types (12) : slug=s category_id=i title=s excerpt=s body=s
                    //   cover_emoji=s author=s meta_title=s meta_desc=s status=s
                    //   reading_minutes=i published_at=s
                    $stmt->bind_param(
                        'sissssssssis',
                        $slug, $catId, $title, $excerpt, $body, $emoji,
                        $author, $metaT, $metaD, $status, $reading, $pubAt
                    );
                    $stmt->execute();
                    $stmt->close();
                    $notice = t('admin.blog.created');
                } else {
                    $id = (int)($_POST['id'] ?? 0);
                    // Récupère l'ancien statut pour gérer published_at
                    $old = db_one("SELECT status, published_at FROM blog_posts WHERE id = " . $id);
                    $pubAt = $old['published_at'] ?? null;
                    if ($status === 'published' && empty($pubAt)) {
                        $pubAt = gmdate('Y-m-d H:i:s'); // première publication
                    }
                    $stmt = $db->prepare(
                        "UPDATE blog_posts SET
                            slug = ?, category_id = ?, title = ?, excerpt = ?,
                            body = ?, cover_emoji = ?, author_name = ?,
                            meta_title = ?, meta_description = ?, status = ?,
                            reading_minutes = ?, published_at = ?
                          WHERE id = ?"
                    );
                    $stmt->bind_param(
                        'sissssssssisi',
                        $slug, $catId, $title, $excerpt, $body, $emoji,
                        $author, $metaT, $metaD, $status, $reading, $pubAt, $id
                    );
                    $stmt->execute();
                    $stmt->close();
                    $notice = t('admin.blog.updated');
                }
            } catch (Throwable $ex) {
                $error = t('admin.blog.err_dup');
                error_log('[Wintaskly blog admin] ' . $ex->getMessage());
            }
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM blog_posts WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $notice = t('admin.blog.deleted');
    }
}

/* ====================== LECTURE ====================== */
$enabled  = (string) cfg('blog.enabled', '0') === '1';
$blogTitle = (string) cfg('blog.title', '');
$blogDesc  = (string) cfg('blog.description', '');
$categories = wt_blog_categories();

// Article en cours d'édition ?
$editPost = null;
if (isset($_GET['edit'])) {
    $editSlugOrId = (string)$_GET['edit'];
    if (ctype_digit($editSlugOrId)) {
        $editPost = db_one("SELECT * FROM blog_posts WHERE id = " . (int)$editSlugOrId);
    }
}

// Liste des articles
$posts = [];
if ($res = $db->query(
    "SELECT p.id, p.slug, p.title, p.status, p.views, p.published_at, p.updated_at,
            c.name AS category_name
       FROM blog_posts p
       LEFT JOIN blog_categories c ON c.id = p.category_id
      ORDER BY p.updated_at DESC"
)) {
    while ($r = $res->fetch_assoc()) { $posts[] = $r; }
    $res->free();
}

$totalPosts = count($posts);
$publishedCount = count(array_filter($posts, fn($p) => $p['status'] === 'published'));

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
    <section class="wt-admin-v2__content" data-reveal>

      <header class="wt-admin-v2__page-header">
        <div>
          <span class="wt-eyebrow">📰 <?= e(t('admin.blog.eyebrow')) ?></span>
          <h1 class="wt-admin-v2__title"><?= e(t('admin.blog')) ?></h1>
          <p class="wt-muted"><?= e(t('admin.blog.lead')) ?></p>
        </div>
      </header>

      <?php if ($notice): ?><div class="wt-alert wt-alert--success"><?= e($notice) ?></div><?php endif; ?>
      <?php if ($error):  ?><div class="wt-alert wt-alert--error"><?= e($error) ?></div><?php endif; ?>

      <!-- Stats -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:1rem;margin-bottom:1.5rem">
        <div class="wt-card wt-card--padded" style="text-align:center">
          <div style="font-size:1.8rem;font-weight:800;color:var(--wt-accent)"><?= $totalPosts ?></div>
          <div class="wt-muted" style="font-size:.85rem"><?= e(t('admin.blog.stat_total')) ?></div>
        </div>
        <div class="wt-card wt-card--padded" style="text-align:center">
          <div style="font-size:1.8rem;font-weight:800;color:#22c55e"><?= $publishedCount ?></div>
          <div class="wt-muted" style="font-size:.85rem"><?= e(t('admin.blog.stat_published')) ?></div>
        </div>
      </div>

      <!-- Config -->
      <details class="wt-card wt-card--padded" style="margin-bottom:1.5rem">
        <summary style="cursor:pointer;font-weight:700">⚙️ <?= e(t('admin.blog.config_title')) ?></summary>
        <form method="post" style="margin-top:1rem">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save_config">
          <label class="wt-checkbox" style="display:flex;gap:.5rem;align-items:center;margin-bottom:1rem">
            <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
            <span><strong><?= e(t('admin.blog.enable')) ?></strong></span>
          </label>
          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.blog.f_blogtitle')) ?></span>
            <input class="wt-input" type="text" name="blog_title" value="<?= e($blogTitle) ?>">
          </label>
          <label class="wt-field" style="margin-top:.75rem">
            <span class="wt-field__label"><?= e(t('admin.blog.f_blogdesc')) ?></span>
            <input class="wt-input" type="text" name="blog_description" value="<?= e($blogDesc) ?>">
          </label>
          <button class="wt-btn wt-btn--primary" style="margin-top:1rem"><?= e(t('common.save')) ?></button>
        </form>
      </details>

      <!-- Formulaire création/édition -->
      <section class="wt-card wt-card--padded" style="margin-bottom:1.5rem">
        <h2 style="margin-top:0">
          <?= $editPost ? '✏️ ' . e(t('admin.blog.edit_title')) : '➕ ' . e(t('admin.blog.add_title')) ?>
        </h2>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="<?= $editPost ? 'update' : 'create' ?>">
          <?php if ($editPost): ?>
            <input type="hidden" name="id" value="<?= (int)$editPost['id'] ?>">
          <?php endif; ?>

          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem">
            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.blog.f_title')) ?> *</span>
              <input class="wt-input" type="text" name="title" required maxlength="200"
                     value="<?= e((string)($editPost['title'] ?? '')) ?>">
            </label>
            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.blog.f_slug')) ?></span>
              <input class="wt-input wt-mono" type="text" name="slug" maxlength="160"
                     placeholder="<?= e(t('admin.blog.f_slug_ph')) ?>"
                     value="<?= e((string)($editPost['slug'] ?? '')) ?>">
            </label>
            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.blog.f_category')) ?></span>
              <select class="wt-input" name="category_id">
                <option value="0"><?= e(t('admin.blog.f_nocat')) ?></option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int)$c['id'] ?>" <?= (isset($editPost['category_id']) && (int)$editPost['category_id'] === (int)$c['id']) ? 'selected' : '' ?>>
                    <?= e($c['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.blog.f_emoji')) ?></span>
              <input class="wt-input" type="text" name="cover_emoji" maxlength="16" placeholder="📄"
                     value="<?= e((string)($editPost['cover_emoji'] ?? '')) ?>">
            </label>
            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.blog.f_author')) ?></span>
              <input class="wt-input" type="text" name="author_name" maxlength="120"
                     value="<?= e((string)($editPost['author_name'] ?? 'Équipe Wintaskly')) ?>">
            </label>
            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.blog.f_reading')) ?></span>
              <input class="wt-input" type="number" name="reading_minutes" min="1" max="60"
                     value="<?= (int)($editPost['reading_minutes'] ?? 3) ?>">
            </label>
          </div>

          <label class="wt-field" style="margin-top:1rem">
            <span class="wt-field__label"><?= e(t('admin.blog.f_excerpt')) ?></span>
            <textarea class="wt-input" name="excerpt" rows="2" maxlength="320"
                      placeholder="<?= e(t('admin.blog.f_excerpt_ph')) ?>"><?= e((string)($editPost['excerpt'] ?? '')) ?></textarea>
          </label>

          <label class="wt-field" style="margin-top:1rem">
            <span class="wt-field__label"><?= e(t('admin.blog.f_body')) ?> *</span>
            <textarea class="wt-input wt-mono" name="body" rows="16" required
                      placeholder="<?= e(t('admin.blog.f_body_ph')) ?>"
                      style="font-size:.85rem;line-height:1.5"><?= e((string)($editPost['body'] ?? '')) ?></textarea>
            <small class="wt-field__hint"><?= e(t('admin.blog.f_body_hint')) ?></small>
          </label>

          <!-- SEO -->
          <details style="margin-top:1rem">
            <summary style="cursor:pointer;font-weight:600;font-size:.9rem">🔍 <?= e(t('admin.blog.seo_title')) ?></summary>
            <div style="margin-top:.75rem;display:grid;gap:.75rem">
              <label class="wt-field">
                <span class="wt-field__label"><?= e(t('admin.blog.f_metatitle')) ?></span>
                <input class="wt-input" type="text" name="meta_title" maxlength="200"
                       placeholder="<?= e(t('admin.blog.f_metatitle_ph')) ?>"
                       value="<?= e((string)($editPost['meta_title'] ?? '')) ?>">
              </label>
              <label class="wt-field">
                <span class="wt-field__label"><?= e(t('admin.blog.f_metadesc')) ?></span>
                <textarea class="wt-input" name="meta_description" rows="2" maxlength="320"
                          placeholder="<?= e(t('admin.blog.f_metadesc_ph')) ?>"><?= e((string)($editPost['meta_description'] ?? '')) ?></textarea>
              </label>
            </div>
          </details>

          <div style="display:flex;gap:1rem;align-items:center;margin-top:1.25rem;flex-wrap:wrap">
            <label class="wt-field" style="margin:0">
              <span class="wt-field__label"><?= e(t('admin.blog.f_status')) ?></span>
              <select class="wt-input" name="status">
                <option value="draft" <?= (($editPost['status'] ?? 'draft') === 'draft') ? 'selected' : '' ?>><?= e(t('admin.blog.status_draft')) ?></option>
                <option value="published" <?= (($editPost['status'] ?? '') === 'published') ? 'selected' : '' ?>><?= e(t('admin.blog.status_published')) ?></option>
              </select>
            </label>
            <button class="wt-btn wt-btn--primary" style="align-self:flex-end">
              <?= $editPost ? e(t('common.save')) : e(t('admin.blog.create_btn')) ?>
            </button>
            <?php if ($editPost): ?>
              <a href="<?= e(wt_url('/admin/blog.php')) ?>" class="wt-btn wt-btn--ghost" style="align-self:flex-end"><?= e(t('common.cancel')) ?></a>
              <a href="<?= e(wt_url('/blog/' . $editPost['slug'])) ?>" target="_blank" class="wt-btn wt-btn--ghost" style="align-self:flex-end">👁️ <?= e(t('admin.blog.preview')) ?></a>
            <?php endif; ?>
          </div>
        </form>
      </section>

      <!-- Liste des articles -->
      <section class="wt-card wt-card--padded">
        <h2 style="margin-top:0">📋 <?= e(t('admin.blog.list_title')) ?> (<?= $totalPosts ?>)</h2>
        <?php if (empty($posts)): ?>
          <p class="wt-muted"><?= e(t('admin.blog.empty')) ?></p>
        <?php else: ?>
          <div class="wt-table-wrap">
            <table class="wt-table">
              <thead>
                <tr>
                  <th><?= e(t('admin.blog.col_title')) ?></th>
                  <th><?= e(t('admin.blog.col_cat')) ?></th>
                  <th><?= e(t('admin.blog.col_status')) ?></th>
                  <th><?= e(t('admin.blog.col_views')) ?></th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($posts as $p): ?>
                  <tr>
                    <td><strong><?= e($p['title']) ?></strong><br><code style="font-size:.7rem;opacity:.5"><?= e($p['slug']) ?></code></td>
                    <td><?= e($p['category_name'] ?? '—') ?></td>
                    <td>
                      <?php if ($p['status'] === 'published'): ?>
                        <span style="color:#22c55e;font-size:.85rem">✅ <?= e(t('admin.blog.status_published')) ?></span>
                      <?php else: ?>
                        <span style="opacity:.6;font-size:.85rem">📝 <?= e(t('admin.blog.status_draft')) ?></span>
                      <?php endif; ?>
                    </td>
                    <td class="wt-mono"><?= (int)$p['views'] ?></td>
                    <td style="white-space:nowrap">
                      <a href="<?= e(wt_url('/admin/blog.php?edit=' . (int)$p['id'])) ?>" class="wt-btn wt-btn--ghost wt-btn--xs">✏️</a>
                      <form method="post" style="display:inline" onsubmit="return confirm('<?= e(t('admin.blog.confirm_del')) ?>')">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <button type="submit" class="wt-btn wt-btn--ghost wt-btn--xs" style="color:#ef4444">🗑</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

    </section>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
