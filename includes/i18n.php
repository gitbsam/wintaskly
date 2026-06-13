<?php
/**
 * Wintaskly — i18n.
 * Détecte la langue (cookie > GET ?lang > Accept-Language > défaut),
 * la persiste en cookie et expose la fonction t().
 */

if (!function_exists('wt_detect_lang')) {
    function wt_detect_lang(array $allowed, string $default): string
    {
        // 1) Forçage par URL (?lang=xx)
        if (isset($_GET['lang'])) {
            $candidate = strtolower(substr((string)$_GET['lang'], 0, 5));
            if (in_array($candidate, $allowed, true)) {
                setcookie('wt_lang', $candidate, [
                    'expires'  => time() + 60 * 60 * 24 * 365,
                    'path'     => '/',
                    'secure'   => !empty($GLOBALS['WT_CONFIG']['cookie_secure']),
                    'httponly' => false,
                    'samesite' => 'Lax',
                ]);
                return $candidate;
            }
        }

        // 2) Cookie persistant
        if (!empty($_COOKIE['wt_lang'])) {
            $candidate = strtolower(substr((string)$_COOKIE['wt_lang'], 0, 5));
            if (in_array($candidate, $allowed, true)) {
                return $candidate;
            }
        }

        // 3) Accept-Language
        $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        if ($accept) {
            foreach (explode(',', $accept) as $part) {
                $code = strtolower(substr(trim(explode(';', $part)[0]), 0, 2));
                if (in_array($code, $allowed, true)) {
                    return $code;
                }
            }
        }

        return $default;
    }
}

if (!function_exists('wt_load_lang')) {
    function wt_load_lang(string $lang): array
    {
        $file = __DIR__ . '/lang/' . preg_replace('/[^a-z]/', '', $lang) . '.php';
        if (!is_file($file)) {
            $file = __DIR__ . '/lang/fr.php';
        }
        return require $file;
    }
}

if (!function_exists('t')) {
    function t(string $key, array $params = []): string
    {
        $dict = $GLOBALS['WT_LANG'] ?? [];
        $msg  = $dict[$key] ?? $key;
        foreach ($params as $k => $v) {
            $msg = str_replace('{' . $k . '}', (string)$v, $msg);
        }
        return $msg;
    }
}
