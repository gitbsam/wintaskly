-- ============================================================================
-- Wintaskly — Migration : Système anti-fraude
-- ============================================================================
-- Ajoute la détection automatique des comportements frauduleux :
--   - Multi-comptes (plusieurs comptes depuis la même IP)
--   - Limites de retrait progressives selon l'ancienneté du compte
--   - Score de risque + flag de revue manuelle
--
-- S'appuie sur les colonnes existantes : users.ip_registered,
-- users.last_login_ip, users.created_at (déjà présentes).
--
-- À exécuter UNE FOIS dans phpMyAdmin pour une install existante.
-- ============================================================================

-- 1) Colonnes de suivi anti-fraude sur users
ALTER TABLE `users` ADD COLUMN `risk_score` SMALLINT UNSIGNED NOT NULL DEFAULT 0
  COMMENT 'Score de risque 0-100 (plus élevé = plus suspect)';
ALTER TABLE `users` ADD COLUMN `flagged_at` DATETIME NULL
  COMMENT 'Date de signalement pour revue manuelle';
ALTER TABLE `users` ADD COLUMN `flag_reason` VARCHAR(255) NULL
  COMMENT 'Raison du signalement';

-- Index pour retrouver rapidement les comptes partageant une IP
ALTER TABLE `users` ADD INDEX `idx_ip_registered` (`ip_registered`);

-- 2) Journal des événements anti-fraude (pour audit + revue admin)
CREATE TABLE IF NOT EXISTS `fraud_events` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NULL,
  `event_type`  VARCHAR(40) NOT NULL COMMENT 'multi_account, vpn_suspected, rapid_withdraw, etc.',
  `severity`    ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
  `details`     VARCHAR(500) NULL,
  `ip`          VARBINARY(16) NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`, `created_at`),
  KEY `idx_type_created` (`event_type`, `created_at`),
  CONSTRAINT `fk_fraud_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Configuration anti-fraude (tout activable/réglable via /admin)
INSERT IGNORE INTO `config` (`k`, `v`) VALUES
 -- Détection multi-comptes
 ('fraud.multiaccount_enabled',   '1'),
 ('fraud.multiaccount_max_per_ip', '3'),
 ('fraud.multiaccount_action',    'flag'),   -- flag | block

 -- Limites de retrait progressives (anti-fraude paiement)
 ('fraud.withdraw_min_account_age_hours', '24'),  -- âge min du compte pour retirer
 ('fraud.withdraw_require_verified_email', '1'),  -- email vérifié obligatoire

 -- Détection VPN/proxy (heuristique basique, sans API externe)
 ('fraud.vpn_check_enabled',      '0'),   -- désactivé par défaut (nécessite config)

 -- Seuils de score de risque
 ('fraud.risk_threshold_review',  '50'),  -- au-delà : revue manuelle
 ('fraud.risk_threshold_block',   '80');  -- au-delà : blocage retrait

-- Enregistre la migration
INSERT IGNORE INTO `applied_migrations` (`filename`, `version`, `notes`) VALUES
 ('migration_antifraud.sql', '8.12.0', 'Système anti-fraude : multi-comptes, limites retrait, score de risque');
