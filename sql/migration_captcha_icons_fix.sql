-- ============================================================================
-- Wintaskly — Migration : fix couleur des icônes captcha
-- ============================================================================
-- Les SVG en BDD (table `captcha_icons`) n'avaient pas d'attribut `fill`
-- explicite → le navigateur appliquait son fill par défaut = noir.
-- Conséquence : icônes invisibles en mode dark, et toutes noires en
-- mode light (pas distinguées par la couleur du thème).
--
-- Cette migration met à jour les 8 icônes pour utiliser `fill="currentColor"`
-- (et `stroke="currentColor"` pour le soleil qui utilise des lignes), ce qui
-- les fait s'adapter automatiquement à la couleur du texte (light/dark).
--
-- À exécuter UNE SEULE FOIS via phpMyAdmin → SQL.
-- ============================================================================

UPDATE `captcha_icons` SET `svg` = '<svg viewBox="0 0 24 24" fill="currentColor"><polygon points="12,2 15,9 22,9 17,14 19,21 12,17 5,21 7,14 2,9 9,9"/></svg>' WHERE `slug` = 'star';

UPDATE `captcha_icons` SET `svg` = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 21s-7-4.5-9-9c-1-3 1-6 4-6 2 0 3 1 5 3 2-2 3-3 5-3 3 0 5 3 4 6-2 4.5-9 9-9 9z"/></svg>' WHERE `slug` = 'heart';

UPDATE `captcha_icons` SET `svg` = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 18a4 4 0 0 1 0-8 5 5 0 0 1 10 0 4 4 0 0 1 0 8z"/></svg>' WHERE `slug` = 'cloud';

-- Soleil : a un cercle (fill) ET des lignes (stroke) - les deux en currentColor
UPDATE `captcha_icons` SET `svg` = '<svg viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4" stroke="none"/><line x1="12" y1="2" x2="12" y2="5" fill="none"/><line x1="12" y1="19" x2="12" y2="22" fill="none"/><line x1="2" y1="12" x2="5" y2="12" fill="none"/><line x1="19" y1="12" x2="22" y2="12" fill="none"/><line x1="5" y1="5" x2="7" y2="7" fill="none"/><line x1="17" y1="17" x2="19" y2="19" fill="none"/><line x1="5" y1="19" x2="7" y2="17" fill="none"/><line x1="17" y1="7" x2="19" y2="5" fill="none"/></svg>' WHERE `slug` = 'sun';

UPDATE `captcha_icons` SET `svg` = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 14a8 8 0 0 1-10-10 8 8 0 1 0 10 10z"/></svg>' WHERE `slug` = 'moon';

UPDATE `captcha_icons` SET `svg` = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2c0 5-5 5-5 11a5 5 0 0 0 10 0c0-3-2-4-3-6 1 2-2 4-2 4z"/></svg>' WHERE `slug` = 'fire';

UPDATE `captcha_icons` SET `svg` = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2c-3 5-7 8-7 13a7 7 0 0 0 14 0c0-5-4-8-7-13z"/></svg>' WHERE `slug` = 'drop';

UPDATE `captcha_icons` SET `svg` = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 4c-8 0-14 6-14 14 0 0 1-1 2-1 6 0 12-6 12-13z"/></svg>' WHERE `slug` = 'leaf';

-- ============================================================================
-- Optionnel : ajouter quelques icônes supplémentaires si elles manquent
-- (faut vérifier qu'on a bien les slugs cités : eclair, coeur, cercle...)
-- ============================================================================
INSERT IGNORE INTO `captcha_icons` (`name`, `slug`, `svg`, `active`) VALUES
('Éclair', 'flash', '<svg viewBox="0 0 24 24" fill="currentColor"><polygon points="13,2 4,14 11,14 9,22 20,10 13,10"/></svg>', 1),
('Cercle', 'circle', '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="9"/></svg>', 1);
