-- =====================================================================
-- WINTASKLY — Migration V3 → V4
-- Témoignages · Support guest-tracking · Messagerie · Notifications · TTL
-- Fresh installs : schema.sql couvre déjà tout.
-- =====================================================================

USE `wintaskly`;

-- 1) Colonne avatar_url (MySQL 5.7 compatible : on ignore l'erreur si déjà présent) -----
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'avatar_url'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `avatar_url` VARCHAR(255) NULL AFTER `theme`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Témoignages -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `testimonials` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `rating`        TINYINT UNSIGNED NOT NULL,
  `title`         VARCHAR(120) NOT NULL,
  `body`          TEXT NOT NULL,
  `status`        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reject_reason` VARCHAR(180) NULL,
  `featured`      TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_at`   DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status_created` (`status`, `created_at`),
  KEY `idx_user`           (`user_id`),
  CONSTRAINT `fk_testi_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Support tickets -------------------------------------------------
CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NULL,
  `guest_email`   VARCHAR(190) NULL,
  `guest_name`    VARCHAR(120) NULL,
  `guest_token`   CHAR(48) NULL,
  `subject`       VARCHAR(180) NOT NULL,
  `status`        ENUM('open','answered','closed') NOT NULL DEFAULT 'open',
  `last_reply_by` ENUM('user','admin','guest') NULL,
  `last_reply_at` DATETIME NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip`            VARBINARY(16) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_guest_token` (`guest_token`),
  KEY `idx_user`        (`user_id`),
  KEY `idx_status_date` (`status`, `last_reply_at`),
  CONSTRAINT `fk_ticket_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `support_messages` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`   BIGINT UNSIGNED NOT NULL,
  `author_role` ENUM('user','guest','admin') NOT NULL,
  `author_id`   INT UNSIGNED NULL,
  `body`        TEXT NOT NULL,
  `read_at`     DATETIME NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ticket_created` (`ticket_id`, `created_at`),
  CONSTRAINT `fk_sm_ticket`
    FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) Messages (admin → user) -----------------------------------------
CREATE TABLE IF NOT EXISTS `messages` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `sender_role` ENUM('admin','system') NOT NULL DEFAULT 'admin',
  `subject`     VARCHAR(180) NOT NULL,
  `body`        TEXT NOT NULL,
  `read_at`     DATETIME NULL,
  `expires_at`  DATETIME NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_read`    (`user_id`, `read_at`),
  KEY `idx_user_created` (`user_id`, `created_at`),
  KEY `idx_expires`      (`expires_at`),
  CONSTRAINT `fk_msg_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5) Notifications ---------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `type`       VARCHAR(40) NOT NULL,
  `title`      VARCHAR(160) NOT NULL,
  `body`       VARCHAR(255) NULL,
  `url`        VARCHAR(255) NULL,
  `read_at`    DATETIME NULL,
  `expires_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_read`    (`user_id`, `read_at`),
  KEY `idx_user_created` (`user_id`, `created_at`),
  KEY `idx_expires`      (`expires_at`),
  CONSTRAINT `fk_notif_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6) Seed des paramètres V4 (idempotent) -----------------------------
INSERT INTO `config` (`k`, `v`) VALUES
  ('testimonials.show_on_home', '1'),
  ('testimonials.home_limit',   '8'),
  ('ttl.message_read_days',     '30'),
  ('ttl.message_unread_days',   '90'),
  ('ttl.notif_read_days',       '30'),
  ('ttl.notif_unread_days',     '90'),
  ('ttl.cleanup_probability',   '0.02')
ON DUPLICATE KEY UPDATE v = VALUES(v);
