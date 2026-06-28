<?php
/**
 * Wintaskly — Chiffrement des secrets stockés en base
 * ----------------------------------------------------------------------
 * Chiffre/déchiffre les données sensibles (clés API de paiement) avant
 * stockage en base, avec AES-256-GCM (chiffrement authentifié).
 *
 * Défense en profondeur : si la base de données est compromise (fuite,
 * sauvegarde volée), les secrets restent illisibles sans la clé maître,
 * qui vit dans config.php (hors base, non versionné).
 *
 * RÉTROCOMPATIBILITÉ : wt_decrypt() détecte si une valeur est chiffrée
 * (préfixe "enc:v1:") ou en clair (ancien format). Les anciennes clés en
 * clair continuent de fonctionner et sont ré-chiffrées au prochain
 * enregistrement. Aucune migration de données obligatoire.
 * ----------------------------------------------------------------------
 */
declare(strict_types=1);

if (!function_exists('wt_encryption_key')) {
    /**
     * Retourne la clé maître de chiffrement (32 octets), dérivée par SHA-256.
     * Source : config 'encryption_key' si définie, sinon 'app_secret'.
     */
    function wt_encryption_key(): string
    {
        static $key = null;
        if ($key !== null) {
            return $key;
        }
        $cfg = $GLOBALS['WT_CONFIG'] ?? [];
        $raw = (string) ($cfg['encryption_key'] ?? '');
        if ($raw === '') {
            // Fallback : dérive depuis app_secret (toujours présent)
            $raw = (string) ($cfg['app_secret'] ?? 'wintaskly-default-insecure-key');
        }
        // SHA-256 → 32 octets binaires pour AES-256
        $key = hash('sha256', 'wt-enc:' . $raw, true);
        return $key;
    }
}

if (!function_exists('wt_encrypt')) {
    /**
     * Chiffre une chaîne en AES-256-GCM. Retourne une chaîne préfixée
     * "enc:v1:" + base64(iv | tag | ciphertext), ou la chaîne vide inchangée.
     *
     * @param  string $plain  Donnée en clair
     * @return string  Donnée chiffrée (ou '' si entrée vide)
     */
    function wt_encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $key = wt_encryption_key();
        $iv  = random_bytes(12); // 96 bits, recommandé pour GCM
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            error_log('[Wintaskly crypto] openssl_encrypt a échoué');
            return $plain; // on ne perd pas la donnée
        }
        return 'enc:v1:' . base64_encode($iv . $tag . $cipher);
    }
}

if (!function_exists('wt_decrypt')) {
    /**
     * Déchiffre une valeur produite par wt_encrypt().
     * RÉTROCOMPATIBLE : si la valeur n'a pas le préfixe "enc:v1:", elle est
     * considérée comme en clair (ancien format) et renvoyée telle quelle.
     *
     * @param  string $value  Donnée chiffrée ou en clair
     * @return string  Donnée en clair
     */
    function wt_decrypt(string $value): string
    {
        if ($value === '' || strncmp($value, 'enc:v1:', 7) !== 0) {
            return $value; // clair (ancien format) ou vide
        }
        $blob = base64_decode(substr($value, 7), true);
        if ($blob === false || strlen($blob) < 28) {
            error_log('[Wintaskly crypto] blob chiffré invalide');
            return '';
        }
        $iv     = substr($blob, 0, 12);
        $tag    = substr($blob, 12, 16);
        $cipher = substr($blob, 28);
        $key    = wt_encryption_key();
        $plain  = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            error_log('[Wintaskly crypto] openssl_decrypt a échoué (clé changée ?)');
            return '';
        }
        return $plain;
    }
}

if (!function_exists('wt_is_encrypted')) {
    /**
     * Indique si une valeur est déjà chiffrée (préfixe "enc:v1:").
     */
    function wt_is_encrypted(string $value): bool
    {
        return strncmp($value, 'enc:v1:', 7) === 0;
    }
}
