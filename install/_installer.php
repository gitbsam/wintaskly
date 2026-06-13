<?php
/**
 * Wintaskly — Cœur de l'installeur (sans état, fonctions pures).
 *
 * Toutes les opérations sensibles passent ici :
 *   - vérification des prérequis système
 *   - test de connexion BDD
 *   - création de la BDD (CREATE DATABASE si droits)
 *   - chargement schema.sql + migrations + seed.sql
 *   - création du compte super-admin
 *   - écriture de config.php + .installed.lock
 *
 * Aucune fonction ici n'accède à la session ou aux $_POST directement :
 * les step files passent toutes les valeurs en arguments. Ça permet de
 * tester chaque fonction isolément et facilite le débogage.
 *
 * IMPORTANT : ce fichier ne charge PAS includes/init.php — on est avant
 * que le système soit configuré. On utilise mysqli natif.
 */

declare(strict_types=1);

/* ====================================================================
 * 1) Vérifications système (étape 1 du wizard)
 * ==================================================================== */

/**
 * Vérifie les prérequis système nécessaires à Wintaskly.
 * Chaque entrée du retour : ['label', 'ok' => bool, 'detail', 'critical' => bool].
 * Si une seule entrée critique est 'ok=false', l'install ne peut pas continuer.
 */
function wt_install_check_requirements(): array
{
    $rootPath = dirname(__DIR__);

    $checks = [];

    // PHP 8.2+
    $phpOk = version_compare(PHP_VERSION, '8.2.0', '>=');
    $checks[] = [
        'label'    => 'PHP ≥ 8.2',
        'ok'       => $phpOk,
        'detail'   => 'Version actuelle : ' . PHP_VERSION,
        'critical' => true,
    ];

    // Extensions PHP
    foreach (['mysqli', 'mbstring', 'json', 'session'] as $ext) {
        $checks[] = [
            'label'    => 'Extension PHP ' . $ext,
            'ok'       => extension_loaded($ext),
            'detail'   => extension_loaded($ext) ? 'Disponible' : 'Manquante — installez le paquet PHP correspondant',
            'critical' => true,
        ];
    }

    // Extensions recommandées (non critiques)
    foreach (['curl', 'zlib', 'openssl'] as $ext) {
        $checks[] = [
            'label'    => 'Extension PHP ' . $ext . ' (recommandée)',
            'ok'       => extension_loaded($ext),
            'detail'   => extension_loaded($ext)
                          ? 'Disponible'
                          : 'Manquante — certaines fonctions (paiements auto, gzip, mailer TLS) seront indisponibles',
            'critical' => false,
        ];
    }

    // Permissions : la racine doit être writable pour pouvoir y créer config.php
    $rootWritable = is_writable($rootPath);
    $checks[] = [
        'label'    => 'Dossier racine accessible en écriture',
        'ok'       => $rootWritable,
        'detail'   => $rootWritable
                      ? 'OK (' . $rootPath . ')'
                      : 'CHMOD 755 ou 775 requis sur ' . $rootPath,
        'critical' => true,
    ];

    // config.php ne doit PAS déjà exister (sinon install déjà faite)
    $configExists = is_file($rootPath . '/config.php');
    $checks[] = [
        'label'    => 'config.php absent',
        'ok'       => !$configExists,
        'detail'   => $configExists
                      ? '⚠️ config.php existe déjà. Si vous voulez réinstaller, supprimez config.php ET .installed.lock manuellement.'
                      : 'Aucun config.php existant, prêt à installer',
        'critical' => true,
    ];

    return $checks;
}

/**
 * True si tous les checks critiques passent (pour activer le bouton "Suivant").
 */
function wt_install_requirements_pass(array $checks): bool
{
    foreach ($checks as $c) {
        if ($c['critical'] && !$c['ok']) return false;
    }
    return true;
}

/* ====================================================================
 * 2) Connexion BDD (étape 2 du wizard)
 * ==================================================================== */

/**
 * Teste la connexion BDD avec les credentials fournis.
 * Tente d'abord par socket Unix si fourni, sinon TCP host/port.
 *
 * @param array $db ['host','socket','name','user','pass','charset']
 * @return array ['ok' => bool, 'message' => string, 'mysqli' => ?mysqli, 'db_exists' => bool]
 */
function wt_install_test_db_connection(array $db): array
{
    @mysqli_report(MYSQLI_REPORT_OFF);  // pas d'exceptions, on gère manuellement

    $host    = $db['host'] ?? 'localhost';
    $socket  = !empty($db['socket']) ? $db['socket'] : null;
    $user    = $db['user'] ?? 'root';
    $pass    = $db['pass'] ?? '';
    $charset = $db['charset'] ?? 'utf8mb4';
    $name    = $db['name'] ?? '';

    // 1) Connexion sans choisir la BDD (pour pouvoir la créer si absente)
    $m = @new mysqli(
        $socket ? null : $host,
        $user,
        $pass,
        '',
        0,
        $socket ?: null
    );
    if ($m->connect_error) {
        return [
            'ok'        => false,
            'message'   => 'Connexion refusée : ' . $m->connect_error,
            'mysqli'    => null,
            'db_exists' => false,
        ];
    }
    $m->set_charset($charset);

    // 2) La BDD demandée existe-t-elle ?
    $dbExists = false;
    if ($name !== '') {
        $stmt = $m->prepare("SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $dbExists = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
    }

    return [
        'ok'        => true,
        'message'   => $dbExists
                       ? 'Connexion OK et BDD « ' . $name . ' » trouvée'
                       : 'Connexion OK, mais BDD « ' . $name . ' » introuvable (sera créée si vous en avez les droits)',
        'mysqli'    => $m,
        'db_exists' => $dbExists,
    ];
}

/**
 * Crée la BDD si absente. Si l'utilisateur n'a pas les droits CREATE,
 * retourne un message clair (LWS impose souvent de créer la BDD via cPanel).
 */
function wt_install_create_database(mysqli $m, string $dbName, string $charset = 'utf8mb4'): array
{
    $charsetSafe = preg_replace('/[^a-z0-9_]/i', '', $charset) ?: 'utf8mb4';
    $collate     = $charsetSafe === 'utf8mb4' ? 'utf8mb4_unicode_ci' : ($charsetSafe . '_general_ci');
    $nameSafe    = preg_replace('/[^a-z0-9_]/i', '', $dbName);

    if ($nameSafe === '') {
        return ['ok' => false, 'message' => 'Nom de BDD invalide'];
    }

    $sql = "CREATE DATABASE IF NOT EXISTS `{$nameSafe}` CHARACTER SET {$charsetSafe} COLLATE {$collate}";
    if (@$m->query($sql)) {
        return ['ok' => true, 'message' => 'BDD « ' . $nameSafe . ' » créée'];
    }

    return [
        'ok'      => false,
        'message' => 'Impossible de créer la BDD : ' . $m->error
                     . '. Créez-la manuellement via cPanel/phpMyAdmin puis revenez.',
    ];
}

/* ====================================================================
 * 3) Exécution des fichiers SQL (étape 5 du wizard)
 * ==================================================================== */

/**
 * Exécute un fichier SQL multi-statements.
 *
 * Parser robuste qui gère :
 *   - Statements séparés par `;` même sur la même ligne (ex: PREPARE/EXECUTE/DEALLOCATE)
 *   - Chaînes simples et doubles ('...' et "...") où les `;` sont ignorés
 *   - Échappements `\;` dans les chaînes
 *   - Commentaires `-- ...` (fin de ligne) et `/* ... *\/`
 *   - Lignes vides ignorées
 *
 * NE GÈRE PAS :
 *   - Procédures stockées avec DELIMITER (cas avancé, pas utilisé par schema.sql)
 *   - Triggers multi-statements complexes
 *
 * Retourne ['ok' => bool, 'executed' => int, 'errors' => array].
 */
function wt_install_run_sql_file(mysqli $m, string $filePath): array
{
    if (!is_file($filePath)) {
        return ['ok' => false, 'executed' => 0, 'errors' => ['Fichier introuvable : ' . $filePath]];
    }

    $sql = file_get_contents($filePath);
    if ($sql === false || $sql === '') {
        return ['ok' => false, 'executed' => 0, 'errors' => ['Fichier vide ou illisible : ' . $filePath]];
    }

    $statements = wt_install_split_sql($sql);

    $executed = 0;
    $errors   = [];
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;

        // Mode silencieux : on capture les exceptions mysqli (PHP 8.1+ peut les lever)
        try {
            if (@$m->query($stmt)) {
                $executed++;
            } else {
                $errors[] = $m->error . ' [SQL: ' . substr($stmt, 0, 100) . '...]';
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage() . ' [SQL: ' . substr($stmt, 0, 100) . '...]';
        }
    }

    return [
        'ok'       => empty($errors),
        'executed' => $executed,
        'errors'   => $errors,
    ];
}

/**
 * Découpe un script SQL en statements individuels.
 * Gère les chaînes (' et ") pour ne pas couper sur les `;` à l'intérieur.
 *
 * @return array<string>
 */
function wt_install_split_sql(string $sql): array
{
    // 1) Strip des commentaires de ligne (-- ... fin de ligne)
    $sql = preg_replace('/^\s*--[^\n]*$/m', '', $sql);
    // 2) Strip des commentaires block (/* ... */)
    $sql = preg_replace('!/\*.*?\*/!s', '', (string)$sql);

    $statements = [];
    $current    = '';
    $len        = strlen($sql);
    $inSingle   = false;  // dans une chaîne '...'
    $inDouble   = false;  // dans une chaîne "..."
    $inBacktick = false;  // dans un identifiant `...`

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $prev = $i > 0 ? $sql[$i - 1] : '';

        // Gestion des chaînes (ignore les ; à l'intérieur)
        if ($ch === "'" && !$inDouble && !$inBacktick && $prev !== '\\') {
            $inSingle = !$inSingle;
        } elseif ($ch === '"' && !$inSingle && !$inBacktick && $prev !== '\\') {
            $inDouble = !$inDouble;
        } elseif ($ch === '`' && !$inSingle && !$inDouble) {
            $inBacktick = !$inBacktick;
        }

        // Statement terminator : `;` hors de toute chaîne
        if ($ch === ';' && !$inSingle && !$inDouble && !$inBacktick) {
            $stmt = trim($current);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $current = '';
            continue;
        }

        $current .= $ch;
    }

    // Reste éventuel après le dernier `;`
    $tail = trim($current);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

/* ====================================================================
 * 4) Création du compte super-admin
 * ==================================================================== */

function wt_install_create_admin(mysqli $m, string $username, string $email, string $password): array
{
    // Validation basique
    if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) {
        return ['ok' => false, 'message' => 'Nom d\'utilisateur invalide (3-32 caractères, alphanumériques + underscore)'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Adresse email invalide'];
    }
    if (strlen($password) < 10) {
        return ['ok' => false, 'message' => 'Mot de passe trop court (10 caractères minimum)'];
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);

    // -----------------------------------------------------------------
    // IDEMPOTENCE : si un user avec ce username OU cet email existe déjà,
    // on regarde son rôle.
    //   - Si c'est déjà un 'admin' → on met juste à jour son password.
    //     C'est utile si l'install a été interrompue/relancée : pas besoin
    //     de drop la BDD à la main, on récupère propre.
    //   - Si c'est un user normal → erreur explicite (ne pas voler le
    //     compte d'un utilisateur existant).
    // -----------------------------------------------------------------
    $stmtCheck = $m->prepare(
        "SELECT id, username, email, role FROM users
         WHERE username = ? OR email = ? LIMIT 1"
    );
    if (!$stmtCheck) {
        return ['ok' => false, 'message' => 'Préparation SELECT impossible : ' . $m->error];
    }
    $stmtCheck->bind_param('ss', $username, $email);
    $stmtCheck->execute();
    $existing = $stmtCheck->get_result()->fetch_assoc();
    $stmtCheck->close();

    if ($existing) {
        // Conflit : un user existant correspond
        if ($existing['role'] !== 'admin') {
            return [
                'ok' => false,
                'message' => sprintf(
                    'Le nom « %s » ou l\'email « %s » est déjà utilisé par un compte non-admin (ID #%d). Choisissez un autre nom/email à l\'étape 4.',
                    $existing['username'],
                    $existing['email'],
                    $existing['id']
                ),
            ];
        }

        // C'est un admin existant : on met à jour le password (idempotence)
        $stmtUpdate = $m->prepare(
            "UPDATE users
                SET password_hash = ?, email = ?, username = ?, status = 'active'
              WHERE id = ?"
        );
        if (!$stmtUpdate) {
            return ['ok' => false, 'message' => 'Préparation UPDATE impossible : ' . $m->error];
        }
        $stmtUpdate->bind_param('sssi', $hash, $email, $username, $existing['id']);
        if (!$stmtUpdate->execute()) {
            $err = $stmtUpdate->error;
            $stmtUpdate->close();
            return ['ok' => false, 'message' => 'Mise à jour admin échouée : ' . $err];
        }
        $stmtUpdate->close();

        return [
            'ok'       => true,
            'message'  => sprintf('Compte admin existant mis à jour · ID #%d (password réinitialisé)', $existing['id']),
            'admin_id' => $existing['id'],
            'updated'  => true,
        ];
    }

    // -----------------------------------------------------------------
    // Aucun user existant → INSERT classique
    // -----------------------------------------------------------------
    $refCode = strtoupper(substr(sha1($username . microtime(true)), 0, 8));

    $stmt = $m->prepare(
        "INSERT INTO users
            (username, email, password_hash, role, status,
             coins, xp, level, referral_code, email_verified_at)
         VALUES (?, ?, ?, 'admin', 'active', 0, 0, 1, ?, UTC_TIMESTAMP())"
    );
    if (!$stmt) {
        return ['ok' => false, 'message' => 'Préparation INSERT impossible : ' . $m->error];
    }
    $stmt->bind_param('ssss', $username, $email, $hash, $refCode);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        // Race condition rare (création par autre process entre SELECT et INSERT)
        if (str_contains($err, 'Duplicate entry')) {
            return [
                'ok' => false,
                'message' => 'Conflit pendant l\'insertion (rafraîchissez et réessayez)',
            ];
        }
        return ['ok' => false, 'message' => 'Création admin échouée : ' . $err];
    }
    $adminId = $stmt->insert_id;
    $stmt->close();

    return [
        'ok'       => true,
        'message'  => 'Compte admin créé · ID #' . $adminId,
        'admin_id' => $adminId,
    ];
}

/* ====================================================================
 * 5) Génération de config.php
 * ==================================================================== */

/**
 * Génère le fichier config.php à la racine avec les paramètres fournis.
 * Utilise var_export pour produire du code PHP valide et lisible.
 *
 * Robustesse :
 *   - Test d'écriture préalable (au cas où le dossier devient read-only entre check et write)
 *   - Fallback : si rename atomique échoue (certains hébergeurs FTP),
 *     on tente une écriture directe.
 *   - var_export protégé contre les objets/closures (qui plantent var_export).
 */
function wt_install_write_config(array $config): array
{
    $rootPath = dirname(__DIR__);
    $target   = $rootPath . '/config.php';

    // Refus d'écraser un config existant
    if (is_file($target)) {
        return ['ok' => false, 'message' => 'config.php existe déjà — refus d\'écraser'];
    }

    // Vérif écriture possible
    if (!is_writable($rootPath)) {
        return [
            'ok'      => false,
            'message' => 'Dossier racine non-writable : ' . $rootPath . '. CHMOD 755 ou 775 requis.',
        ];
    }

    // Génère un app_secret aléatoire si pas fourni
    if (empty($config['app_secret'])) {
        try {
            $config['app_secret'] = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            // Fallback faible si random_bytes indisponible (très rare)
            $config['app_secret'] = bin2hex(hex2bin(md5(uniqid('', true) . microtime(true))));
        }
    }

    // Nettoie les valeurs (sécurité var_export : pas d'objets/closures, juste scalaires/arrays)
    $cleanConfig = wt_install_sanitize_config_recursive($config);

    $php = "<?php\n"
         . "/**\n"
         . " * Wintaskly — Configuration générée automatiquement par l'installeur.\n"
         . " * Date : " . gmdate('Y-m-d H:i:s') . " UTC\n"
         . " *\n"
         . " * NE PARTAGEZ JAMAIS CE FICHIER ! Il contient les credentials BDD\n"
         . " * et le secret applicatif. Le fichier .htaccess racine le protège\n"
         . " * déjà des accès HTTP directs.\n"
         . " */\n\n"
         . "return " . var_export($cleanConfig, true) . ";\n";

    // Tentative 1 : écriture atomique via tmp + rename
    $tmp = $target . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $php, LOCK_EX) !== false) {
        if (@rename($tmp, $target)) {
            @chmod($target, 0644);
            return ['ok' => true, 'message' => 'config.php écrit avec succès'];
        }
        @unlink($tmp);
    }

    // Fallback : écriture directe (LWS et autres hébergeurs FTP peuvent
    // refuser le rename si le fichier dest existe pas et que les droits
    // sont restrictifs)
    if (@file_put_contents($target, $php, LOCK_EX) !== false) {
        @chmod($target, 0644);
        return ['ok' => true, 'message' => 'config.php écrit (mode fallback)'];
    }

    return [
        'ok'      => false,
        'message' => 'Écriture impossible — vérifiez les permissions sur ' . $rootPath
                     . ' (chmod 755 ou 775 requis)',
    ];
}

/**
 * Nettoie récursivement un tableau de config : seuls scalaires + arrays autorisés.
 * Évite les erreurs var_export sur les objets/closures.
 */
function wt_install_sanitize_config_recursive(array $config): array
{
    $clean = [];
    foreach ($config as $k => $v) {
        if (is_array($v)) {
            $clean[$k] = wt_install_sanitize_config_recursive($v);
        } elseif (is_scalar($v) || is_null($v)) {
            $clean[$k] = $v;
        }
        // Tout le reste est ignoré (object, resource, closure)
    }
    return $clean;
}

/* ====================================================================
 * 6) Création du fichier .installed.lock
 * ==================================================================== */

function wt_install_create_lock(): array
{
    $rootPath = dirname(__DIR__);
    $target   = $rootPath . '/.installed.lock';

    $content = "Installed at: " . gmdate('Y-m-d H:i:s') . " UTC\n"
             . "Version: V8\n"
             . "\n"
             . "Ce fichier marque l'installation comme terminée.\n"
             . "Pour réinstaller : supprimez ce fichier ET config.php manuellement.\n";

    if (file_put_contents($target, $content, LOCK_EX) === false) {
        return ['ok' => false, 'message' => 'Impossible d\'écrire .installed.lock'];
    }

    @chmod($target, 0644);

    return ['ok' => true, 'message' => '.installed.lock créé'];
}

/* ====================================================================
 * 7) Détection auto de l'URL de base (pour pré-remplir l'étape 3)
 * ==================================================================== */

function wt_install_detect_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // On est dans /install/ ou /install.php → on remonte d'un cran
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $base   = str_replace('\\', '/', dirname($script));
    // Si on est dans /install/, on remonte d'un dossier
    if (preg_match('#/install/?$#', $base) || preg_match('#/install$#', $base)) {
        $base = dirname($base);
    }
    $base = rtrim($base, '/');

    return $scheme . '://' . $host . $base;
}

/* ====================================================================
 * 8) Génération du token cron pour cPanel
 * ==================================================================== */

function wt_install_generate_cron_token(): string
{
    return bin2hex(random_bytes(16));
}
