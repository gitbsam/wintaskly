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
  `daily_streak`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `daily_last_claim_at` DATETIME NULL,
  `risk_score`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `flagged_at`        DATETIME     NULL,
  `flag_reason`       VARCHAR(255) NULL,
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
  `callback_key`    VARCHAR(255) NULL,
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
  `type`       ENUM('faucet','shortlink','ptc','offerwall','referral','withdraw','admin','bonus','daily_bonus','achievement','bingo_buy','bingo_win') NOT NULL,
  `coins`      DECIMAL(18,4) NOT NULL DEFAULT 0,
  `xp`         INT NOT NULL DEFAULT 0,
  `meta`       VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  KEY `idx_type` (`type`),
  KEY `idx_user_coins` (`user_id`,`coins`),
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
  `callback_secret`  VARCHAR(255) NULL,
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
  `payout_amount`   DECIMAL(18,8) NOT NULL,
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
  MODIFY `type` ENUM('faucet','shortlink','ptc','offerwall','referral','withdraw','admin','bonus','daily_bonus','achievement') NOT NULL;

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
 ('leaderboard.mask_usernames',   '0'),
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
 ('legal.editor_name',            ''),
 ('legal.editor_status',          ''),
 ('legal.editor_address',         ''),
 ('legal.editor_email',           ''),
 ('legal.editor_siret',           ''),
 ('legal.publication_director',   ''),
 ('legal.host_name',              'LWS'),
 ('legal.host_address',           '10 rue Penthièvre, 75008 Paris'),
 ('legal.host_contact',           'https://www.lws.fr'),
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

-- Zones publicitaires du blog (in-article)
INSERT IGNORE INTO `ad_zones` (`k`,`label`,`code`,`active`) VALUES
 ('blog_article_top',    'Blog — Haut d''article',  '<!-- Insérer ici le code AdSense responsive -->', 1),
 ('blog_article_bottom', 'Blog — Bas d''article',   '<!-- Insérer ici le code AdSense responsive -->', 1);

-- Configuration AdSense (Auto Ads) modifiable via /admin/ads.php
INSERT IGNORE INTO `config` (`k`, `v`) VALUES
 ('ads.adsense_client', ''),
 ('ads.adsense_auto',   '0'),
 ('ads.head_enabled',   '0'),
 ('ads.head_code',      ''),
 ('ads.body_enabled',   '0'),
 ('ads.body_code',      ''),
 ('ads.banner_728',     ''),
 ('ads.banner_468',     ''),
 ('ads.banner_300',     ''),
 ('ads.adsterra_api_token', ''),
 ('ads.adsterra_domain_id', '');

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

-- ----------------------------------------------------------------------
-- Journal des actions d'administration (qui a fait quoi sur quel compte).
-- Alimentée par api/admin_user_action.php, consultée dans /admin/security.php.
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_actions` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`   INT UNSIGNED NOT NULL,
  `target_id`  INT UNSIGNED NOT NULL,
  `action`     VARCHAR(32) NOT NULL,
  `meta`       VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_target` (`target_id`, `created_at`),
  KEY `idx_admin`  (`admin_id`,  `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

-- ============================================================================
-- BONUS QUOTIDIEN (Daily Bonus / Streak) — V8.9+
-- ============================================================================
CREATE TABLE IF NOT EXISTS `daily_bonus_claims` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `streak_day`    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `coins_awarded` DECIMAL(18,4) NOT NULL DEFAULT 0,
  `xp_awarded`    INT UNSIGNED NOT NULL DEFAULT 0,
  `ip`            VARBINARY(16) NULL,
  `claimed_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `next_claim_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_claimed` (`user_id`, `claimed_at`),
  KEY `idx_user_next` (`user_id`, `next_claim_at`),
  CONSTRAINT `fk_daily_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `daily_bonus_tiers` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `streak_day` SMALLINT UNSIGNED NOT NULL,
  `coins`      DECIMAL(18,4) NOT NULL DEFAULT 0,
  `xp`         INT UNSIGNED NOT NULL DEFAULT 0,
  `label`      VARCHAR(60) NULL,
  `is_jackpot` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_streak_day` (`streak_day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `daily_bonus_tiers` (`streak_day`, `coins`, `xp`, `label`, `is_jackpot`) VALUES
 (1, 10,  5,  NULL,      0),
 (2, 15,  5,  NULL,      0),
 (3, 20,  10, NULL,      0),
 (4, 25,  10, NULL,      0),
 (5, 30,  15, NULL,      0),
 (6, 40,  15, NULL,      0),
 (7, 100, 50, 'Jackpot', 1);

INSERT IGNORE INTO `config` (`k`, `v`) VALUES
 ('daily_bonus.enabled',      '1'),
 ('daily_bonus.window_hours', '24'),
 ('daily_bonus.reset_hours',  '48'),
 ('daily_bonus.cycle_mode',   'repeat');

-- ============================================================================
-- ACHIEVEMENTS (badges / succès) — V8.10+
-- ============================================================================
CREATE TABLE IF NOT EXISTS `achievements` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `k`           VARCHAR(60) NOT NULL,
  `metric`      VARCHAR(40) NOT NULL,
  `threshold`   DECIMAL(18,4) NOT NULL DEFAULT 1,
  `tier`        ENUM('bronze','silver','gold','platinum','special') NOT NULL DEFAULT 'bronze',
  `title`       VARCHAR(120) NOT NULL,
  `description` VARCHAR(255) NULL,
  `icon`        VARCHAR(20) NULL,
  `reward_coins` DECIMAL(18,4) NOT NULL DEFAULT 0,
  `reward_xp`   INT UNSIGNED NOT NULL DEFAULT 0,
  `sort_order`  INT NOT NULL DEFAULT 0,
  `active`      TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_k` (`k`),
  KEY `idx_metric` (`metric`),
  KEY `idx_active_sort` (`active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_achievements` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED NOT NULL,
  `achievement_id` INT UNSIGNED NOT NULL,
  `unlocked_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `claimed`        TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_achievement` (`user_id`, `achievement_id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_ua_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ua_achievement`
    FOREIGN KEY (`achievement_id`) REFERENCES `achievements`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `achievements`
  (`k`, `metric`, `threshold`, `tier`, `title`, `description`, `icon`, `reward_coins`, `reward_xp`, `sort_order`) VALUES
  ('faucet_10',     'faucet_claims',      10,    'bronze',   'Goutte à goutte',    'Réclame 10 fois le faucet',           '💧', 20,   10,  10),
  ('faucet_100',    'faucet_claims',      100,   'silver',   'Robinet ouvert',     'Réclame 100 fois le faucet',          '🚰', 100,  50,  11),
  ('faucet_1000',   'faucet_claims',      1000,  'gold',     'Cascade',            'Réclame 1000 fois le faucet',         '🌊', 1000, 250, 12),
  ('shortlink_10',  'shortlinks_done',    10,    'bronze',   'Premiers liens',     'Complète 10 shortlinks',              '🔗', 25,   10,  20),
  ('shortlink_100', 'shortlinks_done',    100,   'silver',   'Chaîne solide',      'Complète 100 shortlinks',             '⛓️', 120,  60,  21),
  ('shortlink_500', 'shortlinks_done',    500,   'gold',     'Maître des liens',   'Complète 500 shortlinks',             '🏅', 600,  200, 22),
  ('streak_3',      'daily_streak',       3,     'bronze',   'Habitué',            'Atteins une série de 3 jours',        '🔥', 30,   15,  30),
  ('streak_7',      'daily_streak',       7,     'silver',   'Assidu',             'Atteins une série de 7 jours',        '🔥', 100,  50,  31),
  ('streak_30',     'daily_streak',       30,    'gold',     'Inarrêtable',        'Atteins une série de 30 jours',       '⚡', 500,  300, 32),
  ('referral_1',    'referrals',          1,     'bronze',   'Recruteur',          'Parraine ton premier ami',            '👤', 50,   20,  40),
  ('referral_5',    'referrals',          5,     'silver',   'Ambassadeur',        'Parraine 5 amis',                     '👥', 250,  100, 41),
  ('referral_20',   'referrals',          20,    'gold',     'Influenceur',        'Parraine 20 amis',                    '🌟', 1000, 400, 42),
  ('coins_1k',      'total_coins_earned', 1000,  'bronze',   'Premier magot',      'Gagne 1 000 coins au total',          '💰', 50,   25,  50),
  ('coins_10k',     'total_coins_earned', 10000, 'silver',   'Petite fortune',     'Gagne 10 000 coins au total',         '💎', 300,  150, 51),
  ('first_payout',  'withdrawals_done',   1,     'special',  'Premier retrait',    'Effectue ton premier retrait',        '🏆', 100,  50,  60),
  ('level_10',      'level',              10,    'silver',   'Vétéran',            'Atteins le niveau 10',                '🎖️', 150,  0,   70),
  ('veteran_30',    'account_age_days',   30,    'special',  'Fidèle',             'Compte actif depuis 30 jours',        '📅', 100,  50,  80);

INSERT IGNORE INTO `config` (`k`, `v`) VALUES
 ('achievements.enabled', '1');

-- ============================================================================
-- BLOG (contenu éditorial public) — V8.11+
-- ============================================================================
CREATE TABLE IF NOT EXISTS `blog_categories` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`        VARCHAR(80)  NOT NULL,
  `name`        VARCHAR(120) NOT NULL,
  `description` VARCHAR(255) NULL,
  `sort_order`  INT NOT NULL DEFAULT 0,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cat_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `blog_posts` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`            VARCHAR(160) NOT NULL,
  `category_id`     INT UNSIGNED NULL,
  `title`           VARCHAR(200) NOT NULL,
  `excerpt`         VARCHAR(320) NULL,
  `body`            MEDIUMTEXT   NOT NULL,
  `cover_emoji`     VARCHAR(16)  NULL,
  `author_name`     VARCHAR(120) NULL DEFAULT 'Équipe Wintaskly',
  `meta_title`      VARCHAR(200) NULL,
  `meta_description` VARCHAR(320) NULL,
  `status`          ENUM('draft','published') NOT NULL DEFAULT 'draft',
  `views`           INT UNSIGNED NOT NULL DEFAULT 0,
  `reading_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 3,
  `published_at`    DATETIME NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_post_slug` (`slug`),
  KEY `idx_status_pub` (`status`, `published_at`),
  KEY `idx_category` (`category_id`),
  CONSTRAINT `fk_post_category`
    FOREIGN KEY (`category_id`) REFERENCES `blog_categories`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `blog_categories` (`slug`, `name`, `description`, `sort_order`) VALUES
 ('guides',      'Guides',          'Tutoriels pas à pas pour bien démarrer',          10),
 ('crypto',      'Crypto',          'Comprendre les cryptomonnaies et les paiements',  20),
 ('astuces',     'Astuces',         'Conseils pour optimiser tes gains',               30),
 ('actualites',  'Actualités',      'Nouveautés et mises à jour de la plateforme',     40);

INSERT IGNORE INTO `config` (`k`, `v`) VALUES
 ('blog.enabled',      '1'),
 ('blog.title',        'Le Blog Wintaskly'),
 ('blog.description',  'Guides, astuces et actualités sur les micro-gains et la crypto.');


-- Articles de blog de démarrage (contenu original éditorial)
-- exigences de contenu d'AdSense et apporter une vraie valeur aux visiteurs.
--

-- Article 1 — Guide débutant (catégorie: guides)
INSERT IGNORE INTO `blog_posts`
 (`slug`, `category_id`, `title`, `excerpt`, `cover_emoji`, `author_name`,
  `meta_title`, `meta_description`, `status`, `reading_minutes`, `published_at`, `body`)
VALUES (
 'guide-debutant-gagner-coins-wintaskly',
 (SELECT id FROM blog_categories WHERE slug='guides'),
 'Guide du débutant : comment gagner tes premiers coins sur Wintaskly',
 'Tu débutes sur Wintaskly ? Ce guide complet t''explique pas à pas comment créer ton compte, réaliser tes premières tâches et accumuler tes premiers coins efficacement.',
 '🚀',
 'Équipe Wintaskly',
 'Guide débutant Wintaskly : gagner ses premiers coins (2026)',
 'Apprends à gagner tes premiers coins sur Wintaskly : inscription, faucet, raccourcisseurs de liens, PTC et offres. Guide pas à pas pour bien démarrer.',
 'published', 6, UTC_TIMESTAMP(),
 '<p>Bienvenue sur Wintaskly ! Si tu viens de découvrir notre plateforme de micro-gains, tu te demandes sûrement par où commencer. Ce guide complet va t''accompagner pas à pas, depuis la création de ton compte jusqu''à tes premiers retraits.</p>

<h2>Qu''est-ce que Wintaskly exactement ?</h2>
<p>Wintaskly est une plateforme de type GPT (Get-Paid-To, ou "payé pour faire"). Le principe est simple : tu réalises de petites tâches en ligne et tu gagnes des <strong>coins</strong>, une monnaie virtuelle que tu peux ensuite convertir et retirer. Ces tâches ne demandent aucune compétence particulière : il suffit d''un peu de temps libre et d''une connexion internet.</p>
<p>Contrairement à beaucoup d''idées reçues, ce type de plateforme ne te rendra pas riche du jour au lendemain. En revanche, utilisée régulièrement et intelligemment, elle peut constituer un complément intéressant pour arrondir tes fins de mois.</p>

<h2>Étape 1 : créer ton compte</h2>
<p>La première étape est évidemment de t''inscrire. Le processus prend moins de deux minutes :</p>
<ul>
<li>Clique sur le bouton d''inscription en haut de la page.</li>
<li>Renseigne ton adresse e-mail et choisis un mot de passe solide.</li>
<li>Valide ton adresse e-mail en cliquant sur le lien que tu recevras.</li>
</ul>
<p>La validation de l''e-mail est importante : elle sécurise ton compte et te permet de récupérer ton accès en cas d''oubli de mot de passe. Pense à vérifier ton dossier de courriers indésirables si tu ne reçois rien dans les minutes qui suivent.</p>

<h2>Étape 2 : découvrir les différents types de tâches</h2>
<p>Wintaskly propose plusieurs façons de gagner des coins. Chacune a ses avantages, et le secret d''une bonne progression est de les combiner.</p>

<h3>Le faucet</h3>
<p>Le faucet (ou "robinet") est le moyen le plus simple de commencer. À intervalles réguliers, tu peux réclamer une petite quantité de coins gratuitement. C''est rapide, sans risque, et parfait pour prendre l''habitude de revenir sur la plateforme. Pense à réclamer ton faucet à chaque fois que le délai d''attente est écoulé.</p>

<h3>Les raccourcisseurs de liens</h3>
<p>Les raccourcisseurs de liens (shortlinks) te demandent de traverser une courte page intermédiaire avant d''obtenir ta récompense. Ces tâches rapportent un peu plus que le faucet et ne prennent que quelques secondes. C''est l''une des sources de gains les plus rentables pour le temps investi.</p>

<h3>Les publicités PTC</h3>
<p>Le PTC (Paid-To-Click) consiste à regarder une publicité pendant quelques secondes. Un minuteur s''affiche, et une fois écoulé, tu reçois ta récompense. C''est passif et facile à intégrer dans ta routine.</p>

<h3>Les offres partenaires</h3>
<p>Les offerwalls (murs d''offres) regroupent des tâches proposées par des partenaires : sondages, inscriptions à des services, tests d''applications. Ces offres rapportent généralement beaucoup plus que les autres tâches, mais demandent plus de temps et d''engagement.</p>

<h2>Étape 3 : adopter une routine gagnante</h2>
<p>La clé de la réussite sur une plateforme GPT, c''est la <strong>régularité</strong>. Voici une routine simple et efficace :</p>
<ul>
<li>Connecte-toi chaque jour pour réclamer ton bonus quotidien et ton faucet.</li>
<li>Enchaîne quelques raccourcisseurs de liens pendant que tu as un moment.</li>
<li>Regarde les publicités PTC disponibles.</li>
<li>Réserve les offres partenaires pour les moments où tu as plus de temps.</li>
</ul>
<p>En quelques minutes par jour, tu verras ton solde grandir progressivement. La patience est ta meilleure alliée.</p>

<h2>Étape 4 : comprendre les retraits</h2>
<p>Une fois que tu as accumulé suffisamment de coins, tu peux demander un retrait. Wintaskly te permet de convertir tes coins et de les recevoir via différentes méthodes de paiement. Chaque méthode a un seuil minimum, alors vérifie bien les conditions avant de faire ta demande.</p>
<p>Un conseil important : ne cours pas après le retrait immédiat. Laisse ton solde grandir un peu pour atteindre des seuils plus confortables et limiter les frais éventuels.</p>

<h2>Les erreurs à éviter quand on débute</h2>
<p>Quelques pièges classiques guettent les nouveaux venus :</p>
<ul>
<li><strong>Vouloir aller trop vite.</strong> Les micro-gains demandent du temps. Méfie-toi de toute promesse de gains rapides et énormes.</li>
<li><strong>Négliger la régularité.</strong> Une visite quotidienne, même courte, est bien plus rentable que de longues sessions espacées.</li>
<li><strong>Ignorer le système de parrainage.</strong> Inviter des amis peut considérablement augmenter tes gains sur le long terme.</li>
</ul>

<h2>En résumé</h2>
<p>Gagner tes premiers coins sur Wintaskly est à la portée de tous. Crée ton compte, explore les différentes tâches, adopte une routine régulière, et sois patient. Les micro-gains récompensent la constance bien plus que l''intensité. À toi de jouer !</p>'
);

-- Article 2 — Crypto pour débutants (catégorie: crypto)
INSERT IGNORE INTO `blog_posts`
 (`slug`, `category_id`, `title`, `excerpt`, `cover_emoji`, `author_name`,
  `meta_title`, `meta_description`, `status`, `reading_minutes`, `published_at`, `body`)
VALUES (
 'cryptomonnaie-debutant-comprendre-bases',
 (SELECT id FROM blog_categories WHERE slug='crypto'),
 'Cryptomonnaie pour débutants : comprendre les bases avant de se lancer',
 'Bitcoin, wallet, blockchain... Le vocabulaire de la crypto peut intimider. Cet article décrypte les notions essentielles pour comprendre comment fonctionnent les paiements en cryptomonnaie.',
 '₿',
 'Équipe Wintaskly',
 'Cryptomonnaie pour débutants : guide des bases (2026)',
 'Comprendre la cryptomonnaie facilement : blockchain, wallet, Bitcoin, frais de réseau. Guide pédagogique pour débutants qui veulent se lancer sereinement.',
 'published', 7, UTC_TIMESTAMP(),
 '<p>La cryptomonnaie est partout : dans les médias, les conversations, et de plus en plus dans les paiements en ligne. Pourtant, pour beaucoup, ce domaine reste flou et intimidant. Si tu reçois des paiements en crypto ou que tu envisages de t''y intéresser, ce guide va t''éclairer sur les notions fondamentales, sans jargon inutile.</p>

<h2>Qu''est-ce qu''une cryptomonnaie ?</h2>
<p>Une cryptomonnaie est une monnaie numérique qui fonctionne sans banque centrale ni autorité unique. Au lieu d''être gérée par une institution, elle repose sur un réseau d''ordinateurs répartis dans le monde entier. Cette absence d''intermédiaire central est l''une des caractéristiques les plus importantes de la crypto.</p>
<p>Le Bitcoin, créé en 2009, fut la première cryptomonnaie. Depuis, des milliers d''autres ont vu le jour, chacune avec ses spécificités. Mais elles partagent toutes un socle technologique commun : la blockchain.</p>

<h2>La blockchain, expliquée simplement</h2>
<p>Imagine un grand cahier de comptes public, que tout le monde peut consulter mais que personne ne peut falsifier. Chaque fois qu''une transaction a lieu, elle est inscrite dans ce cahier. Les pages de ce cahier sont appelées des "blocs", et elles sont reliées entre elles de manière chronologique : c''est la "chaîne de blocs", ou blockchain.</p>
<p>Ce qui rend la blockchain si fiable, c''est qu''elle est dupliquée sur des milliers d''ordinateurs. Pour falsifier une transaction, il faudrait modifier simultanément toutes ces copies, ce qui est pratiquement impossible. C''est cette architecture qui garantit la sécurité et la transparence du système.</p>

<h2>Le wallet : ton portefeuille numérique</h2>
<p>Pour recevoir, stocker et envoyer de la cryptomonnaie, tu as besoin d''un <strong>wallet</strong> (portefeuille). Contrairement à ce que son nom suggère, un wallet ne "contient" pas réellement tes cryptos : il contient les clés qui te permettent d''y accéder sur la blockchain.</p>
<p>Il existe deux types de clés à comprendre :</p>
<ul>
<li><strong>La clé publique</strong> : c''est ton adresse, que tu peux partager pour recevoir des paiements. C''est l''équivalent de ton numéro de compte bancaire.</li>
<li><strong>La clé privée</strong> : c''est ton mot de passe secret, qui te donne le contrôle de tes fonds. Ne la partage JAMAIS avec qui que ce soit. Quiconque possède ta clé privée possède tes cryptos.</li>
</ul>
<p>Cette distinction est cruciale. La règle d''or de la crypto est : "Not your keys, not your coins" (si tu ne contrôles pas tes clés, tu ne contrôles pas tes cryptos).</p>

<h2>Les frais de réseau</h2>
<p>Chaque transaction sur une blockchain implique des frais, appelés "frais de réseau" ou "frais de gas". Ces frais rémunèrent les ordinateurs qui valident et sécurisent les transactions. Ils varient selon l''affluence sur le réseau : plus il y a de transactions en attente, plus les frais augmentent.</p>
<p>C''est pourquoi, quand tu retires de petites sommes, les frais peuvent représenter une part importante du montant. Pour optimiser, mieux vaut souvent regrouper ses retraits et attendre d''avoir accumulé une somme plus conséquente.</p>

<h2>Les cryptomonnaies les plus courantes pour les micro-paiements</h2>
<p>Toutes les cryptos ne se valent pas pour recevoir de petits montants. Certaines ont des frais élevés qui les rendent peu adaptées aux micro-paiements. D''autres, conçues pour être rapides et peu coûteuses, sont idéales :</p>
<ul>
<li><strong>Bitcoin (BTC)</strong> : la plus connue, mais ses frais peuvent être élevés en période de forte activité.</li>
<li><strong>Litecoin (LTC)</strong> : plus rapide et moins cher que le Bitcoin, souvent privilégié pour les petits montants.</li>
<li><strong>Dogecoin (DOGE)</strong> : des frais très faibles, ce qui en fait un bon choix pour les micro-retraits.</li>
</ul>

<h2>Quelques règles de sécurité essentielles</h2>
<p>La crypto offre une grande liberté, mais cette liberté s''accompagne de responsabilités. Voici les principes à respecter absolument :</p>
<ul>
<li>Ne partage jamais ta clé privée ou ta phrase de récupération.</li>
<li>Méfie-toi des offres trop belles pour être vraies : les arnaques sont nombreuses dans cet univers.</li>
<li>Vérifie toujours deux fois l''adresse de destination avant d''envoyer des fonds : une transaction crypto est irréversible.</li>
<li>Active l''authentification à deux facteurs partout où c''est possible.</li>
</ul>

<h2>Conclusion</h2>
<p>La cryptomonnaie n''est pas aussi compliquée qu''elle en a l''air une fois qu''on en maîtrise les bases. Une monnaie numérique, une blockchain qui sécurise les transactions, un wallet avec ses clés, et des frais de réseau à anticiper : voilà l''essentiel. Avec ces notions en tête, tu peux désormais recevoir et gérer tes paiements en crypto en toute sérénité.</p>'
);

-- Article 3 — Astuces pour maximiser ses gains (catégorie: astuces)
INSERT IGNORE INTO `blog_posts`
 (`slug`, `category_id`, `title`, `excerpt`, `cover_emoji`, `author_name`,
  `meta_title`, `meta_description`, `status`, `reading_minutes`, `published_at`, `body`)
VALUES (
 'astuces-maximiser-gains-plateforme-gpt',
 (SELECT id FROM blog_categories WHERE slug='astuces'),
 '7 astuces pour maximiser tes gains sur une plateforme de micro-tâches',
 'Tu veux tirer le meilleur parti de ton temps sur Wintaskly ? Découvre 7 stratégies concrètes et éprouvées pour augmenter tes gains sans y passer tes journées.',
 '💡',
 'Équipe Wintaskly',
 '7 astuces pour maximiser ses gains sur un site GPT (2026)',
 'Augmente tes gains sur les plateformes de micro-tâches : régularité, parrainage, bonus quotidien, choix des tâches. 7 astuces concrètes et efficaces.',
 'published', 5, UTC_TIMESTAMP(),
 '<p>Sur une plateforme de micro-tâches, deux personnes peuvent passer le même temps et obtenir des résultats très différents. La différence ? La stratégie. Voici sept astuces concrètes pour optimiser tes gains et faire fructifier chaque minute investie.</p>

<h2>1. Mise sur la régularité plutôt que l''intensité</h2>
<p>C''est le conseil numéro un, et pour cause. Les plateformes GPT récompensent la fidélité. Une visite quotidienne de dix minutes rapporte généralement bien plus qu''une session de deux heures une fois par semaine. Pourquoi ? Parce que de nombreux mécanismes (bonus quotidien, faucet, séries de connexion) se basent sur ta présence régulière.</p>

<h2>2. Ne rate jamais ton bonus quotidien</h2>
<p>Le bonus quotidien est de l''argent gratuit, littéralement. La plupart des plateformes augmentent même la récompense à mesure que tu enchaînes les jours consécutifs : c''est ce qu''on appelle une "série" ou "streak". Rater un jour peut réinitialiser ta série et te faire perdre des bonus importants. Crée-toi un rappel si nécessaire.</p>

<h2>3. Choisis les tâches les plus rentables au temps investi</h2>
<p>Toutes les tâches ne se valent pas. Pour optimiser, calcule mentalement le rapport entre la récompense et le temps nécessaire. Les raccourcisseurs de liens, par exemple, offrent souvent un excellent rendement : quelques secondes pour une récompense correcte. Les offres partenaires rapportent gros mais demandent plus d''engagement. Adapte ton choix au temps dont tu disposes.</p>

<h2>4. Exploite le parrainage</h2>
<p>Le parrainage est sans doute le levier le plus puissant pour augmenter tes gains sur le long terme. En invitant des amis, tu touches généralement une commission sur leurs gains, sans que cela ne réduise les leurs. Partage ton lien de parrainage sur tes réseaux, dans des communautés intéressées, ou auprès de proches. Quelques filleuls actifs peuvent transformer tes revenus.</p>

<h2>5. Débloque les succès et récompenses</h2>
<p>Beaucoup de plateformes proposent des systèmes de succès (achievements) qui récompensent l''atteinte de certains objectifs : un certain nombre de tâches réalisées, une série de connexions, un palier de gains. Garde un œil sur ces objectifs : ils représentent des bonus non négligeables que tu peux viser activement.</p>

<h2>6. Sois attentif aux événements et promotions</h2>
<p>Les plateformes organisent régulièrement des événements spéciaux, des concours ou des périodes de gains boostés. Ces moments sont l''occasion de gagner davantage. Active les notifications et consulte régulièrement les annonces pour ne rien manquer.</p>

<h2>7. Reste patient et garde une vision réaliste</h2>
<p>La dernière astuce est peut-être la plus importante : garde des attentes réalistes. Les micro-tâches ne remplacent pas un emploi. Elles constituent un complément. En adoptant cet état d''esprit, tu éviteras la frustration et tu resteras motivé sur la durée, ce qui est précisément ce qui paie le plus.</p>

<h2>En conclusion</h2>
<p>Maximiser ses gains sur une plateforme de micro-tâches ne relève pas de la chance, mais de la méthode. Régularité, bonus quotidien, choix intelligent des tâches, parrainage et patience : applique ces principes et tu verras une réelle différence dans ta progression. Le succès appartient à ceux qui jouent sur la durée.</p>'
);

-- Article 4 — Sécurité en ligne (catégorie: guides)
INSERT IGNORE INTO `blog_posts`
 (`slug`, `category_id`, `title`, `excerpt`, `cover_emoji`, `author_name`,
  `meta_title`, `meta_description`, `status`, `reading_minutes`, `published_at`, `body`)
VALUES (
 'securite-ligne-proteger-compte-arnaques',
 (SELECT id FROM blog_categories WHERE slug='guides'),
 'Sécurité en ligne : comment protéger ton compte et éviter les arnaques',
 'Protéger ton compte et tes gains est essentiel. Découvre les bonnes pratiques de sécurité, les signes d''une arnaque, et les réflexes à adopter pour naviguer sereinement.',
 '🔒',
 'Équipe Wintaskly',
 'Sécurité en ligne : protéger son compte et éviter les arnaques',
 'Guide de sécurité en ligne : mots de passe forts, authentification à deux facteurs, détection des arnaques et phishing. Protège ton compte efficacement.',
 'published', 6, UTC_TIMESTAMP(),
 '<p>Sur internet, ta sécurité dépend largement de tes habitudes. Que tu utilises une plateforme de micro-gains, une messagerie ou un service bancaire, les principes de protection restent les mêmes. Ce guide te donne les réflexes essentiels pour protéger ton compte, tes gains et tes données personnelles.</p>

<h2>Un mot de passe solide : ta première ligne de défense</h2>
<p>Le mot de passe est la base de ta sécurité, et pourtant il est souvent négligé. Un bon mot de passe doit être :</p>
<ul>
<li><strong>Long</strong> : au moins douze caractères. La longueur est le facteur le plus important.</li>
<li><strong>Varié</strong> : mélange majuscules, minuscules, chiffres et symboles.</li>
<li><strong>Unique</strong> : n''utilise jamais le même mot de passe sur plusieurs sites. Si l''un est compromis, les autres restent protégés.</li>
</ul>
<p>Pour gérer tous ces mots de passe différents, un gestionnaire de mots de passe est un outil précieux. Il génère et mémorise des mots de passe complexes à ta place, et tu n''as qu''à retenir un seul mot de passe maître.</p>

<h2>L''authentification à deux facteurs : un rempart supplémentaire</h2>
<p>L''authentification à deux facteurs (2FA) ajoute une seconde couche de sécurité. Même si quelqu''un découvre ton mot de passe, il lui faudra un second code, généralement envoyé sur ton téléphone ou généré par une application dédiée, pour accéder à ton compte.</p>
<p>Active la 2FA partout où elle est disponible. C''est l''une des mesures les plus efficaces pour empêcher les accès non autorisés, et elle ne prend que quelques secondes à utiliser au quotidien.</p>

<h2>Reconnaître les tentatives de phishing</h2>
<p>Le phishing (hameçonnage) est une technique d''arnaque très répandue. Le principe : te faire croire que tu communiques avec un service légitime pour te soutirer tes identifiants ou tes informations. Voici les signes qui doivent t''alerter :</p>
<ul>
<li>Un e-mail ou un message qui crée un sentiment d''urgence ("ton compte va être suspendu !").</li>
<li>Des fautes d''orthographe ou une formulation maladroite.</li>
<li>Une adresse d''expéditeur suspecte ou légèrement différente de l''officielle.</li>
<li>Un lien qui ne mène pas vers le site officiel (vérifie toujours l''adresse avant de cliquer).</li>
<li>Une demande de tes identifiants, mot de passe ou clé privée par message.</li>
</ul>
<p>Règle d''or : un service sérieux ne te demandera JAMAIS ton mot de passe par e-mail ou message. En cas de doute, ne clique sur aucun lien et rends-toi directement sur le site officiel en tapant son adresse toi-même.</p>

<h2>Les arnaques aux gains rapides</h2>
<p>Méfie-toi de toute promesse de gains faramineux sans effort. Sur les plateformes de micro-gains comme ailleurs, si une offre semble trop belle pour être vraie, c''est presque toujours le cas. Les arnaqueurs jouent sur l''appât du gain pour te pousser à baisser ta garde. Une plateforme honnête est transparente sur ce que tu peux réellement espérer gagner.</p>

<h2>Protéger ses informations personnelles</h2>
<p>Tes données personnelles ont de la valeur. Quelques précautions simples :</p>
<ul>
<li>Ne partage que les informations strictement nécessaires.</li>
<li>Méfie-toi des formulaires qui demandent trop de détails personnels.</li>
<li>Utilise une adresse e-mail dédiée pour tes inscriptions à des plateformes.</li>
<li>Lis les politiques de confidentialité pour comprendre comment tes données sont utilisées.</li>
</ul>

<h2>Que faire en cas de problème ?</h2>
<p>Si tu suspectes que ton compte a été compromis, agis vite :</p>
<ul>
<li>Change immédiatement ton mot de passe.</li>
<li>Active la 2FA si ce n''était pas déjà fait.</li>
<li>Vérifie l''activité récente de ton compte.</li>
<li>Contacte le support de la plateforme concernée.</li>
</ul>

<h2>Conclusion</h2>
<p>La sécurité en ligne n''est pas une affaire de chance, mais d''habitudes. Un mot de passe solide et unique, l''authentification à deux facteurs, une vigilance face au phishing et un bon sens face aux promesses irréalistes : avec ces réflexes, tu réduis drastiquement les risques. Prends quelques minutes aujourd''hui pour renforcer la sécurité de tes comptes, tu te remercieras plus tard.</p>'
);

-- Article 5 — Le parrainage (catégorie: astuces)
INSERT IGNORE INTO `blog_posts`
 (`slug`, `category_id`, `title`, `excerpt`, `cover_emoji`, `author_name`,
  `meta_title`, `meta_description`, `status`, `reading_minutes`, `published_at`, `body`)
VALUES (
 'parrainage-revenus-passifs-comment-ca-marche',
 (SELECT id FROM blog_categories WHERE slug='astuces'),
 'Le parrainage : la clé pour générer des revenus passifs en ligne',
 'Le parrainage est l''un des moyens les plus efficaces d''augmenter ses gains sur le long terme. On t''explique comment ça fonctionne et comment bâtir un réseau actif.',
 '👥',
 'Équipe Wintaskly',
 'Le parrainage en ligne : générer des revenus passifs (guide 2026)',
 'Comprendre le parrainage : commissions, revenus passifs, comment recruter des filleuls actifs et bâtir un réseau durable. Guide complet et conseils pratiques.',
 'published', 5, UTC_TIMESTAMP(),
 '<p>Et si une partie de tes gains pouvait être générée par d''autres personnes, sans effort supplémentaire de ta part ? C''est exactement la promesse du parrainage. Souvent sous-estimé par les débutants, c''est pourtant l''un des leviers les plus puissants pour augmenter durablement ses revenus en ligne. Voyons comment en tirer parti.</p>

<h2>Qu''est-ce que le parrainage exactement ?</h2>
<p>Le parrainage consiste à inviter de nouvelles personnes à rejoindre une plateforme grâce à ton lien personnel. Lorsqu''une personne s''inscrit via ce lien, elle devient ton "filleul". En retour, tu touches généralement une commission sur l''activité de tes filleuls.</p>
<p>Le point essentiel à comprendre : cette commission ne réduit pas les gains de ton filleul. Elle est versée en plus, par la plateforme, comme une récompense pour avoir fait grandir la communauté. C''est un système gagnant-gagnant.</p>

<h2>Pourquoi parle-t-on de revenus "passifs" ?</h2>
<p>Une fois qu''un filleul est actif, tu continues de percevoir des commissions sur son activité sans avoir à intervenir. Ton travail initial (l''inviter et l''encourager à démarrer) continue de porter ses fruits dans le temps. C''est ce qui distingue le revenu passif du revenu actif : tu n''échanges plus ton temps contre de l''argent à chaque fois.</p>
<p>Attention toutefois : "passif" ne veut pas dire "sans aucun effort". Construire un réseau de filleuls actifs demande un investissement de départ. Mais cet effort est rentabilisé sur la durée.</p>

<h2>Comment recruter des filleuls ?</h2>
<p>Recruter efficacement demande un peu de méthode. Voici les approches les plus efficaces :</p>
<ul>
<li><strong>Ton entourage</strong> : commence par les personnes qui te font confiance. Explique-leur honnêtement le fonctionnement et les bénéfices.</li>
<li><strong>Les réseaux sociaux</strong> : partage ton expérience sur tes profils. Un témoignage authentique vaut mieux qu''une publicité agressive.</li>
<li><strong>Les communautés en ligne</strong> : forums, groupes et communautés intéressés par les revenus complémentaires sont des terrains propices, à condition de respecter leurs règles.</li>
<li><strong>Le bouche-à-oreille</strong> : un filleul satisfait en parlera à son tour, créant un effet boule de neige.</li>
</ul>

<h2>Le secret : des filleuls actifs, pas juste nombreux</h2>
<p>Une erreur fréquente est de chercher à recruter un maximum de personnes sans se soucier de leur engagement. Pourtant, dix filleuls actifs rapportent bien plus que cent inscrits inactifs. La qualité prime sur la quantité.</p>
<p>Pour favoriser l''engagement de tes filleuls :</p>
<ul>
<li>Accompagne-les à leurs débuts. Réponds à leurs questions, partage tes astuces.</li>
<li>Montre l''exemple en restant toi-même actif.</li>
<li>Encourage-les sans les harceler. Le respect est la clé d''une relation durable.</li>
</ul>

<h2>Construire un réseau sur le long terme</h2>
<p>Le parrainage est un marathon, pas un sprint. Les meilleurs résultats viennent de la constance : partager régulièrement, accompagner ses filleuls, et bâtir une réputation de personne fiable et honnête. Avec le temps, ton réseau grandit et tes revenus passifs avec lui.</p>
<p>Évite les pratiques douteuses comme le spam ou les promesses mensongères : elles peuvent te nuire à long terme et ternir ta réputation. La transparence et l''authenticité sont tes meilleurs atouts.</p>

<h2>Conclusion</h2>
<p>Le parrainage est une opportunité réelle de générer des revenus complémentaires durables, à condition de l''aborder avec sérieux. Recrute intelligemment, privilégie l''engagement à la quantité, accompagne tes filleuls et inscris ton action dans la durée. Bien mené, un réseau de parrainage peut devenir l''une de tes sources de gains les plus précieuses, travaillant pour toi même quand tu te reposes.</p>'
);

-- ============================================================================
-- ANTI-FRAUDE — V8.12+
-- ============================================================================
CREATE TABLE IF NOT EXISTS `fraud_events` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NULL,
  `event_type`  VARCHAR(40) NOT NULL,
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

INSERT IGNORE INTO `config` (`k`, `v`) VALUES
 ('fraud.multiaccount_enabled',   '1'),
 ('fraud.multiaccount_max_per_ip', '3'),
 ('fraud.multiaccount_action',    'flag'),
 ('fraud.withdraw_min_account_age_hours', '24'),
 ('fraud.withdraw_require_verified_email', '1'),
 ('fraud.vpn_check_enabled',      '0'),
 ('fraud.risk_threshold_review',  '50'),
 ('fraud.risk_threshold_block',   '80');

-- ============================================================================
-- BINGO (cycle de 7 jours, jackpot évolutif) — V8.14+
-- ============================================================================
CREATE TABLE IF NOT EXISTS `bingo_rounds` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `started_on`    DATE NOT NULL,
  `max_days`      TINYINT UNSIGNED NOT NULL DEFAULT 7,
  `draw_count`    TINYINT UNSIGNED NOT NULL DEFAULT 14,
  `number_max`    TINYINT UNSIGNED NOT NULL DEFAULT 99,
  `days_drawn`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `jackpot`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `status`        ENUM('active','ending','settled') NOT NULL DEFAULT 'active',
  `end_reason`    ENUM('','max_days','claim','auto_full') NOT NULL DEFAULT '',
  `winners_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `reward_each`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `last_draw_on`  DATE NULL,
  `ending_at`     DATETIME NULL,
  `settled_at`    DATETIME NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bingo_draws` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `round_id`      INT UNSIGNED NOT NULL,
  `draw_index`    TINYINT UNSIGNED NOT NULL,
  `draw_date`     DATE NOT NULL,
  `numbers`       VARCHAR(120) NOT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_round_index` (`round_id`, `draw_index`),
  UNIQUE KEY `uniq_round_date` (`round_id`, `draw_date`),
  KEY `idx_round` (`round_id`),
  CONSTRAINT `fk_bingo_draw_round` FOREIGN KEY (`round_id`) REFERENCES `bingo_rounds`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bingo_cards` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `round_id`    INT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NOT NULL,
  `numbers`     VARCHAR(120) NOT NULL,
  `slot_index`  TINYINT UNSIGNED NOT NULL,
  `is_free`     TINYINT(1) NOT NULL DEFAULT 0,
  `status`      ENUM('locked','active','claimed','void') NOT NULL DEFAULT 'locked',
  `activated_at` DATETIME NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_round_user_slot` (`round_id`, `user_id`, `slot_index`),
  KEY `idx_round_user` (`round_id`, `user_id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_bingo_card_round` FOREIGN KEY (`round_id`) REFERENCES `bingo_rounds`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bingo_card_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bingo_card_marks` (
  `id`        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `card_id`   BIGINT UNSIGNED NOT NULL,
  `number`    TINYINT UNSIGNED NOT NULL,
  `marked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_card_number` (`card_id`, `number`),
  KEY `idx_card` (`card_id`),
  CONSTRAINT `fk_bingo_mark_card` FOREIGN KEY (`card_id`) REFERENCES `bingo_cards`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bingo_claims` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `round_id`   INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `card_id`    BIGINT UNSIGNED NOT NULL,
  `reward`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `claimed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_round_user` (`round_id`, `user_id`),
  KEY `idx_round` (`round_id`),
  CONSTRAINT `fk_bingo_claim_round` FOREIGN KEY (`round_id`) REFERENCES `bingo_rounds`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bingo_claim_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `config` (`k`, `v`) VALUES
 ('bingo.enabled','1'),('bingo.max_days','7'),('bingo.cards_per_user','5'),
 ('bingo.free_cards','1'),('bingo.card_price_coins','5000'),('bingo.draw_count','14'),
 ('bingo.number_max','99'),('bingo.jackpot_base','30000'),('bingo.jackpot_growth_pct','25'),
 ('bingo.jackpot_carryover','1'),('bingo.test_mode','1'),('bingo.coming_soon','1'),('bingo.launch_at','');
