-- ============================================================================
-- Wintaskly — Migration : Système de mises à jour
-- ============================================================================
-- Crée 2 tables pour gérer les updates :
--
-- 1) `update_checks` : journal des vérifications faites par le cron
--    (utile pour debug : pourquoi le check a échoué, quand le dernier OK).
--
-- 2) `applied_migrations` : registre des migrations SQL déjà jouées.
--    Permet aux futures updates de savoir quoi exécuter en plus, et
--    d'éviter de re-jouer une migration qui a déjà tourné.
--
-- Configs ajoutées dans `config` :
--   - update.feed_url        : URL latest.json à interroger
--   - update.last_check_at   : timestamp dernier check (auto)
--   - update.latest_version  : version dispo (auto)
--   - update.latest_data     : JSON brut latest.json (auto)
--   - update.maintenance_on  : 1/0 mode maintenance actif
--   - update.maintenance_msg : message custom pendant maintenance
--   - update.user_banner_on  : 1/0 bannière annonce aux users
--   - update.user_banner_msg : texte de la bannière user
--   - update.user_banner_until : date d'expiration auto de la bannière
--
-- À exécuter UNE SEULE FOIS via phpMyAdmin → SQL.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `update_checks` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `checked_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status`        ENUM('ok','network_error','parse_error','disabled') NOT NULL,
  `current_ver`   VARCHAR(20)  NOT NULL,
  `latest_ver`    VARCHAR(20)  NULL,
  `error_message` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_checked_at` (`checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `applied_migrations` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `filename`   VARCHAR(120) NOT NULL,
  `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `applied_by` VARCHAR(120) NULL COMMENT 'Username admin ayant déclenché',
  `version`    VARCHAR(20)  NULL COMMENT 'Version Wintaskly cible',
  `notes`      TEXT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Enregistre les migrations déjà appliquées historiquement
-- (à n'insérer que si elles ne sont pas déjà présentes)
INSERT IGNORE INTO `applied_migrations` (`filename`, `version`, `notes`) VALUES
  ('schema.sql',                            '8.7.6', 'Install initiale'),
  ('migration_v2.sql',                      '2.0.0', 'Migration V1→V2 (historique)'),
  ('migration_v3.sql',                      '3.0.0', 'Migration V2→V3 (historique)'),
  ('migration_v4.sql',                      '4.0.0', 'Migration V3→V4 (historique)'),
  ('migration_v5.sql',                      '5.0.0', 'Migration V4→V5 (historique)'),
  ('migration_v8.sql',                      '8.0.0', 'Migration V5→V8 (prize pool + cron)'),
  ('migration_indexes_v8.sql',              '8.1.0', 'Optimisation perf admin'),
  ('migration_api_credentials.sql',         '8.2.0', 'Credentials API payments'),
  ('migration_payout_tracking.sql',         '8.3.0', 'Traçabilité paiements'),
  ('migration_shortlinks_api.sql',          '8.4.0', 'Mode API shortlinks + callback_key'),
  ('migration_shortlinks_rate.sql',         '8.5.0', 'Calculateur rentabilité shortlinks'),
  ('migration_captcha_icons_fix.sql',       '8.6.0', 'Fix SVG icônes captcha (currentColor)'),
  ('migration_update_system.sql',           '8.7.6', 'Système de mises à jour (this)');

-- Configuration initiale du système d'update
INSERT INTO `config` (`k`, `v`) VALUES
  ('update.feed_url',         'https://gitbsam.github.io/wintaskly/latest.json'),
  ('update.last_check_at',    ''),
  ('update.latest_version',   ''),
  ('update.latest_data',      ''),
  ('update.maintenance_on',   '0'),
  ('update.maintenance_msg',  ''),
  ('update.user_banner_on',   '0'),
  ('update.user_banner_msg',  ''),
  ('update.user_banner_until','')
ON DUPLICATE KEY UPDATE v = v;
