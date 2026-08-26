-- ============================================================================
-- Wintaskly — Campagnes d'acquisition
-- ============================================================================
-- OBJECTIF
-- --------
-- Mesurer l'efficacité réelle des achats d'espace publicitaire : quel site
-- envoie du trafic, ce que ce trafic fait une fois arrivé, combien de
-- visiteurs deviennent membres, et combien coûte réellement un membre acquis.
--
-- Sans ce suivi, un budget publicitaire se dépense à l'aveugle : on voit des
-- visiteurs arriver, sans jamais savoir lesquels sont devenus des membres
-- actifs ni combien chacun a coûté.
--
-- STRUCTURE DE L'URL
-- ------------------
--   /campagn/                  → page seule, aucune campagne
--   /campagn/2026/AB12CD34     → campagne identifiée, suivi actif
--
-- L'année n'est pas une page à créer : c'est un paramètre. Elle sert à
-- situer l'offre dans le temps et à repérer immédiatement un lien périmé
-- qui circulerait encore sur un site partenaire des années plus tard.
--
-- ⚠️ DONNÉES PERSONNELLES — RGPD
-- ------------------------------
-- Cette fonctionnalité enregistre des adresses IP et pose un identifiant de
-- visite. Ce sont des données personnelles au sens du RGPD. Trois obligations
-- en découlent, et elles ne sont pas optionnelles :
--
--   1. L'IP est stockée sous forme HACHÉE (ip_hash), jamais en clair. Elle
--      permet de reconnaître un visiteur qui revient, sans constituer un
--      fichier d'adresses exploitable en cas de fuite.
--   2. Une durée de conservation est appliquée (campaign.retention_days),
--      avec purge automatique par tâche planifiée.
--   3. La finalité doit être décrite dans la politique de confidentialité,
--      et le dépôt du cookie de suivi soumis au consentement.
--
-- La suppression d'un compte efface les visites qui lui sont rattachées
-- (ON DELETE CASCADE sur converted_user_id).
--
-- ⚠️ Importer avec --default-character-set=utf8mb4.
-- ============================================================================

-- ---------------------------------------------------------------------
-- 1) Les campagnes
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `campaigns` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `code`             VARCHAR(24)   NOT NULL,
  `year`             SMALLINT UNSIGNED NOT NULL,
  `name`             VARCHAR(120)  NOT NULL,
  `site_name`        VARCHAR(120)  NULL,
  `site_url`         VARCHAR(255)  NULL,
  `placement`        VARCHAR(120)  NULL COMMENT 'Emplacement acheté : bannière page d''accueil, encart latéral…',

  -- Objectifs annoncés par le site partenaire. Servent de référence pour
  -- comparer le promis au constaté — c''est tout l''intérêt du suivi.
  `expected_clicks`  INT UNSIGNED  NOT NULL DEFAULT 0,
  `expected_seconds` INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'Temps de visionnage annoncé, en secondes',
  `budget_eur`       DECIMAL(10,2) NOT NULL DEFAULT 0,

  -- Récompense accordée au nouveau membre issu de cette campagne.
  `reward_coins`     DECIMAL(18,4) NOT NULL DEFAULT 0,

  `status`           ENUM('draft','active','paused','cancelled','ended') NOT NULL DEFAULT 'draft',
  `starts_at`        DATETIME      NULL,
  `ends_at`          DATETIME      NULL,
  `notes`            TEXT          NULL,
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  -- Le code seul est unique : il figure dans l''URL et doit désigner une
  -- seule campagne, indépendamment de l''année affichée.
  UNIQUE KEY `uniq_code` (`code`),
  KEY `idx_year_status` (`year`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2) Les visites
-- ---------------------------------------------------------------------
-- Une ligne par VISITEUR et par campagne, pas par page vue : un visiteur qui
-- consulte cinq pages met à jour sa ligne plutôt que d''en créer cinq. Cela
-- garde la table exploitable et évite d''accumuler des données inutiles.
CREATE TABLE IF NOT EXISTS `campaign_visits` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id`    INT UNSIGNED    NULL COMMENT 'NULL si le code de l''URL est inconnu',
  `visitor_key`    CHAR(32)        NOT NULL COMMENT 'Identifiant anonyme déposé en cookie',

  -- IP hachée avec le secret de l''application : reconnaît un visiteur qui
  -- revient sans stocker d''adresse exploitable. Irréversible en pratique.
  `ip_hash`        CHAR(64)        NULL,
  `country`        CHAR(2)         NULL,
  `user_agent`     VARCHAR(255)    NULL,
  `referer`        VARCHAR(255)    NULL,

  `pages_viewed`   INT UNSIGNED    NOT NULL DEFAULT 1,
  `total_seconds`  INT UNSIGNED    NOT NULL DEFAULT 0,
  `first_seen_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- Conversion : rattachement au compte créé, puis versement de la prime.
  `converted_user_id` INT UNSIGNED NULL,
  `converted_at`   DATETIME        NULL,
  `rewarded_at`    DATETIME        NULL COMMENT 'Prime versée : garantit l''unicité',
  `reward_coins`   DECIMAL(18,4)   NOT NULL DEFAULT 0,

  PRIMARY KEY (`id`),
  -- Un visiteur ne crée qu''une ligne par campagne, même en revenant.
  UNIQUE KEY `uniq_visitor_campaign` (`visitor_key`, `campaign_id`),
  KEY `idx_campaign` (`campaign_id`),
  KEY `idx_converted` (`converted_user_id`),
  KEY `idx_seen` (`last_seen_at`),
  CONSTRAINT `fk_cv_campaign`
    FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE SET NULL,
  -- Supprimer un compte efface la visite associée : l''utilisateur
  -- disparaît des statistiques, conformément au droit à l''effacement.
  CONSTRAINT `fk_cv_user`
    FOREIGN KEY (`converted_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3) Les pages consultées
-- ---------------------------------------------------------------------
-- Détail du parcours, pour comprendre ce que fait un visiteur au-delà du
-- simple compteur. Table séparée pour ne pas alourdir campaign_visits, et
-- purgée en premier car c''est la plus volumineuse.
CREATE TABLE IF NOT EXISTS `campaign_pageviews` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `visit_id`   BIGINT UNSIGNED NOT NULL,
  `path`       VARCHAR(190)    NOT NULL,
  `seconds`    INT UNSIGNED    NOT NULL DEFAULT 0,
  `viewed_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_visit` (`visit_id`),
  CONSTRAINT `fk_cpv_visit`
    FOREIGN KEY (`visit_id`) REFERENCES `campaign_visits` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4) Réglages
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `config` (`k`, `v`) VALUES
  -- Conservation des données de visite, en jours. Obligation RGPD :
  -- une durée illimitée n''est pas défendable. 400 jours permet une
  -- comparaison d''une année sur l''autre.
  ('campaign.retention_days',   '400'),
  -- Conditions de versement de la prime de bienvenue.
  ('campaign.active_days',      '10'),
  ('campaign.active_min_days',  '5'),
  -- Durée de vie du cookie de suivi, en jours. Un visiteur qui revient
  -- s''inscrire plus tard reste rattaché à sa campagne d''origine.
  ('campaign.cookie_days',      '90');
