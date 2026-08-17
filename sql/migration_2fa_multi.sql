-- ============================================================================
-- Wintaskly — 2FA multi-méthodes + codes de secours
-- ============================================================================
-- Étend la double authentification, jusqu'ici limitée au TOTP, à trois
-- méthodes activables indépendamment par l'administrateur puis par
-- l'utilisateur :
--
--   • TOTP  — application d'authentification (le plus sûr, hors ligne)
--   • E-MAIL — code à usage unique envoyé par e-mail
--   • SMS   — code à usage unique envoyé par SMS (nécessite un fournisseur)
--
-- S'y ajoutent les CODES DE SECOURS : indispensables, car sans eux la perte
-- du téléphone ou de l'accès à la boîte e-mail rend le compte inaccessible.
--
-- Idempotent : chaque ALTER est précédé d'un test d'existence, les CREATE
-- utilisent IF NOT EXISTS.
-- ⚠️ Importer avec --default-character-set=utf8mb4.
-- ============================================================================

-- ---------------------------------------------------------------------
-- 1) Colonnes utilisateur
-- ---------------------------------------------------------------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'twofa_email_enabled') = 0,
  'ALTER TABLE `users`
     ADD COLUMN `twofa_email_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `totp_enabled`,
     ADD COLUMN `twofa_sms_enabled`   TINYINT(1) NOT NULL DEFAULT 0 AFTER `twofa_email_enabled`,
     ADD COLUMN `twofa_phone`         VARCHAR(32) NULL AFTER `twofa_sms_enabled`,
     ADD COLUMN `twofa_phone_verified_at` DATETIME NULL AFTER `twofa_phone`,
     ADD COLUMN `twofa_preferred`     ENUM(''totp'',''email'',''sms'') NULL AFTER `twofa_phone_verified_at`',
  'SELECT "colonnes 2FA deja presentes" AS info');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- 2) Codes à usage unique (e-mail et SMS)
-- ---------------------------------------------------------------------
-- Le code n'est JAMAIS stocké en clair : seul son hachage l'est, comme un
-- mot de passe. Une fuite de la table ne permettrait donc pas de s'en servir.
CREATE TABLE IF NOT EXISTS `user_2fa_codes` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `method`     ENUM('email','sms') NOT NULL,
  `code_hash`  VARCHAR(255) NOT NULL,
  `attempts`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  `used_at`    DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip`         VARBINARY(16) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_method` (`user_id`, `method`, `used_at`),
  KEY `idx_expires` (`expires_at`),
  CONSTRAINT `fk_2fa_codes_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 3) Codes de secours
-- ---------------------------------------------------------------------
-- Générés en lot à l'activation de la 2FA, à usage unique. Hachés eux aussi.
CREATE TABLE IF NOT EXISTS `user_backup_codes` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `code_hash`  VARCHAR(255) NOT NULL,
  `used_at`    DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_used` (`user_id`, `used_at`),
  CONSTRAINT `fk_backup_codes_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 4) Réglages administrateur
-- ---------------------------------------------------------------------
-- L'admin décide quelles méthodes sont proposées. Une méthode désactivée ici
-- n'apparaît plus, même pour un utilisateur qui l'avait activée : le contrôle
-- reste côté plateforme.
--
-- Le SMS est désactivé par défaut : il exige un fournisseur externe et a un
-- coût par message. L'activer sans fournisseur configuré n'aurait aucun effet.
INSERT IGNORE INTO `config` (`k`, `v`) VALUES
  ('2fa.totp_enabled',    '1'),
  ('2fa.email_enabled',   '1'),
  ('2fa.sms_enabled',     '0'),
  ('2fa.backup_enabled',  '1'),
  ('2fa.preferred',       'totp'),
  ('2fa.code_ttl_minutes','10'),
  ('2fa.max_attempts',    '5'),
  ('2fa.backup_count',    '10'),
  ('2fa.alert_new_login', '1'),
  ('sms.provider',        ''),
  ('sms.api_key',         ''),
  ('sms.sender',          'Wintaskly');
