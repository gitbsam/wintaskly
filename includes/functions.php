<?php
/**
 * Wintaskly — Fonctions utilitaires (gains, parrainage, niveaux).
 */

/**
 * Crédite un utilisateur en Coins/XP, journalise la transaction
 * et déclenche la commission de parrainage (10% par défaut)
 * sans impacter le gain du filleul.
 *
 * @return array{coins:float,xp:int,new_level:int,referrer_bonus:float}
 */
function award_user(int $userId, float $coins, int $xp, string $type, ?string $meta = null): array
{
    $db = db();
    $db->begin_transaction();
    try {
        // 1) Mise à jour solde + XP + niveau
        $stmt = $db->prepare(
            "UPDATE users
                SET coins = coins + ?,
                    xp    = xp + ?,
                    level = GREATEST(level, 1 + FLOOR((xp + ?) / 100))
              WHERE id = ?"
        );
        $stmt->bind_param('diii', $coins, $xp, $xp, $userId);
        $stmt->execute();
        $stmt->close();

        // 2) Transaction principale
        $stmt = $db->prepare(
            "INSERT INTO transactions (user_id, type, coins, xp, meta)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('isdis', $userId, $type, $coins, $xp, $meta);
        $stmt->execute();
        $stmt->close();

        // 3) Parrainage : commission 10% au parrain
        $bonus = 0.0;
        $stmt = $db->prepare("SELECT referrer_id FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $referrerId = $row['referrer_id'] ?? null;
        /* Types ouvrant droit à la commission de parrainage.
         *
         * Couvre les quatre TÂCHES rémunérées : le filleul a fourni un
         * effort, son parrain en touche 10 %.
         *
         * Volontairement exclus :
         *   - bingo_win  : relève du hasard, pas d'un effort du filleul ;
         *   - daily_bonus / achievement : récompenses de fidélité liées au
         *     compte lui-même, pas à une tâche accomplie ;
         *   - referral   : évite qu'une commission génère une commission ;
         *   - withdraw / bingo_buy / admin : sorties ou ajustements.
         *
         * Ces exclusions sont annoncées explicitement dans la FAQ et sur la
         * page de parrainage : la promesse affichée doit correspondre au
         * code, au centime près. */
        $refEligible = ['faucet', 'shortlink', 'ptc', 'offerwall'];
        if ($referrerId && in_array($type, $refEligible, true)) {
            $rate  = (float)(cfg('referral_rate', '0.10'));
            $bonus = round($coins * $rate, 4);

            if ($bonus > 0) {
                $stmt = $db->prepare("UPDATE users SET coins = coins + ? WHERE id = ?");
                $stmt->bind_param('di', $bonus, $referrerId);
                $stmt->execute();
                $stmt->close();

                $stmt = $db->prepare(
                    "INSERT INTO transactions (user_id, type, coins, xp, meta)
                     VALUES (?, 'referral', ?, 0, ?)"
                );
                $metaRef = 'from_user:' . $userId . ',source:' . $type;
                $stmt->bind_param('ids', $referrerId, $bonus, $metaRef);
                $stmt->execute();
                $stmt->close();

                /* Journal des commissions — alimente la page de parrainage.
                 *
                 * ⚠️ Cette insertion peut échouer SILENCIEUSEMENT si la
                 * colonne `source` est un ENUM qui n'accepte pas encore
                 * 'ptc' et 'offerwall' (base non migrée). Le parrain était
                 * alors bien crédité — la transaction, elle, passe — mais
                 * la page affichait un total sous-évalué, voire zéro.
                 *
                 * On journalise donc l'échec au lieu de le laisser passer :
                 * un écart entre le solde et le total affiché doit être
                 * visible dans les logs, pas découvert par l'utilisateur. */
                $stmt = $db->prepare(
                    "INSERT INTO referral_earnings
                       (referrer_id, referee_id, source, source_amount, commission)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $stmt->bind_param('iisdd', $referrerId, $userId, $type, $coins, $bonus);
                if (!@$stmt->execute()) {
                    error_log(sprintf(
                        '[Wintaskly referral] journal non ecrit (source=%s, parrain=%d) : %s'
                        . ' — verifiez que sql/migration_fix_referral_source_enum.sql est applique',
                        $type, $referrerId, $stmt->error
                    ));
                }
                $stmt->close();
            }
        }

        // 4) Récupérer le niveau mis à jour
        $stmt = $db->prepare("SELECT level FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $level = (int)($stmt->get_result()->fetch_assoc()['level'] ?? 1);
        $stmt->close();

        $db->commit();

        // ------------------------------------------------------------------
        // Vérification des achievements APRÈS le commit (hors transaction).
        //
        // Garde anti-récursion : si ce crédit est LUI-MÊME une récompense
        // d'achievement (type 'achievement'), on ne re-vérifie pas — sinon
        // débloquer un badge qui crédite des coins relancerait la vérif à
        // l'infini. Les autres types (faucet, shortlink, daily_bonus...)
        // déclenchent la vérification en temps réel.
        //
        // wt_ach_check est tolérant aux pannes : si le module/les tables ne
        // sont pas dispo, il retourne [] sans erreur.
        if ($type !== 'achievement' && function_exists('wt_ach_check')) {
            try {
                $unlocked = wt_ach_check($userId);
                // On stocke les déblocages dans un buffer global pour que la
                // page courante puisse les afficher (toasts/notifications).
                if (!empty($unlocked)) {
                    if (!isset($GLOBALS['__wt_ach_just_unlocked'])) {
                        $GLOBALS['__wt_ach_just_unlocked'] = [];
                    }
                    foreach ($unlocked as $u) {
                        $GLOBALS['__wt_ach_just_unlocked'][] = $u;
                    }
                }
            } catch (Throwable $e) {
                error_log('[Wintaskly ach] check after award: ' . $e->getMessage());
            }
        }

        return [
            'coins'          => $coins,
            'xp'             => $xp,
            'new_level'      => $level,
            'referrer_bonus' => $bonus,
        ];
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * Logge un événement de tricherie et bannit si nécessaire.
 */
function flag_cheat(?int $userId, string $reason, bool $autoBan = false): void
{
    $ipBin = wt_ip_bin();
    $stmt  = db()->prepare(
        "INSERT INTO bans (ip, user_id, reason, expires_at)
         VALUES (?, ?, ?, ?)"
    );
    $expires = $autoBan ? null : date('Y-m-d H:i:s', time() + 3600);
    $stmt->bind_param('siss', $ipBin, $userId, $reason, $expires);
    $stmt->execute();
    $stmt->close();
}

/**
 * Vérifie si l'IP ou l'utilisateur courant est sous le coup d'un ban actif.
 */
function is_banned(?int $userId = null): bool
{
    $ipBin = wt_ip_bin();
    $stmt = db()->prepare(
        "SELECT 1 FROM bans
          WHERE (expires_at IS NULL OR expires_at > NOW())
            AND (ip = ? OR user_id = ?)
          LIMIT 1"
    );
    $stmt->bind_param('si', $ipBin, $userId);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

/**
 * Génère un code de parrainage unique (alphanumérique).
 */
function generate_referral_code(): string
{
    do {
        $code = 'WT-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 7));
        $stmt = db()->prepare("SELECT 1 FROM users WHERE referral_code = ? LIMIT 1");
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
    } while ($exists);
    return $code;
}

/**
 * Calcule le pourcentage de progression vers le prochain niveau (XP).
 */
function xp_progress(int $xp): array
{
    $level     = 1 + (int)floor($xp / 100);
    $current   = $xp % 100;
    return [
        'level'         => $level,
        'next_level'    => $level + 1,
        'current_xp'    => $current,        // XP gagnés dans le niveau courant
        'xp_for_next'   => 100,             // XP requis pour passer au niveau suivant
        'percent'       => $current,        // %, équivalent à current_xp tant que palier = 100
        'to_next'       => 100 - $current,  // XP restants pour passer au suivant
    ];
}

/**
 * Envoie des en-têtes HTTP de cache pour les pages statiques ou
 * quasi-statiques (pages légales, FAQ, etc.).
 *
 * Cache public (CDN-friendly) avec ETag pour permettre les
 * réponses 304 Not Modified si le contenu n'a pas changé.
 *
 * @param int    $maxAgeSeconds  Durée de cache (défaut 1h)
 * @param string $version        Marqueur pour invalidation (date de mise à jour)
 *
 * Usage typique en haut d'une page légale :
 *   wt_static_cache_headers(3600, $updatedAt . '-' . $WT_LANG_CODE);
 */
function wt_static_cache_headers(int $maxAgeSeconds = 3600, string $version = ''): void
{
    // Si déjà envoyés ou si on n'est pas en GET, on skip
    if (headers_sent() || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return;
    }

    // ETag basé sur la version (date de mise à jour + langue + thème)
    $etag = '"' . substr(sha1($version), 0, 16) . '"';

    // 304 Not Modified si le client a déjà la bonne version
    $clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    if ($clientEtag !== '' && trim($clientEtag) === $etag) {
        http_response_code(304);
        exit;
    }

    // Le 3e paramètre `true` force le REMPLACEMENT des headers existants
    // (notamment ceux posés automatiquement par session_start() :
    // 'Cache-Control: no-store, no-cache, must-revalidate' et 'Pragma: no-cache').
    header('Cache-Control: public, max-age=' . $maxAgeSeconds, true);
    header('ETag: ' . $etag, true);
    header('Vary: Accept-Encoding, Cookie', true);
    // Neutralise Pragma: no-cache posé par session_start (sinon il prime sur Cache-Control en HTTP/1.0)
    header_remove('Pragma');
    header_remove('Expires');
}

/* ============================================================================
   SHORTLINK API : génération d'un lien court via l'API du provider
   ============================================================================
   Appelée par tasks/shortlinks/gateway.php quand un shortlink est en mode 'api'.

   Compatible avec les providers REST qui exposent :
       GET https://provider.com/api?api=TOKEN&url=URL_ENCODED
       → réponse JSON  { "status": "success", "shortenedUrl": "https://..." }

   C'est le format standard exe.io, shrinkme.io, shortest, etc. Si un provider
   utilise un autre format de réponse, il faudra adapter (mais l'écrasante
   majorité respecte ce format issu de l'historique d'adf.ly).

   Retour : URL courte (string) en cas de succès, ou null en cas d'échec
   (timeout, API down, token invalide, réponse malformée).
   ============================================================================ */
if (!function_exists('wt_shortlink_create_via_api')) {
    function wt_shortlink_create_via_api(string $apiEndpoint, string $apiToken, string $destUrl): ?string
    {
        // Validation entrées
        if ($apiEndpoint === '' || $apiToken === '' || $destUrl === '') {
            error_log('[Wintaskly shortlink_api] missing params');
            return null;
        }

        // Détection du format de la régie d'après l'endpoint.
        //   - Ad-Maven : paramètre api_token + title obligatoire, réponse
        //     { type, message: { desturl } }
        //   - exe.io & compatibles (shrinkme, etc.) : paramètre api + url,
        //     réponse { status, shortenedUrl }
        $isAdMaven = (stripos($apiEndpoint, 'ad-maven') !== false)
                  || (stripos($apiEndpoint, 'admaven') !== false);

        $sep = (strpos($apiEndpoint, '?') === false) ? '?' : '&';

        if ($isAdMaven) {
            // Ad-Maven exige un 'title' (max 30 car.). On en génère un court
            // et unique pour tracer le lien côté panel Ad-Maven.
            $title = 'wt-' . substr(md5($destUrl . microtime()), 0, 10);
            $callUrl = $apiEndpoint . $sep
                     . 'api_token=' . urlencode($apiToken)
                     . '&title=' . urlencode($title)
                     . '&url=' . urlencode($destUrl);
        } else {
            // Format standard exe.io / adf.ly historique
            $callUrl = $apiEndpoint . $sep
                     . 'api=' . urlencode($apiToken)
                     . '&url=' . urlencode($destUrl)
                     . '&format=json';
        }

        // Appel HTTP avec cURL (préféré à file_get_contents pour le timeout
        // et la gestion d'erreurs propre).
        if (function_exists('curl_init')) {
            $ch = curl_init($callUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'Wintaskly/8.0',
            ]);
            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($response === false || $httpCode < 200 || $httpCode >= 300) {
                error_log('[Wintaskly shortlink_api] cURL failed (http=' . $httpCode . ' err=' . $curlErr . ')');
                return null;
            }
        } else {
            // Fallback : file_get_contents avec stream context timeout
            $ctx = stream_context_create([
                'http' => ['timeout' => 8, 'user_agent' => 'Wintaskly/8.0'],
                'ssl'  => ['verify_peer' => true],
            ]);
            $response = @file_get_contents($callUrl, false, $ctx);
            if ($response === false) {
                error_log('[Wintaskly shortlink_api] file_get_contents failed');
                return null;
            }
        }

        // Parse JSON
        $json = json_decode($response, true);
        if (!is_array($json)) {
            error_log('[Wintaskly shortlink_api] non-JSON response: ' . substr((string)$response, 0, 200));
            return null;
        }

        if ($isAdMaven) {
            // Format Ad-Maven : { type: "created"|"fetched", message: {...} }
            //   - succès POST : message.desturl
            //   - succès GET  : message[0].desturl (tableau)
            //   - erreur      : { type: "error", message: "..." }
            $type = strtolower((string) ($json['type'] ?? ''));
            if ($type === 'error') {
                $msg = is_string($json['message'] ?? null) ? $json['message'] : 'unknown';
                error_log('[Wintaskly shortlink_api] Ad-Maven error: ' . $msg);
                return null;
            }
            $message = $json['message'] ?? null;
            $short = '';
            if (is_array($message)) {
                if (isset($message['desturl'])) {
                    // Réponse POST (objet)
                    $short = (string) $message['desturl'];
                } elseif (isset($message[0]) && is_array($message[0]) && isset($message[0]['desturl'])) {
                    // Réponse GET (tableau)
                    $short = (string) $message[0]['desturl'];
                }
            }
            if ($short === '') {
                error_log('[Wintaskly shortlink_api] Ad-Maven: no desturl in response: ' . substr((string)$response, 0, 200));
                return null;
            }
        } else {
            // Format standard exe.io : { "status": "success", "shortenedUrl": "..." }
            $status = strtolower((string) ($json['status'] ?? ''));
            $short  = (string) ($json['shortenedUrl'] ?? $json['short'] ?? $json['url'] ?? '');

            if ($status !== 'success' || $short === '') {
                $msg = (string) ($json['message'] ?? 'unknown');
                error_log('[Wintaskly shortlink_api] provider returned error: ' . $msg);
                return null;
            }
        }

        // Validation basique de l'URL retournée
        if (!filter_var($short, FILTER_VALIDATE_URL)) {
            error_log('[Wintaskly shortlink_api] invalid URL in response: ' . $short);
            return null;
        }

        return $short;
    }
}

if (!function_exists('wt_ad_zone')) {
    /**
     * Affiche (echo) le code d'une zone publicitaire depuis la table
     * `ad_zones`, identifiée par sa clé. Ne plante jamais : si la zone
     * n'existe pas, est inactive, ou si la table est absente, retourne
     * une chaîne vide (rien n'est affiché).
     *
     * Les codes sont mis en cache au premier appel pour éviter de
     * re-requêter la BDD à chaque zone sur une même page.
     *
     * Usage dans une vue :
     *   <?= wt_ad_zone('faucet_transition_top') ?>
     *
     * @param  string $key Clé de la zone (ex: 'ptc_chrono_top')
     * @return string      Code HTML/JS de la pub, ou '' si indisponible
     */
    function wt_ad_zone(string $key): string
    {
        // Cache des zones chargé une seule fois par requête
        if (!isset($GLOBALS['__wt_ad_zones_cache'])) {
            $GLOBALS['__wt_ad_zones_cache'] = [];
            try {
                $res = db()->query("SELECT k, code, banner_id, size_key FROM ad_zones WHERE active = 1");
                if ($res instanceof mysqli_result) {
                    while ($r = $res->fetch_assoc()) {
                        $GLOBALS['__wt_ad_zones_cache'][$r['k']] = [
                            'code'      => (string) $r['code'],
                            'banner_id' => $r['banner_id'] !== null ? (int) $r['banner_id'] : null,
                            'size_key'  => $r['size_key'] !== null ? (string) $r['size_key'] : null,
                        ];
                    }
                    $res->free();
                }
            } catch (Throwable $e) {
                // Table ad_zones absente ou inaccessible → pas de pub, pas de crash
                error_log('[Wintaskly ad_zone] ' . $e->getMessage());
            }
        }

        $zone = $GLOBALS['__wt_ad_zones_cache'][$key] ?? null;
        if ($zone === null) {
            return '';
        }
        $code = $zone['code'];

        // On n'affiche pas les placeholders de démo (commentaires HTML seuls)
        $stripped = trim(preg_replace('/<!--.*?-->/s', '', $code));

        if ($stripped !== '') {
            // Priorité 1 : régie publicitaire configurée (code réel).
            // Exception : un code AdSense ne doit JAMAIS être servi sur une
            // page où le membre est rémunéré (voir wt_adsense_allowed()).
            // Dans ce cas on ignore ce code et on retombe sur la bannière
            // maison / la rotation : l'emplacement reste monétisé, mais par
            // une régie compatible avec le modèle.
            /* Un code de régie (AdSense, Adsterra...) dépose des cookies
             * tiers : il est soumis au consentement « Publicité ». Sans
             * consentement, on n'affiche pas ce code — mais on ne perd pas
             * l'emplacement pour autant : la suite de la fonction retombe
             * sur la bannière maison ou la rotation, qui sont servies
             * depuis notre propre domaine et ne déposent aucun cookie.
             * L'emplacement reste donc monétisé, en toute conformité. */
            $adsOk = wt_consent_allows('ads');
            if ($adsOk && (!wt_code_has_adsense($code) || wt_adsense_allowed())) {
                return '<div class="wt-ad-scale">'
                     . '<div class="wt-ad-label">' . e(t('ad.title.pub')) . '</div>'
                     . '<div class="wt-ad-scale__inner">'
                     . $code
                     . '</div></div>';
            }
        }

        // Priorité 2 : pas de régie → bannière maison uploadée, si liée et active
        if ($zone['banner_id'] !== null) {
            $banner = wt_ad_banner_get($zone['banner_id']);
            if ($banner !== null) {
                $src = e(wt_url('/media/wintaskly/img/banners/' . $banner['filename']));
                $signup = e(wt_url('/auth/signup.php'));
                return '<div class="wt-ad-scale">'
                     . '<div class="wt-ad-label">' . e(t('ad.title.pub')) . '</div>'
                     . '<div class="wt-ad-scale__inner">'
                     . '<a href="' . $signup . '" class="wt-ad-house">'
                     . '<img src="' . $src . '" width="' . (int) $banner['width'] . '"'
                     . ' height="' . (int) $banner['height'] . '" alt="Wintaskly" loading="lazy">'
                     . '</a></div></div>';
            }
        }

        // Priorité 3 : ni régie ni bannière spécifique → rotation automatique
        // parmi toutes les bannières actives du même format (size_key de la
        // zone), affichées une à la fois côté client, alternant environ
        // toutes les 15-30 secondes tant que la page reste ouverte.
        if ($zone['size_key'] !== null) {
            $pool = wt_ad_banners_by_size($zone['size_key']);
            if (!empty($pool)) {
                return wt_ad_rotator_html($pool);
            }
        }

        // Priorité 4 : rien (comportement sûr existant)
        return '';
    }

    /**
     * Bannières actives d'un format donné (ex: '300x250'), pour la
     * rotation automatique. Cache mémoire par requête, par format.
     *
     * @return array<int, array{id:int,filename:string,width:int,height:int}>
     */
    function wt_ad_banners_by_size(string $sizeKey): array
    {
        if (!isset($GLOBALS['__wt_ad_banners_by_size_cache'][$sizeKey])) {
            $rows = [];
            try {
                $stmt = db()->prepare(
                    "SELECT id, filename, width, height FROM ad_banners
                      WHERE active = 1 AND size_key = ? ORDER BY id ASC"
                );
                $stmt->bind_param('s', $sizeKey);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = $res->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            } catch (Throwable $e) {
                error_log('[Wintaskly ad_banners_by_size] ' . $e->getMessage());
            }
            $GLOBALS['__wt_ad_banners_by_size_cache'][$sizeKey] = $rows;
        }
        return $GLOBALS['__wt_ad_banners_by_size_cache'][$sizeKey];
    }

    /**
     * Bloc HTML de rotation : toutes les bannières du pool sont présentes
     * dans le DOM (seule la première est visible), et media/wintaskly/js/
     * ad-rotator.js bascule la visibilité toutes les ~15-30s si le pool
     * contient plus d'une bannière. Avec une seule bannière, s'affiche
     * simplement telle quelle (pas de JS déclenché).
     *
     * @param array<int, array{id:int,filename:string,width:int,height:int}> $pool
     */
    function wt_ad_rotator_html(array $pool): string
    {
        $signup = e(wt_url('/auth/signup.php'));
        $slides = '';
        foreach ($pool as $i => $banner) {
            $src = e(wt_url('/media/wintaskly/img/banners/' . $banner['filename']));
            $active = $i === 0 ? ' is-active' : '';
            $slides .= '<a href="' . $signup . '" class="wt-ad-house wt-ad-rotator__slide' . $active . '">'
                     . '<img src="' . $src . '" width="' . (int) $banner['width'] . '"'
                     . ' height="' . (int) $banner['height'] . '" alt="Wintaskly" loading="lazy">'
                     . '</a>';
        }
        return '<div class="wt-ad-scale">'
             . '<div class="wt-ad-label">' . e(t('ad.title.pub')) . '</div>'
             . '<div class="wt-ad-scale__inner">'
             . '<div class="wt-ad-rotator" data-ad-rotator>' . $slides . '</div>'
             . '</div></div>';
    }

    /**
     * Récupère une bannière uploadée active par son ID (avec cache mémoire
     * par requête). Retourne null si absente, inactive, ou table indisponible.
     *
     * @return array{id:int,filename:string,width:int,height:int,size_key:string}|null
     */
    function wt_ad_banner_get(int $id): ?array
    {
        if (!isset($GLOBALS['__wt_ad_banners_cache'])) {
            $GLOBALS['__wt_ad_banners_cache'] = [];
            try {
                $res = db()->query("SELECT id, filename, width, height, size_key FROM ad_banners WHERE active = 1");
                if ($res instanceof mysqli_result) {
                    while ($r = $res->fetch_assoc()) {
                        $GLOBALS['__wt_ad_banners_cache'][(int) $r['id']] = [
                            'id'       => (int) $r['id'],
                            'filename' => (string) $r['filename'],
                            'width'    => (int) $r['width'],
                            'height'   => (int) $r['height'],
                            'size_key' => (string) $r['size_key'],
                        ];
                    }
                    $res->free();
                }
            } catch (Throwable $e) {
                error_log('[Wintaskly ad_banner] ' . $e->getMessage());
            }
        }
        return $GLOBALS['__wt_ad_banners_cache'][$id] ?? null;
    }
}

if (!function_exists('wt_partners_real')) {
    /**
     * Partenaires RÉELS de la plateforme, groupés par catégorie.
     *
     * La section « Partenaires » de l'accueil décrivait auparavant des
     * catégories abstraites sans jamais nommer personne — une section
     * intitulée « partenaires vérifiés » qui ne cite aucun partenaire
     * produit l'effet inverse de celui recherché.
     *
     * On lit donc les noms directement dans les tables : offerwalls et
     * shortlinks actifs, méthodes de retrait actives. Aucun nom n'est
     * écrit en dur, la liste suit la configuration réelle et reste juste
     * automatiquement quand un partenaire est ajouté ou désactivé.
     *
     * @return array{offers:string[], links:string[], pay:string[]}
     */
    function wt_partners_real(): array
    {
        if (isset($GLOBALS['__wt_partners'])) {
            return $GLOBALS['__wt_partners'];
        }
        $out = ['offers' => [], 'links' => [], 'pay' => []];
        $queries = [
            'offers' => "SELECT DISTINCT name FROM offerwalls WHERE active = 1 ORDER BY name LIMIT 12",
            // Les shortlinks sont des liens individuels : on regroupe par
            // fournisseur, sinon on afficherait 40 fois le même partenaire.
            'links'  => "SELECT DISTINCT provider AS name FROM shortlinks
                          WHERE active = 1 AND provider <> '' AND provider <> 'manual'
                          ORDER BY provider LIMIT 12",
            'pay'    => "SELECT DISTINCT label AS name FROM withdrawal_methods WHERE active = 1 ORDER BY sort_order LIMIT 12",
        ];
        foreach ($queries as $key => $sql) {
            try {
                $res = db()->query($sql);
                if ($res instanceof mysqli_result) {
                    while ($r = $res->fetch_assoc()) {
                        $n = trim((string) $r['name']);
                        if ($n !== '') $out[$key][] = $n;
                    }
                    $res->free();
                }
            } catch (Throwable $e) {
                error_log('[Wintaskly partners] ' . $key . ': ' . $e->getMessage());
            }
        }
        $GLOBALS['__wt_partners'] = $out;
        return $out;
    }
}

if (!function_exists('wt_has_testimonials')) {
    /**
     * Y a-t-il au moins un témoignage publié ?
     *
     * Sert à masquer entièrement le lien vers /testimonials/ tant que la
     * page est vide : une page « Ce que disent nos membres » sans aucun
     * membre qui s'exprime dessert la crédibilité, surtout liée depuis le
     * pied de page de toutes les pages du site.
     *
     * Résultat mis en cache pour la durée de la requête : le lien apparaît
     * dans le header ET le footer, on ne veut pas deux requêtes SQL.
     */
    function wt_has_testimonials(): bool
    {
        if (!isset($GLOBALS['__wt_has_testi'])) {
            $n = 0;
            try {
                $res = db()->query("SELECT COUNT(*) c FROM testimonials WHERE status = 'approved'");
                if ($res instanceof mysqli_result) {
                    $n = (int) ($res->fetch_assoc()['c'] ?? 0);
                    $res->free();
                }
            } catch (Throwable $e) {
                error_log('[Wintaskly testimonials] ' . $e->getMessage());
            }
            $GLOBALS['__wt_has_testi'] = $n > 0;
        }
        return (bool) $GLOBALS['__wt_has_testi'];
    }
}

if (!function_exists('wt_consent_allows')) {
    /**
     * L'utilisateur a-t-il consenti à cette catégorie de cookies ?
     *
     * POURQUOI CE CONTRÔLE EXISTE
     * ---------------------------
     * La bannière de consentement enregistrait bien le choix du visiteur
     * dans le cookie `wt_consent`, mais aucun code serveur ne le lisait :
     * Google Analytics et AdSense étaient injectés à l'identique, que le
     * visiteur ait accepté, refusé, ou n'ait pas encore répondu.
     *
     * Le RGPD exige un consentement PRÉALABLE et EFFECTIF : déposer un
     * cookie publicitaire malgré un refus est précisément ce que sanctionne
     * la CNIL. Cette fonction rend le choix du visiteur réellement
     * opérant côté serveur.
     *
     * Valeurs écrites par la bannière (media/wintaskly/js/wintaskly.js) :
     *   'all'                       → tout accepté
     *   'essential'                 → refus (strictement nécessaire)
     *   'custom:ads' / 'custom:analytics' / 'custom:ads,analytics'
     *
     * Par défaut (aucun cookie = pas encore de réponse), on refuse : c'est
     * l'exigence du consentement préalable. Un visiteur qui n'a pas répondu
     * ne doit pas être pisté.
     *
     * @param string $category 'ads' ou 'analytics'
     */
    function wt_consent_allows(string $category): bool
    {
        $raw = (string) ($_COOKIE['wt_consent'] ?? '');
        if ($raw === '') {
            return false;               // pas encore de réponse → refus
        }
        if ($raw === 'all') {
            return true;
        }
        if ($raw === 'essential') {
            return false;
        }
        if (str_starts_with($raw, 'custom:')) {
            $flags = array_map('trim', explode(',', substr($raw, 7)));
            return in_array($category, $flags, true);
        }
        return false;                   // valeur inconnue → refus par prudence
    }
}

if (!function_exists('wt_analytics_allowed')) {
    /**
     * La mesure d'audience est-elle autorisée sur la page courante ?
     *
     * DEUX RAISONS DE L'EXCLURE
     * -------------------------
     * 1. FUITE DE JETONS. Google Analytics enregistre l'URL COMPLÈTE de
     *    chaque page vue. Or plusieurs pages portent un jeton dans l'URL :
     *      - /auth/reset-password.php?token=…   → réinitialisation de mot de passe
     *      - /auth/verify-email.php?token=…     → validation de compte
     *      - /help/contact-track/<token>        → accès au ticket d'un membre
     *      - /tasks/faucet/transition.php?t=…   → session de réclamation
     *    Ces jetons se retrouveraient dans les rapports Analytics, lisibles
     *    par toute personne ayant accès au compte. Un jeton de
     *    réinitialisation suffit à prendre la main sur un compte.
     *
     * 2. PERTINENCE. L'administration n'est pas du trafic : mesurer ses
     *    propres allers-retours dans le back-office fausse les statistiques
     *    d'audience et expose la structure interne du site.
     *
     * Le reste de l'espace membre (/dashboard/, /tasks/) reste mesuré :
     * comprendre le parcours des membres a une vraie valeur, et ces pages
     * ne portent pas de jeton — hors celles listées ci-dessus.
     */
    function wt_analytics_allowed(): bool
    {
        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) parse_url($uri, PHP_URL_PATH);

        // Back-office : jamais mesuré
        if (stripos($path, '/admin') !== false) {
            return false;
        }
        // Pages dont l'URL contient un jeton
        foreach (['/auth/reset-password', '/auth/verify-email',
                  '/help/contact-track', '/tasks/faucet/transition',
                  '/tasks/faucet/verify'] as $sensitive) {
            if (stripos($path, $sensitive) !== false) {
                return false;
            }
        }
        // Garde-fou générique : tout paramètre ressemblant à un jeton
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $q);
        foreach (['token', 't', 'code', 'key', 'hash'] as $k) {
            if (!empty($q[$k])) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('wt_adsense_allowed')) {
    /**
     * AdSense est-il autorisé sur la page courante ?
     *
     * POURQUOI CE GARDE-FOU
     * ---------------------
     * Les règles du programme AdSense interdisent de rémunérer les
     * utilisateurs pour le visionnage d'annonces, et classent les modèles
     * paid-to-click / paid-to-surf en trafic invalide (motif de fermeture
     * de compte). Servir AdSense sur une page où le membre est payé pour
     * son activité expose donc le compte à une fermeture définitive.
     *
     * On bloque AdSense sur :
     *   - /tasks/*      : toutes les activités rémunérées (faucet, PTC,
     *                     shortlinks, offerwalls, bingo)
     *   - /dashboard/*  : espace membre lié aux gains et aux retraits
     *   - /achievements : récompenses
     *
     * AdSense reste servi sur les pages éditoriales publiques (accueil,
     * blog, à propos, aide, FAQ, légal) : ce sont des visiteurs non
     * rémunérés, venus notamment de la recherche.
     *
     * Les autres régies (Adsterra) et les bannières maison ne sont pas
     * concernées : elles acceptent ce modèle et continuent de servir
     * partout.
     */
    function wt_adsense_allowed(): bool
    {
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        foreach (['/tasks/', '/dashboard/', '/achievements'] as $blocked) {
            if (stripos($path, $blocked) !== false) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('wt_code_has_adsense')) {
    /**
     * Le code d'une zone contient-il de l'AdSense ? Sert à ne pas le rendre
     * sur une page rémunérée, même si l'admin l'y a collé par erreur.
     */
    function wt_code_has_adsense(string $code): bool
    {
        return stripos($code, 'adsbygoogle') !== false
            || stripos($code, 'googlesyndication') !== false
            || stripos($code, 'ca-pub-') !== false;
    }
}

if (!function_exists('wt_adsense_head')) {
    /**
     * Retourne le script AdSense "Auto Ads" à placer dans le <head>, si
     * un identifiant éditeur (ca-pub-XXXX) est configuré via /admin.
     *
     * Avec AdSense Auto Ads, ce SEUL script suffit : Google place
     * automatiquement les annonces sur le site. C'est complémentaire des
     * zones manuelles (ad_zones) pour un contrôle fin.
     *
     * Config BDD : clé 'ads.adsense_client' = 'ca-pub-1234567890123456'
     *
     * @return string Balise <script> AdSense, ou '' si non configuré
     */
    function wt_adsense_head(): string
    {
        $client = trim((string) cfg('ads.adsense_client', ''));
        if ($client === '') {
            return '';
        }
        // Validation basique du format ca-pub-XXXXXXXXXXXXXXXX
        if (!preg_match('/^ca-pub-\d{10,20}$/', $client)) {
            return '';
        }
        $enabled = (string) cfg('ads.adsense_auto', '0') === '1';
        if (!$enabled) {
            return '';
        }
        // Pages rémunérées : pas d'AdSense du tout (voir wt_adsense_allowed())
        if (!wt_adsense_allowed()) {
            return '';
        }
        // adsbygoogle.js ne doit être chargé qu'une seule fois par page :
        // header.php peut déjà l'avoir injecté via 'tracking.google_adsense_client'.
        // Un double chargement déclenche des TagError côté AdSense.
        if (!empty($GLOBALS['__wt_adsense_loaded'])) {
            return '';
        }
        $GLOBALS['__wt_adsense_loaded'] = true;
        $c = htmlspecialchars($client, ENT_QUOTES, 'UTF-8');
        return "\n<!-- Google AdSense Auto Ads -->\n"
             . "<script async src=\"https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client={$c}\""
             . " crossorigin=\"anonymous\"></script>\n";
    }
}

if (!function_exists('wt_ads_head_scripts')) {
    /**
     * Scripts publicitaires à injecter dans le <head>, une seule fois pour
     * tout le site. Typiquement le Popunder Adsterra (qui doit être placé
     * avant </head> selon leur doc).
     *
     * Config BDD : 'ads.head_code' = code brut (script Popunder, etc.)
     * Activation : 'ads.head_enabled' = '1'
     *
     * @return string Le code à injecter, ou '' si désactivé/vide
     */
    function wt_ads_head_scripts(): string
    {
        if ((string) cfg('ads.head_enabled', '0') !== '1') {
            return '';
        }
        $code = trim((string) cfg('ads.head_code', ''));
        if ($code === '') {
            return '';
        }
        return "\n<!-- Wintaskly ads (head) -->\n" . $code . "\n";
    }
}

if (!function_exists('wt_ads_body_scripts')) {
    /**
     * Scripts publicitaires à injecter juste avant </body>, une seule fois
     * pour tout le site. Typiquement la Social Bar Adsterra (qui doit être
     * placée avant </body> selon leur doc), et tout autre script global
     * (bannière native sticky, etc.).
     *
     * Config BDD : 'ads.body_code' = code brut (Social Bar, etc.)
     * Activation : 'ads.body_enabled' = '1'
     *
     * @return string Le code à injecter, ou '' si désactivé/vide
     */
    function wt_ads_body_scripts(): string
    {
        if ((string) cfg('ads.body_enabled', '0') !== '1') {
            return '';
        }
        $code = trim((string) cfg('ads.body_code', ''));
        if ($code === '') {
            return '';
        }
        return "\n<!-- Wintaskly ads (body) -->\n" . $code . "\n";
    }
}

if (!function_exists('wt_ad_banner_auto')) {
    /**
     * Bannière publicitaire AUTO-RESPONSIVE : affiche le bon format selon
     * la largeur de l'écran (728x90 desktop, 468x60 tablette, 300x250
     * mobile). Les 3 codes sont rendus, le CSS n'en montre qu'un seul à la
     * fois selon les media queries (.wt-ad-auto__728 / __468 / __300).
     *
     * Les codes proviennent de 3 zones ad_zones dédiées :
     *   - 'ads.banner_728' (Bannière 728x90)
     *   - 'ads.banner_468' (Bannière 468x60)
     *   - 'ads.banner_300' (Bannière 300x250)
     *
     * IMPORTANT : Adsterra recommande de ne pas charger le même code deux
     * fois sur une page. Ici les 3 codes sont DIFFÉRENTS (formats distincts),
     * donc c'est conforme. En revanche, n'appelle wt_ad_banner_auto() qu'UNE
     * fois par page pour éviter de dupliquer un même format.
     *
     * @return string Le bloc HTML des 3 bannières (CSS gère l'affichage)
     */
    function wt_ad_banner_auto(): string
    {
        $b728 = trim((string) cfg('ads.banner_728', ''));
        $b468 = trim((string) cfg('ads.banner_468', ''));
        $b300 = trim((string) cfg('ads.banner_300', ''));

        // Rien de configuré → rien à afficher
        if ($b728 === '' && $b468 === '' && $b300 === '') {
            return '';
        }

        $html = '<div class="wt-ad-auto">';
        if ($b728 !== '') {
            $html .= '<div class="wt-ad-auto__fmt wt-ad-auto__728">'
                   . '<div class="wt-ad-scale"><div class="wt-ad-scale__inner">' . $b728 . '</div></div>'
                   . '</div>';
        }
        if ($b468 !== '') {
            $html .= '<div class="wt-ad-auto__fmt wt-ad-auto__468">'
                   . '<div class="wt-ad-scale"><div class="wt-ad-scale__inner">' . $b468 . '</div></div>'
                   . '</div>';
        }
        if ($b300 !== '') {
            $html .= '<div class="wt-ad-auto__fmt wt-ad-auto__300">'
                   . '<div class="wt-ad-scale"><div class="wt-ad-scale__inner">' . $b300 . '</div></div>'
                   . '</div>';
        }
        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('wt_adsterra_fetch_stats')) {
    /**
     * Récupère les statistiques de revenus depuis l'API Publisher Adsterra.
     *
     * Endpoint : https://api3.adsterratools.com/publisher/stats.json
     * Auth     : header X-API-Key (jamais dans l'URL, pour la sécurité)
     * Méthode  : GET uniquement (l'API Publisher est en lecture seule)
     *
     * Config BDD :
     *   - 'ads.adsterra_api_token'  : le token généré dans Settings → API
     *   - 'ads.adsterra_domain_id'  : (optionnel) l'ID du site wintaskly.com
     *
     * @param string $startDate Date début (Y-m-d)
     * @param string $finishDate Date fin (Y-m-d)
     * @param string $groupBy   Regroupement : 'date', 'country', 'placement'...
     * @return array ['ok'=>bool, 'items'=>array, 'error'=>?string]
     */
    function wt_adsterra_fetch_stats(string $startDate, string $finishDate, string $groupBy = 'date'): array
    {
        $token = trim((string) cfg('ads.adsterra_api_token', ''));
        if ($token === '') {
            return ['ok' => false, 'items' => [], 'error' => 'no_token'];
        }
        // Déchiffrement du token (rétrocompatible : clair lu tel quel)
        if (function_exists('wt_decrypt')) {
            $token = wt_decrypt($token);
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'items' => [], 'error' => 'no_curl'];
        }

        // Validation simple des dates (format Y-m-d)
        $reDate = '/^\d{4}-\d{2}-\d{2}$/';
        if (!preg_match($reDate, $startDate) || !preg_match($reDate, $finishDate)) {
            return ['ok' => false, 'items' => [], 'error' => 'bad_date'];
        }

        // Construction de l'URL avec paramètres
        $params = [
            'start_date'  => $startDate,
            'finish_date' => $finishDate,
            'group_by[]'  => $groupBy,
        ];
        $domainId = trim((string) cfg('ads.adsterra_domain_id', ''));
        if ($domainId !== '') {
            $params['domain'] = $domainId;
        }
        $url = 'https://api3.adsterratools.com/publisher/stats.json?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'X-API-Key: ' . $token,
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        // Gestion des codes d'erreur documentés par Adsterra
        if ($response === false) {
            error_log('[Wintaskly adsterra] curl error: ' . $curlErr);
            return ['ok' => false, 'items' => [], 'error' => 'network'];
        }
        if ($httpCode === 401) {
            return ['ok' => false, 'items' => [], 'error' => 'token_invalid'];
        }
        if ($httpCode === 403) {
            return ['ok' => false, 'items' => [], 'error' => 'token_expired'];
        }
        if ($httpCode !== 200) {
            error_log('[Wintaskly adsterra] HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 200));
            return ['ok' => false, 'items' => [], 'error' => 'http_' . $httpCode];
        }

        $json = json_decode((string) $response, true);
        if (!is_array($json)) {
            return ['ok' => false, 'items' => [], 'error' => 'bad_json'];
        }

        // L'API renvoie typiquement { "items": [ { date, impression, clicks, ctr, cpm, revenue }, ... ] }
        $items = $json['items'] ?? $json['dates'] ?? $json['data'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }

        return ['ok' => true, 'items' => $items, 'error' => null];
    }
}

if (!function_exists('wt_adsterra_error_msg')) {
    /**
     * Traduit un code d'erreur de wt_adsterra_fetch_stats() en message i18n.
     */
    function wt_adsterra_error_msg(string $code): string
    {
        $map = [
            'no_token'      => t('admin.ads.stats_err_no_token'),
            'no_curl'       => t('admin.ads.stats_err_no_curl'),
            'bad_date'      => t('admin.ads.stats_err_bad_date'),
            'token_invalid' => t('admin.ads.stats_err_token_invalid'),
            'token_expired' => t('admin.ads.stats_err_token_expired'),
            'network'       => t('admin.ads.stats_err_network'),
            'bad_json'      => t('admin.ads.stats_err_bad_json'),
        ];
        return $map[$code] ?? (t('admin.ads.stats_err_generic') . ' (' . $code . ')');
    }
}

/**
 * Récupère le prix en temps réel d'une paire sur Binance
 * @param string $symbol Exemple: "BTCEUR", "LTCEUR", "EURUSDT"
 * @return float|null Retourne le prix ou null en cas d'erreur
 */
function getBinancePrice(string $symbol): ?float {
    // Nettoyer le symbole (en majuscules, sans espaces)
    $symbol = strtoupper(trim($symbol));

    $url = "https://api.binance.com/api/v3/ticker/price?symbol=" . urlencode($symbol);

    // Initialisation de cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Timeout rapide de 5s pour ne pas bloquer le site
    curl_setopt($ch, CURLOPT_USERAGENT, 'Wintaskly-Admin/1.0');

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['price'])) {
            return (float)$data['price'];
        }
    }

    return null;
}

/**
 * Récupère les taux de change EUR depuis le cache local (ou Binance si expiré)
 */
function get_cached_rates(): array {
    $cacheFile = __DIR__ . '/rates_cache.json';
    $cacheLifetime = 600; // 10 minutes en secondes

    // Si le fichier existe et est récent, on le lit
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheLifetime) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if (is_array($data)) {
            return $data;
        }
    }

    // Sinon, on recrée le cache en interrogeant Binance
    $currencies = ['USD', 'BTC', 'LTC', 'DOGE', 'BCH', 'TRX', 'ETH', 'USDC', 'XRP'];

    foreach ($currencies as $cur) {
        if ($cur === 'USD' || $cur === 'USDC') {
            $price = getBinancePrice('EURUSDC'); 
            $ratesToEur[$cur] = $price > 0 ? round(1 / $price, 4) : 0.92;
        } else {
            // Tentative paire EUR directe
            $price = getBinancePrice($cur . 'EUR');
            if ($price !== null && $price > 0) {
                $ratesToEur[$cur] = $price;
            } else {
                // Secours via USDC
                $priceUsdc = getBinancePrice($cur . 'USDC');
                $eurUsdc = getBinancePrice('EURUSDC');
                /* Repli à 0 et non à 1.0.
                 *
                 * Un taux de 1.0 signifierait « 1 TRX = 1 EUR » (ou 1 BTC =
                 * 1 EUR) : le montant du retrait serait calculé sur une base
                 * fausse, et le contrôle `if ($rate <= 0)` de
                 * api/withdraw_submit.php ne le détecterait pas puisque 1.0
                 * est une valeur « valide ». Avec 0, la demande est rejetée
                 * proprement plutôt que payée au mauvais taux. */
                $ratesToEur[$cur] = ($priceUsdc > 0 && $eurUsdc > 0) ? round($priceUsdc / $eurUsdc, 6) : 0.0;
            }
        }
    }

    /* On ne met en cache que si TOUS les taux ont été obtenus : sinon un
     * échec réseau ponctuel figerait des taux inutilisables pendant 10
     * minutes. En cas d'échec partiel, la prochaine requête réessaiera. */
    if (!in_array(0.0, $ratesToEur, true) && !in_array(0, $ratesToEur, true)) {
        file_put_contents($cacheFile, json_encode($ratesToEur));
    } else {
        error_log('[Wintaskly rates] taux incomplets, cache non écrit : '
                  . implode(',', array_keys($ratesToEur, 0.0)));
    }
    return $ratesToEur;
}

/**
 * Types de transactions correspondant à des coins RÉELLEMENT DISTRIBUÉS
 * aux membres. Exclut volontairement :
 *   - 'withdraw'   : sortie de coins (retrait), pas une distribution
 *   - 'bingo_buy'  : dépense du membre (montant négatif en base)
 *   - 'admin'      : ajustements manuels, non représentatifs de l'activité
 * 'bingo_win' EST inclus : les gains du Bingo sont bien distribués aux
 * membres, au même titre que le faucet ou les offerwalls.
 */
const WT_TX_TYPES_DISTRIBUTED = [
    'faucet', 'shortlink', 'ptc', 'offerwall', 'referral',
    'bonus', 'daily_bonus', 'achievement', 'bingo_win',
];

/**
 * Total des coins distribués aux membres.
 *
 * @param int|null $days  Limite aux N derniers jours (glissants). null = tout.
 * @return int            Total arrondi, 0 si aucune donnée.
 */
function wt_coins_distributed(?int $days = null): int
{
    $types = WT_TX_TYPES_DISTRIBUTED;
    $in    = implode(',', array_fill(0, count($types), '?'));
    $sql   = "SELECT COALESCE(SUM(coins),0) s FROM transactions WHERE type IN ($in)";
    $args  = $types;
    $kinds = str_repeat('s', count($types));

    if ($days !== null && $days > 0) {
        $sql   .= " AND created_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY)";
        $args[] = $days;
        $kinds .= 'i';
    }

    try {
        $stmt = db()->prepare($sql);
        $stmt->bind_param($kinds, ...$args);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) round((float) ($row['s'] ?? 0));
    } catch (Throwable $e) {
        error_log('[Wintaskly coins_distributed] ' . $e->getMessage());
        return 0;
    }
}

/**
 * Méthodes de retrait actives, triées pour affichage public (accueil,
 * footer, etc.). Source unique : withdrawal_methods (déjà gérée en
 * admin/payment_methods.php) — évite toute liste codée en dur qui
 * pourrait diverger de la config réelle.
 *
 * @return array<int, array{k:string,label:string,currency:string,min_coins:float,coins_per_unit:float}>
 */
function wt_active_payment_methods(): array
{
    $rows = [];
    if ($res = db()->query(
        "SELECT k, label, currency, min_coins, coins_per_unit
           FROM withdrawal_methods
          WHERE active = 1
          ORDER BY sort_order ASC"
    )) {
        $rows = $res->fetch_all(MYSQLI_ASSOC);
        $res->free();
    }
    return $rows;
}

/**
 * Emoji visuel associé à une méthode de paiement, déduit de sa clé
 * interne (ex: 'paypal', 'btc_wallet' → 💳/₿). Repli générique 💸.
 */
function wt_pay_icon(string $k): string
{
    static $icons = [
        'paypal' => '💳', 'wise'   => '🏦', 'crypto' => '₿',
        'btc'    => '₿',  'eth'    => '⟠',  'usdt'   => '₮',
        'orange' => '📱', 'mpesa'  => '📱', 'mtn'    => '📱',
        'card'   => '💳', 'bank'   => '🏦',
    ];
    foreach ($icons as $needle => $emoji) {
        if (stripos($k, $needle) !== false) return $emoji;
    }
    return '💸';
}

