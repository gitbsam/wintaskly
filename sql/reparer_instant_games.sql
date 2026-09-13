-- =====================================================================
-- Wintaskly — reparation de instant_games
--
-- A executer si Instant Gagnant ne fonctionne pas et que la table
-- instant_games porte encore la colonne `win_permille`.
--
-- CONTEXTE. La version 9.66.0 livrait une migration perimee : elle
-- creait instant_games avec `win_permille`, vestige d'une conception
-- abandonnee au profit du compteur partage. CREATE TABLE IF NOT EXISTS
-- ne corrige rien sur une table deja creee : il l'ignore en silence.
--
-- POURQUOI CETTE VERSION. La precedente interrogeait information_schema
-- pour verifier l'etat avant d'agir. Sur un hebergement mutualise, cet
-- acces est refuse a l'utilisateur de la base :
--    #1044 - Acces refuse pour l'utilisateur ... Base 'information_schema'
-- On utilise donc IF NOT EXISTS / IF EXISTS, que MariaDB comprend
-- directement sur ALTER TABLE. Aucun privilege particulier n'est requis,
-- et le fichier reste rejouable sans erreur.
--
-- SAUVEGARDEZ LA BASE AVANT. Un ALTER ne s'annule pas.
-- =====================================================================

-- 1) Colonnes du compteur partage.
ALTER TABLE `instant_games`
  ADD COLUMN IF NOT EXISTS `parts_total` SMALLINT UNSIGNED NOT NULL DEFAULT 7,
  ADD COLUMN IF NOT EXISTS `parts_left`  SMALLINT UNSIGNED NOT NULL DEFAULT 7,
  ADD COLUMN IF NOT EXISTS `plays_total` INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `wins_total`  INT UNSIGNED NOT NULL DEFAULT 0;

-- 2) Ancienne colonne, devenue sans objet.
ALTER TABLE `instant_games` DROP COLUMN IF EXISTS `win_permille`;

-- 3) Trace du compteur sur chaque partie jouee, pour retracer un litige.
ALTER TABLE `instant_plays`
  ADD COLUMN IF NOT EXISTS `parts_left` SMALLINT UNSIGNED NOT NULL DEFAULT 0;

-- 4) Coherence : un compteur restant superieur a son total, ou nul,
--    ferait gagner le prochain joueur presque aussitot.
UPDATE `instant_games` SET `parts_left` = `parts_total`
 WHERE `parts_left` > `parts_total` OR `parts_left` = 0;

-- 5) Verification. La liste doit contenir parts_total, parts_left,
--    plays_total et wins_total — et AUCUN win_permille.
SHOW COLUMNS FROM `instant_games`;
