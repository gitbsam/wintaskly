<?php
/**
 * Wintaskly — Helpers d'authentification étendus.
 *
 * Inclus automatiquement par includes/init.php.
 *
 * Contient :
 *   - Rate-limit des tentatives de connexion (auth_attempts).
 *   - Génération / consommation de tokens (auth_tokens),
 *     pour la vérification d'e-mail, la réinitialisation de mot
 *     de passe, et le « remember-me ».
 *   - Vérification TOTP (RFC 6238) compatible Google Authenticator.
 *
 * Tous les tokens sont stockés en base sous forme de hash sha256 hex.
 * Le token brut n'existe que dans l'URL ou le cookie côté utilisateur.
 */
declare(strict_types=1);

// =====================================================================
// 1) RATE-LIMIT (anti-brute-force)
// =====================================================================

/**
 * Enregistre une tentative de connexion.
 */
function auth_attempt_record(string $identifier, ?string $ipBin, bool $success): void
{
    $stmt = db()->prepare(
        "INSERT INTO auth_attempts (identifier, ip, success)
         VALUES (?, ?, ?)"
    );
    $ok = $success ? 1 : 0;
    $stmt->bind_param('ssi', $identifier, $ipBin, $ok);
    $stmt->execute();
    $stmt->close();
}

/**
 * Indique si l'identifiant OU l'IP sont bloqués.
 * Renvoie [bool blocked, int seconds_left].
 */
function auth_attempt_blocked(string $identifier, ?string $ipBin): array
{
    $cfg     = $GLOBALS['WT_CONFIG']['auth'] ?? [];
    $maxAcc  = (int) ($cfg['max_attempts_per_account'] ?? 5);
    $maxIp   = (int) ($cfg['max_attempts_per_ip']      ?? 15);
    $lock    = (int) ($cfg['lockout_minutes']          ?? 15);

    $db = db();

    // Compte sur 15 minutes glissantes : nombre d'échecs depuis la dernière réussite.
    $sql = "SELECT COUNT(*) c, MAX(created_at) last_at
              FROM auth_attempts
             WHERE identifier = ?
               AND success = 0
               AND created_at >= UTC_TIMESTAMP() - INTERVAL ? MINUTE
               AND created_at > COALESCE(
                   (SELECT MAX(created_at) FROM auth_attempts a2
                     WHERE a2.identifier = ? AND a2.success = 1),
                   '1970-01-01')";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('sis', $identifier, $lock, $identifier);
    $stmt->execute();
    $accRow = $stmt->get_result()->fetch_assoc() ?: ['c' => 0, 'last_at' => null];
    $stmt->close();

    if ((int) $accRow['c'] >= $maxAcc) {
        $left = max(1, $lock * 60 - (time() - strtotime($accRow['last_at'] . ' UTC')));
        return [true, $left];
    }

    if ($ipBin !== null) {
        $stmt = $db->prepare(
            "SELECT COUNT(*) c, MAX(created_at) last_at
               FROM auth_attempts
              WHERE ip = ? AND success = 0
                AND created_at >= UTC_TIMESTAMP() - INTERVAL ? MINUTE"
        );
        $stmt->bind_param('si', $ipBin, $lock);
        $stmt->execute();
        $ipRow = $stmt->get_result()->fetch_assoc() ?: ['c' => 0, 'last_at' => null];
        $stmt->close();

        if ((int) $ipRow['c'] >= $maxIp) {
            $left = max(1, $lock * 60 - (time() - strtotime($ipRow['last_at'] . ' UTC')));
            return [true, $left];
        }
    }

    return [false, 0];
}

// =====================================================================
// 2) AUTH TOKENS — vérif e-mail, reset, remember-me
// =====================================================================

/**
 * Crée un token (selector + verifier) et stocke son hash en base.
 *
 * Retourne le token brut au format "<selector>.<verifier>" — à inclure
 * dans l'URL ou le cookie, jamais à stocker.
 *
 * @param 'verify_email'|'reset_password'|'remember_me' $purpose
 */
function auth_token_create(int $userId, string $purpose, int $ttlSeconds): string
{
    $selector = bin2hex(random_bytes(8));         // 16 chars
    $verifier = bin2hex(random_bytes(32));        // 64 chars
    $hash     = hash('sha256', $verifier);

    $ipBin    = wt_ip_bin();
    $ua       = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $stmt = db()->prepare(
        "INSERT INTO auth_tokens
           (user_id, purpose, selector, token_hash, expires_at, ip, user_agent)
         VALUES (?, ?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? SECOND, ?, ?)"
    );
    $stmt->bind_param(
        'isssiss',
        $userId, $purpose, $selector, $hash, $ttlSeconds, $ipBin, $ua
    );
    $stmt->execute();
    $stmt->close();

    return $selector . '.' . $verifier;
}

/**
 * Consomme un token. Retourne user_id si valide, ou null sinon.
 * Marque used_at en cas de succès (utilisable une seule fois).
 *
 * @param 'verify_email'|'reset_password'|'remember_me' $purpose
 */
function auth_token_consume(string $rawToken, string $purpose): ?int
{
    if (!str_contains($rawToken, '.')) {
        return null;
    }
    [$selector, $verifier] = explode('.', $rawToken, 2);
    if (strlen($selector) !== 16 || strlen($verifier) !== 64) {
        return null;
    }

    $db = db();
    $stmt = $db->prepare(
        "SELECT id, user_id, token_hash, expires_at, used_at
           FROM auth_tokens
          WHERE selector = ? AND purpose = ?
          LIMIT 1
          FOR UPDATE"
    );
    $db->begin_transaction();
    $stmt->bind_param('ss', $selector, $purpose);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $db->rollback();
        return null;
    }
    if (!hash_equals($row['token_hash'], hash('sha256', $verifier))) {
        $db->rollback();
        return null;
    }
    if ($row['used_at'] !== null) {
        $db->rollback();
        return null;
    }
    if (strtotime($row['expires_at'] . ' UTC') < time()) {
        $db->rollback();
        return null;
    }

    $upd = $db->prepare("UPDATE auth_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ?");
    $upd->bind_param('i', $row['id']);
    $upd->execute();
    $upd->close();

    $db->commit();
    return (int) $row['user_id'];
}

/**
 * Vérifie un token sans le consommer (utile pour valider l'arrivée
 * sur /auth/reset-password.php avant d'afficher le formulaire).
 */
function auth_token_peek(string $rawToken, string $purpose): ?int
{
    if (!str_contains($rawToken, '.')) return null;
    [$selector, $verifier] = explode('.', $rawToken, 2);
    if (strlen($selector) !== 16 || strlen($verifier) !== 64) return null;

    $stmt = db()->prepare(
        "SELECT user_id, token_hash, expires_at, used_at
           FROM auth_tokens
          WHERE selector = ? AND purpose = ?
          LIMIT 1"
    );
    $stmt->bind_param('ss', $selector, $purpose);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) return null;
    if (!hash_equals($row['token_hash'], hash('sha256', $verifier))) return null;
    if ($row['used_at'] !== null) return null;
    if (strtotime($row['expires_at'] . ' UTC') < time()) return null;
    return (int) $row['user_id'];
}

/**
 * Invalide tous les tokens d'un utilisateur pour un usage donné.
 * Utile pour révoquer une session "remember-me" à la déconnexion.
 */
function auth_tokens_revoke(int $userId, ?string $purpose = null): void
{
    if ($purpose === null) {
        $stmt = db()->prepare("DELETE FROM auth_tokens WHERE user_id = ?");
        $stmt->bind_param('i', $userId);
    } else {
        $stmt = db()->prepare("DELETE FROM auth_tokens WHERE user_id = ? AND purpose = ?");
        $stmt->bind_param('is', $userId, $purpose);
    }
    $stmt->execute();
    $stmt->close();
}

// =====================================================================
// 3) REMEMBER-ME — cookie persistant
// =====================================================================

/**
 * Pose le cookie « remember-me » signé pour cet utilisateur.
 */
function auth_remember_set(int $userId): void
{
    $ttl   = (int)($GLOBALS['WT_CONFIG']['auth']['remember_me_ttl'] ?? 60 * 24 * 3600);
    $raw   = auth_token_create($userId, 'remember_me', $ttl);

    setcookie('wt_remember', $raw, [
        'expires'  => time() + $ttl,
        'path'     => '/',
        'domain'   => $GLOBALS['WT_CONFIG']['cookie_domain'] ?? '',
        'secure'   => !empty($GLOBALS['WT_CONFIG']['cookie_secure']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Tente une connexion automatique depuis le cookie « remember-me ».
 * Appelée par init.php avant les bans / requêtes utilisateur.
 *
 * IMPORTANT : on consomme le token (one-shot) et on en émet un nouveau
 * pour limiter la fenêtre d'exploitation d'un cookie volé (token rotation).
 */
function auth_remember_check(): void
{
    if (!empty($_SESSION['uid'])) return;
    if (empty($_COOKIE['wt_remember'])) return;

    $uid = auth_token_consume((string) $_COOKIE['wt_remember'], 'remember_me');
    if (!$uid) {
        // Cookie invalide → on le nettoie
        auth_remember_clear();
        return;
    }

    // Vérifie le statut du compte avant de signer la session
    $stmt = db()->prepare("SELECT id, status FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || $row['status'] !== 'active') {
        auth_remember_clear();
        return;
    }

    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $row['id'];
    auth_remember_set((int) $row['id']); // rotation
}

/**
 * Efface le cookie remember-me et révoque les tokens actifs.
 */
function auth_remember_clear(?int $userId = null): void
{
    if (!empty($_COOKIE['wt_remember'])) {
        setcookie('wt_remember', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => $GLOBALS['WT_CONFIG']['cookie_domain'] ?? '',
            'secure'   => !empty($GLOBALS['WT_CONFIG']['cookie_secure']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE['wt_remember']);
    }
    if ($userId !== null) {
        auth_tokens_revoke($userId, 'remember_me');
    }
}

// =====================================================================
// 4) TOTP (RFC 6238) — Google Authenticator compatible
// =====================================================================

/**
 * Décode une clé secrète en Base32 (RFC 4648).
 */
function wt_base32_decode(string $secret): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret   = strtoupper(rtrim($secret, '='));
    $secret   = preg_replace('/[^A-Z2-7]/', '', $secret);
    if ($secret === '') return '';

    $binary = '';
    $buffer = 0;
    $bits   = 0;
    $len    = strlen($secret);
    for ($i = 0; $i < $len; $i++) {
        $idx = strpos($alphabet, $secret[$i]);
        if ($idx === false) continue;
        $buffer = ($buffer << 5) | $idx;
        $bits  += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $binary .= chr(($buffer >> $bits) & 0xff);
        }
    }
    return $binary;
}

/**
 * Génère un secret TOTP aléatoire encodé en base32 (compatible Google
 * Authenticator, Authy, etc.). Par défaut 32 caractères base32 (160 bits),
 * la longueur recommandée par la RFC 4226.
 *
 * @param  int $length  Nombre de caractères base32 (défaut 32)
 * @return string  Secret en base32 (A-Z, 2-7)
 */
function auth_totp_generate_secret(int $length = 32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret   = '';
    $max      = strlen($alphabet) - 1;
    for ($i = 0; $i < $length; $i++) {
        $secret .= $alphabet[random_int(0, $max)];
    }
    return $secret;
}

/**
 * Construit l'URI otpauth:// à encoder dans un QR code, pour que l'app
 * d'authentification (Google Authenticator...) enregistre le compte.
 *
 * Format : otpauth://totp/Issuer:label?secret=...&issuer=Issuer&...
 *
 * @param  string $secret  Secret base32
 * @param  string $label   Identifiant du compte (ex: email ou username)
 * @param  string $issuer  Nom du service (ex: "Wintaskly")
 * @return string  URI otpauth
 */
function auth_totp_provisioning_uri(string $secret, string $label, string $issuer = 'Wintaskly'): string
{
    $label  = rawurlencode($issuer) . ':' . rawurlencode($label);
    $params = http_build_query([
        'secret'    => $secret,
        'issuer'    => $issuer,
        'algorithm' => 'SHA1',
        'digits'    => 6,
        'period'    => 30,
    ]);
    return 'otpauth://totp/' . $label . '?' . $params;
}

/**
 * Vérifie un code TOTP à 6 chiffres pour un secret donné.
 * Accepte ±1 fenêtre de 30 s pour amortir la dérive d'horloge.
 */
function auth_totp_verify(string $secret, string $code, int $window = 1, int $period = 30): bool
{
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code)) return false;

    $key = wt_base32_decode($secret);
    if ($key === '') return false;

    $now = intdiv(time(), $period);

    for ($i = -$window; $i <= $window; $i++) {
        $counter = $now + $i;

        // Pack counter en big-endian 8 octets
        $bin = pack('N*', 0, $counter);

        $hash    = hash_hmac('sha1', $bin, $key, true);
        $offset  = ord($hash[19]) & 0x0f;
        $binCode = ((ord($hash[$offset])     & 0x7f) << 24)
                 | ((ord($hash[$offset + 1]) & 0xff) << 16)
                 | ((ord($hash[$offset + 2]) & 0xff) <<  8)
                 |  (ord($hash[$offset + 3]) & 0xff);
        $otp = str_pad((string)($binCode % 1000000), 6, '0', STR_PAD_LEFT);

        if (hash_equals($otp, $code)) {
            return true;
        }
    }
    return false;
}
