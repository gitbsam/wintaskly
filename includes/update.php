<?php
/**
 * Wintaskly — Module système de mises à jour
 * ─────────────────────────────────────────────────────────────────────
 * Centralise toute la logique de check des mises à jour distantes :
 *   - Téléchargement de latest.json depuis l'URL configurée
 *   - Comparaison de version (semver)
 *   - Persistance en BDD du résultat
 *   - Détection de la disponibilité d'une nouvelle version
 *
 * Le check effectif est appelé périodiquement par /api/cron.php (toutes
 * les 6 heures) pour ne pas marteler le serveur GitHub.
 *
 * Le module est volontairement TOLÉRANT aux pannes : si GitHub est down,
 * pas d'exception remontée, juste un log. Le site doit tourner même
 * sans connexion vers l'extérieur.
 */
declare(strict_types=1);

if (!function_exists('wt_update_check_now')) {
    /**
     * Effectue un check de mise à jour MAINTENANT en allant chercher
     * le latest.json distant. Met à jour la BDD avec le résultat.
     *
     * Retourne un tableau :
     *   ['status' => 'ok'|'network_error'|'parse_error'|'disabled',
     *    'current' => '8.7.6',
     *    'latest'  => '8.8.0',
     *    'has_update' => true,
     *    'data' => [...],         // données brutes latest.json
     *    'error' => '...']
     */
    function wt_update_check_now(): array
    {
        $result = [
            'status'     => 'ok',
            'current'    => WT_VERSION,
            'latest'     => null,
            'has_update' => false,
            'data'       => null,
            'error'      => null,
        ];

        $feedUrl = (string) cfg('update.feed_url', WT_UPDATE_FEED_DEFAULT);
        if ($feedUrl === '' || !filter_var($feedUrl, FILTER_VALIDATE_URL)) {
            $result['status'] = 'disabled';
            $result['error']  = 'URL de mise à jour non configurée';
            wt_update_record_check($result);
            return $result;
        }

        // Récupère le JSON distant via cURL avec timeout court
        $raw = wt_update_http_get($feedUrl, 8);
        if ($raw === null) {
            $result['status'] = 'network_error';
            $result['error']  = 'Impossible de joindre ' . $feedUrl;
            wt_update_record_check($result);
            return $result;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['version'])) {
            $result['status'] = 'parse_error';
            $result['error']  = 'JSON invalide ou sans champ "version"';
            wt_update_record_check($result);
            return $result;
        }

        $latest = trim((string) $data['version']);
        $result['latest'] = $latest;
        $result['data']   = $data;
        $result['has_update'] = wt_version_compare($latest, WT_VERSION) > 0;

        // Persistance config
        wt_config_set('update.last_check_at',  date('Y-m-d H:i:s'));
        wt_config_set('update.latest_version', $latest);
        wt_config_set('update.latest_data',    $raw);

        wt_update_record_check($result);
        return $result;
    }
}

if (!function_exists('wt_update_http_get')) {
    /**
     * GET HTTP simple avec timeout court. Retourne le body ou null.
     * Utilise cURL si dispo, sinon file_get_contents en fallback.
     */
    function wt_update_http_get(string $url, int $timeoutSec = 8): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeoutSec,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_USERAGENT      => 'Wintaskly/' . WT_VERSION . ' (+update-check)',
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);
            $body = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body !== false && $http >= 200 && $http < 300) {
                return (string) $body;
            }
            return null;
        }

        // Fallback file_get_contents (allow_url_fopen requis)
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => $timeoutSec,
                'header'  => "User-Agent: Wintaskly/" . WT_VERSION . "\r\nAccept: application/json\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return $body !== false ? $body : null;
    }
}

if (!function_exists('wt_update_record_check')) {
    /**
     * Enregistre une trace du check dans update_checks pour audit.
     */
    function wt_update_record_check(array $result): void
    {
        try {
            $db = db();
            $stmt = $db->prepare(
                "INSERT INTO update_checks (status, current_ver, latest_ver, error_message)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->bind_param('ssss',
                $result['status'],
                $result['current'],
                $result['latest'],
                $result['error']
            );
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log('[Wintaskly update] record_check failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('wt_version_compare')) {
    /**
     * Compare deux versions SemVer.
     * Retourne :
     *   -1 si $a < $b
     *    0 si $a == $b
     *   +1 si $a > $b
     *
     * Wrapper autour de version_compare() pour gérer le préfixe 'v'
     * et les chaînes non-strictement SemVer (ex: '8.7.6-rc1').
     */
    function wt_version_compare(string $a, string $b): int
    {
        $a = ltrim($a, 'vV');
        $b = ltrim($b, 'vV');
        $res = version_compare($a, $b);
        if ($res < 0) return -1;
        if ($res > 0) return 1;
        return 0;
    }
}

if (!function_exists('wt_update_has_pending')) {
    /**
     * Retourne true s'il y a une update dispo (selon la dernière vérif
     * stockée en BDD). N'effectue PAS de nouveau check — c'est cron qui
     * fait ça toutes les 6h. Lecture O(1) depuis la config BDD.
     */
    function wt_update_has_pending(): bool
    {
        $latest = (string) cfg('update.latest_version', '');
        if ($latest === '') return false;
        return wt_version_compare($latest, WT_VERSION) > 0;
    }
}

if (!function_exists('wt_update_latest_data')) {
    /**
     * Retourne les données décodées du dernier latest.json récupéré
     * (ou null si pas encore de check valide).
     */
    function wt_update_latest_data(): ?array
    {
        $raw = (string) cfg('update.latest_data', '');
        if ($raw === '') return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}

if (!function_exists('wt_update_is_critical')) {
    /**
     * Une update est dite "critique" si elle contient le flag critical=true
     * dans latest.json. Sert à afficher une bannière rouge en admin pour
     * les fixes de sécurité.
     */
    function wt_update_is_critical(): bool
    {
        $data = wt_update_latest_data();
        return is_array($data) && !empty($data['critical']);
    }
}

if (!function_exists('wt_maintenance_on')) {
    /**
     * Le site est-il en mode maintenance ? Lecture rapide depuis config BDD.
     * Si oui, le middleware en bas de init.php renvoie 503 à tous sauf admin.
     */
    function wt_maintenance_on(): bool
    {
        return (string) cfg('update.maintenance_on', '0') === '1';
    }
}

if (!function_exists('wt_config_set')) {
    /**
     * Mini helper pour upsert dans config. Utilisé par le système d'update
     * pour persister last_check_at, latest_version, etc.
     */
    function wt_config_set(string $key, string $value): void
    {
        try {
            $db = db();
            $stmt = $db->prepare(
                "INSERT INTO config (k, v) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE v = VALUES(v)"
            );
            $stmt->bind_param('ss', $key, $value);
            $stmt->execute();
            $stmt->close();
            // Invalide le cache cfg() local s'il existe
            if (isset($GLOBALS['__wt_cfg_cache'])) {
                $GLOBALS['__wt_cfg_cache'][$key] = $value;
            }
        } catch (Throwable $e) {
            error_log('[Wintaskly config_set] ' . $e->getMessage());
        }
    }
}
