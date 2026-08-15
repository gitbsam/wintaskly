<?php
/**
 * Wintaskly — sitemap.xml dynamique
 *
 * Déclaré dans robots.txt (Sitemap: https://wintaskly.com/sitemap.xml) mais
 * inexistant jusqu'ici : les moteurs suivaient ce lien et tombaient sur une
 * 404. Ce script génère le plan du site à la volée.
 *
 * Contenu : pages publiques statiques + catégories du blog + articles
 * publiés. Les zones privées (admin, dashboard, api, install) et les pages
 * nécessitant un compte en sont volontairement exclues — elles sont déjà
 * bloquées dans robots.txt et n'ont pas vocation à être indexées.
 *
 * Accessible via /sitemap.xml grâce à la règle de réécriture du .htaccess
 * (et directement via /sitemap.php en secours).
 */
declare(strict_types=1);
require __DIR__ . '/includes/init.php';

header('Content-Type: application/xml; charset=utf-8');

$base = rtrim(wt_url('/'), '/');

/** @var array<int, array{loc:string, lastmod:?string, freq:string, prio:string}> $urls */
$urls = [];

$add = static function (string $path, string $freq, string $prio, ?string $lastmod = null) use (&$urls, $base): void {
    $urls[] = [
        'loc'     => $base . $path,
        'lastmod' => $lastmod,
        'freq'    => $freq,
        'prio'    => $prio,
    ];
};

// --- Pages statiques publiques -------------------------------------------
$add('/',                     'daily',   '1.0');
$add('/tasks/',               'daily',   '0.9');
$add('/blog',                 'daily',   '0.9');
$add('/about/',               'monthly', '0.7');
$add('/about/editorial.php',  'monthly', '0.6');
$add('/help/',                'monthly', '0.7');
$add('/help/faq.php',         'weekly',  '0.8');
$add('/help/antifraud.php',   'monthly', '0.6');
$add('/help/contact.php',     'monthly', '0.5');
$add('/leaderboard/',         'daily',   '0.6');
// Témoignages : listée seulement si la page a du contenu (cohérent avec
// son noindex conditionnel).
try {
    $_tRow = db_one("SELECT COUNT(*) c FROM testimonials WHERE status = 'approved'");
    if ((int) ($_tRow['c'] ?? 0) >= 5) {   // même seuil que le noindex de la page
        $add('/testimonials/', 'weekly', '0.5');
    }
} catch (Throwable $e) {
    error_log('[Wintaskly sitemap] testimonials: ' . $e->getMessage());
}
// Connexion / inscription : volontairement absentes — ces pages sont en
// noindex (formulaires sans valeur en recherche). Un sitemap ne doit
// lister que des URLs réellement indexables, sinon il envoie un signal
// contradictoire aux moteurs.
$add('/legal/mentions.php',   'yearly',  '0.3');
$add('/legal/privacy.php',    'yearly',  '0.3');
$add('/legal/cookies.php',    'yearly',  '0.3');
$add('/legal/cgu.php',        'yearly',  '0.3');

// --- Catégories du blog (seulement celles qui ont des articles) -----------
try {
    if (function_exists('wt_blog_categories')) {
        foreach (wt_blog_categories() as $cat) {
            if ((int) ($cat['post_count'] ?? 0) > 0) {
                $add('/blog/categorie/' . $cat['slug'], 'weekly', '0.6');
            }
        }
    }
} catch (Throwable $e) {
    error_log('[Wintaskly sitemap] categories: ' . $e->getMessage());
}

// --- Articles publiés ----------------------------------------------------
try {
    $res = db()->query(
        "SELECT slug, updated_at, published_at
           FROM blog_posts
          WHERE status = 'published' AND published_at <= UTC_TIMESTAMP()
          ORDER BY published_at DESC
          LIMIT 5000"
    );
    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_assoc()) {
            $ts = $row['updated_at'] ?: $row['published_at'];
            $add(
                '/blog/' . $row['slug'],
                'monthly',
                '0.7',
                $ts ? date('Y-m-d', strtotime((string) $ts)) : null
            );
        }
        $res->free();
    }
} catch (Throwable $e) {
    error_log('[Wintaskly sitemap] posts: ' . $e->getMessage());
}

// --- Rendu ---------------------------------------------------------------
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($u['loc'], ENT_XML1, 'UTF-8') . "</loc>\n";
    if ($u['lastmod'] !== null) {
        echo '    <lastmod>' . htmlspecialchars($u['lastmod'], ENT_XML1, 'UTF-8') . "</lastmod>\n";
    }
    echo '    <changefreq>' . $u['freq'] . "</changefreq>\n";
    echo '    <priority>' . $u['prio'] . "</priority>\n";
    echo "  </url>\n";
}
echo '</urlset>' . "\n";
