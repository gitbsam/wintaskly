<?php
/**
 * Wintaskly — includes/campaigns.php
 * ---------------------------------------------------------------------------
 * Suivi des campagnes d'acquisition.
 *
 * PARCOURS COMPLET
 * ----------------
 *   1. Un visiteur arrive sur /campagn/2026/CODE.
 *   2. Un identifiant anonyme est déposé en cookie et une visite est ouverte.
 *   3. Ses pages vues et son temps de présence alimentent cette visite.
 *   4. S'il s'inscrit, le compte est rattaché à la visite.
 *   5. Après vérification de l'e-mail ET dix jours d'activité réelle, la
 *      prime est versée — une seule fois.
 *
 * POURQUOI L'ÉTAPE 5 EST EXIGEANTE
 * --------------------------------
 * Verser au moment de l'inscription reviendrait à payer des comptes créés en
 * masse pour toucher la prime. Le versement suppose donc une activité étalée
 * dans le temps : plusieurs journées distinctes avec au moins une tâche
 * rémunérée accomplie. C'est difficile à automatiser et cela sélectionne les
 * membres réellement intéressés — les seuls qui rentabilisent un budget
 * publicitaire.
 *
 * PROTECTION DES DONNÉES
 * ----------------------
 * L'adresse IP n'est jamais stockée en clair : seul un condensé calculé avec
 * le secret de l'application est conservé. Il permet de reconnaître un
 * visiteur qui revient sans constituer un fichier d'adresses exploitable.
 */
declare(strict_types=1);

if (!function_exists('wt_campaign_find')) {

    /** Retrouve une campagne par son code. */
    function wt_campaign_find(string $code): ?array
    {
        $code = preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '';
        if ($code === '' || mb_strlen($code) > 24) { return null; }
        return db_one("SELECT * FROM campaigns WHERE code = ? LIMIT 1", [$code], 's');
    }

    /**
     * La campagne est-elle en mesure de récompenser un visiteur ?
     *
     * Distinguer « campagne trouvée » et « campagne active » est essentiel :
     * un lien périmé qui circule encore sur un site partenaire doit afficher
     * la page normalement — sinon on perd un visiteur — mais sans promettre
     * une récompense qui ne sera pas versée.
     */
    function wt_campaign_is_live(?array $c): bool
    {
        if (!$c || $c['status'] !== 'active') { return false; }
        $now = time();
        if (!empty($c['starts_at']) && strtotime((string) $c['starts_at']) > $now) { return false; }
        if (!empty($c['ends_at'])   && strtotime((string) $c['ends_at'])   < $now) { return false; }
        return (float) $c['reward_coins'] > 0;
    }

    /** Condensé d'IP, non réversible en pratique. */
    function wt_campaign_ip_hash(): ?string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '') { return null; }
        $salt = (string) ($GLOBALS['WT_CONFIG']['app_secret'] ?? 'wt');
        return hash('sha256', $ip . '|' . $salt);
    }

    /**
     * Identifiant de visiteur, déposé en cookie.
     *
     * Anonyme et sans lien avec un compte : il sert uniquement à rattacher
     * une inscription ultérieure à la campagne d'origine. Sa durée de vie
     * permet à quelqu'un qui hésite plusieurs semaines d'être malgré tout
     * comptabilisé.
     */
    function wt_campaign_visitor_key(): string
    {
        $name = 'wt_cv';
        $cur  = (string) ($_COOKIE[$name] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/', $cur)) { return $cur; }

        $key  = bin2hex(random_bytes(16));
        $days = max(1, (int) cfg('campaign.cookie_days', 90));
        setcookie($name, $key, [
            'expires'  => time() + $days * 86400,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[$name] = $key;
        return $key;
    }

    /**
     * Enregistre ou met à jour une visite, et note la page consultée.
     *
     * @return int|null Identifiant de la visite
     */
    function wt_campaign_track(?array $campaign, string $path, int $seconds = 0): ?int
    {
        $key = wt_campaign_visitor_key();
        $cid = $campaign ? (int) $campaign['id'] : null;
        $db  = db();

        try {
            /* Une seule ligne par visiteur et par campagne : on tente
               l'insertion, et la clé unique transforme un doublon en simple
               mise à jour des compteurs. Deux requêtes séparées laisseraient
               passer deux visites simultanées. */
            $stmt = $db->prepare(
                "INSERT INTO campaign_visits
                   (campaign_id, visitor_key, ip_hash, country, user_agent, referer,
                    pages_viewed, total_seconds)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?)
                 ON DUPLICATE KEY UPDATE
                   pages_viewed  = pages_viewed + 1,
                   total_seconds = total_seconds + VALUES(total_seconds),
                   last_seen_at  = UTC_TIMESTAMP()"
            );
            $ipHash  = wt_campaign_ip_hash();
            $country = null;
            $ua      = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            $ref     = mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 255);
            $stmt->bind_param('isssssi', $cid, $key, $ipHash, $country, $ua, $ref, $seconds);
            $stmt->execute();
            $stmt->close();

            $row = db_one(
                "SELECT id FROM campaign_visits
                  WHERE visitor_key = ? AND campaign_id <=> ? LIMIT 1",
                [$key, $cid], 'si'
            );
            $vid = $row ? (int) $row['id'] : null;

            if ($vid && $path !== '') {
                $stmt = $db->prepare(
                    "INSERT INTO campaign_pageviews (visit_id, path, seconds) VALUES (?, ?, ?)"
                );
                $p = mb_substr($path, 0, 190);
                $stmt->bind_param('isi', $vid, $p, $seconds);
                $stmt->execute();
                $stmt->close();
            }
            return $vid;
        } catch (Throwable $e) {
            error_log('[Wintaskly campaign] ' . $e->getMessage()
                      . ' — appliquez sql/migration_campaigns.sql');
            return null;
        }
    }

    /**
     * Rattache un compte nouvellement créé à sa visite d'origine.
     *
     * Appelé à l'inscription. On ne verse rien à ce stade : la prime dépend
     * d'une activité qui n'a pas encore eu lieu.
     */
    function wt_campaign_attach_user(int $userId): bool
    {
        $key = (string) ($_COOKIE['wt_cv'] ?? '');
        if ($userId <= 0 || !preg_match('/^[a-f0-9]{32}$/', $key)) { return false; }

        try {
            /* On ne rattache qu'une visite liée à une campagne réelle et
               pas déjà convertie : sinon deux comptes créés depuis le même
               navigateur revendiqueraient la même visite. */
            $stmt = db()->prepare(
                "UPDATE campaign_visits
                    SET converted_user_id = ?, converted_at = UTC_TIMESTAMP()
                  WHERE visitor_key = ? AND campaign_id IS NOT NULL
                    AND converted_user_id IS NULL
                  ORDER BY last_seen_at DESC LIMIT 1"
            );
            $stmt->bind_param('is', $userId, $key);
            $stmt->execute();
            $ok = $stmt->affected_rows > 0;
            $stmt->close();
            return $ok;
        } catch (Throwable $e) {
            error_log('[Wintaskly campaign] rattachement : ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Le membre a-t-il satisfait la condition d'activité ?
     *
     * On compte les JOURS DISTINCTS où une tâche rémunérée a été accomplie,
     * dans la fenêtre suivant l'inscription. Compter les tâches plutôt que
     * les jours permettrait de tout faire en une heure ; compter les
     * connexions ne prouverait rien du tout.
     */
    function wt_campaign_user_qualifies(int $userId): bool
    {
        $window = max(1, (int) cfg('campaign.active_days', 10));
        $need   = max(1, (int) cfg('campaign.active_min_days', 5));

        $row = db_one(
            "SELECT COUNT(DISTINCT DATE(t.created_at)) AS d
               FROM transactions t
               JOIN users u ON u.id = t.user_id
              WHERE t.user_id = ?
                AND t.type IN ('faucet','shortlink','ptc','offerwall')
                AND t.created_at >= u.created_at
                AND t.created_at <= DATE_ADD(u.created_at, INTERVAL ? DAY)",
            [$userId, $window], 'ii'
        );
        return (int) ($row['d'] ?? 0) >= $need;
    }
}
