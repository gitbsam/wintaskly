-- ============================================================================
-- Wintaskly — Migration : option de masquage des pseudos au classement
-- ============================================================================
-- Ajoute leaderboard.mask_usernames (désactivé par défaut → pseudos complets,
-- comportement historique préservé). Activable via /admin/settings.php.
-- ============================================================================

INSERT IGNORE INTO `config` (`k`, `v`) VALUES
 ('leaderboard.mask_usernames', '0');
