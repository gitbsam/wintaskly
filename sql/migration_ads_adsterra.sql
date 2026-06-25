-- ============================================================================
-- Wintaskly — Migration : configs publicitaires Adsterra
-- ============================================================================
-- Ajoute les réglages pour :
--   - Popunder (script global dans <head>)
--   - Social Bar (script global avant </body>)
--   - Bannières auto-responsive 728/468/300
--
-- INSERT IGNORE = sans danger si relancé (n'écrase pas les valeurs existantes).
-- À exécuter une fois sur une installation déjà en place.
-- ============================================================================

INSERT IGNORE INTO `config` (`k`, `v`) VALUES
 ('ads.head_enabled', '0'),
 ('ads.head_code',    ''),
 ('ads.body_enabled', '0'),
 ('ads.body_code',    ''),
 ('ads.banner_728',   ''),
 ('ads.banner_468',   ''),
 ('ads.banner_300',   '');

-- API Publisher Adsterra (dashboard de revenus dans /admin)
INSERT IGNORE INTO `config` (`k`, `v`) VALUES
 ('ads.adsterra_api_token', ''),
 ('ads.adsterra_domain_id', '');
