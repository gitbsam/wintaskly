-- ============================================================================
-- Wintaskly — Migration : Système BINGO (cycle de 7 jours)
-- ============================================================================
-- Bingo à cycle : une PARTIE dure jusqu'à 7 jours, avec 1 tirage par jour.
-- Les numéros s'accumulent sur le cycle. Jackpot évolutif (+25%/carton payant).
--
-- Mécanique :
--   - 1 partie (round) = cycle de 7 jours max, 14 numéros tirés/jour
--   - Les numéros s'accumulent (jusqu'à 98 sur la plage 1-99)
--   - Chaque joueur reçoit 5 cartons à l'ouverture (1 gratuit + payants),
--     valables tout le cycle. 25 numéros uniques par carton.
--   - Pour gagner : valider manuellement les 25 numéros ET réclamer avant
--     le minuit de fin. Avoir 25/25 tirés sans réclamer = pas gagnant.
--   - Fin de partie si : 7 tirages faits, OU 1ère réclamation, OU détection
--     auto d'un carton 25/25 tiré. Vérification + distribution à minuit.
--   - Jackpot partagé entre gagnants ; report si aucun gagnant.
--
-- Tables :
--   bingo_rounds      : parties (cycles), jackpot, état, raison de fin
--   bingo_draws       : tirages individuels (1 par jour de cycle)
--   bingo_cards       : cartons des joueurs (valables tout le cycle)
--   bingo_card_marks  : numéros validés manuellement
--   bingo_claims      : réclamations de cartons pleins
--
-- À exécuter UNE FOIS dans phpMyAdmin pour une install existante.
-- ============================================================================

-- 1) Parties (cycles de 7 jours)
CREATE TABLE IF NOT EXISTS `bingo_rounds` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `started_on`    DATE NOT NULL COMMENT 'Jour d''ouverture du cycle (UTC)',
  `max_days`      TINYINT UNSIGNED NOT NULL DEFAULT 7 COMMENT 'Durée max du cycle',
  `draw_count`    TINYINT UNSIGNED NOT NULL DEFAULT 14 COMMENT 'Numéros tirés par jour',
  `number_max`    TINYINT UNSIGNED NOT NULL DEFAULT 99 COMMENT 'Plage haute (1..max)',
  `days_drawn`    TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Nombre de tirages déjà effectués',
  `jackpot`       BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Cagnotte en coins',
  `status`        ENUM('active','ending','settled') NOT NULL DEFAULT 'active'
                  COMMENT 'active=en cours, ending=fin déclenchée (attend minuit), settled=réglée',
  `end_reason`    ENUM('','max_days','claim','auto_full') NOT NULL DEFAULT ''
                  COMMENT 'Pourquoi la partie se termine',
  `winners_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `reward_each`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `last_draw_on`  DATE NULL COMMENT 'Date du dernier tirage effectué',
  `ending_at`     DATETIME NULL COMMENT 'Quand la fin a été déclenchée',
  `settled_at`    DATETIME NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Tirages individuels (1 par jour de cycle)
CREATE TABLE IF NOT EXISTS `bingo_draws` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `round_id`      INT UNSIGNED NOT NULL,
  `draw_index`    TINYINT UNSIGNED NOT NULL COMMENT 'Numéro du tirage dans le cycle (1..7)',
  `draw_date`     DATE NOT NULL COMMENT 'Jour du tirage (UTC)',
  `numbers`       VARCHAR(120) NOT NULL COMMENT 'Numéros tirés ce jour, CSV ordonné',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_round_index` (`round_id`, `draw_index`),
  UNIQUE KEY `uniq_round_date` (`round_id`, `draw_date`),
  KEY `idx_round` (`round_id`),
  CONSTRAINT `fk_bingo_draw_round`
    FOREIGN KEY (`round_id`) REFERENCES `bingo_rounds`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Cartons des joueurs (valables tout le cycle)
CREATE TABLE IF NOT EXISTS `bingo_cards` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `round_id`    INT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NOT NULL,
  `numbers`     VARCHAR(120) NOT NULL COMMENT '25 numéros uniques CSV',
  `slot_index`  TINYINT UNSIGNED NOT NULL COMMENT 'Position (0..N-1) pour ce user/cycle',
  `is_free`     TINYINT(1) NOT NULL DEFAULT 0,
  `status`      ENUM('locked','active','claimed','void') NOT NULL DEFAULT 'locked',
  `activated_at` DATETIME NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_round_user_slot` (`round_id`, `user_id`, `slot_index`),
  KEY `idx_round_user` (`round_id`, `user_id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_bingo_card_round`
    FOREIGN KEY (`round_id`) REFERENCES `bingo_rounds`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bingo_card_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) Numéros validés manuellement
CREATE TABLE IF NOT EXISTS `bingo_card_marks` (
  `id`        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `card_id`   BIGINT UNSIGNED NOT NULL,
  `number`    TINYINT UNSIGNED NOT NULL,
  `marked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_card_number` (`card_id`, `number`),
  KEY `idx_card` (`card_id`),
  CONSTRAINT `fk_bingo_mark_card`
    FOREIGN KEY (`card_id`) REFERENCES `bingo_cards`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5) Réclamations (1 par user et par cycle)
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
  CONSTRAINT `fk_bingo_claim_round`
    FOREIGN KEY (`round_id`) REFERENCES `bingo_rounds`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bingo_claim_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6) Configuration bingo (tout réglable via /admin)
INSERT IGNORE INTO `config` (`k`, `v`) VALUES
 ('bingo.enabled',            '1'),
 ('bingo.max_days',           '7'),     -- durée max d'un cycle (jours/tirages)
 ('bingo.cards_per_user',     '5'),     -- cartons distribués par joueur/cycle
 ('bingo.free_cards',         '1'),     -- combien sont gratuits
 ('bingo.card_price_coins',   '5000'),  -- prix d'un carton payant en COINS
 ('bingo.draw_count',         '14'),    -- numéros tirés par jour
 ('bingo.number_max',         '99'),    -- plage haute (1..max)
 ('bingo.jackpot_base',       '30000'), -- jackpot de départ
 ('bingo.jackpot_growth_pct', '25'),    -- % ajouté par carton payant
 ('bingo.jackpot_carryover',  '1'),     -- 1 = report si pas de gagnant
 ('bingo.test_mode',          '1'),     -- 1 = visible uniquement par les admins
 ('bingo.coming_soon',        '1'),     -- 1 = affiche le teaser "bientôt" dans la liste des tâches
 ('bingo.launch_at',          '');      -- date ISO de lancement public (vide = pas de compte à rebours)

-- 7) Étend l'enum des transactions pour les opérations bingo
ALTER TABLE `transactions`
  MODIFY COLUMN `type`
  ENUM('faucet','shortlink','ptc','offerwall','referral','withdraw','admin','bonus','daily_bonus','achievement','bingo_buy','bingo_win')
  NOT NULL;

-- Enregistre la migration
INSERT IGNORE INTO `applied_migrations` (`filename`, `version`, `notes`) VALUES
 ('migration_bingo.sql', '8.14.0', 'Système Bingo cycle 7 jours avec jackpot évolutif');
