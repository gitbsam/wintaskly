-- ============================================================================
-- Wintaskly — Suivi de disponibilité des fournisseurs
-- ============================================================================
-- PROBLÈME
-- --------
-- Les fournisseurs de liens et les murs d'offres cessent parfois de répondre :
-- service fermé, domaine expiré, compte suspendu, API modifiée. Rien dans la
-- plateforme ne le détecte aujourd'hui.
--
-- Conséquence pour l'utilisateur : il lance une tâche, traverse les pages,
-- et rien n'est crédité — parce qu'il n'y a plus personne au bout. Il conclut
-- logiquement que la plateforme ne paie pas, alors que le fournisseur a
-- simplement disparu.
--
-- CE QUE CETTE MIGRATION AJOUTE
-- -----------------------------
-- Trois colonnes par table, alimentées par la tâche planifiée
-- `provider_health` :
--   • last_check_at   — date du dernier contrôle
--   • last_http_code  — code renvoyé au dernier contrôle (0 = injoignable)
--   • fail_streak     — nombre d'échecs CONSÉCUTIFS
--
-- Le compteur consécutif est essentiel : un fournisseur en maintenance
-- quelques minutes ne doit pas être désactivé, alors qu'un service disparu
-- échoue jour après jour. La désactivation automatique n'intervient donc
-- qu'après plusieurs échecs d'affilée.
--
-- Idempotent : chaque ALTER est précédé d'un test d'existence.
-- ⚠️ Importer avec --default-character-set=utf8mb4.
-- ============================================================================

ALTER TABLE `shortlinks`
     ADD COLUMN IF NOT EXISTS `last_check_at`  DATETIME NULL AFTER `active`,
     ADD COLUMN IF NOT EXISTS `last_http_code` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_check_at`,
     ADD COLUMN IF NOT EXISTS `fail_streak`    SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_http_code`;

ALTER TABLE `offerwalls`
     ADD COLUMN IF NOT EXISTS `last_check_at`  DATETIME NULL AFTER `active`,
     ADD COLUMN IF NOT EXISTS `last_http_code` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_check_at`,
     ADD COLUMN IF NOT EXISTS `fail_streak`    SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_http_code`;

-- ---------------------------------------------------------------------
-- Annonces PTC
-- ---------------------------------------------------------------------
-- Une annonce PTC pointe vers un site externe. Si ce site ferme, le membre
-- regarde une page morte pendant le décompte et n'est crédité de rien.
ALTER TABLE `ptc_ads`
     ADD COLUMN IF NOT EXISTS `last_check_at`  DATETIME NULL AFTER `active`,
     ADD COLUMN IF NOT EXISTS `last_http_code` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_check_at`,
     ADD COLUMN IF NOT EXISTS `fail_streak`    SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_http_code`;

-- ---------------------------------------------------------------------
-- Zones publicitaires
-- ---------------------------------------------------------------------
-- Une zone contient le code fourni par la régie (Adsterra, ad-maven…).
-- Il n'y a pas d'URL à sonder directement : le domaine est extrait du code
-- JavaScript par la tâche de contrôle. Une régie dont le domaine ne répond
-- plus n'affiche plus rien — donc plus aucune recette, sans aucun signal.
ALTER TABLE `ad_zones`
     ADD COLUMN IF NOT EXISTS `last_check_at`  DATETIME NULL AFTER `active`,
     ADD COLUMN IF NOT EXISTS `last_http_code` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_check_at`,
     ADD COLUMN IF NOT EXISTS `fail_streak`    SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_http_code`;

-- ---------------------------------------------------------------------
-- Réglages
-- ---------------------------------------------------------------------
-- health.fail_limit : nombre d'échecs consécutifs avant désactivation
--   automatique. 3 par défaut — assez pour absorber une maintenance,
--   assez peu pour ne pas laisser une tâche morte une semaine.
-- health.auto_disable : mettre à 0 pour se contenter de SIGNALER sans
--   jamais désactiver, si vous préférez décider vous-même.
INSERT IGNORE INTO `config` (`k`, `v`) VALUES
  ('health.enabled',      '1'),
  ('health.fail_limit',   '3'),
  ('health.auto_disable', '1'),
  ('health.timeout',      '8');
