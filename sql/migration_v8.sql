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
