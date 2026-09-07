-- =====================================================================
-- Wintaskly — migration 9.30.0 -> 9.58.x
--
-- Rassemble TOUS les changements de schéma de ce cycle. Chaque
-- instruction est idempotente : le fichier peut être rejoué sans
-- risque, et n'a aucun effet sur une base déjà à jour.
--
-- À appliquer depuis /admin/migrations.php, section « Migrations »,
-- OU par import direct. Il remplace le réimport complet de
-- schema.sql : moins de surface, donc moins de risque.
--
-- SAUVEGARDEZ LA BASE AVANT. Ce fichier crée des tables et modifie
-- des colonnes ; aucune de ces opérations ne s'annule.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1) Raccourcisseur maison : configuration des campagnes
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `shortlinks_local` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`          VARCHAR(120) NOT NULL,
  `is_local`       TINYINT(1)   NOT NULL DEFAULT 1
                   COMMENT '1 = raccourcisseur Wintaskly, 0 = prestataire externe',
  `api_key`        CHAR(32)     NOT NULL,
  `api_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `steps_count`    SMALLINT UNSIGNED NOT NULL DEFAULT 3
                   COMMENT 'SMALLINT et non TINYINT : jusqu a 1000 etapes',
  `step_seconds`   SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  `final_seconds`  SMALLINT UNSIGNED NOT NULL DEFAULT 20,
  `run_minutes`    SMALLINT UNSIGNED NOT NULL DEFAULT 30
                   COMMENT 'Delai avant abandon d un parcours commence',
  `rate_amount`    DECIMAL(10,4) NOT NULL DEFAULT 0
                   COMMENT 'Recette pour rate_per_views participations',
  `rate_currency`  CHAR(3)      NOT NULL DEFAULT 'EUR',
  `rate_per_views` INT UNSIGNED NOT NULL DEFAULT 1000,
  `content_type`   ENUM('blog','url') NOT NULL DEFAULT 'blog',
  `content_ref`    VARCHAR(255) NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_api_key` (`api_key`),
  KEY `idx_active` (`api_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 2) Raccourcisseur maison : parcours individuels
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `shortlink_local_runs` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `local_id`        INT UNSIGNED NOT NULL,
  `code`            VARBINARY(10) NOT NULL
                    COMMENT 'VARBINARY : sensible a la casse, sinon aB3 = Ab3',
  `user_id`         INT UNSIGNED NULL
                    COMMENT 'Fixe a la premiere ouverture, puis impose',
  `destination`     TEXT NOT NULL
                    COMMENT 'URL de rappel recue. N est jamais emise avant la fin',
  `step`            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `step_token`      CHAR(32) NOT NULL,
  `step_started_at` DATETIME NULL,
  `expires_at`      DATETIME NOT NULL,
  `status`          ENUM('en_cours','termine','expire','rejete')
                    NOT NULL DEFAULT 'en_cours',
  `ip`              VARBINARY(16) NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at`    DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_code` (`code`),
  KEY `idx_local`  (`local_id`),
  KEY `idx_user`   (`user_id`, `status`),
  KEY `idx_expiry` (`status`, `expires_at`),
  CONSTRAINT `fk_slr_local` FOREIGN KEY (`local_id`)
    REFERENCES `shortlinks_local`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 3) Registre des recettes prestataires
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `revenue_entries` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source_kind`    ENUM('shortlink','offerwall','ptc','ads','other')
                   NOT NULL DEFAULT 'shortlink',
  `source_id`      INT UNSIGNED NULL
                   COMMENT 'Ligne shortlinks/offerwalls concernee, NULL si global',
  `provider`       VARCHAR(60) NOT NULL
                   COMMENT 'Nom libre du prestataire, toujours renseigne',
  `period_start`   DATE NOT NULL,
  `period_end`     DATE NOT NULL,
  -- 8 decimales, pas 4 : ces montants peuvent etre libelles en crypto.
  -- 0.00007277 BTC arrondi a 4 decimales devient 0.0001, soit 37 % d'ecart.
  -- La colonne received_eur, elle, reste en 4 decimales : l'euro n'en a
  -- pas besoin de plus.
  `declared_amount` DECIMAL(18,8) NOT NULL DEFAULT 0,
  `received_amount` DECIMAL(18,8) NOT NULL DEFAULT 0,
  `currency`       CHAR(3) NOT NULL DEFAULT 'USD',
  `eur_rate`       DECIMAL(16,8) NOT NULL DEFAULT 1
                   COMMENT 'Valeur d une unite en EUR, figee a la saisie',
  `received_eur`   DECIMAL(12,4) NOT NULL DEFAULT 0
                   COMMENT 'received_amount x eur_rate, fige',
  `status`         ENUM('attendu','recu','impaye') NOT NULL DEFAULT 'attendu',
  `paid_at`        DATE NULL,
  `reference`      VARCHAR(120) NULL COMMENT 'Reference du versement',
  `notes`          TEXT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                   ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_period` (`period_start`, `period_end`),
  KEY `idx_source` (`source_kind`, `source_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 4) Montants de recettes en 8 décimales
--
-- Une table créée avant cette correction tronquerait 0.00007277 BTC à
-- 0.0001, soit 37 % d'écart. L'élargissement ne récupère pas ce qui a
-- déjà été tronqué : ressaisissez les lignes crypto antérieures.
-- ---------------------------------------------------------------------
SET @p := (SELECT numeric_scale FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'revenue_entries'
              AND column_name = 'received_amount');
SET @s := IF(@p IS NOT NULL AND @p < 8,
  'ALTER TABLE `revenue_entries`
     MODIFY `declared_amount` DECIMAL(18,8) NOT NULL DEFAULT 0,
     MODIFY `received_amount` DECIMAL(18,8) NOT NULL DEFAULT 0',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------
-- 5) Suivi du dernier alignement automatique des cours
-- ---------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'withdrawal_methods'
              AND column_name = 'rate_updated_at');
SET @s := IF(@c = 0,
  'ALTER TABLE `withdrawal_methods` ADD COLUMN `rate_updated_at` DATETIME NULL',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------
-- 6) Zone publicitaire de l'encart flottant des pages de tâches
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `ad_zones` (`k`,`label`,`code`,`size_key`,`active`) VALUES
 ('tasks_overlay', 'Tâches — Encart flottant (10 s)', '<!-- Insérer ici le code de la régie -->', '300x250', 1);

-- ---------------------------------------------------------------------
-- 7) Format de repli des zones publicitaires
--
-- Sans size_key, wt_ad_zone() ne peut afficher aucun visuel de repli :
-- c'est ce qui laissait l'accueil et le blog vides alors que les pages
-- de tâches affichaient bien leurs annonces.
-- ---------------------------------------------------------------------
UPDATE `ad_zones` SET `size_key` = '300x250'
 WHERE `size_key` IS NULL
   AND (`k` LIKE '%sidebar%' OR `k` LIKE '%_center' OR `k` = 'shortlink_gateway');
UPDATE `ad_zones` SET `size_key` = '728x90'
 WHERE `size_key` IS NULL;

-- ---------------------------------------------------------------------
-- 8) Méthodes de retrait en euro
--
-- Elles ne dépendent d'aucun cours : si le flux de cotation tombe, elles
-- restent le seul moyen d'être payé. Inactives tant que vous ne les
-- activez pas dans /admin/payment_methods.php.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `withdrawal_methods`
  (`k`,`label`,`currency`,`coins_per_unit`,`min_coins`,`address_label`,`address_placeholder`,`sort_order`)
VALUES
  ('paypal', 'PayPal',        'EUR', 10000,  30000, 'E-mail PayPal', 'vous@exemple.com', 5),
  ('sepa',   'Virement SEPA', 'EUR', 10000, 200000, 'IBAN',          'FR76 ...',         6);
