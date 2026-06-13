-- =====================================================================
-- WINTASKLY — Migration V1 → V2
-- À exécuter sur une installation existante.
-- Sur une fresh install, utiliser directement `schema.sql`.
-- =====================================================================

USE `wintaskly`;

-- 1) Extension des ENUM existants -------------------------------------
ALTER TABLE `transactions`
  MODIFY `type` ENUM('faucet','shortlink','ptc','offerwall','referral','withdraw','admin','bonus') NOT NULL;

ALTER TABLE `referral_earnings`
  MODIFY `source` ENUM('faucet','shortlink','ptc','offerwall') NOT NULL;

-- 2) Nouvelles tables -------------------------------------------------

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
  KEY `idx_active` (`active`)
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
  UNIQUE KEY `uniq_ext` (`offerwall_id`,`external_tx_id`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  CONSTRAINT `fk_owtx_user` FOREIGN KEY (`user_id`)      REFERENCES `users`(`id`)       ON DELETE CASCADE,
  CONSTRAINT `fk_owtx_ow`   FOREIGN KEY (`offerwall_id`) REFERENCES `offerwalls`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip`              VARBINARY(16) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_wd_user`   FOREIGN KEY (`user_id`)      REFERENCES `users`(`id`)               ON DELETE CASCADE,
  CONSTRAINT `fk_wd_method` FOREIGN KEY (`method_id`)    REFERENCES `withdrawal_methods`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_wd_admin`  FOREIGN KEY (`processed_by`) REFERENCES `users`(`id`)               ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Seed des méthodes de retrait par défaut --------------------------
INSERT IGNORE INTO `withdrawal_methods`
  (`k`,`label`,`currency`,`coins_per_unit`,`min_coins`,`address_label`,`address_placeholder`,`sort_order`)
VALUES
  ('faucetpay',     'FaucetPay',           'USD',   10000,  10000, 'E-mail FaucetPay',     'votre.email@exemple.com', 1),
  ('payeer',        'Payeer',              'USD',   10000,  20000, 'Compte Payeer',        'P1000000000',             2),
  ('btc',           'Bitcoin (BTC)',       'BTC', 5000000,5000000, 'Adresse BTC',          'bc1q...',                 3),
  ('ltc',           'Litecoin (LTC)',      'LTC',   50000,  50000, 'Adresse LTC',          'ltc1q...',                4);

INSERT IGNORE INTO `ad_zones` (`k`,`label`,`code`,`active`) VALUES
 ('ptc_chrono_top', 'PTC — Chrono haut',         '<!-- Insérer le code AdSense responsive -->', 1),
 ('offerwall_top',  'Offerwalls — Bandeau haut', '<!-- Insérer le code AdSense responsive -->', 1);
