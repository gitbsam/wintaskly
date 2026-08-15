<?php
/**
 * Wintaskly — Données structurées Schema.org (JSON-LD)
 *
 * Les moteurs de recherche s'en servent pour comprendre le contenu et
 * afficher des résultats enrichis (étoiles, questions dépliables, fil
 * d'Ariane...). Aucune de ces données n'est visible pour le visiteur.
 *
 * Principe : chaque page appelle wt_schema_add() AVANT d'inclure le
 * header, et header.php rend l'ensemble dans un seul bloc <script>.
 * Regrouper évite de multiplier les balises et facilite le débogage via
 * l'outil de test des résultats enrichis de Google.
 */
declare(strict_types=1);

if (!function_exists('wt_schema_add')) {
    /**
     * Empile un objet Schema.org pour la page courante.
     *
     * @param array<string, mixed> $data Objet JSON-LD (avec @type)
     */
    function wt_schema_add(array $data): void
    {
        if (!isset($GLOBALS['__wt_schema'])) {
            $GLOBALS['__wt_schema'] = [];
        }
        $GLOBALS['__wt_schema'][] = $data;
    }
}

if (!function_exists('wt_schema_organization')) {
    /**
     * Identité du site — présent sur toutes les pages. Permet à Google
     * d'associer logo, nom et réseaux sociaux au domaine.
     *
     * @return array<string, mixed>
     */
    function wt_schema_organization(): array
    {
        $base = rtrim(wt_url('/'), '/');
        $org = [
            '@type' => 'Organization',
            '@id'   => $base . '/#organization',
            'name'  => (string) t('site_name'),
            'url'   => $base . '/',
            'logo'  => [
                '@type' => 'ImageObject',
                'url'   => $base . '/media/wintaskly/img/logo-light-192.png',
                'width' => 192,
                'height' => 192,
            ],
        ];

        $desc = trim((string) cfg('seo.meta_description', ''));
        if ($desc !== '') {
            $org['description'] = $desc;
        }

        // Réseaux sociaux renseignés en admin (sameAs aide Google à relier
        // le site à ses profils officiels)
        $sameAs = [];
        foreach (['social.twitter_url', 'social.facebook_url', 'social.instagram_url',
                  'social.telegram_url', 'social.youtube_url'] as $k) {
            $v = trim((string) cfg($k, ''));
            if ($v !== '' && filter_var($v, FILTER_VALIDATE_URL)) {
                $sameAs[] = $v;
            }
        }
        if ($sameAs) {
            $org['sameAs'] = $sameAs;
        }

        return $org;
    }
}

if (!function_exists('wt_schema_website')) {
    /**
     * Le site en tant qu'entité. Rattaché à l'Organization ci-dessus.
     *
     * @return array<string, mixed>
     */
    function wt_schema_website(): array
    {
        $base = rtrim(wt_url('/'), '/');
        return [
            '@type'    => 'WebSite',
            '@id'      => $base . '/#website',
            'url'      => $base . '/',
            'name'     => (string) t('site_name'),
            'publisher' => ['@id' => $base . '/#organization'],
            'inLanguage' => (($GLOBALS['WT_LANG_CODE'] ?? 'fr') === 'en') ? 'en-US' : 'fr-FR',
        ];
    }
}

if (!function_exists('wt_schema_faq')) {
    /**
     * FAQPage — rend les questions éligibles à l'affichage déplié
     * directement dans les résultats Google.
     *
     * Google exige que les questions/réponses soient VISIBLES sur la page :
     * on ne balise donc que ce qui est réellement affiché.
     *
     * @param array<string, array{q:string, a:string}> $qa
     * @return array<string, mixed>
     */
    function wt_schema_faq(array $qa): array
    {
        $items = [];
        foreach ($qa as $item) {
            $q = trim(strip_tags((string) ($item['q'] ?? '')));
            $a = trim(strip_tags((string) ($item['a'] ?? '')));
            if ($q === '' || $a === '') {
                continue;
            }
            $items[] = [
                '@type' => 'Question',
                'name'  => $q,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $a,
                ],
            ];
        }
        return [
            '@type' => 'FAQPage',
            'mainEntity' => $items,
        ];
    }
}

if (!function_exists('wt_schema_blogposting')) {
    /**
     * BlogPosting — article de blog (titre, dates, auteur, image, durée).
     *
     * @param array<string, mixed> $post Ligne blog_posts
     * @return array<string, mixed>
     */
    function wt_schema_blogposting(array $post): array
    {
        $base = rtrim(wt_url('/'), '/');
        $url  = $base . '/blog/' . (string) $post['slug'];

        $data = [
            '@type'            => 'BlogPosting',
            'headline'         => wt_substr((string) $post['title'], 0, 110),
            'url'              => $url,
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            'publisher'        => ['@id' => $base . '/#organization'],
            'inLanguage'       => (($GLOBALS['WT_LANG_CODE'] ?? 'fr') === 'en') ? 'en-US' : 'fr-FR',
        ];

        if (!empty($post['excerpt'])) {
            $data['description'] = (string) $post['excerpt'];
        }
        if (!empty($post['published_at'])) {
            $data['datePublished'] = date('c', strtotime((string) $post['published_at']));
        }
        // Google recommande dateModified ; on retombe sur la publication
        $mod = $post['updated_at'] ?? $post['published_at'] ?? null;
        if (!empty($mod)) {
            $data['dateModified'] = date('c', strtotime((string) $mod));
        }
        if (!empty($post['author_name'])) {
            $data['author'] = [
                '@type' => 'Person',
                'name'  => (string) $post['author_name'],
            ];
        }
        // Image : celle de l'article si elle existe, sinon l'image OG du site
        if (!empty($post['cover_image'])) {
            $data['image'] = $base . '/media/wintaskly/img/blog/' . (string) $post['cover_image'];
        } else {
            $og = trim((string) cfg('seo.og_image_url', ''));
            $data['image'] = $og !== '' ? $og : $base . '/media/wintaskly/img/og-image.png';
        }
        if (!empty($post['reading_minutes'])) {
            $data['timeRequired'] = 'PT' . (int) $post['reading_minutes'] . 'M';
        }

        return $data;
    }
}

if (!function_exists('wt_schema_breadcrumb')) {
    /**
     * BreadcrumbList — fil d'Ariane affiché sous le lien dans Google.
     *
     * @param array<int, array{name:string, url:string}> $crumbs
     * @return array<string, mixed>
     */
    function wt_schema_breadcrumb(array $crumbs): array
    {
        $items = [];
        $i = 1;
        foreach ($crumbs as $c) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $i++,
                'name'     => (string) $c['name'],
                'item'     => (string) $c['url'],
            ];
        }
        return ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }
}

if (!function_exists('wt_schema_render')) {
    /**
     * Rend tous les objets empilés dans un unique bloc JSON-LD.
     * Appelé par header.php ; renvoie '' s'il n'y a rien à écrire.
     */
    function wt_schema_render(): string
    {
        $graph = $GLOBALS['__wt_schema'] ?? [];
        if (!$graph) {
            return '';
        }
        $payload = [
            '@context' => 'https://schema.org',
            '@graph'   => $graph,
        ];
        // JSON_UNESCAPED_* pour garder les accents et les URLs lisibles ;
        // JSON_HEX_TAG protège contre une fermeture de balise injectée.
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        );
        if ($json === false) {
            return '';
        }
        return "\n<script type=\"application/ld+json\">" . $json . "</script>\n";
    }
}
