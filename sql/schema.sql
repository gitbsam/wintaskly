-- =====================================================================
-- WINTASKLY — SCHÉMA SQL CONSOLIDÉ (V1 → V5) — Version corrigée
-- =====================================================================
-- MySQL 5.7+ / MariaDB 10.3+ / utf8mb4 / InnoDB
--
-- Ce fichier est entièrement IDEMPOTENT : exécutable sur une fresh
-- install OU re-exécutable sans erreur sur une base déjà existante.
--
-- Différences avec la version "PASTED" non corrigée :
--   • Nom de base = `wintaskly` (cohérent avec config.example.php).
--   • Tous les CREATE TABLE utilisent `IF NOT EXISTS`.
--   • Tous les INSERT initiaux utilisent `INSERT IGNORE` ou
--     `ON DUPLICATE KEY UPDATE` pour permettre les replays.
--   • Suppression des sections "migrations" qui dupliquaient ou
--     conflictuaient avec le schéma initial (`ALTER TABLE ADD COLUMN`
--     sur des colonnes déjà créées).
--   • Vérifications préventives via INFORMATION_SCHEMA pour les rares
--     ALTER nécessaires sur des bases pré-existantes.
-- =====================================================================
--
-- NOTE : ce fichier ne contient PAS de `CREATE DATABASE` ni de `USE`.
-- C'est volontaire : l'installeur sélectionne lui-même la BDD active
-- avant d'exécuter ce schéma. Si vous chargez manuellement via mysql CLI :
--   mysql -u user -p maBase < schema.sql
-- =====================================================================

-- ---------------------------------------------------------------------
-- USERS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`          VARCHAR(40)  NOT NULL,
  `email`             VARCHAR(190) NOT NULL,
  `password_hash`     VARCHAR(255) NOT NULL,
  `coins`             DECIMAL(18,4) NOT NULL DEFAULT 0,
  `xp`                INT UNSIGNED NOT NULL DEFAULT 0,
  `level`             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `referrer_id`       INT UNSIGNED NULL,
  `referral_code`     VARCHAR(16)  NOT NULL,
  `lang`              VARCHAR(5)   NOT NULL DEFAULT 'fr',
  `theme`             ENUM('light','dark') NOT NULL DEFAULT 'dark',
  `avatar_url`        VARCHAR(255) NULL,
  `timezone`          VARCHAR(64)  NOT NULL DEFAULT 'UTC',
  `role`              ENUM('user','admin') NOT NULL DEFAULT 'user',
  `status`            ENUM('active','pending','suspended','banned') NOT NULL DEFAULT 'active',
  `email_verified_at` DATETIME     NULL,
  `totp_secret`       VARCHAR(32)  NULL,
  `totp_enabled`      TINYINT(1)   NOT NULL DEFAULT 0,
  `tfa_email_enabled` TINYINT(1)   NOT NULL DEFAULT 0,
  `tfa_sms_enabled`   TINYINT(1)   NOT NULL DEFAULT 0,
  `phone_e164`        VARCHAR(20)  NULL,
  `bio`               VARCHAR(500) NULL,
  `country`           CHAR(2)      NULL,
  `delete_requested_at` DATETIME   NULL,
  `delete_token`      CHAR(64)     NULL,
  `ip_registered`     VARBINARY(16) NULL,
  `last_login_at`     DATETIME     NULL,
  `last_login_ip`     VARBINARY(16) NULL,
  `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_email`         (`email`),
  UNIQUE KEY `uniq_username`      (`username`),
  UNIQUE KEY `uniq_referral_code` (`referral_code`),
  KEY        `idx_referrer`       (`referrer_id`),
  CONSTRAINT `fk_users_referrer`
    FOREIGN KEY (`referrer_id`) REFERENCES `users`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- FAUCET
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `faucet_sessions` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED NOT NULL,
  `token`          CHAR(64)  NOT NULL,
  `step1_at`       DATETIME  NOT NULL,
  `step2_at`       DATETIME  NULL,
  `step3_at`       DATETIME  NULL,
  `expires_at`     DATETIME  NOT NULL,
  `captcha_target` VARCHAR(40) NOT NULL,
  `captcha_order`  VARCHAR(255) NOT NULL,
  `ip`             VARBINARY(16) NULL,
  `user_agent`     VARCHAR(255) NULL,
  `status`         ENUM('open','consumed','expired','rejected') NOT NULL DEFAULT 'open',
  `reject_reason`  VARCHAR(120) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token` (`token`),
  KEY `idx_user_status` (`user_id`,`status`),
  CONSTRAINT `fk_faucet_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `faucet_claims` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED NOT NULL,
  `session_id`     BIGINT UNSIGNED NOT NULL,
  `coins_awarded`  DECIMAL(18,4) NOT NULL,
  `xp_awarded`     INT UNSIGNED NOT NULL,
  `ip`             VARBINARY(16) NULL,
  `claimed_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `next_claim_at`  DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_next` (`user_id`,`next_claim_at`),
  CONSTRAINT `fk_claim_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_claim_session`
    FOREIGN KEY (`session_id`) REFERENCES `faucet_sessions`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- SHORTLINKS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `shortlinks` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(120) NOT NULL,
  `provider`        VARCHAR(60)  NOT NULL DEFAULT 'manual',
  `mode`            ENUM('manual','api') NOT NULL DEFAULT 'manual',
  `destination_url` TEXT NOT NULL,
  `api_endpoint`    VARCHAR(255) NULL,
  `api_token`       VARCHAR(255) NULL,
  `callback_key`    VARCHAR(64)  NULL,
  `reward_coins`    DECIMAL(18,4) NOT NULL DEFAULT 0,
  `reward_xp`       INT UNSIGNED NOT NULL DEFAULT 0,
  `cooldown_hours`  SMALLINT UNSIGNED NOT NULL DEFAULT 24,
  `gateway_seconds` TINYINT UNSIGNED NOT NULL DEFAULT 10,
  -- Données de rentabilité (informationnel pour l'admin, ne change pas
  -- la logique de récompense). Permet de calculer en temps réel combien
  -- l'admin gagne par vue et combien va à l'utilisateur.
  -- Ex: 12.00 USD pour 1000 vues = 0.012 USD/vue
  `provider_rate_amount`     DECIMAL(10,4) NOT NULL DEFAULT 0,
  `provider_rate_currency`   CHAR(3)       NOT NULL DEFAULT 'USD',
  `provider_rate_per_views`  INT UNSIGNED  NOT NULL DEFAULT 1000,
  `active`          TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `shortlink_attempts` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED NOT NULL,
  `shortlink_id`   INT UNSIGNED NOT NULL,
  `token`          CHAR(64) NOT NULL,
  `status`         ENUM('en_attente','valide','rejete','expire') NOT NULL DEFAULT 'en_attente',
  `started_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at`   DATETIME NULL,
  `ip`             VARBINARY(16) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token` (`token`),
  KEY `idx_user_link` (`user_id`,`shortlink_id`,`status`),
  CONSTRAINT `fk_sla_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sla_link`
    FOREIGN KEY (`shortlink_id`) REFERENCES `shortlinks`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `shortlink_cooldowns` (
  `user_id`       INT UNSIGNED NOT NULL,
  `shortlink_id`  INT UNSIGNED NOT NULL,
  `available_at`  DATETIME NOT NULL,
  PRIMARY KEY (`user_id`,`shortlink_id`),
  CONSTRAINT `fk_slc_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_slc_link`
    FOREIGN KEY (`shortlink_id`) REFERENCES `shortlinks`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- TRANSACTIONS (historique unifié des gains/dépenses)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `transactions` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `type`       ENUM('faucet','shortlink','ptc','offerwall','referral','withdraw','admin','bonus') NOT NULL,
  `coins`      DECIMAL(18,4) NOT NULL DEFAULT 0,
  `xp`         INT NOT NULL DEFAULT 0,
  `meta`       VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  KEY `idx_type` (`type`),
  CONSTRAINT `fk_tx_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- REFERRAL EARNINGS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `referral_earnings` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `referrer_id`    INT UNSIGNED NOT NULL,
  `referee_id`     INT UNSIGNED NOT NULL,
  `source`         ENUM('faucet','shortlink','ptc','offerwall') NOT NULL,
  `source_amount`  DECIMAL(18,4) NOT NULL,
  `commission`     DECIMAL(18,4) NOT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_referrer` (`referrer_id`,`created_at`),
  CONSTRAINT `fk_re_referrer`
    FOREIGN KEY (`referrer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_re_referee`
    FOREIGN KEY (`referee_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- BANS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bans` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip`         VARBINARY(16) NULL,
  `user_id`    INT UNSIGNED  NULL,
  `reason`     VARCHAR(180) NOT NULL,
  `banned_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ip`   (`ip`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- CONFIG (paramètres admin)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `config` (
  `k`           VARCHAR(64) NOT NULL,
  `v`           TEXT NOT NULL,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- CAPTCHA ICONS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `captcha_icons` (
  `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`   VARCHAR(40)  NOT NULL,
  `slug`   VARCHAR(40)  NOT NULL,
  `svg`    TEXT NOT NULL,
  `active` TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- HOMEPAGE BLOCKS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `homepage_blocks` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `k`          VARCHAR(40) NOT NULL,
  `title`      VARCHAR(180) NOT NULL,
  `content`    TEXT NULL,
  `visible`    TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_k` (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- AD ZONES
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ad_zones` (
  `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `k`      VARCHAR(40) NOT NULL,
  `label`  VARCHAR(120) NOT NULL,
  `code`   TEXT NOT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_k` (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- PTC (Paid-To-Click)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ptc_ads` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`            VARCHAR(150) NOT NULL,
  `description`      TEXT NULL,
  `url`              TEXT NOT NULL,
  `reward_coins`     DECIMAL(18,4) NOT NULL DEFAULT 0,
  `reward_xp`        INT UNSIGNED NOT NULL DEFAULT 0,
  `duration_seconds` SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  `daily_view_limit` INT UNSIGNED NOT NULL DEFAULT 1000,
  `cooldown_hours`   SMALLINT UNSIGNED NOT NULL DEFAULT 24,
  `total_views`      INT UNSIGNED NOT NULL DEFAULT 0,
  `active`           TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ptc_sessions` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED NOT NULL,
  `ptc_id`          INT UNSIGNED NOT NULL,
  `token`           CHAR(64) NOT NULL,
  `captcha_target`  VARCHAR(40) NOT NULL,
  `captcha_order`   VARCHAR(255) NOT NULL,
  `started_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`      DATETIME NOT NULL,
  `status`          ENUM('active','consumed','expired','rejected','cancelled') NOT NULL DEFAULT 'active',
  `reject_reason`   VARCHAR(120) NULL,
  `ip`              VARBINARY(16) NULL,
  `user_agent`      VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token` (`token`),
  KEY `idx_user_status` (`user_id`,`status`),
  CONSTRAINT `fk_ptcs_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ptcs_ad`   FOREIGN KEY (`ptc_id`)  REFERENCES `ptc_ads`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ptc_views` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `ptc_id`       INT UNSIGNED NOT NULL,
  `coins`        DECIMAL(18,4) NOT NULL DEFAULT 0,
  `xp`           INT UNSIGNED NOT NULL DEFAULT 0,
  `viewed_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `next_view_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_ptc_date` (`user_id`,`ptc_id`,`viewed_at`),
  KEY `idx_ptc_date`      (`ptc_id`,`viewed_at`),
  CONSTRAINT `fk_ptcv_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ptcv_ad`   FOREIGN KEY (`ptc_id`)  REFERENCES `ptc_ads`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- OFFERWALLS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `offerwalls` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `k`                VARCHAR(40)  NOT NULL,
  `name`             VARCHAR(120) NOT NULL,
  `logo_url`         VARCHAR(255) NULL,
  `iframe_url`       TEXT NULL,
  `redirect_url`     TEXT NULL,
  `callback_secret`  VARCHAR(120) NULL,
  `description`      TEXT NULL,
  `active`           TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order`       SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_k` (`k`),
  KEY `idx_active`   (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `offerwall_transactions` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED NOT NULL,
  `offerwall_id`    INT UNSIGNED NOT NULL,
  `external_tx_id`  VARCHAR(120) NOT NULL,
  `coins`           DECIMAL(18,4) NOT NULL DEFAULT 0,
  `xp`              INT UNSIGNED NOT NULL DEFAULT 0,
  `status`          ENUM('pending','credited','rejected') NOT NULL DEFAULT 'pending',
  `reject_reason`   VARCHAR(180) NULL,
  `raw_payload`     TEXT NULL,
  `signature`       VARCHAR(128) NULL,
  `ip`              VARBINARY(16) NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ext`     (`offerwall_id`,`external_tx_id`),
  KEY        `idx_user_created` (`user_id`,`created_at`),
  CONSTRAINT `fk_owtx_user` FOREIGN KEY (`user_id`)      REFERENCES `users`(`id`)       ON DELETE CASCADE,
  CONSTRAINT `fk_owtx_ow`   FOREIGN KEY (`offerwall_id`) REFERENCES `offerwalls`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- WITHDRAWAL METHODS / WITHDRAWALS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `withdrawal_methods` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `k`                   VARCHAR(40) NOT NULL,
  `label`               VARCHAR(80) NOT NULL,
  `currency`            VARCHAR(20) NOT NULL DEFAULT 'USD',
  `coins_per_unit`      DECIMAL(18,4) NOT NULL DEFAULT 10000,
  `min_coins`           DECIMAL(18,4) NOT NULL DEFAULT 10000,
  `max_coins`           DECIMAL(18,4) NULL,
  `address_label`       VARCHAR(80) NOT NULL DEFAULT 'Adresse de retrait',
  `address_placeholder` VARCHAR(160) NULL,
  `api_credentials`     TEXT NULL COMMENT 'JSON: api_key, merchant_id, secret, etc.',
  `auto_payout`         TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Si 1 : paiement auto via API. Si 0 : paiement manuel.',
  `active`              TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order`          SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_k` (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `withdrawals` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED NOT NULL,
  `method_id`       INT UNSIGNED NOT NULL,
  `coins_amount`    DECIMAL(18,4) NOT NULL,
  `payout_amount`   DECIMAL(18,4) NOT NULL,
  `payout_currency` VARCHAR(20)  NOT NULL,
  `payout_address`  VARCHAR(255) NOT NULL,
  `status`          ENUM('pending','completed','refused') NOT NULL DEFAULT 'pending',
  `refused_reason`  VARCHAR(255) NULL,
  `processed_by`    INT UNSIGNED NULL,
  `processed_at`    DATETIME NULL,
  `payout_txid`     VARCHAR(120) NULL COMMENT 'ID de transaction retourné par le provider (paiement auto réussi)',
  `payout_mode`     ENUM('manual','auto','auto_failed') NOT NULL DEFAULT 'manual',
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip`              VARBINARY(16) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  KEY `idx_status`       (`status`),
  CONSTRAINT `fk_wd_user`   FOREIGN KEY (`user_id`)      REFERENCES `users`(`id`)              ON DELETE CASCADE,
  CONSTRAINT `fk_wd_method` FOREIGN KEY (`method_id`)    REFERENCES `withdrawal_methods`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_wd_admin`  FOREIGN KEY (`processed_by`) REFERENCES `users`(`id`)              ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- AUTH (V3) — tokens + tentatives
-- ---------------------------------------------------------------------
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
  UNIQUE KEY `uniq_selector`    (`selector`),
  KEY        `idx_user_purpose` (`user_id`,`purpose`),
  KEY        `idx_expires`      (`expires_at`),
  CONSTRAINT `fk_auth_tokens_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

-- ---------------------------------------------------------------------
-- TÉMOIGNAGES (V4)
-- ---------------------------------------------------------------------
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

-- ---------------------------------------------------------------------
-- SUPPORT TICKETS / MESSAGES (V4)
-- ---------------------------------------------------------------------
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

-- ---------------------------------------------------------------------
-- MESSAGES & NOTIFICATIONS (V4)
-- ---------------------------------------------------------------------
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

-- ---------------------------------------------------------------------
-- LEADERBOARD (V5)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `leaderboard_cache` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `period_ym`    CHAR(7) NOT NULL,
  `rank`         TINYINT UNSIGNED NOT NULL,
  `user_id`      INT UNSIGNED NOT NULL,
  `coins_month`  DECIMAL(18,4) NOT NULL DEFAULT 0,
  `refreshed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_period_rank` (`period_ym`, `rank`),
  KEY `idx_user`     (`user_id`),
  KEY `idx_refresh`  (`refreshed_at`),
  CONSTRAINT `fk_lb_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `leaderboard_history` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `period_ym`    CHAR(7) NOT NULL,
  `rank`         TINYINT UNSIGNED NOT NULL,
  `user_id`      INT UNSIGNED NOT NULL,
  `username`     VARCHAR(40) NOT NULL,
  `coins_month`  DECIMAL(18,4) NOT NULL DEFAULT 0,
  `reward_coins` DECIMAL(18,4) NOT NULL DEFAULT 0,
  `reward_xp`    INT UNSIGNED NOT NULL DEFAULT 0,
  `archived_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_period_rank` (`period_ym`, `rank`),
  KEY `idx_period`      (`period_ym`),
  KEY `idx_user_period` (`user_id`, `period_ym`),
  CONSTRAINT `fk_lbh_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- HOTFIX MIGRATIONS — pour bases pré-existantes manquant des colonnes
-- (les blocs IF (informant_schema=0) sont no-op sur fresh install).
-- =====================================================================

-- users.avatar_url (ajouté en V4 sur les bases V1/V2/V3)
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'avatar_url'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `avatar_url` VARCHAR(255) NULL AFTER `theme`',
  'SELECT ''avatar_url already exists'' AS info');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- users.email_verified_at, totp_secret, totp_enabled (V3 sur V1/V2)
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_verified_at'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `users`
     ADD COLUMN `email_verified_at` DATETIME NULL AFTER `status`,
     ADD COLUMN `totp_secret`       VARCHAR(32) NULL AFTER `email_verified_at`,
     ADD COLUMN `totp_enabled`      TINYINT(1) NOT NULL DEFAULT 0 AFTER `totp_secret`',
  'SELECT ''email_verified_at already exists'' AS info');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- users.status — extension de l'ENUM pour inclure 'pending' (V3 sur V1/V2)
-- Sûr à exécuter à chaque fois car MODIFY est idempotent.
ALTER TABLE `users`
  MODIFY `status` ENUM('active','pending','suspended','banned') NOT NULL DEFAULT 'active';

-- transactions.type — extension de l'ENUM pour 'ptc','offerwall','bonus' (V2 sur V1)
ALTER TABLE `transactions`
  MODIFY `type` ENUM('faucet','shortlink','ptc','offerwall','referral','withdraw','admin','bonus') NOT NULL;

-- referral_earnings.source — extension pour 'ptc','offerwall' (V2 sur V1)
ALTER TABLE `referral_earnings`
  MODIFY `source` ENUM('faucet','shortlink','ptc','offerwall') NOT NULL;

-- Rattrapage : les comptes actifs créés avant V3 sont considérés "déjà vérifiés"
UPDATE `users`
   SET `email_verified_at` = COALESCE(`created_at`, CURRENT_TIMESTAMP)
 WHERE `email_verified_at` IS NULL
   AND `status` = 'active';

-- users.tfa_email_enabled, tfa_sms_enabled, phone_e164, bio, country,
-- delete_requested_at, delete_token  (V7 — paramètres compte étendus)
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'tfa_email_enabled'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `users`
     ADD COLUMN `tfa_email_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `totp_enabled`,
     ADD COLUMN `tfa_sms_enabled`   TINYINT(1) NOT NULL DEFAULT 0 AFTER `tfa_email_enabled`,
     ADD COLUMN `phone_e164`        VARCHAR(20) NULL AFTER `tfa_sms_enabled`,
     ADD COLUMN `bio`               VARCHAR(500) NULL AFTER `phone_e164`,
     ADD COLUMN `country`           CHAR(2)     NULL AFTER `bio`,
     ADD COLUMN `delete_requested_at` DATETIME  NULL AFTER `country`,
     ADD COLUMN `delete_token`        CHAR(64)  NULL AFTER `delete_requested_at`',
  'SELECT ''V7 columns already exist'' AS info');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- =====================================================================
-- SEED DES DONNÉES — Tout en INSERT IGNORE / ON DUPLICATE pour idempotence
-- =====================================================================

-- ---------- config ----------
INSERT INTO `config` (`k`,`v`) VALUES
 ('faucet_reward_coins',         '25.0000'),
 ('faucet_reward_xp',             '10'),
 ('faucet_cooldown_seconds',      '10800'),
 ('faucet_session_ttl_seconds',   '300'),
 ('faucet_transition_seconds',    '12'),
 ('referral_rate',                '0.10'),
 ('site_name',                    'Wintaskly'),
 ('site_tagline',                 'Gagne en accomplissant des micro-tâches'),
 ('hero_visible',                 '1'),
 ('stats_visible',                '1'),
 ('stats_users',                  '1247'),
 ('stats_paid',                   '58420'),
 ('stats_tasks_today',            '3580'),
 -- V4
 ('testimonials.show_on_home',    '1'),
 ('testimonials.home_limit',      '8'),
 ('ttl.message_read_days',        '30'),
 ('ttl.message_unread_days',      '90'),
 ('ttl.notif_read_days',          '30'),
 ('ttl.notif_unread_days',        '90'),
 ('ttl.cleanup_probability',      '0.02'),
 -- V5
 ('leaderboard.cache_minutes',    '15'),
 ('leaderboard.rewards_enabled',  '1'),
 ('leaderboard.reward_coins_1',   '1000'),
 ('leaderboard.reward_coins_2',   '600'),
 ('leaderboard.reward_coins_3',   '400'),
 ('leaderboard.reward_coins_4',   '250'),
 ('leaderboard.reward_coins_5',   '200'),
 ('leaderboard.reward_coins_6',   '150'),
 ('leaderboard.reward_coins_7',   '120'),
 ('leaderboard.reward_coins_8',   '100'),
 ('leaderboard.reward_coins_9',   '80'),
 ('leaderboard.reward_coins_10',  '60'),
 ('leaderboard.reward_xp_1',      '500'),
 ('leaderboard.reward_xp_2',      '300'),
 ('leaderboard.reward_xp_3',      '200'),
 ('leaderboard.reward_xp_4',      '100'),
 ('leaderboard.reward_xp_5',      '80'),
 ('leaderboard.reward_xp_6',      '60'),
 ('leaderboard.reward_xp_7',      '50'),
 ('leaderboard.reward_xp_8',      '40'),
 ('leaderboard.reward_xp_9',      '30'),
 ('leaderboard.reward_xp_10',     '20'),
 ('leaderboard.last_archived_period', ''),
 -- V7 — 2FA methods enabled by admin (1 = available to users, 0 = hidden)
 ('tfa.totp_available',           '1'),
 ('tfa.email_available',          '1'),
 ('tfa.sms_available',            '0'),
 ('tfa.sms_provider',             ''),
 -- V7 — Account deletion delay (in days) before purge
 ('account.delete_grace_days',    '7')
ON DUPLICATE KEY UPDATE v = VALUES(v);

-- ---------- captcha icons ----------
INSERT IGNORE INTO `captcha_icons` (`name`,`slug`,`svg`,`active`) VALUES
 ('clé','key',
  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="8" cy="15" r="4"/><path d="M10.85 12.15 19 4M18 5l2 2M15 8l2 2"/></svg>',
  1),
 ('étoile','star',
  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15 9 22 9.5 17 14.5 18.5 22 12 18 5.5 22 7 14.5 2 9.5 9 9 12 2"/></svg>',
  1),
 ('coeur','heart',
  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>',
  1),
 ('éclair','bolt',
  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
  1),
 ('lune','moon',
  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>',
  1),
 ('soleil','sun',
  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>',
  1),
 ('cadenas','lock',
  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
  1),
 ('engrenage','gear',
  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/></svg>',
  1);

-- ---------- homepage blocks ----------
INSERT IGNORE INTO `homepage_blocks` (`k`,`title`,`content`,`visible`,`sort_order`) VALUES
 ('hero',
  'Transforme ton temps en récompenses',
  'Réclame des Coins toutes les 3 heures, complète des shortlinks, fais grimper ton XP et invite tes amis pour gagner 10% sur tous leurs gains.',
  1, 1),
 ('stats', 'Une plateforme qui paye', NULL, 1, 2),
 ('how',
  'Comment ça marche ?',
  'Trois étapes : 1) Crée ton compte. 2) Réclame ton Faucet ou complète un shortlink. 3) Échange tes Coins.',
  1, 3);

-- ---------- ad zones ----------
INSERT IGNORE INTO `ad_zones` (`k`,`label`,`code`,`active`) VALUES
 ('faucet_transition_top',    'Faucet — Transition haut',         '<!-- Insérer ici le code AdSense responsive -->', 1),
 ('faucet_transition_bottom', 'Faucet — Transition bas',          '<!-- Insérer ici le code AdSense responsive -->', 1),
 ('faucet_verify_center',     'Faucet — Validation 300x250',      '<!-- Insérer ici le code AdSense 300x250 -->',    1),
 ('shortlink_gateway',        'Shortlink — Passerelle interne',   '<!-- Insérer ici le code AdSense -->',            1),
 ('ptc_chrono_top',           'PTC — Chrono haut',                '<!-- Insérer ici le code AdSense responsive -->', 1),
 ('offerwall_top',            'Offerwalls — Bandeau haut',        '<!-- Insérer ici le code AdSense responsive -->', 1),
 ('dashboard_top',            'Dashboard — Bandeau haut',         '<!-- Insérer ici le code AdSense responsive -->', 1),
 ('tasks_index_top',          'Tâches — Bandeau haut',            '<!-- Insérer ici le code AdSense responsive -->', 1),
 ('home_hero_bottom',         'Accueil — Sous le hero',           '<!-- Insérer ici le code AdSense responsive -->', 1);

-- Configuration AdSense (Auto Ads) modifiable via /admin/ads.php
INSERT IGNORE INTO `config` (`k`, `v`) VALUES
 ('ads.adsense_client', ''),
 ('ads.adsense_auto',   '0');

-- ---------- withdrawal methods ----------
INSERT IGNORE INTO `withdrawal_methods`
  (`k`,`label`,`currency`,`coins_per_unit`,`min_coins`,`address_label`,`address_placeholder`,`sort_order`)
VALUES
  ('faucetpay', 'FaucetPay',      'USD',   10000,   10000, 'E-mail FaucetPay', 'votre.email@exemple.com', 1),
  ('payeer',    'Payeer',         'USD',   10000,   20000, 'Compte Payeer',    'P1000000000',             2),
  ('btc',       'Bitcoin (BTC)',  'BTC', 5000000, 5000000, 'Adresse BTC',      'bc1q...',                 3),
  ('ltc',       'Litecoin (LTC)', 'LTC',   50000,   50000, 'Adresse LTC',      'ltc1q...',                4);

-- ---------- compte admin par défaut ----------
-- SUPPRIMÉ depuis V8 : l'installeur web (/install/) crée désormais le compte
-- admin avec les credentials saisis par l'utilisateur à l'étape 4. Cet INSERT
-- d'admin par défaut ('admin' / 'admin@wintaskly.local') causait un conflit
-- "Duplicate entry" lors de la création du vrai compte admin à l'install si
-- le user choisissait le username "admin" ou cet email.
--
-- Si vous chargez ce schema.sql manuellement (sans passer par l'installeur),
-- créez votre compte admin avec :
--   INSERT INTO users (username, email, password_hash, referral_code, role, status, email_verified_at)
--   VALUES ('votre_login', 'vous@example.com',
--           '$2y$10$...' /* password_hash() */,
--           'WT-XXXXXX', 'admin', 'active', UTC_TIMESTAMP());

-- =====================================================================
-- Wintaskly — Migration V8 (sous-version : prize pool + cron)
--
-- Ce script est idempotent : il peut être ré-exécuté sans dommage.
-- Il ajoute :
--   1. Les clés config pour la cagnotte mensuelle (prize_pool + split)
--   2. La table `cron_runs` pour tracer les exécutions de tâches
--      planifiées (utile pour debug et idempotence).
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1) Config — Cagnotte du leaderboard mensuel
--    `leaderboard.use_prize_pool` (0|1) :
--       0 = mode legacy, on lit reward_coins_N (valeurs fixes)
--       1 = mode cagnotte, on lit prize_pool + prize_pool_split
--    `leaderboard.prize_pool` : montant total en Coins à se partager
--    `leaderboard.prize_pool_split` : pourcentages CSV par rang
--       Ex: "40,20,12,8,6,5,4,3,1.5,0.5" (somme = 100)
--    `leaderboard.last_archived_period` : marqueur d'archivage
--    `leaderboard.archive_day` : jour du mois où l'archivage tourne (default 1)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `config` (`k`, `v`) VALUES
    ('leaderboard.use_prize_pool',     '1'),
    ('leaderboard.prize_pool',         '5000'),
    ('leaderboard.prize_pool_split',   '40,20,12,8,6,5,4,3,1.5,0.5'),
    ('leaderboard.last_archived_period', '');

-- ---------------------------------------------------------------------
-- 2) Cron — Token d'accès + tracking des runs
-- ---------------------------------------------------------------------
-- Token aléatoire généré au premier lancement (le code PHP le créera
-- si la clé est vide). L'admin peut le régénérer via /admin/cron.php.
INSERT IGNORE INTO `config` (`k`, `v`) VALUES
    ('cron.token',                ''),
    ('cron.last_run_at',          ''),
    ('cron.last_run_summary',     '');

CREATE TABLE IF NOT EXISTS `cron_runs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task`        VARCHAR(60)  NOT NULL,
  `started_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` DATETIME     NULL,
  `status`      ENUM('running','success','error','skipped') NOT NULL DEFAULT 'running',
  `summary`     VARCHAR(500) NULL,
  `error`       TEXT         NULL,
  PRIMARY KEY (`id`),
  KEY `idx_task_started` (`task`, `started_at`),
  KEY `idx_status`       (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- Système de mises à jour (V8.7.6+)
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
  `applied_by` VARCHAR(120) NULL,
  `version`    VARCHAR(20)  NULL,
  `notes`      TEXT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `config` (`k`, `v`) VALUES
  ('update.feed_url',         'https://gitbsam.github.io/wintaskly/latest.json'),
  ('update.last_check_at',    ''),
  ('update.latest_version',   ''),
  ('update.latest_data',      ''),
  ('update.maintenance_on',   '0'),
  ('update.maintenance_msg',  ''),
  ('update.user_banner_on',   '0'),
  ('update.user_banner_msg',  ''),
  ('update.user_banner_until','');
