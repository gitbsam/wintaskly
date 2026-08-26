-- ============================================================================
-- Wintaskly — Emplacements publicitaires de la page de campagne
-- ============================================================================
-- La page /campagn dépasse 1 500 mots et reçoit du trafic acheté. Elle doit
-- donc rapporter par elle-même, et pas seulement convertir : une part des
-- visiteurs ne s'inscrira jamais, et l'affichage publicitaire est le seul
-- revenu qu'ils génèrent.
--
-- QUATRE EMPLACEMENTS, ET POURQUOI CEUX-LÀ
--   • campaign_top    (728×90)  — après la première section, une fois que le
--     lecteur est entré dans le contenu. Placer une bannière avant le premier
--     paragraphe ferait fuir avant même d'avoir expliqué quoi que ce soit.
--   • campaign_mid    (728×90)  — au milieu, après le premier appel à
--     l'action, là où l'attention retombe naturellement.
--   • campaign_bottom (728×90)  — en fin de page, sur les lecteurs qui sont
--     allés au bout : les plus engagés, donc les plus rentables.
--   • campaign_side   (300×250) — colonne latérale, visible pendant tout le
--     défilement sur grand écran.
--
-- Les zones sont créées VIDES. Un emplacement sans code ne rend aucun cadre :
-- la page reste propre tant que vous n'avez rien collé, plutôt que d'afficher
-- des blocs vides qui donneraient une impression d'inachevé.
--
-- ⚠️ Importer avec --default-character-set=utf8mb4.
-- ============================================================================

INSERT IGNORE INTO `ad_zones` (`k`, `label`, `code`, `size_key`, `active`) VALUES
  ('campaign_top',    'Campagne — haut de page',     '', '728x90',  1),
  ('campaign_mid',    'Campagne — milieu de page',   '', '728x90',  1),
  ('campaign_bottom', 'Campagne — bas de page',      '', '728x90',  1),
  ('campaign_side',   'Campagne — colonne latérale', '', '300x250', 1);
