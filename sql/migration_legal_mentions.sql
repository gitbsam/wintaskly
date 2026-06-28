-- ============================================================================
-- Wintaskly — Migration : mentions légales éditables
-- ============================================================================
-- Ajoute les clés de config pour la page /legal/mentions.php.
-- À remplir ensuite via /admin/settings.php → onglet « Légal ».
-- Les infos hébergeur sont pré-remplies avec LWS (modifiable).
-- ============================================================================

INSERT IGNORE INTO `config` (`k`, `v`) VALUES
 ('legal.editor_name', ''),
 ('legal.editor_status', ''),
 ('legal.editor_address', ''),
 ('legal.editor_email', ''),
 ('legal.editor_siret', ''),
 ('legal.publication_director', ''),
 ('legal.host_name', 'LWS'),
 ('legal.host_address', '10 rue Penthièvre, 75008 Paris'),
 ('legal.host_contact', 'https://www.lws.fr');
