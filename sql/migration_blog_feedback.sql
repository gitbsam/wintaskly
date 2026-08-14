-- ============================================================================
-- Wintaskly — Migration : avis "cet article vous a-t-il aidé ?" sur le blog
-- ============================================================================
-- Ajoute helpful_yes / helpful_no (compteurs agrégés, lecture rapide) à
-- blog_posts, + une table blog_post_feedback pour dédupliquer les votes
-- (un seul vote par article et par IP, visiteurs anonymes inclus — le blog
-- ne nécessite pas de compte). Idempotent.
-- ============================================================================

SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blog_posts' AND COLUMN_NAME = 'helpful_yes'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `blog_posts` ADD COLUMN `helpful_yes` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `views`',
  'SELECT ''helpful_yes already exists'' AS info');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @col_exists2 := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blog_posts' AND COLUMN_NAME = 'helpful_no'
);
SET @sql2 := IF(@col_exists2 = 0,
  'ALTER TABLE `blog_posts` ADD COLUMN `helpful_no` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `helpful_yes`',
  'SELECT ''helpful_no already exists'' AS info');
PREPARE _stmt2 FROM @sql2; EXECUTE _stmt2; DEALLOCATE PREPARE _stmt2;

CREATE TABLE IF NOT EXISTS `blog_post_feedback` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id`    INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NULL,
  `ip_hash`    CHAR(64) NOT NULL,
  `is_helpful` TINYINT(1) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_post_ip` (`post_id`, `ip_hash`),
  KEY `idx_post` (`post_id`),
  CONSTRAINT `fk_feedback_post`
    FOREIGN KEY (`post_id`) REFERENCES `blog_posts`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
