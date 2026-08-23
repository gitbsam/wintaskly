<?php
/**
 * Wintaskly — cron/tasks/provider_health.php
 * ---------------------------------------------------------------------------
 * Contrôle de disponibilité des fournisseurs de liens et des murs d'offres.
 *
 * POURQUOI CETTE TÂCHE
 * --------------------
 * Un fournisseur peut disparaître sans prévenir : service fermé, domaine
 * expiré, compte suspendu. Sans contrôle, la tâche reste proposée aux
 * membres, qui la lancent, la terminent, et ne sont jamais crédités — parce
 * qu'il n'y a plus personne au bout de la chaîne.
 *
 * Du point de vue de l'utilisateur, c'est indiscernable d'une plateforme qui
 * ne paie pas. C'est donc autant un sujet de confiance qu'un sujet technique.
 *
 * PRINCIPE
 * --------
 * Une requête légère (HEAD, puis GET en repli) sur l'URL publique de chaque
 * fournisseur actif. On enregistre le code obtenu et on tient un compteur
 * d'échecs CONSÉCUTIFS.
 *
 * Deux choix volontaires :
 *
 *   • On compte les échecs consécutifs, pas cumulés. Une maintenance de
 *     quelques minutes remet le compteur à zéro dès le contrôle suivant ;
 *     seul un service réellement disparu accumule.
 *
 *   • La désactivation automatique est OPTIONNELLE (health.auto_disable).
 *     Certains administrateurs préfèrent être alertés et décider eux-mêmes.
 *     Dans les deux cas, l'état est visible en base et en journal.
 *
 * CE QUE CE CONTRÔLE NE FAIT PAS
 * ------------------------------
 * Il vérifie qu'un service RÉPOND, pas qu'il crédite correctement. Un
 * fournisseur peut renvoyer 200 tout en ayant cessé de valider les
 * conversions. Ce contrôle attrape les disparitions franches, qui sont le
 * cas le plus fréquent — pas les défaillances silencieuses.
 */
declare(strict_types=1);

if (!function_exists('wt_health_key')) {
    /** Identifie un point d'accès de façon unique : schéma + hôte + port. */
    function wt_health_key(string $url): string
    {
        $p = parse_url($url);
        if (empty($p['host'])) { return ''; }
        $scheme = strtolower($p['scheme'] ?? 'https');
        $port   = isset($p['port']) ? ':' . (int) $p['port'] : '';
        return $scheme . '://' . strtolower($p['host']) . $port;
    }
}

wt_cron_register('provider_health', static function (): array {

    if ((string) cfg('health.enabled', '1') !== '1') {
        return ['summary' => 'health check disabled'];
    }

    $db        = db();
    $timeout   = max(3, min(30, (int) cfg('health.timeout', 8)));
    $failLimit = max(1, (int) cfg('health.fail_limit', 3));
    $autoOff   = (string) cfg('health.auto_disable', '1') === '1';

    /**
     * Interroge une URL et renvoie le code HTTP (0 si injoignable).
     * HEAD d'abord — plus léger ; certains serveurs le refusent, on
     * bascule alors sur GET.
     */
    $probe = static function (string $url) use ($timeout): int {
        if ($url === '') {
            return 0;
        }

        /* Repli sans cURL. Certains hébergements mutualisés ne l'activent
           pas ; sans ce repli, TOUS les fournisseurs seraient déclarés
           injoignables et finiraient désactivés — exactement le contraire
           du but recherché. On utilise alors les flux HTTP natifs. */
        if (!function_exists('curl_init')) {
            if (!ini_get('allow_url_fopen')) {
                return -1;   // -1 = contrôle impossible, distinct d'un échec
            }
            $ctx = stream_context_create(['http' => [
                'method'        => 'HEAD',
                'timeout'       => $timeout,
                'ignore_errors' => true,
                'user_agent'    => 'Wintaskly-HealthCheck/1.0',
            ]]);
            $h = @get_headers($url, false, $ctx);
            if (!$h || !isset($h[0])) { return 0; }
            return (int) (preg_match('#\s(\d{3})\s#', $h[0], $m) ? $m[1] : 0);
        }
        foreach ([true, false] as $useHead) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY         => $useHead,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                // Certains fournisseurs bloquent les agents inconnus
                CURLOPT_USERAGENT      => 'Wintaskly-HealthCheck/1.0',
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code > 0 && !($useHead && $code === 405)) {
                return $code;
            }
        }
        return 0;
    };

    /* Un code est considéré comme sain s'il indique que le service répond.
       401/403 sont acceptés : une API protégée qui refuse une requête
       anonyme est bien vivante — la désactiver serait une erreur. */
    $healthy = static fn(int $c): bool => $c > 0 && ($c < 400 || in_array($c, [401, 403, 429], true));

    $checked = $failed = $disabled = $skipped = 0;
    $alerts  = [];

    /* Cache des sondages, indexé par HÔTE.
     *
     * Beaucoup de fournisseurs proposent plusieurs services : le même
     * domaine peut alimenter un lien court ET un mur d'offres. Sans cache,
     * on l'interrogeait deux fois — inutilement, et surtout avec un risque
     * d'incohérence : un timeout sur l'un et pas sur l'autre aboutissait à
     * désactiver un service tout en laissant l'autre actif, alors que le
     * fournisseur est le même.
     *
     * Un hôte n'est donc sondé qu'une fois par exécution, et le verdict
     * s'applique à tous ses services. */
    $hostCache = [];

    /* table => [URL prioritaire, URL de repli, colonne de libellé].
       La colonne de libellé varie : `name` pour les liens et les murs,
       `title` pour les annonces PTC. La supposer identique partout
       provoquait une erreur SQL qui faisait sauter toute la table. */
    $tables = [
        'shortlinks' => ['api_endpoint', 'destination_url', 'name'],
        'offerwalls' => ['iframe_url',   'redirect_url',    'name'],
        // Une annonce PTC pointe vers un site externe : s'il ferme, le
        // membre regarde une page morte pendant tout le décompte.
        'ptc_ads'    => ['url',          'url',             'title'],
    ];

    foreach ($tables as $table => $cols) {
        [$primary, $fallback, $labelCol] = $cols;
        try {
            $res = $db->query(
                "SELECT id, `$labelCol` AS name, `$primary` AS u1, `$fallback` AS u2, fail_streak
                   FROM `$table` WHERE active = 1"
            );
        } catch (Throwable $e) {
            error_log('[Wintaskly health] ' . $table . ' : ' . $e->getMessage()
                      . ' — appliquez sql/migration_provider_health.sql');
            continue;
        }
        if (!$res) { continue; }

        while ($row = $res->fetch_assoc()) {
            $url = trim((string) ($row['u1'] ?: $row['u2']));
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                continue;   // rien à sonder : on n'invente pas un échec
            }
            /* Clé de cache = schéma + hôte + port.
               L'hôte seul ne suffit pas : deux services sur le même
               domaine mais des ports différents partageaient le même
               verdict — un service mort héritait du résultat d'un
               service vivant, et n'était jamais détecté. */
            $host = wt_health_key($url);
            if (array_key_exists($host, $hostCache)) {
                $code = $hostCache[$host];   // même fournisseur, déjà sondé
            } else {
                $code = $probe($url);
                $hostCache[$host] = $code;
            }
            if ($code === -1) {
                /* Contrôle impossible sur cet hébergement : on ne touche
                   à rien plutôt que de pénaliser des fournisseurs sains. */
                $skipped++;
                continue;
            }
            $ok   = $healthy($code);
            $checked++;

            $streak = $ok ? 0 : ((int) $row['fail_streak'] + 1);
            if (!$ok) { $failed++; }

            $turnOff = (!$ok && $autoOff && $streak >= $failLimit);

            $sql = "UPDATE `$table`
                       SET last_check_at = UTC_TIMESTAMP(),
                           last_http_code = ?, fail_streak = ?"
                 . ($turnOff ? ', active = 0' : '')
                 . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            $id = (int) $row['id'];
            $stmt->bind_param('iii', $code, $streak, $id);
            $stmt->execute();
            $stmt->close();

            if ($turnOff) {
                $disabled++;
                $alerts[] = sprintf('%s « %s » désactivé (%d échecs, dernier code %d)',
                                    $table, (string) $row['name'], $streak, $code);
            } elseif (!$ok) {
                $alerts[] = sprintf('%s « %s » injoignable (%d/%d, code %d)',
                                    $table, (string) $row['name'], $streak, $failLimit, $code);
            }
        }
    }

    /* ------------------------------------------------------------------
     * Zones publicitaires
     * ------------------------------------------------------------------
     * Une régie ne fournit pas une URL mais un extrait de code à coller.
     * On en extrait les domaines appelés (src="https://…") et on les sonde.
     *
     * Pourquoi c'est utile : quand le domaine d'une régie cesse de
     * répondre, plus aucune publicité ne s'affiche — donc plus aucune
     * recette. Rien ne le signale aujourd'hui, et c'est précisément la
     * ressource qui finance les récompenses.
     *
     * On ne désactive JAMAIS une zone automatiquement : contrairement à
     * une tâche, une zone morte ne trompe personne, elle ne rapporte
     * simplement plus. On se contente donc d'alerter. */
    try {
        $res = $db->query("SELECT id, k, label, code, fail_streak FROM ad_zones WHERE active = 1");
        while ($res && ($row = $res->fetch_assoc())) {
            $code = (string) $row['code'];
            if (trim($code) === '') { continue; }

            // Domaines appelés par le script de la régie
            preg_match_all('#(?:src|href)\s*=\s*["\']([^"\']+)["\']#i', $code, $m);
            $hosts = [];
            foreach ($m[1] as $u) {
                if (!preg_match('#^https?://#i', $u)) { continue; }
                $k = wt_health_key($u);
                if ($k !== '') { $hosts[$k] = $u; }
            }
            if (!$hosts) { continue; }   // code sans appel externe : rien à sonder

            /* La zone est saine dès qu'UN de ses domaines répond : les
               régies utilisent souvent plusieurs domaines de secours,
               et exiger que tous répondent produirait de fausses alertes. */
            $anyOk = false; $lastCode = 0;
            foreach ($hosts as $h => $probeUrl) {
                if (array_key_exists($h, $hostCache)) {
                    $c = $hostCache[$h];
                } else {
                    $c = $probe($probeUrl);
                    $hostCache[$h] = $c;
                }
                if ($c === -1) { continue; }
                $lastCode = $c;
                if ($healthy($c)) { $anyOk = true; break; }
            }
            if ($lastCode === 0 && !$anyOk && !$hosts) { continue; }

            $streak = $anyOk ? 0 : ((int) $row['fail_streak'] + 1);
            $checked++;
            if (!$anyOk) { $failed++; }

            $stmt = $db->prepare(
                "UPDATE ad_zones
                    SET last_check_at = UTC_TIMESTAMP(), last_http_code = ?, fail_streak = ?
                  WHERE id = ?"
            );
            $zid = (int) $row['id'];
            $stmt->bind_param('iii', $lastCode, $streak, $zid);
            $stmt->execute();
            $stmt->close();

            if (!$anyOk) {
                $alerts[] = sprintf('ad_zones « %s » : régie injoignable (%d échecs, code %d)',
                                    (string) ($row['label'] ?: $row['k']), $streak, $lastCode);
            }
        }
    } catch (Throwable $e) {
        error_log('[Wintaskly health] ad_zones : ' . $e->getMessage()
                  . ' — appliquez sql/migration_provider_health.sql');
    }

    foreach ($alerts as $a) {
        error_log('[Wintaskly health] ' . $a);
    }

    /* Notification à l'administrateur uniquement en cas de désactivation :
       un fournisseur qui vient d'être retiré doit être remplacé, sinon la
       tâche correspondante disparaît de la plateforme sans que personne ne
       le sache. Les simples échecs isolés ne notifient pas — ce serait du
       bruit quotidien. */
    if ($disabled > 0 && function_exists('wt_notify')) {
        try {
            $admins = $db->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
            while ($admins && ($a = $admins->fetch_assoc())) {
                wt_notify((int) $a['id'], 'security',
                    (string) t('health.notif_title'),
                    sprintf((string) t('health.notif_body'), $disabled));
            }
        } catch (Throwable $e) {
            error_log('[Wintaskly health] notification : ' . $e->getMessage());
        }
    }

    return [
        'summary' => sprintf('services=%d hosts=%d failed=%d disabled=%d skipped=%d',
                             $checked, count($hostCache), $failed, $disabled, $skipped),
    ];
});
