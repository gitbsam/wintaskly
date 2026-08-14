-- ============================================================================
-- Wintaskly — Migration : zones publicitaires manquantes (sidebars & mid)
-- ============================================================================
-- Ces 5 emplacements sont appelés par wt_ad_zone() dans les templates mais
-- n'avaient jamais été créés en base : ils ne s'affichaient donc jamais,
-- silencieusement (wt_ad_zone() retourne '' pour une zone inconnue).
--   - blog_index_top        : blog/index.php (haut de la liste)
--   - blog_sidebar          : blog/index.php (colonne latérale)
--   - offerwall_sidebar     : tasks/offerwalls/index.php (colonne latérale)
--   - tasks_index_sidebar   : tasks/index.php (colonne latérale)
--   - tasks_index_mid       : tasks/index.php (après la grille de tâches)
--
-- size_key est renseigné dès la création pour activer la rotation
-- automatique des bannières maison : format rectangle pour les colonnes
-- latérales, bandeau large pour les emplacements pleine largeur.
-- INSERT IGNORE : idempotent, n'écrase jamais une zone déjà configurée.
-- ============================================================================
INSERT IGNORE INTO `ad_zones` (`k`,`label`,`code`,`size_key`,`active`) VALUES
 ('blog_index_top',      'Blog — Haut de la liste',          '<!-- Insérer ici le code AdSense responsive -->', '728x90',  1),
 ('blog_sidebar',        'Blog — Colonne latérale',          '<!-- Insérer ici le code AdSense responsive -->', '300x250', 1),
 ('offerwall_sidebar',   'Offerwalls — Colonne latérale',    '<!-- Insérer ici le code AdSense responsive -->', '300x250', 1),
 ('tasks_index_sidebar', 'Tâches — Colonne latérale',        '<!-- Insérer ici le code AdSense responsive -->', '300x250', 1),
 ('tasks_index_mid',     'Tâches — Après la grille',         '<!-- Insérer ici le code AdSense responsive -->', '728x90',  1);
