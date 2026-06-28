-- ============================================================================
-- Wintaskly — Migration : agrandir les colonnes de secrets pour le chiffrement
-- ============================================================================
-- Les secrets chiffrés (AES-256-GCM + préfixe enc:v1: + base64) sont PLUS
-- LONGS que les secrets en clair. Sans cet agrandissement, le chiffrement
-- TRONQUERAIT les données (secret irrécupérable).
--
-- Tailles observées :
--   callback_key   : 32 chars clair → ~87 chars chiffré  (était VARCHAR(64) ❌)
--   callback_secret : 48 chars clair → ~111 chars chiffré (était VARCHAR(120) ⚠️)
--
-- On passe tout en VARCHAR(255) (marge confortable, comme api_token).
-- À EXÉCUTER AVANT le script de re-chiffrement en masse.
-- ============================================================================

ALTER TABLE `shortlinks`
    MODIFY `callback_key` VARCHAR(255) NULL;

ALTER TABLE `offerwalls`
    MODIFY `callback_secret` VARCHAR(255) NULL;

-- api_token (shortlinks) est déjà en VARCHAR(255) — pas besoin de le modifier.
-- api_credentials (withdrawal_methods) est en TEXT — illimité, pas besoin.
