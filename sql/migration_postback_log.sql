-- ============================================================================
-- Wintaskly — Journal des postbacks et alerte administrateur
-- ============================================================================
-- POURQUOI
-- --------
-- Un postback refusé échoue en silence : le fournisseur reçoit un 403, le
-- membre n'est jamais crédité, et personne ne l'apprend avant qu'un ticket
-- de support ne remonte — souvent plusieurs jours plus tard.
--
-- La cause la plus fréquente n'est pas une attaque, c'est un secret mal
-- copié ou régénéré chez le fournisseur. Une erreur de configuration
-- silencieuse, donc, qui ressemble à un fonctionnement normal.
--
-- Cette table rend ces échecs visibles, et déclenche une notification
-- lorsqu'ils s'accumulent.
--
-- ⚠️ Importer avec --default-character-set=utf8mb4.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `postback_log` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider`   VARCHAR(32)     NOT NULL,
  `result`     VARCHAR(32)     NOT NULL COMMENT 'OK, BAD_SIGNATURE, INVALID_IP…',
  `detail`     VARCHAR(190)    NULL,
  `ip`         VARBINARY(16)   NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- Index sur (provider, result, created_at) : c'est exactement la requête
  -- de comptage exécutée à chaque échec. Sans lui, elle scannerait toute la
  -- table à chaque postback refusé.
  KEY `idx_alert` (`provider`, `result`, `created_at`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `config` (`k`, `v`) VALUES
  -- Nombre d'échecs identiques déclenchant une alerte.
  ('postback.alert_threshold',   '5'),
  -- Fenêtre d'observation, en minutes.
  ('postback.alert_window_min',  '60'),
  -- Délai avant une nouvelle alerte pour le même fournisseur. Sans lui,
  -- une rafale d'échecs enverrait autant de notifications.
  ('postback.alert_cooldown_min','180'),
  -- Conservation du journal, en jours.
  ('postback.retention_days',    '90'),
  -- Mode test Drip Offers : horodatage d'expiration. 0 = désactivé.
  -- Un mode test qu'on doit penser à refermer reste ouvert des mois :
  -- celui-ci se referme seul.
  ('dripoffers.debug_until',     '0');
