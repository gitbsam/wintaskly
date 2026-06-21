<?php
/**
 * Wintaskly — Module Blog
 * ─────────────────────────────────────────────────────────────────────
 * Helpers de lecture pour le blog public. Tolérants aux pannes : si les
 * tables n'existent pas encore, retournent des valeurs vides sans planter.
 */
declare(strict_types=1);

if (!function_exists('wt_blog_enabled')) {
    function wt_blog_enabled(): bool
    {
        return (string) cfg('blog.enabled', '0') === '1';
    }
}

if (!function_exists('wt_blog_categories')) {
    /**
     * Toutes les catégories, triées. Avec le nombre d'articles publiés.
     */
    function wt_blog_categories(): array
    {
        $cats = [];
        try {
            $res = db()->query(
                "SELECT c.id, c.slug, c.name, c.description,
                        COUNT(p.id) AS post_count
                   FROM blog_categories c
                   LEFT JOIN blog_posts p
                     ON p.category_id = c.id AND p.status = 'published'
                  GROUP BY c.id
                  ORDER BY c.sort_order ASC, c.name ASC"
            );
            if ($res instanceof mysqli_result) {
                while ($r = $res->fetch_assoc()) { $cats[] = $r; }
                $res->free();
            }
        } catch (Throwable $e) {
            error_log('[Wintaskly blog] categories: ' . $e->getMessage());
        }
        return $cats;
    }
}

if (!function_exists('wt_blog_posts')) {
    /**
     * Liste paginée des articles publiés.
     *
     * @param int      $limit
     * @param int      $offset
     * @param int|null $categoryId  Filtre par catégorie (optionnel)
     * @return array
     */
    function wt_blog_posts(int $limit = 12, int $offset = 0, ?int $categoryId = null): array
    {
        $posts = [];
        try {
            $db = db();
            if ($categoryId !== null) {
                $stmt = $db->prepare(
                    "SELECT p.id, p.slug, p.title, p.excerpt, p.cover_emoji,
                            p.reading_minutes, p.published_at, p.views,
                            c.name AS category_name, c.slug AS category_slug
                       FROM blog_posts p
                       LEFT JOIN blog_categories c ON c.id = p.category_id
                      WHERE p.status = 'published' AND p.category_id = ?
                      ORDER BY p.published_at DESC
                      LIMIT ? OFFSET ?"
                );
                $stmt->bind_param('iii', $categoryId, $limit, $offset);
            } else {
                $stmt = $db->prepare(
                    "SELECT p.id, p.slug, p.title, p.excerpt, p.cover_emoji,
                            p.reading_minutes, p.published_at, p.views,
                            c.name AS category_name, c.slug AS category_slug
                       FROM blog_posts p
                       LEFT JOIN blog_categories c ON c.id = p.category_id
                      WHERE p.status = 'published'
                      ORDER BY p.published_at DESC
                      LIMIT ? OFFSET ?"
                );
                $stmt->bind_param('ii', $limit, $offset);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) { $posts[] = $r; }
            $stmt->close();
        } catch (Throwable $e) {
            error_log('[Wintaskly blog] posts: ' . $e->getMessage());
        }
        return $posts;
    }
}

if (!function_exists('wt_blog_count')) {
    /**
     * Nombre total d'articles publiés (pour la pagination).
     */
    function wt_blog_count(?int $categoryId = null): int
    {
        try {
            $db = db();
            if ($categoryId !== null) {
                $stmt = $db->prepare("SELECT COUNT(*) c FROM blog_posts WHERE status='published' AND category_id = ?");
                $stmt->bind_param('i', $categoryId);
            } else {
                $stmt = $db->prepare("SELECT COUNT(*) c FROM blog_posts WHERE status='published'");
            }
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return (int) ($row['c'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('wt_blog_post')) {
    /**
     * Un article complet par son slug (publié uniquement, sauf si $anyStatus).
     * Retourne null si introuvable.
     */
    function wt_blog_post(string $slug, bool $anyStatus = false): ?array
    {
        try {
            $db = db();
            $sql = "SELECT p.*, c.name AS category_name, c.slug AS category_slug
                      FROM blog_posts p
                      LEFT JOIN blog_categories c ON c.id = p.category_id
                     WHERE p.slug = ?";
            if (!$anyStatus) {
                $sql .= " AND p.status = 'published'";
            }
            $sql .= " LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->bind_param('s', $slug);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('[Wintaskly blog] post: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('wt_blog_increment_views')) {
    /**
     * Incrémente le compteur de vues d'un article (best-effort).
     */
    function wt_blog_increment_views(int $postId): void
    {
        try {
            $stmt = db()->prepare("UPDATE blog_posts SET views = views + 1 WHERE id = ?");
            $stmt->bind_param('i', $postId);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            // non bloquant
        }
    }
}

if (!function_exists('wt_blog_related')) {
    /**
     * Articles liés (même catégorie, hors article courant).
     */
    function wt_blog_related(int $postId, ?int $categoryId, int $limit = 3): array
    {
        if ($categoryId === null) return [];
        $posts = [];
        try {
            $stmt = db()->prepare(
                "SELECT slug, title, cover_emoji, reading_minutes
                   FROM blog_posts
                  WHERE status='published' AND category_id = ? AND id <> ?
                  ORDER BY published_at DESC
                  LIMIT ?"
            );
            $stmt->bind_param('iii', $categoryId, $postId, $limit);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) { $posts[] = $r; }
            $stmt->close();
        } catch (Throwable $e) {}
        return $posts;
    }
}

if (!function_exists('wt_blog_slugify')) {
    /**
     * Génère un slug propre depuis un titre (pour l'admin).
     */
    function wt_blog_slugify(string $text): string
    {
        $text = trim($text);
        // Translittération basique des accents
        $text = strtr($text, [
            'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a',
            'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
            'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
            'ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
            'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u',
            'ç'=>'c','ñ'=>'n',
            'À'=>'a','Â'=>'a','É'=>'e','È'=>'e','Ê'=>'e','Ô'=>'o','Û'=>'u','Ç'=>'c',
        ]);
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        return $text === '' ? 'article' : $text;
    }
}
