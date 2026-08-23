<?php
/**
 * Wintaskly — includes/icons.php
 * ---------------------------------------------------------------------------
 * Bibliothèque d'icônes unique, basée sur le jeu Lucide (licence ISC).
 *
 * POURQUOI DES SVG EN LIGNE PLUTÔT QU'UNE POLICE OU UN CDN
 * --------------------------------------------------------
 *   • Aucune requête réseau supplémentaire : les icônes font partie du HTML,
 *     déjà compressé en gzip. Un CDN ajouterait une dépendance externe, un
 *     point de défaillance, et ferait fuiter l'adresse IP des visiteurs vers
 *     un tiers — un problème de conformité inutile.
 *   • Aucune étape de compilation : le site tourne sur un hébergement
 *     mutualisé, sans build.
 *   • Les icônes héritent de la couleur du texte (`currentColor`), donc
 *     elles suivent le thème clair/sombre sans réglage.
 *
 * POURQUOI REMPLACER LES EMOJIS
 * -----------------------------
 * Un emoji n'est pas une icône : son dessin dépend du système du visiteur.
 * Le même caractère apparaît en jaune plat sur Android, en dégradé sur iOS,
 * et parfois en carré vide sur d'anciens navigateurs. Impossible d'obtenir
 * une identité visuelle cohérente avec ça.
 *
 * PÉRIMÈTRE
 * ---------
 * Le site compte environ 935 emojis, dont la grande majorité dans
 * l'administration — invisible pour les visiteurs. La conversion porte donc
 * d'abord sur les zones publiques et structurelles. Les emojis éditoriaux
 * (vignettes d'articles choisies par l'auteur) restent volontairement : ce
 * sont des choix de contenu, pas des éléments d'interface.
 *
 * USAGE
 * -----
 *     <?= wt_icon('shield') ?>
 *     <?= wt_icon('wallet', ['size' => 20, 'class' => 'wt-ico--muted']) ?>
 *     <?= wt_icon('clock', ['label' => 'Délai de traitement']) ?>
 *
 * Sans `label`, l'icône est marquée décorative (aria-hidden) : c'est le cas
 * le plus fréquent, l'information étant portée par le texte voisin.
 */
declare(strict_types=1);

if (!function_exists('wt_icon')) {

    /**
     * Tracés Lucide. Chaque entrée est le contenu interne du <svg>,
     * dessiné sur une grille de 24×24 avec un trait de 2 unités.
     */
    function wt_icon_paths(): array
    {
        static $p = null;
        if ($p !== null) { return $p; }

        return $p = [
            // — Confiance et sécurité
            'shield'       => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'shield-check' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
            'lock'         => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
            'key'          => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/>',
            'alert'        => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
            'check'        => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',

            // — Argent et paiements
            'wallet'       => '<path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/>',
            'card'         => '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>',
            'coins'        => '<circle cx="8" cy="8" r="6"/><path d="M18.09 10.37A6 6 0 1 1 10.34 18"/><path d="M7 6h1v4"/>',
            'trending'     => '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>',

            // — Contenu et aide
            'book'         => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
            'file'         => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
            'help'         => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
            'search'       => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
            'list'         => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',

            // — Tâches
            'target'       => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
            'link'         => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
            'monitor'      => '<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>',
            'droplet'      => '<path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/>',
            'clock'        => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',

            // — Divers
            'users'        => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'mail'         => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
            'info'         => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
            'globe'        => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        ];
    }

    /**
     * Rend une icône en SVG inline.
     *
     * @param string $name Clé de wt_icon_paths()
     * @param array  $opt  size (px), class (CSS), label (texte accessible)
     */
    function wt_icon(string $name, array $opt = []): string
    {
        $paths = wt_icon_paths();
        if (!isset($paths[$name])) {
            /* Icône inconnue : on ne renvoie rien plutôt qu'un carré vide.
               Un nom mal orthographié ne doit pas laisser de trou visible. */
            return '';
        }

        $size  = max(10, min(64, (int) ($opt['size'] ?? 18)));
        $class = trim('wt-ico ' . (string) ($opt['class'] ?? ''));
        $label = trim((string) ($opt['label'] ?? ''));

        $a11y = $label !== ''
            ? ' role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"'
            : ' aria-hidden="true" focusable="false"';

        return '<svg class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"'
             . ' width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24"'
             . ' fill="none" stroke="currentColor" stroke-width="2"'
             . ' stroke-linecap="round" stroke-linejoin="round"' . $a11y . '>'
             . $paths[$name]
             . '</svg>';
    }
}
