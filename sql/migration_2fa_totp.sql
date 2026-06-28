-- ============================================================================
-- Wintaskly — Migration : disponibilité de la 2FA par application (TOTP)
-- ============================================================================
-- Ajoute le flag de config tfa.totp_available (activé par défaut).
-- Les colonnes totp_secret / totp_enabled existent déjà dans la table users
-- (schéma initial), seule cette config de disponibilité manquait.
-- ============================================================================

INSERT IGNORE INTO `config` (`k`, `v`) VALUES
 ('tfa.totp_available', '1');
