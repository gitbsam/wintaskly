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
-- MODIFY est idempotent par nature : reappliquer la meme definition ne
-- change rien. Aucun test prealable n'est donc necessaire, ce qui evite
-- information_schema — inaccessible a l'utilisateur de la base sur un
-- hebergement mutualise (#1044).
ALTER TABLE `revenue_entries`
  MODIFY `declared_amount` DECIMAL(18,8) NOT NULL DEFAULT 0,
  MODIFY `received_amount` DECIMAL(18,8) NOT NULL DEFAULT 0;

-- ---------------------------------------------------------------------
-- 5) Suivi du dernier alignement automatique des cours
-- ---------------------------------------------------------------------
ALTER TABLE `withdrawal_methods`
  ADD COLUMN IF NOT EXISTS `rate_updated_at` DATETIME NULL;

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

-- ---------------------------------------------------------------------
-- 9) Nouvelles zones publicitaires (V9.59)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `ad_zones` (`k`,`label`,`code`,`size_key`,`active`) VALUES
 ('achievements_inline', 'Succès — Intercalée (toutes les 6 cartes)', '<!-- Insérer ici le code de la régie -->', '300x250', 1),
 ('bingo_bottom',        'Bingo — Bas de page',                       '<!-- Insérer ici le code de la régie -->', '728x90',  1),
 ('offerwall_top',       'Offerwall — Haut de page',                  '<!-- Insérer ici le code de la régie -->', '728x90',  1),
 ('offerwall_bottom',    'Offerwall — Bas de page',                   '<!-- Insérer ici le code de la régie -->', '728x90',  1),
 ('offerwall_side',      'Offerwall — Colonne latérale (grands écrans)','<!-- Insérer ici le code de la régie -->', '160x600', 1);

-- ---------------------------------------------------------------------
-- 10) Rappel
--
-- Si les fichiers de contenu ont ete importes AVANT la version 9.59.2,
-- les articles sont ranges dans la mauvaise categorie : appliquez
-- sql/migration_reparer_categories_blog.sql, puis reimportez
-- seed_blog_actualites_brouillon.sql.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
-- 11) Zones de la page d'accueil (V9.60)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `ad_zones` (`k`,`label`,`code`,`size_key`,`active`) VALUES
 ('home_blog_inline', 'Accueil — Dans les derniers articles (3e place)', '<!-- Insérer ici le code de la régie -->', '300x250', 1),
 ('home_how_bottom',  'Accueil — Sous « Comment ça marche »',            '<!-- Insérer ici le code de la régie -->', '728x90',  1);

-- ---------------------------------------------------------------------
-- 12) Plafonds quotidiens des liens sponsorises (V9.63)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `config` (`k`,`v`) VALUES
 ('shortlink.max_daily_coins','0'),
 ('shortlink.max_daily_per_link','0');

-- ---------------------------------------------------------------------
-- 13) Instant Gagnant (V9.66)
-- ---------------------------------------------------------------------
ALTER TABLE `transactions`
  MODIFY `type` ENUM('faucet','shortlink','ptc','offerwall','referral','withdraw','admin','bonus','daily_bonus','achievement','bingo_buy','bingo_win','instant','instant_ticket','gift','quiz') NOT NULL;

-- ---------------------------------------------------------------------
-- INSTANT GAGNANT (V9.66)
--
-- Deux voies d'acces, choisies par jeu :
--   'ads'     : l'utilisateur suit un parcours publicitaire, comme pour
--               un shortlink, et le gain se debloque au retour. Aucun
--               clic n'est exige : les regies interdisent les clics
--               incites et fermeraient le compte.
--   'tickets' : l'utilisateur mise des tickets. Les tickets GAGNES ne
--               posent aucun probleme. Les tickets ACHETES font entrer
--               le jeu dans le champ des jeux d'argent (mise + hasard +
--               gain) : la vente reste donc desactivee par defaut et
--               protegee par un reglage distinct.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `instant_games` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(120) NOT NULL,
  `mode`           ENUM('ads','tickets') NOT NULL DEFAULT 'ads',
  `reward_coins`   DECIMAL(18,4) NOT NULL DEFAULT 0
                   COMMENT 'Gain en coins si la partie est gagnante',
  -- Compteur PARTAGE entre tous les joueurs.
  --
  -- Chaque ticket mise le decremente de 1. Celui qui l'amene a zero
  -- remporte le gain, et le compteur repart a parts_total.
  --
  -- Le hasard ne vient pas d'un tirage mais de l'etat du compteur au
  -- moment ou l'on joue : il depend des parties des autres, donc
  -- imprevisible pour chacun. C'est cette imprevisibilite qui garde le
  -- jeu dans le champ des jeux de hasard, et qui impose que la vente de
  -- tickets reste desactivee.
  `parts_total`    SMALLINT UNSIGNED NOT NULL DEFAULT 7
                   COMMENT 'Parties necessaires pour remporter le gain',
  `parts_left`     SMALLINT UNSIGNED NOT NULL DEFAULT 7
                   COMMENT 'Parties restantes, partagees entre tous',
  `plays_total`    INT UNSIGNED NOT NULL DEFAULT 0,
  `wins_total`     INT UNSIGNED NOT NULL DEFAULT 0,
  `ticket_options` VARCHAR(120) NOT NULL DEFAULT '1,2,5,10'
                   COMMENT 'Mises proposees, separees par des virgules',
  `cooldown_hours` SMALLINT UNSIGNED NOT NULL DEFAULT 24,
  `daily_max`      SMALLINT UNSIGNED NOT NULL DEFAULT 1
                   COMMENT 'Parties par jour et par utilisateur. 0 = illimite',
  `active`         TINYINT(1) NOT NULL DEFAULT 0,
  `test_mode`      TINYINT(1) NOT NULL DEFAULT 1
                   COMMENT '1 = visible des seuls administrateurs',
  `launch_at`      DATETIME NULL COMMENT 'Date de mise en ligne, NULL = aucune',
  `sort_order`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_active` (`active`, `test_mode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Solde de tickets. Volontairement separe de users.coins : un ticket
-- n'est pas une monnaie, il ne se convertit pas en euros et ne se
-- retire pas. Les melanger rendrait cette distinction impossible a
-- tenir, et c'est elle qui separe un jeu promotionnel d'une loterie.
CREATE TABLE IF NOT EXISTS `user_tickets` (
  `user_id`    INT UNSIGNED NOT NULL,
  `balance`    INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_ut_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Journal des mouvements de tickets. Sans lui, impossible de prouver
-- l'origine d'un ticket — or c'est exactement ce qui distingue un
-- ticket gagne d'un ticket achete en cas de controle.
CREATE TABLE IF NOT EXISTS `ticket_ledger` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `delta`      INT NOT NULL COMMENT 'Positif = credit, negatif = mise',
  `origin`     ENUM('task','bonus','referral','admin','purchase','play','refund')
               NOT NULL DEFAULT 'admin',
  `note`       VARCHAR(190) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`, `created_at`),
  KEY `idx_origin` (`origin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Une partie jouee. Conserve le resultat ET la mise : un litige sur un
-- gain ne se tranche pas sans trace horodatee.
CREATE TABLE IF NOT EXISTS `instant_plays` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `game_id`      INT UNSIGNED NOT NULL,
  `user_id`      INT UNSIGNED NOT NULL,
  `mode`         ENUM('ads','tickets') NOT NULL,
  `tickets_used` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `won`          TINYINT(1) NOT NULL DEFAULT 0,
  `coins_won`    DECIMAL(18,4) NOT NULL DEFAULT 0,
  `token`        CHAR(64) NULL COMMENT 'Jeton du parcours publicitaire',
  `status`       ENUM('en_attente','valide','rejete','expire')
                 NOT NULL DEFAULT 'valide',
  `ip`           VARBINARY(16) NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token` (`token`),
  KEY `idx_user_day` (`user_id`, `created_at`),
  KEY `idx_game` (`game_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rattrapage : une base creee avant la V9.68 porte `win_permille` et
-- n'a pas le compteur partage. CREATE TABLE IF NOT EXISTS ne corrige
-- rien sur une table existante : il faut l'ALTER explicitement.
--
-- IF NOT EXISTS plutot qu'un test sur information_schema : celui-ci est
-- refuse a l'utilisateur de la base sur un hebergement mutualise.
ALTER TABLE `instant_games`
  ADD COLUMN IF NOT EXISTS `parts_total` SMALLINT UNSIGNED NOT NULL DEFAULT 7,
  ADD COLUMN IF NOT EXISTS `parts_left`  SMALLINT UNSIGNED NOT NULL DEFAULT 7,
  ADD COLUMN IF NOT EXISTS `plays_total` INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `wins_total`  INT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE `instant_games` DROP COLUMN IF EXISTS `win_permille`;

ALTER TABLE `instant_plays`
  ADD COLUMN IF NOT EXISTS `parts_left` SMALLINT UNSIGNED NOT NULL DEFAULT 0;


-- Reglages globaux.
-- instant.tickets_purchase_enabled reste a 0 : l'activer fait entrer le
-- jeu dans le champ des jeux d'argent et suppose une autorisation.
INSERT IGNORE INTO `config` (`k`,`v`) VALUES
 ('instant.enabled','1'),
 ('instant.ticket_label','Ticket'),
 ('instant.tickets_purchase_enabled','0'),
 ('instant.ads_seconds','45');


-- ---------------------------------------------------------------------
-- 14) Boite a cadeaux (V9.68)
-- ---------------------------------------------------------------------
-- ---------------------------------------------------------------------
-- BOITE A CADEAUX (V9.68)
--
-- Une PARTIE est une grille de N boites, toutes gagnantes. Ouvrir une
-- boite demande de suivre un parcours publicitaire ; le lot se revele
-- au retour.
--
-- Les lots sont tires A LA CREATION de la partie, pas a l'ouverture.
-- Trois raisons : l'enveloppe est alors exacte au coin pres, un lot
-- exceptionnel ne peut pas sortir deux fois, et en cas de litige on
-- peut montrer que la grille etait figee avant le premier clic.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `gift_rounds` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `boxes_count`   SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  `envelope`      DECIMAL(18,4) NOT NULL DEFAULT 0
                  COMMENT 'Total des coins places dans la grille',
  `special_kind`  ENUM('none','big','jackpot') NOT NULL DEFAULT 'none'
                  COMMENT 'Lot exceptionnel de CETTE partie. Jamais les deux.',
  `special_value` DECIMAL(18,4) NOT NULL DEFAULT 0,
  `status`        ENUM('active','done') NOT NULL DEFAULT 'active',
  `opened_count`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `closed_at`     DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `gift_boxes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `round_id`   INT UNSIGNED NOT NULL,
  `position`   SMALLINT UNSIGNED NOT NULL COMMENT 'Place affichee, 1..N',
  `kind`       ENUM('coins','xp','big','jackpot') NOT NULL DEFAULT 'coins',
  `coins`      DECIMAL(18,4) NOT NULL DEFAULT 0,
  `xp`         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `opened_by`  INT UNSIGNED NULL,
  `opened_at`  DATETIME NULL,
  `offer_pct`  SMALLINT UNSIGNED NOT NULL DEFAULT 0
               COMMENT 'Bonus propose a l ouverture. Fige pour empecher de relancer le tirage.',
  `multiplier` SMALLINT UNSIGNED NOT NULL DEFAULT 0
               COMMENT 'Bonus en pourcent reellement applique, 0 si refuse ou non propose',
  `coins_paid` DECIMAL(18,4) NOT NULL DEFAULT 0
               COMMENT 'Verse au joueur, multiplicateur inclus',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_pos` (`round_id`, `position`),
  KEY `idx_open` (`round_id`, `opened_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Parcours publicitaire d'une boite. Meme principe que pour Instant
-- Gagnant : le jeton est emis a l'ouverture, le temps est recalcule au
-- retour a partir de l'horodatage en base.
CREATE TABLE IF NOT EXISTS `gift_claims` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `box_id`     INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `token`      CHAR(64) NOT NULL,
  `status`     ENUM('en_attente','valide','expire') NOT NULL DEFAULT 'en_attente',
  `ip`         VARBINARY(16) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token` (`token`),
  KEY `idx_user` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reglages.
-- gift.envelope_ratio : part de la recette estimee reversee en lots.
--   Le multiplicateur ajoute environ 10 % par-dessus si un joueur sur
--   cinq l'utilise : une part de 50 % laisse donc 45 % de marge, pas 50.
-- gift.jackpot_step : ce qu'une boite ouverte ajoute au jackpot.
-- gift.multipliers : bonus proposes, en pourcent. Le 0 et le 100 ont
--   ete retires — le premier frustre, le second double la sortie.
INSERT IGNORE INTO `config` (`k`,`v`) VALUES
 ('gift.enabled','1'),
 ('gift.test_mode','1'),
 ('gift.boxes_count','100'),
 ('gift.rpv_estimate','0.005'),
 ('gift.envelope_ratio','0.40'),
 ('gift.jackpot','0'),
 ('gift.jackpot_step','5'),
 ('gift.prize_pot','0'),
 ('gift.pot_step','20'),
 ('gift.jackpot_seed','10000'),
 ('gift.jackpot_min','40000'),
 ('gift.big_tiers','800,1000,2000,5000'),
 ('gift.special_chance','50'),
 ('gift.multipliers','20,50,80'),
 ('gift.ads_seconds','30'),
 ('gift.ads_url','');


-- ---------------------------------------------------------------------
-- 15) Zones des nouvelles taches (V9.70)
-- ---------------------------------------------------------------------
-- Zones des nouvelles taches (V9.70). Deux par tache au minimum :
-- une en haut, vue par tous ceux qui arrivent, une en bas, vue par ceux
-- qui restent. Une seule zone ne capte que la moitie du trafic utile.
INSERT IGNORE INTO `ad_zones` (`k`,`label`,`code`,`size_key`,`active`) VALUES
 ('instant_top',    'Instant Gagnant — Haut de page',   '<!-- Insérer ici le code de la régie -->', '728x90',  1),
 ('instant_bottom', 'Instant Gagnant — Bas de page',    '<!-- Insérer ici le code de la régie -->', '300x250', 1),
 ('gift_top',       'Boîte à cadeaux — Haut de page',   '<!-- Insérer ici le code de la régie -->', '728x90',  1),
 ('gift_bottom',    'Boîte à cadeaux — Bas de page',    '<!-- Insérer ici le code de la régie -->', '300x250', 1),
 ('quiz_top',       'WintQuiz — Haut de page',          '<!-- Insérer ici le code de la régie -->', '728x90',  1),
 ('quiz_bottom',    'WintQuiz — Bas de page',           '<!-- Insérer ici le code de la régie -->', '300x250', 1);


-- ---------------------------------------------------------------------
-- 16) WintQuiz (V9.70)
-- ---------------------------------------------------------------------
-- ---------------------------------------------------------------------
-- WINTQUIZ (V9.70)
--
-- Une PARTIE s'acheve a 5 bonnes reponses. Les questions s'enchainent
-- tant que le compte n'y est pas : une mauvaise reponse ne fait pas
-- perdre, elle allonge.
--
-- La bonne reponse n'est JAMAIS envoyee au navigateur avant validation.
-- C'est la seule protection qui vaille : tout ce qui part vers le
-- client se lit dans le code source.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `quiz_questions` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `question`   VARCHAR(400) NOT NULL,
  `a`          VARCHAR(200) NOT NULL,
  `b`          VARCHAR(200) NOT NULL,
  `c`          VARCHAR(200) NOT NULL,
  `d`          VARCHAR(200) NOT NULL,
  `correct`    ENUM('a','b','c','d') NOT NULL,
  -- Nom en clair et non `explain` : celui-ci est un mot reserve de
  -- MariaDB, et toute requete qui l'oublierait entre accents graves
  -- echouerait.
  `explanation` VARCHAR(400) NULL COMMENT 'Affiche apres une mauvaise reponse',
  `category`   VARCHAR(60) NOT NULL DEFAULT 'general',
  `difficulty` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1 facile, 2 moyen, 3 difficile',
  `lang`       CHAR(2) NOT NULL DEFAULT 'fr',
  `active`     TINYINT(1) NOT NULL DEFAULT 1,
  `asked`      INT UNSIGNED NOT NULL DEFAULT 0,
  `correct_n`  INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pick` (`active`, `lang`, `difficulty`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Une partie en cours ou terminee.
CREATE TABLE IF NOT EXISTS `quiz_sessions` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `goal`        TINYINT UNSIGNED NOT NULL DEFAULT 5,
  `correct_cnt` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `asked_cnt`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `reward`      DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT 'Cagnotte figee a l ouverture',
  `status`      ENUM('en_cours','gagne','abandonne') NOT NULL DEFAULT 'en_cours',
  -- Question en attente de reponse. Sans elle, on pourrait repondre a
  -- une question qu'on n'a jamais recue.
  `current_qid` INT UNSIGNED NULL,
  `asked_ids`   TEXT NULL COMMENT 'Questions deja posees, pour ne pas les repeter',
  `started_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ended_at`    DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`, `status`),
  KEY `idx_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Chaque reponse donnee. Sert a retracer une partie contestee et a
-- mesurer quelles questions sont trop dures.
CREATE TABLE IF NOT EXISTS `quiz_answers` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id`  BIGINT UNSIGNED NOT NULL,
  `question_id` INT UNSIGNED NOT NULL,
  `given`       ENUM('a','b','c','d') NOT NULL,
  `is_correct`  TINYINT(1) NOT NULL DEFAULT 0,
  `answered_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reglages.
-- quiz.cooldown_hours : 3 h, comme le faucet.
-- quiz.reward_coins   : cagnotte d'une partie gagnee. Une partie coute
--   5 publicites : a 0,005 EUR la vue, 250 coins de recette. 125 laisse
--   la moitie.
INSERT IGNORE INTO `config` (`k`,`v`) VALUES
 ('quiz.enabled','1'),
 ('quiz.test_mode','1'),
 ('quiz.goal','5'),
 ('quiz.reward_coins','125'),
 ('quiz.cooldown_hours','3'),
 ('quiz.ads_seconds','20'),
 ('quiz.ads_url',''),
 ('quiz.start_date',''),
 ('quiz.repeat_after_days','30');


-- ---------------------------------------------------------------------
-- 17) Progression de campagne du quiz (V9.73)
-- ---------------------------------------------------------------------
-- Progression de campagne du quiz (V9.73).
--
-- Une partie s'achete en 5 bonnes reponses et verse un petit gain. En
-- parallele, CHAQUE bonne reponse fait avancer une progression de
-- campagne qui, a 100 %, debloque une cagnotte bien plus consequente.
--
-- Table separee de quiz_sessions : la progression survit aux parties,
-- et une remise a zero de campagne ne doit pas effacer l'historique des
-- parties jouees.
CREATE TABLE IF NOT EXISTS `quiz_progress` (
  `user_id`     INT UNSIGNED NOT NULL,
  `campaign`    SMALLINT UNSIGNED NOT NULL DEFAULT 1
                COMMENT 'Numero de campagne. Incremente pour repartir a zero.',
  `correct_cnt` INT UNSIGNED NOT NULL DEFAULT 0,
  `claimed`     TINYINT(1) NOT NULL DEFAULT 0
                COMMENT '1 = cagnotte de campagne deja versee',
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `campaign`),
  CONSTRAINT `fk_qp_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reglages de campagne et hypothese de recette.
-- econ.rpv_estimate est PARTAGE par toutes les taches : c'est le seul
-- chiffre a corriger quand le registre des recettes donnera la valeur
-- reelle, et toutes les projections suivront.
INSERT IGNORE INTO `config` (`k`,`v`) VALUES
 ('econ.rpv_estimate','0.005'),
 ('quiz.campaign','1'),
 ('quiz.campaign_goal','500'),
 ('quiz.campaign_reward','5000'),
 ('quiz.ads_per_answer','1');

