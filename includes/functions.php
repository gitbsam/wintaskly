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
        if ($referrerId && in_array($type, ['faucet', 'shortlink'], true)) {
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

                $stmt = $db->prepare(
                    "INSERT INTO referral_earnings
                       (referrer_id, referee_id, source, source_amount, commission)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $stmt->bind_param('iisdd', $referrerId, $userId, $type, $coins, $bonus);
                $stmt->execute();
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
                $res = db()->query("SELECT k, code FROM ad_zones WHERE active = 1");
                if ($res instanceof mysqli_result) {
                    while ($r = $res->fetch_assoc()) {
                        $GLOBALS['__wt_ad_zones_cache'][$r['k']] = (string) $r['code'];
                    }
                    $res->free();
                }
            } catch (Throwable $e) {
                // Table ad_zones absente ou inaccessible → pas de pub, pas de crash
                error_log('[Wintaskly ad_zone] ' . $e->getMessage());
            }
        }

        $code = $GLOBALS['__wt_ad_zones_cache'][$key] ?? '';

        // On n'affiche pas les placeholders de démo (commentaires HTML seuls)
        $stripped = trim(preg_replace('/<!--.*?-->/s', '', $code));
        if ($stripped === '') {
            return '';
        }

        // Enveloppe le code dans un conteneur de mise à l'échelle responsive.
        // Les iframes pub à taille fixe (A-ADS 728x90, etc.) sont ainsi
        // réduites proportionnellement sur mobile au lieu de déborder.
        // Le script wt-ads-responsive.js calcule l'échelle automatiquement.
        return '<div class="wt-ad-scale"><div class="wt-ad-scale__inner">'
             . $code
             . '</div></div>';
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
