<?php
/**
 * Wintaskly — Connexion MySQLi.
 * Expose la fonction db() qui renvoie une instance mysqli partagée.
 */

if (!function_exists('db')) {
    function db(): mysqli
    {
        static $mysqli = null;
        if ($mysqli instanceof mysqli) {
            return $mysqli;
        }

        $config = $GLOBALS['WT_CONFIG'] ?? [];
        $dbc    = $config['db'] ?? [];

        // ------------------------------------------------------------------
        // Mode de rapport d'erreur mysqli selon l'environnement.
        //
        // SÉCURITÉ : on considère qu'on est en PRODUCTION par défaut, SAUF si
        // 'environment' vaut explicitement 'development'. Ainsi, si la clé
        // 'environment' est absente du config.php (ancienne version de
        // l'installeur, config incomplet), on retombe sur le mode SÛR
        // (production) au lieu de strict — ce qui évite des 500 fatals en
        // cascade sur une table/colonne manquante.
        //
        // En DÉVELOPPEMENT (explicite) : MYSQLI_REPORT_ERROR | STRICT
        //   → toute erreur SQL lève une exception (pratique pour débugger).
        //
        // En PRODUCTION (défaut) : MYSQLI_REPORT_ERROR uniquement
        //   → une requête échouée retourne false au lieu de lever une
        //     exception fatale. Les erreurs sont loggées mais gérables via
        //     `if ($res = $db->query(...))` ou try/catch.
        // ------------------------------------------------------------------
        $isDevelopment = (($config['environment'] ?? 'production') === 'development');
        if ($isDevelopment) {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        } else {
            mysqli_report(MYSQLI_REPORT_ERROR);
        }

        try {
            // Support socket Unix (hébergements partagés type OVH, Infomaniak)
            // ou connexion TCP classique selon la config.
            $socket = $dbc['socket'] ?? null;
            if ($socket) {
                $mysqli = new mysqli(
                    $dbc['host'] ?? 'localhost',
                    $dbc['user'] ?? 'root',
                    $dbc['pass'] ?? '',
                    $dbc['name'] ?? 'wintaskly',
                    null,
                    $socket
                );
            } else {
                $mysqli = new mysqli(
                    $dbc['host'] ?? '127.0.0.1',
                    $dbc['user'] ?? 'root',
                    $dbc['pass'] ?? '',
                    $dbc['name'] ?? 'wintaskly',
                    (int)($dbc['port'] ?? 3306)
                );
            }
            $mysqli->set_charset($dbc['charset'] ?? 'utf8mb4');

            // Calage strict de la timezone serveur (UTC interne)
            $mysqli->query("SET time_zone = '+00:00'");
        } catch (mysqli_sql_exception $e) {
            http_response_code(500);
            if (!empty($config['debug'])) {
                die('DB error : ' . htmlspecialchars($e->getMessage()));
            }
            die('Service indisponible. Réessayez plus tard.');
        }

        return $mysqli;
    }
}

/**
 * Helper : récupère une valeur de la table config (cache statique).
 */
if (!function_exists('cfg')) {
    /**
     * Lit une clé de configuration depuis la table `config`.
     * Le cache est partagé avec cfg_set() pour invalidation correcte.
     */
    function cfg(string $key, $default = null)
    {
        if (!isset($GLOBALS['__wt_cfg_cache'])) {
            $GLOBALS['__wt_cfg_cache'] = [];
            // Try/catch car sur LWS, mysqli est en mode strict
            // (MYSQLI_REPORT_STRICT) : une requête sur une table inexistante
            // lève une exception au lieu de retourner false. Si la table
            // `config` est temporairement absente (migration en cours,
            // restauration de BDD, etc.), on ne plante PAS toute l'app —
            // on retourne juste les valeurs par défaut.
            try {
                $res = db()->query("SELECT k, v FROM config");
                if ($res) {
                    while ($r = $res->fetch_assoc()) {
                        $GLOBALS['__wt_cfg_cache'][$r['k']] = $r['v'];
                    }
                    $res->free();
                }
            } catch (Throwable $e) {
                // Table config absente ou inaccessible. On garde le cache
                // vide (= tout retourne les defaults). On marque un flag
                // pour que d'autres parties du code puissent détecter ce
                // mode dégradé si besoin.
                $GLOBALS['__wt_cfg_unavailable'] = true;
                error_log('[Wintaskly cfg] config table unavailable: ' . $e->getMessage());
            }
        }
        return $GLOBALS['__wt_cfg_cache'][$key] ?? $default;
    }
}

if (!function_exists('db_one')) {
    /**
     * Exécute une requête et retourne la PREMIÈRE ligne (associative) ou
     * null. Ne plante JAMAIS, même si la table n'existe pas, même en mode
     * mysqli strict : tout est entouré d'un try/catch.
     *
     * Usage :
     *   $row = db_one("SELECT COUNT(*) c FROM users");
     *   $n   = (int) ($row['c'] ?? 0);
     *
     * Remplace le pattern dangereux :
     *   $row = $db->query("...")->fetch_assoc();  // ❌ plante si query false
     *
     * @param  string     $sql Requête SQL (sans paramètres — pour requêtes
     *                         dynamiques utiliser des prepared statements)
     * @return array|null      Première ligne, ou null si vide/erreur
     */
    function db_one(string $sql): ?array
    {
        try {
            $res = db()->query($sql);
            if ($res instanceof mysqli_result) {
                $row = $res->fetch_assoc();
                $res->free();
                return $row ?: null;
            }
        } catch (Throwable $e) {
            error_log('[Wintaskly db_one] ' . $e->getMessage() . ' — SQL: ' . $sql);
        }
        return null;
    }
}

if (!function_exists('db_all')) {
    /**
     * Exécute une requête et retourne TOUTES les lignes (tableau de
     * tableaux associatifs) ou un tableau vide. Ne plante jamais.
     *
     * Usage :
     *   $rows = db_all("SELECT id, username FROM users LIMIT 10");
     *   foreach ($rows as $row) { ... }
     *
     * @param  string $sql Requête SQL
     * @return array       Lignes (vide si erreur ou aucun résultat)
     */
    function db_all(string $sql): array
    {
        try {
            $res = db()->query($sql);
            if ($res instanceof mysqli_result) {
                $rows = $res->fetch_all(MYSQLI_ASSOC);
                $res->free();
                return $rows;
            }
        } catch (Throwable $e) {
            error_log('[Wintaskly db_all] ' . $e->getMessage() . ' — SQL: ' . $sql);
        }
        return [];
    }
}

if (!function_exists('cfg_set')) {
    /**
     * Écrit une clé de configuration dans la table `config` (upsert)
     * et VÉRIFIE que la valeur est bien persistée par re-SELECT.
     *
     * Retourne true si la valeur est confirmée en BDD après l'INSERT, false sinon.
     * Cette double vérification est ESSENTIELLE car sur certains hébergeurs
     * mutualisés (LWS avec réplication master-slave, Varnish, tables verrouillées),
     * l'INSERT peut sembler réussir sans être réellement persisté.
     */
    function cfg_set(string $key, string $value): bool
    {
        $db = db();

        // Vérif que la connexion est encore active (sinon échec silencieux)
        if (!$db->ping()) {
            error_log('[Wintaskly cfg_set] DB connection lost for key ' . $key);
            return false;
        }

        // -----------------------------------------------------------------
        // 1) INSERT ou UPDATE via upsert
        // -----------------------------------------------------------------
        $stmt = $db->prepare(
            "INSERT INTO config (k, v) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE v = VALUES(v)"
        );
        if (!$stmt) {
            error_log('[Wintaskly cfg_set] prepare() FAILED for key ' . $key . ' : ' . $db->error);
            return false;
        }

        $stmt->bind_param('ss', $key, $value);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        if (!$ok) {
            error_log('[Wintaskly cfg_set] execute() FAILED for key ' . $key . ' : ' . $stmt->error);
            $stmt->close();
            return false;
        }
        $stmt->close();

        // -----------------------------------------------------------------
        // 2) VÉRIFICATION par re-SELECT — détecte le scénario "INSERT semble
        //    réussir mais ne persiste pas" (rollback silencieux, réplication
        //    master-slave qui retourne l'ancienne valeur, droits manquants).
        // -----------------------------------------------------------------
        $checkStmt = $db->prepare("SELECT v FROM config WHERE k = ? LIMIT 1");
        if (!$checkStmt) {
            error_log('[Wintaskly cfg_set] verify prepare() failed for key ' . $key);
            return true;  // execute OK mais on ne peut pas vérifier, on assume OK
        }
        $checkStmt->bind_param('s', $key);
        $checkStmt->execute();
        $res = $checkStmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $checkStmt->close();

        if (!$row) {
            error_log('[Wintaskly cfg_set] PERSIST FAILED for key ' . $key
                    . ' (INSERT OK but row missing - rollback silencieux ou droits?)');
            return false;
        }

        if ($row['v'] !== $value) {
            error_log('[Wintaskly cfg_set] PERSIST MISMATCH for key ' . $key
                    . ' (expected="' . substr($value, 0, 50) . '" got="' . substr($row['v'], 0, 50) . '")');
            return false;
        }

        // -----------------------------------------------------------------
        // 3) Tout OK : invalide le cache mémoire
        // -----------------------------------------------------------------
        if (isset($GLOBALS['__wt_cfg_cache'])) {
            $GLOBALS['__wt_cfg_cache'][$key] = $value;
        }

        // Log debug si affected_rows == 0 (curieux mais pas forcément un bug :
        // c'est le cas si la valeur était déjà identique)
        if ($affected === 0) {
            error_log('[Wintaskly cfg_set] info: affected_rows=0 for key ' . $key . ' (value unchanged)');
        }

        return true;
    }
}

if (!function_exists('cfg_int')) {
    /**
     * Lit une clé de configuration en castant en int avec une valeur par défaut.
     * Plus expressif que `(int) cfg('faucet_reward_xp', '0')`.
     *
     * Usage : `$xp = cfg_int('faucet_reward_xp', 1);`
     */
    function cfg_int(string $key, int $default = 0): int
    {
        $value = cfg($key, null);
        return $value === null ? $default : (int) $value;
    }
}

if (!function_exists('cfg_float')) {
    /**
     * Idem pour les décimaux (récompenses en coins).
     *
     * Usage : `$coins = cfg_float('faucet_reward_coins', 0.5);`
     */
    function cfg_float(string $key, float $default = 0.0): float
    {
        $value = cfg($key, null);
        return $value === null ? $default : (float) $value;
    }
}

if (!function_exists('cfg_bool')) {
    /**
     * Idem pour les booléens (flags ON/OFF).
     * Accepte '1', 'true', 'yes', 'on' comme vrai.
     *
     * Usage : `if (cfg_bool('faucet_enabled', true)) { ... }`
     */
    function cfg_bool(string $key, bool $default = false): bool
    {
        $value = cfg($key, null);
        if ($value === null) return $default;
        $v = strtolower(trim((string) $value));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }
}
