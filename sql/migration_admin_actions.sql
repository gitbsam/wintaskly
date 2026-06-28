-- ============================================================================
-- Wintaskly — Migration : table du journal des actions admin
-- ============================================================================
-- Déclare proprement la table admin_actions (auparavant créée "à la volée"
-- dans api/admin_user_action.php). Alimente le journal de /admin/security.php.
-- Sûr à exécuter même si la table existe déjà (IF NOT EXISTS).
-- ============================================================================

CREATE TABLE IF NOT EXISTS `admin_actions` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`   INT UNSIGNED NOT NULL,
  `target_id`  INT UNSIGNED NOT NULL,
  `action`     VARCHAR(32) NOT NULL,
  `meta`       VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_target` (`target_id`, `created_at`),
  KEY `idx_admin`  (`admin_id`,  `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
