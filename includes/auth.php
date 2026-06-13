<?php
/**
 * Wintaskly — Authentification & sécurité utilisateur.
 */

if (!function_exists('current_user')) {
    function current_user(): ?array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached ?: null;
        }
        if (empty($_SESSION['uid'])) {
            $cached = false;
            return null;
        }

        // On SELECT toutes les colonnes utiles aux différentes pages
        // (account, settings, dashboard, etc.) pour éviter les bugs où
        // `$u['xxx']` renvoie null parce que la colonne n'est pas dans le
        // SELECT.
        //
        // Coût négligeable : on ne récupère qu'une seule ligne indexée par
        // PK. Les colonnes sensibles (password_hash, totp_secret) restent
        // exclues — elles ne doivent jamais transiter par current_user()
        // qui peut être appelé dans des contextes de log/debug.
        $stmt = db()->prepare(
            "SELECT id, username, email, coins, xp, level,
                    referrer_id, referral_code,
                    lang, theme, avatar_url, timezone,
                    role, status, created_at,
                    email_verified_at,
                    totp_enabled,
                    tfa_email_enabled, tfa_sms_enabled,
                    phone_e164,
                    bio, country,
                    delete_requested_at,
                    last_login_at
             FROM users WHERE id = ? LIMIT 1"
        );
        $uid = (int)$_SESSION['uid'];
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$res || $res['status'] !== 'active') {
            unset($_SESSION['uid']);
            $cached = false;
            return null;
        }

        $cached = $res;
        return $res;
    }
}

if (!function_exists('require_auth')) {
    function require_auth(): array
    {
        $u = current_user();
        if (!$u) {
            header('Location: ' . wt_url('/auth/login.php'));
            exit;
        }
        return $u;
    }
}

if (!function_exists('require_admin')) {
    function require_admin(): array
    {
        $u = require_auth();
        if (($u['role'] ?? 'user') !== 'admin') {
            http_response_code(403);
            exit('Accès refusé.');
        }
        return $u;
    }
}

/**
 * Variante générique : exige un rôle précis (ex. 'admin').
 * Utile pour les futurs rôles ('moderator', 'support'...).
 */
if (!function_exists('require_role')) {
    function require_role(string $role): array
    {
        $u = require_auth();
        if (($u['role'] ?? 'user') !== $role) {
            http_response_code(403);
            exit('Accès refusé.');
        }
        return $u;
    }
}

/**
 * CSRF
 */
if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }
}

if (!function_exists('csrf_check')) {
    function csrf_check(?string $token): bool
    {
        return !empty($_SESSION['csrf'])
            && is_string($token)
            && hash_equals($_SESSION['csrf'], $token);
    }
}

if (!function_exists('wt_url')) {
    function wt_url(string $path = ''): string
    {
        $base = rtrim($GLOBALS['WT_CONFIG']['base_url'] ?? '', '/');
        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('wt_ip_bin')) {
    function wt_ip_bin(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $bin = @inet_pton($ip);
        return $bin !== false ? $bin : null;
    }
}

if (!function_exists('e')) {
    function e($s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * Helpers UTF-8 résilients à l'absence de mbstring (rare mais possible).
 * Utiliser systématiquement à la place de mb_strlen/mb_substr dans le code
 * applicatif. Pour les chaînes d'utilisateurs, on compte en code points
 * UTF-8 si mbstring est disponible, sinon en octets (fallback raisonnable).
 */
if (!function_exists('wt_strlen')) {
    function wt_strlen(string $s): int
    {
        return function_exists('mb_strlen') ? (int) mb_strlen($s, 'UTF-8') : strlen($s);
    }
}
if (!function_exists('wt_substr')) {
    function wt_substr(string $s, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return $length === null
                ? (string) mb_substr($s, $start, null, 'UTF-8')
                : (string) mb_substr($s, $start, $length, 'UTF-8');
        }
        return $length === null ? (string) substr($s, $start) : (string) substr($s, $start, $length);
    }
}
