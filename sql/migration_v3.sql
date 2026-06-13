-- =====================================================================
-- WINTASKLY — Migration V2 → V3
-- Hub d'authentification : email verification, reset password,
-- remember-me, 2FA (TOTP), rate-limiting des tentatives de connexion.
-- Sur une fresh install, schema.sql couvre déjà tout.
-- =====================================================================

USE `wintaskly`;

-- 1) Extension du statut + colonnes de vérification ------------------
ALTER TABLE `users`
  MODIFY `status` ENUM('active','pending','suspended','banned') NOT NULL DEFAULT 'active';

ALTER TABLE `users`
  ADD COLUMN `email_verified_at` DATETIME NULL AFTER `status`,
  ADD COLUMN `totp_secret`       VARCHAR(32) NULL AFTER `email_verified_at`,
  ADD COLUMN `totp_enabled`      TINYINT(1) NOT NULL DEFAULT 0 AFTER `totp_secret`;

-- Les comptes déjà créés en V1/V2 étaient considérés actifs :
-- on les marque comme déjà vérifiés pour éviter de leur réclamer
-- un e-mail de validation rétroactif.
UPDATE `users`
   SET `email_verified_at` = COALESCE(`created_at`, UTC_TIMESTAMP())
 WHERE `email_verified_at` IS NULL AND `status` = 'active';

-- 2) Table auth_tokens -----------------------------------------------
CREATE TABLE IF NOT EXISTS `auth_tokens` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `purpose`     ENUM('verify_email','reset_password','remember_me') NOT NULL,
  `selector`    CHAR(16) NOT NULL,
  `token_hash`  CHAR(64) NOT NULL,
  `expires_at`  DATETIME NOT NULL,
  `used_at`     DATETIME NULL,
  `ip`          VARBINARY(16) NULL,
  `user_agent`  VARCHAR(255) NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_selector` (`selector`),
  KEY `idx_user_purpose` (`user_id`,`purpose`),
  KEY `idx_expires`      (`expires_at`),
  CONSTRAINT `fk_auth_tokens_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Table auth_attempts ---------------------------------------------
CREATE TABLE IF NOT EXISTS `auth_attempts` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier`  VARCHAR(190) NOT NULL,
  `ip`          VARBINARY(16) NULL,
  `success`     TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_identifier_created` (`identifier`, `created_at`),
  KEY `idx_ip_created`         (`ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
