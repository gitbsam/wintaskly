-- ============================================================================
-- Wintaskly — Migration : Achievements (badges / succès) — V8.10.0
-- ============================================================================
-- À exécuter UNE SEULE FOIS dans phpMyAdmin sur une installation EXISTANTE.
-- (Sur une installation neuve, schema.sql contient déjà tout ceci.)
--
-- Cette migration est IDEMPOTENTE autant que possible :
--   - CREATE TABLE IF NOT EXISTS : pas d'erreur si la table existe déjà.
--   - INSERT IGNORE : pas de doublon sur les achievements par défaut.
--   - Les ALTER (enum, index) peuvent renvoyer une erreur "Duplicate"
--     si déjà appliqués : c'est SANS DANGER, ignore-la.
--
-- Contenu :
--   1. Table `achievements`        (définitions configurables)
--   2. Table `user_achievements`   (badges débloqués, UNIQUE user+ach)
--   3. ENUM `transactions.type`    (+ valeur 'achievement')
--   4. Index couvrant              (user_id, coins) pour total_coins_earned
--   5. Achievements par défaut + config
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1) Définitions des achievements (gérées via /admin/achievements.php)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `achievements` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `k`            VARCHAR(60)  NOT NULL COMMENT 'Clé unique (ex: faucet_10)',
  `metric`       VARCHAR(40)  NOT NULL COMMENT 'Métrique mesurée (liste blanche PHP)',
  `threshold`    DECIMAL(18,4) NOT NULL DEFAULT 1 COMMENT 'Valeur à atteindre',
  `tier`         ENUM('bronze','silver','gold','platinum','special') NOT NULL DEFAULT 'bronze',
  `title`        VARCHAR(120) NOT NULL,
  `description`  VARCHAR(255) NULL,
  `icon`         VARCHAR(20)  NULL COMMENT 'Emoji du badge',
  `reward_coins` DECIMAL(18,4) NOT NULL DEFAULT 0,
  `reward_xp`    INT UNSIGNED NOT NULL DEFAULT 0,
  `sort_order`   INT NOT NULL DEFAULT 0,
  `active`       TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_k` (`k`),
  KEY `idx_metric` (`metric`),
  KEY `idx_active_sort` (`active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 2) Achievements débloqués par utilisateur
--    La contrainte UNIQUE (user_id, achievement_id) est la garantie
--    ATOMIQUE anti-double-déblocage : couplée à INSERT IGNORE, deux
--    requêtes concurrentes ne peuvent jamais créditer deux fois.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_achievements` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED NOT NULL,
  `achievement_id` INT UNSIGNED NOT NULL,
  `unlocked_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `claimed`        TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Récompense créditée',
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

-- ----------------------------------------------------------------------------
-- 3) Ajoute la valeur 'achievement' à l'ENUM des transactions
--    (pour tracer les récompenses de badge distinctement).
--    ⚠ Si déjà présent, MySQL renvoie une erreur ignorable.
-- ----------------------------------------------------------------------------
ALTER TABLE `transactions`
  MODIFY COLUMN `type`
  ENUM('faucet','shortlink','ptc','offerwall','referral','withdraw','admin','bonus','daily_bonus','achievement')
  NOT NULL;

-- ----------------------------------------------------------------------------
-- 4) Index COUVRANT (user_id, coins) sur transactions
--    Optimise la métrique total_coins_earned :
--      SELECT SUM(coins) FROM transactions WHERE user_id = ? AND coins > 0
--    Avec cet index, MySQL résout le SUM entièrement dans l'index
--    (covering index) sans lire les lignes de la table → scan rapide
--    même quand un user a des milliers de transactions.
--    ⚠ Si déjà présent, MySQL renvoie une erreur ignorable.
-- ----------------------------------------------------------------------------
ALTER TABLE `transactions` ADD INDEX `idx_user_coins` (`user_id`, `coins`);

-- ----------------------------------------------------------------------------
-- 5) Achievements par défaut + activation
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `achievements`
  (`k`, `metric`, `threshold`, `tier`, `title`, `description`, `icon`, `reward_coins`, `reward_xp`, `sort_order`) VALUES
  ('faucet_10',     'faucet_claims',      10,    'bronze',  'Goutte à goutte',  'Réclame 10 fois le faucet',     '💧', 20,   10,  10),
  ('faucet_100',    'faucet_claims',      100,   'silver',  'Robinet ouvert',   'Réclame 100 fois le faucet',    '🚰', 100,  50,  11),
  ('faucet_1000',   'faucet_claims',      1000,  'gold',    'Cascade',          'Réclame 1000 fois le faucet',   '🌊', 1000, 250, 12),
  ('shortlink_10',  'shortlinks_done',    10,    'bronze',  'Premiers liens',   'Complète 10 shortlinks',        '🔗', 25,   10,  20),
  ('shortlink_100', 'shortlinks_done',    100,   'silver',  'Chaîne solide',    'Complète 100 shortlinks',       '⛓️', 120,  60,  21),
  ('shortlink_500', 'shortlinks_done',    500,   'gold',    'Maître des liens', 'Complète 500 shortlinks',       '🏅', 600,  200, 22),
  ('streak_3',      'daily_streak',       3,     'bronze',  'Habitué',          'Atteins une série de 3 jours',  '🔥', 30,   15,  30),
  ('streak_7',      'daily_streak',       7,     'silver',  'Assidu',           'Atteins une série de 7 jours',  '🔥', 100,  50,  31),
  ('streak_30',     'daily_streak',       30,    'gold',    'Inarrêtable',      'Atteins une série de 30 jours', '⚡', 500,  300, 32),
  ('referral_1',    'referrals',          1,     'bronze',  'Recruteur',        'Parraine ton premier ami',      '👤', 50,   20,  40),
  ('referral_5',    'referrals',          5,     'silver',  'Ambassadeur',      'Parraine 5 amis',               '👥', 250,  100, 41),
  ('referral_20',   'referrals',          20,    'gold',    'Influenceur',      'Parraine 20 amis',              '🌟', 1000, 400, 42),
  ('coins_1k',      'total_coins_earned', 1000,  'bronze',  'Premier magot',    'Gagne 1 000 coins au total',    '💰', 50,   25,  50),
  ('coins_10k',     'total_coins_earned', 10000, 'silver',  'Petite fortune',   'Gagne 10 000 coins au total',   '💎', 300,  150, 51),
  ('first_payout',  'withdrawals_done',   1,     'special', 'Premier retrait',  'Effectue ton premier retrait',  '🏆', 100,  50,  60),
  ('level_10',      'level',              10,    'silver',  'Vétéran',          'Atteins le niveau 10',          '🎖️', 150,  0,   70),
  ('veteran_30',    'account_age_days',   30,    'special', 'Fidèle',           'Compte actif depuis 30 jours',  '📅', 100,  50,  80);

INSERT IGNORE INTO `config` (`k`, `v`) VALUES
 ('achievements.enabled', '1');

INSERT IGNORE INTO `applied_migrations` (`filename`, `version`, `notes`) VALUES
 ('migration_achievements.sql', '8.10.0', 'Achievements + index couvrant idx_user_coins');
