-- =====================================================================
-- WINTASKLY — Migration V4 → V5
-- Classement mensuel : cache live (Top 10), historique archivé,
-- barème de récompenses paramétrable.
-- Fresh installs : schema.sql couvre déjà tout.
-- =====================================================================

USE `wintaskly`;

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

INSERT INTO `config` (`k`, `v`) VALUES
  ('leaderboard.cache_minutes',     '15'),
  ('leaderboard.rewards_enabled',   '1'),
  ('leaderboard.reward_coins_1',   '1000'),
  ('leaderboard.reward_coins_2',    '600'),
  ('leaderboard.reward_coins_3',    '400'),
  ('leaderboard.reward_coins_4',    '250'),
  ('leaderboard.reward_coins_5',    '200'),
  ('leaderboard.reward_coins_6',    '150'),
  ('leaderboard.reward_coins_7',    '120'),
  ('leaderboard.reward_coins_8',    '100'),
  ('leaderboard.reward_coins_9',     '80'),
  ('leaderboard.reward_coins_10',    '60'),
  ('leaderboard.reward_xp_1',       '500'),
  ('leaderboard.reward_xp_2',       '300'),
  ('leaderboard.reward_xp_3',       '200'),
  ('leaderboard.reward_xp_4',       '100'),
  ('leaderboard.reward_xp_5',        '80'),
  ('leaderboard.reward_xp_6',        '60'),
  ('leaderboard.reward_xp_7',        '50'),
  ('leaderboard.reward_xp_8',        '40'),
  ('leaderboard.reward_xp_9',        '30'),
  ('leaderboard.reward_xp_10',       '20'),
  ('leaderboard.last_archived_period', '')
ON DUPLICATE KEY UPDATE v = VALUES(v);
