-- ============================================================================
-- Wintaskly — Régies publicitaires gérées en base
-- ============================================================================
-- LE PROBLÈME
-- -----------
-- Les domaines autorisés par la politique de sécurité de contenu (CSP)
-- étaient écrits en dur dans includes/init.php, dans une variable $_cspAds.
--
-- Conséquence : ajouter une régie imposait de modifier le code, de le
-- redéployer, et de ne rien oublier. Et surtout, en cas d'oubli, le
-- navigateur BLOQUE SILENCIEUSEMENT les scripts de la régie. Aucune erreur
-- visible sur la page — juste des emplacements vides et zéro revenu, sans
-- que rien n'indique pourquoi.
--
-- Cette table sort les régies du code. Ajouter un partenaire devient une
-- ligne en administration.
--
-- POURQUOI DEUX CHAMPS DE DOMAINES
--   • script_domains : d'où les scripts peuvent être chargés (script-src)
--   • connect_domains : vers où ils peuvent ouvrir des connexions
--     (connect-src). Beaucoup de régies mesurent l'affichage par une
--     requête en arrière-plan : sans cette autorisation, les scripts se
--     chargent mais les impressions ne sont jamais comptées — donc pas
--     payées.
--
-- Laisser connect_domains vide reprend automatiquement script_domains,
-- ce qui couvre la majorité des cas.
--
-- ⚠️ Importer avec --default-character-set=utf8mb4.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `ad_networks` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `k`               VARCHAR(40)  NOT NULL COMMENT 'Identifiant court : adsterra, propellerads…',
  `name`            VARCHAR(120) NOT NULL,
  `site_url`        VARCHAR(255) NULL COMMENT 'Espace éditeur de la régie',
  `script_domains`  TEXT         NULL COMMENT 'Domaines autorisés pour script-src, séparés par espace ou virgule',
  `connect_domains` TEXT         NULL COMMENT 'Domaines pour connect-src. Vide = identique à script_domains',
  `frame_domains`   TEXT         NULL COMMENT 'Domaines pour frame-src, si la régie utilise des iframes',
  `notes`           TEXT         NULL,
  `active`          TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`      SMALLINT     NOT NULL DEFAULT 100,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_k` (`k`),
  KEY `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Régies pré-remplies
-- ---------------------------------------------------------------------
-- Google AdSense n'est PAS inclus : la candidature a été refusée, et
-- conserver ses domaines dans la politique de sécurité reviendrait à
-- élargir la surface d'attaque pour un service inutilisé.
--
-- Les régies ci-dessous acceptent généralement les sites de micro-tâches,
-- contrairement à AdSense. Elles sont créées INACTIVES : activez celle chez
-- qui vous avez réellement un compte. Une régie active dont vous n'utilisez
-- pas les scripts n'apporte rien et affaiblit la politique de sécurité.
INSERT IGNORE INTO `ad_networks`
  (`k`, `name`, `site_url`, `script_domains`, `connect_domains`, `frame_domains`, `active`, `sort_order`, `notes`)
VALUES
  ('adsterra', 'Adsterra', 'https://adsterra.com',
   'https://*.adsterranet.com https://*.adsterratools.com https://*.highperformanceformat.com https://*.profitableratecpm.com',
   '', 'https://*.adsterranet.com https://*.highperformanceformat.com',
   0, 10, 'Accepte largement les sites de micro-tâches. Formats bannière, popunder, social bar.'),

  ('propellerads', 'PropellerAds', 'https://propellerads.com',
   'https://*.propellerads.com https://*.propellerpush.com https://*.propu.sh',
   '', 'https://*.propellerads.com',
   0, 20, 'Push et interstitiel. Valider le format avant activation : certains sont intrusifs.'),

  ('hilltopads', 'HilltopAds', 'https://hilltopads.com',
   'https://*.hilltopads.net https://*.hilltopads.com',
   '', 'https://*.hilltopads.net',
   0, 30, 'Bannières et vidéo.'),

  ('adcash', 'Adcash', 'https://adcash.com',
   'https://*.adcash.com https://*.adcashmachine.com',
   '', 'https://*.adcash.com',
   0, 40, 'Bannières et interstitiels, couverture internationale.'),

  ('monetag', 'Monetag', 'https://monetag.com',
   'https://*.monetag.com https://*.wpuhrbjq.com',
   '', 'https://*.monetag.com',
   0, 50, 'Anciennement PropellerAds Publisher.');

-- ---------------------------------------------------------------------
-- Nettoyage AdSense
-- ---------------------------------------------------------------------
-- Les réglages restent en base mais sont vidés : les conserver renseignés
-- ferait charger des scripts Google inutiles sur chaque page, au détriment
-- du temps d'affichage et de la vie privée des visiteurs.
UPDATE `config` SET `v` = '' WHERE `k` = 'tracking.google_adsense_client';
UPDATE `config` SET `v` = '0' WHERE `k` = 'tracking.adsense_auto_ads';
-- Second jeu de reglages, utilise par admin/ads.php et wt_adsense_head().
-- Les deux doivent etre vides : il suffit que l'un porte un identifiant
-- pour que le script Google soit injecte dans la page.
UPDATE `config` SET `v` = '' WHERE `k` = 'ads.adsense_client';
UPDATE `config` SET `v` = '0' WHERE `k` = 'ads.adsense_auto';
