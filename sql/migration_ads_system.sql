-- ============================================================================
-- Wintaskly — Migration : Système publicitaire complet
-- ============================================================================
-- Ajoute :
--   - 3 nouvelles zones publicitaires (dashboard, tâches, accueil)
--   - 2 configs AdSense Auto Ads (client + activation)
--
-- À exécuter UNE FOIS dans phpMyAdmin si tu mets à jour une install
-- existante. Sur une installation neuve, schema.sql contient déjà tout.
--
-- Idempotent : INSERT IGNORE ne crée pas de doublons.
-- ============================================================================

-- Nouvelles zones publicitaires
INSERT IGNORE INTO `ad_zones` (`k`, `label`, `code`, `active`) VALUES
 ('dashboard_top',   'Dashboard — Bandeau haut', '<!-- Insérer ici le code AdSense responsive -->', 1),
 ('tasks_index_top', 'Tâches — Bandeau haut',    '<!-- Insérer ici le code AdSense responsive -->', 1),
 ('home_hero_bottom','Accueil — Sous le hero',   '<!-- Insérer ici le code AdSense responsive -->', 1);

-- Configuration AdSense Auto Ads
INSERT IGNORE INTO `config` (`k`, `v`) VALUES
 ('ads.adsense_client', ''),
 ('ads.adsense_auto',   '0');

-- Enregistre cette migration
INSERT IGNORE INTO `applied_migrations` (`filename`, `version`, `notes`) VALUES
 ('migration_ads_system.sql', '8.8.0', 'Zones pub supplémentaires + AdSense Auto Ads');
