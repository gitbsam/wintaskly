-- ============================================================================
-- Wintaskly — Migration : Bonus quotidien (Daily Bonus / Streak)
-- ============================================================================
-- Système de récompense de connexion quotidienne avec streak (série de
-- jours consécutifs). 100% configurable via /admin/daily-bonus.php.
--
-- Crée :
--   1. Table `daily_bonus_claims` : historique des réclamations
--   2. Colonnes sur `users` : streak courant + date dernier claim
--   3. Table `daily_bonus_tiers` : paliers configurables (jour → récompense)
--   4. Configs : fenêtre de réclamation, activation
--
-- À exécuter UNE FOIS dans phpMyAdmin pour une install existante.
-- Sur une install neuve, schema.sql contient déjà tout.
-- ============================================================================

-- 1) Historique des réclamations de bonus quotidien
CREATE TABLE IF NOT EXISTS `daily_bonus_claims` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `streak_day`    SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Jour du streak au moment du claim',
  `coins_awarded` DECIMAL(18,4) NOT NULL DEFAULT 0,
  `xp_awarded`    INT UNSIGNED NOT NULL DEFAULT 0,
  `ip`            VARBINARY(16) NULL,
  `claimed_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `next_claim_at` DATETIME NOT NULL COMMENT 'Date à partir de laquelle le prochain claim est possible',
  PRIMARY KEY (`id`),
  KEY `idx_user_claimed` (`user_id`, `claimed_at`),
  KEY `idx_user_next` (`user_id`, `next_claim_at`),
  CONSTRAINT `fk_daily_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Colonnes de suivi du streak sur users
--    (ALTER séparés pour compat : si une colonne existe déjà, ignore l'erreur)
ALTER TABLE `users` ADD COLUMN `daily_streak` SMALLINT UNSIGNED NOT NULL DEFAULT 0
  COMMENT 'Nombre de jours consécutifs de réclamation';
ALTER TABLE `users` ADD COLUMN `daily_last_claim_at` DATETIME NULL
  COMMENT 'Date du dernier bonus quotidien réclamé';

-- 2bis) Ajoute le type 'daily_bonus' à l'enum des transactions
--       (pour tracer les bonus quotidiens distinctement des autres bonus)
ALTER TABLE `transactions`
  MODIFY COLUMN `type` ENUM('faucet','shortlink','ptc','offerwall','referral','withdraw','admin','bonus','daily_bonus') NOT NULL;

-- 3) Paliers de récompense configurables (jour du streak → coins + xp)
CREATE TABLE IF NOT EXISTS `daily_bonus_tiers` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `streak_day`   SMALLINT UNSIGNED NOT NULL COMMENT 'Numéro du jour dans le cycle (1, 2, 3...)',
  `coins`        DECIMAL(18,4) NOT NULL DEFAULT 0,
  `xp`           INT UNSIGNED NOT NULL DEFAULT 0,
  `label`        VARCHAR(60) NULL COMMENT 'Libellé optionnel (ex: Jackpot)',
  `is_jackpot`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Mise en avant visuelle',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_streak_day` (`streak_day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Paliers par défaut : cycle de 7 jours (J7 = jackpot)
INSERT IGNORE INTO `daily_bonus_tiers` (`streak_day`, `coins`, `xp`, `label`, `is_jackpot`) VALUES
 (1, 10,  5,  NULL,      0),
 (2, 15,  5,  NULL,      0),
 (3, 20,  10, NULL,      0),
 (4, 25,  10, NULL,      0),
 (5, 30,  15, NULL,      0),
 (6, 40,  15, NULL,      0),
 (7, 100, 50, 'Jackpot', 1);

-- 4) Configuration du système
INSERT IGNORE INTO `config` (`k`, `v`) VALUES
 ('daily_bonus.enabled',        '1'),
 ('daily_bonus.window_hours',   '24'),
 ('daily_bonus.reset_hours',    '48'),
 ('daily_bonus.cycle_mode',     'repeat');

-- Enregistre la migration
INSERT IGNORE INTO `applied_migrations` (`filename`, `version`, `notes`) VALUES
 ('migration_daily_bonus.sql', '8.9.0', 'Système de bonus quotidien avec streak configurable');
