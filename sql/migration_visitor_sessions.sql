-- ============================================================================
-- Wintaskly — Migration : suivi des visiteurs en temps réel (V8.26.0)
-- ============================================================================
-- Ajoute la table visitor_sessions. IP stockée en binaire anonymisé (comme
-- le module anti-fraude), jamais en clair. Idempotent (sûr à relancer).
-- ============================================================================

CREATE TABLE IF NOT EXISTS `visitor_sessions` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_key`   CHAR(40)     NOT NULL,
  `user_id`       INT UNSIGNED NULL,
  `ip_bin`        VARBINARY(16) NULL,
  `user_agent`    VARCHAR(255) NULL,
  `current_page`  VARCHAR(255) NULL,
  `referrer`      VARCHAR(255) NULL,
  `started_at`    DATETIME NOT NULL,
  `last_activity` DATETIME NOT NULL,
  `page_views`    INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_session` (`session_key`),
  KEY `idx_last_activity` (`last_activity`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_vs_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
