<?php
/**
 * Wintaskly — Balises injectées dans le <head>, page par page
 * ----------------------------------------------------------------------
 * Certaines régies demandent une balise <meta> de vérification de
 * propriété, d'autres un <script> à placer dans le <head> plutôt que dans
 * un emplacement. Ces balises ne concernent pas toujours tout le site :
 * une vérification se pose souvent sur l'accueil seul, un script de
 * monétisation sur les pages publiques uniquement.
 *
 * Ce module gère ces règles : quelle balise, sur quelles pages.
 *
 * POURQUOI UN FICHIER JSON PLUTÔT QU'UNE TABLE
 * --------------------------------------------
 * Le reste du projet stocke sa configuration en base. Ici, le choix du
 * fichier est délibéré : ces balises sont lues sur CHAQUE page, très tôt,
 * avant même l'ouverture du <body>. Un fichier lu en une fois évite une
 * requête supplémentaire à chaque affichage.
 *
 * En contrepartie, un fichier n'offre aucune des garanties d'une base :
 * deux enregistrements simultanés peuvent s'écraser, et une écriture
 * interrompue laisse un JSON tronqué — donc plus aucune balise sur tout
 * le site. Les deux risques sont traités ici :
 *
 *   • lecture sous verrou partagé (LOCK_SH)
 *   • écriture atomique : on écrit dans un fichier temporaire, puis on le
 *     renomme. rename() est atomique sur le même système de fichiers, si
 *     bien que le fichier final n'est jamais dans un état intermédiaire :
 *     il contient l'ancienne version, ou la nouvelle, jamais une moitié.
 *
 * Le fichier vit dans includes/, interdit d'accès web par le .htaccess
 * racine : les identifiants de compte régie qu'il contient ne sont donc
 * pas lisibles publiquement.
 */
declare(strict_types=1);

if (!function_exists('wt_ad_tags_path')) {
    /** Emplacement du fichier de règles. */
    function wt_ad_tags_path(): string
    {
        return dirname(__DIR__) . '/includes/ad_tags_rules.json';
    }
}

if (!function_exists('wt_ad_tags_load')) {
    /**
     * Charge les règles. Retourne toujours un tableau : un fichier absent,
     * vide ou corrompu ne doit jamais casser l'affichage du site.
     *
     * @return array<int, array{id:string,pages:array<int,string>,tag_content:string,active:bool,needs_consent:bool}>
     */
    function wt_ad_tags_load(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $path = wt_ad_tags_path();
        if (!is_file($path)) {
            return $cache = [];
        }

        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return $cache = [];
        }
        // Verrou partagé : plusieurs visiteurs peuvent lire ensemble, mais
        // aucun ne lira pendant qu'un administrateur écrit.
        @flock($fh, LOCK_SH);
        $raw = stream_get_contents($fh);
        @flock($fh, LOCK_UN);
        fclose($fh);

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            // JSON illisible : on le signale sans interrompre la page.
            error_log('[Wintaskly ad_tags] JSON invalide : ' . $path);
            return $cache = [];
        }

        // Normalisation : une règle écrite par une version antérieure peut
        // manquer de champs. On complète plutôt que de la rejeter.
        $out = [];
        foreach ($data as $r) {
            if (!is_array($r) || !isset($r['tag_content'])) {
                continue;
            }
            $out[] = [
                'id'            => (string) ($r['id'] ?? uniqid('tag_', true)),
                'label'         => (string) ($r['label'] ?? ''),
                'pages'         => is_array($r['pages'] ?? null) ? array_values(array_map('strval', $r['pages'])) : [],
                'tag_content'   => (string) $r['tag_content'],
                'active'        => !empty($r['active']),
                'needs_consent' => !empty($r['needs_consent']),
            ];
        }
        return $cache = $out;
    }
}

if (!function_exists('wt_ad_tags_save')) {
    /**
     * Écrit les règles de façon atomique.
     *
     * @param  array $rules Liste complète (remplace le contenu existant)
     * @return bool         false si l'écriture a échoué — à signaler à
     *                      l'administrateur, jamais à ignorer en silence :
     *                      sur hébergement mutualisé, un dossier en
     *                      lecture seule est un cas réel.
     */
    function wt_ad_tags_save(array $rules): bool
    {
        $path = wt_ad_tags_path();
        $json = json_encode(
            array_values($rules),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            error_log('[Wintaskly ad_tags] encodage JSON impossible');
            return false;
        }

        // Le fichier temporaire est créé dans le MÊME dossier que la cible :
        // rename() n'est atomique qu'à l'intérieur d'un même système de
        // fichiers, et /tmp est souvent une partition différente.
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            error_log('[Wintaskly ad_tags] écriture impossible dans ' . dirname($path));
            return false;
        }
        @chmod($tmp, 0640);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            error_log('[Wintaskly ad_tags] renommage impossible vers ' . $path);
            return false;
        }
        return true;
    }
}

if (!function_exists('wt_ad_tags_page_match')) {
    /**
     * La page courante correspond-elle au motif d'une règle ?
     *
     * Le chemin est normalisé avant comparaison, sans quoi une même page
     * échouerait selon la façon dont on y arrive :
     *   /tasks/  /tasks/index.php  /tasks  → tous ramenés à /tasks
     *
     * Le préfixe d'installation est retiré : sur un site en sous-dossier,
     * l'URL réelle est /wintaskly/tasks/ alors que les motifs saisis en
     * administration s'écrivent /tasks*.
     *
     * @param string $pattern Motif saisi ('*', '/tasks*', '/index.php'…)
     * @param string $path    Chemin normalisé de la page courante
     */
    function wt_ad_tags_page_match(string $pattern, string $path): bool
    {
        $pattern = trim($pattern);
        if ($pattern === '' ) {
            return false;
        }
        if ($pattern === '*') {
            return true;   // toutes les pages
        }

        $pattern = wt_ad_tags_normalize_path($pattern);

        // preg_quote AVANT de remplacer le joker, sinon les points et
        // tirets du chemin seraient interprétés comme des métacaractères.
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#i';
        return (bool) preg_match($regex, $path);
    }
}

if (!function_exists('wt_ad_tags_normalize_path')) {
    /** Ramène un chemin à sa forme canonique : minuscules, sans /index.php, sans slash final. */
    function wt_ad_tags_normalize_path(string $path): string
    {
        $path = strtolower(trim($path));
        $path = preg_replace('#/index\.php$#', '', $path) ?? $path;
        $path = rtrim($path, '/');
        return $path === '' ? '/' : $path;
    }
}

if (!function_exists('wt_ad_tags_current_path')) {
    /** Chemin normalisé de la page courante, préfixe d'installation retiré. */
    function wt_ad_tags_current_path(): string
    {
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

        // Site installé dans un sous-dossier : on retire le préfixe pour
        // que les motifs restent écrits comme des chemins du site.
        $base = (string) (parse_url((string) wt_url('/'), PHP_URL_PATH) ?: '/');
        $base = rtrim($base, '/');
        if ($base !== '' && str_starts_with(strtolower($path), strtolower($base))) {
            $path = substr($path, strlen($base));
        }

        return wt_ad_tags_normalize_path($path);
    }
}

if (!function_exists('wt_ad_tags_render')) {
    /**
     * HTML à injecter dans le <head> pour la page courante.
     *
     * Rien n'est échappé : le contenu est de l'HTML fourni par un
     * administrateur, c'est tout l'objet de la fonctionnalité. La
     * protection ne vient donc pas d'un échappement mais de trois
     * garde-fous en amont : l'accès à /admin/ est réservé aux
     * administrateurs, le formulaire est protégé par jeton CSRF, et
     * l'administration refuse les balises autres que <meta> et <script>
     * (voir wt_ad_tags_validate()).
     *
     * @return string Chaîne prête à être imprimée, vide s'il n'y a rien
     */
    function wt_ad_tags_render(): string
    {
        $rules = wt_ad_tags_load();
        if ($rules === []) {
            return '';
        }

        $path = wt_ad_tags_current_path();
        $out  = [];

        foreach ($rules as $rule) {
            if (!$rule['active'] || $rule['pages'] === []) {
                continue;
            }
            // Une règle marquée « nécessite le consentement » n'est servie
            // qu'aux visiteurs qui ont accepté les cookies publicitaires.
            // Par défaut la case est décochée, donc la balise sort pour
            // tout le monde.
            if ($rule['needs_consent'] && !wt_consent_allows('ads')) {
                continue;
            }
            foreach ($rule['pages'] as $pattern) {
                if (wt_ad_tags_page_match($pattern, $path)) {
                    $out[] = '    ' . trim($rule['tag_content']);
                    break;
                }
            }
        }

        return $out === [] ? '' : "\n" . implode("\n", $out) . "\n";
    }
}

if (!function_exists('wt_ad_tags_available_pages')) {
    /**
     * Pages proposées à la sélection, groupées par section.
     *
     * Les chemins sont ceux que produit wt_ad_tags_current_path(), pas les
     * noms de fichiers : la nuance compte. Les URL du blog et des
     * campagnes sont réécrites par le .htaccess (/blog/mon-article pointe
     * vers blog/post.php), donc un motif « /blog/post.php » ne
     * correspondrait jamais à rien. Les autres pages gardent leur .php
     * dans l'URL, il doit donc figurer dans le motif.
     *
     * Chaque section propose le motif large en premier (/tasks* couvre
     * tout), puis les pages précises pour cibler finement.
     *
     * @return array<string, array<string, string>> section => [motif => libellé]
     */
    function wt_ad_tags_available_pages(): array
    {
        return [
            t('adtags.g.global') => [
                '*'  => t('adtags.p.all'),
                '/'  => t('adtags.p.home'),
            ],
            t('adtags.g.tasks') => [
                '/tasks*'                    => t('adtags.p.tasks_all'),
                '/tasks'                     => t('adtags.p.tasks_index'),
                '/tasks/faucet*'             => t('adtags.p.faucet_all'),
                '/tasks/faucet'              => t('adtags.p.faucet'),
                '/tasks/faucet/transition.php' => t('adtags.p.faucet_transition'),
                '/tasks/faucet/verify.php'   => t('adtags.p.faucet_verify'),
                '/tasks/shortlinks*'         => t('adtags.p.shortlinks_all'),
                '/tasks/shortlinks'          => t('adtags.p.shortlinks'),
                '/tasks/shortlinks/gateway.php' => t('adtags.p.shortlinks_gateway'),
                '/tasks/ptc'                 => t('adtags.p.ptc'),
                '/tasks/offerwalls*'         => t('adtags.p.offerwalls_all'),
                '/tasks/offerwalls'          => t('adtags.p.offerwalls'),
                '/tasks/bingo'               => t('adtags.p.bingo'),
            ],
            t('adtags.g.blog') => [
                '/blog*'  => t('adtags.p.blog_all'),
                '/blog'   => t('adtags.p.blog_index'),
                '/blog/*' => t('adtags.p.blog_post'),
            ],
            t('adtags.g.dashboard') => [
                '/dashboard*'                => t('adtags.p.dash_all'),
                '/dashboard'                 => t('adtags.p.dash_index'),
                '/dashboard/account.php'     => t('adtags.p.dash_account'),
                '/dashboard/withdraw.php'    => t('adtags.p.dash_withdraw'),
                '/dashboard/referrals.php'   => t('adtags.p.dash_referrals'),
                '/dashboard/messages.php'    => t('adtags.p.dash_messages'),
                '/dashboard/notifications.php' => t('adtags.p.dash_notifications'),
                '/dashboard/settings.php'    => t('adtags.p.dash_settings'),
            ],
            t('adtags.g.public') => [
                '/leaderboard'   => t('adtags.p.leaderboard'),
                '/achievements.php' => t('adtags.p.achievements'),
                '/testimonials'  => t('adtags.p.testimonials'),
                '/campagn*'      => t('adtags.p.campagn'),
                '/about*'        => t('adtags.p.about'),
                '/help*'         => t('adtags.p.help'),
                '/legal*'        => t('adtags.p.legal'),
            ],
            t('adtags.g.auth') => [
                '/auth*'            => t('adtags.p.auth_all'),
                '/auth/login.php'   => t('adtags.p.auth_login'),
                '/auth/signup.php'  => t('adtags.p.auth_signup'),
            ],
        ];
    }
}

if (!function_exists('wt_ad_tags_validate')) {
    /**
     * Vérifie une balise avant enregistrement.
     *
     * N'accepte que <meta> et <script> : ce champ écrit directement dans
     * le <head>, une <iframe> ou un <style> y aurait des effets qui n'ont
     * rien à voir avec la publicité, et une erreur de copier-coller
     * casserait la page sans que la cause soit évidente.
     *
     * @return string '' si la balise est acceptable, sinon la clé i18n du refus
     */
    function wt_ad_tags_validate(string $tag): string
    {
        $tag = trim($tag);
        if ($tag === '') {
            return 'admin.adtags.err_empty';
        }
        // Toutes les balises ouvrantes présentes dans le contenu
        if (!preg_match_all('/<\s*([a-z0-9]+)/i', $tag, $m)) {
            return 'admin.adtags.err_notag';
        }
        foreach ($m[1] as $el) {
            if (!in_array(strtolower($el), ['meta', 'script'], true)) {
                return 'admin.adtags.err_element';
            }
        }
        return '';
    }
}
