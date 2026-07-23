-- ============================================================================
-- Wintaskly — Migration : bannières uploadées (maison)
-- ============================================================================
-- Ajoute la table ad_banners + la colonne ad_zones.banner_id, pour une
-- alternative "bannière maison" quand aucune régie publicitaire n'est
-- configurée sur une zone. Idempotent (sûr à relancer).
-- ============================================================================

CREATE TABLE IF NOT EXISTS `ad_banners` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `size_key`      VARCHAR(20) NOT NULL COMMENT '728x90, 468x60, 300x250 ou other',
  `width`         SMALLINT UNSIGNED NOT NULL,
  `height`        SMALLINT UNSIGNED NOT NULL,
  `filename`      VARCHAR(190) NOT NULL COMMENT 'Nom de fichier dans media/wintaskly/img/banners/',
  `original_name` VARCHAR(190) NULL,
  `active`        TINYINT(1) NOT NULL DEFAULT 1,
  `uploaded_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_size_active` (`size_key`, `active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ad_zones.banner_id (colonne)
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_zones' AND COLUMN_NAME = 'banner_id'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `ad_zones` ADD COLUMN `banner_id` INT UNSIGNED NULL AFTER `code`',
  'SELECT ''banner_id already exists'' AS info');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- ad_zones.idx_banner (index)
SET @idx_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_zones' AND INDEX_NAME = 'idx_banner'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE `ad_zones` ADD KEY `idx_banner` (`banner_id`)',
  'SELECT ''idx_banner already exists'' AS info');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- ad_zones.fk_adz_banner (contrainte FK)
SET @fk_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_zones' AND CONSTRAINT_NAME = 'fk_adz_banner'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE `ad_zones` ADD CONSTRAINT `fk_adz_banner` FOREIGN KEY (`banner_id`) REFERENCES `ad_banners`(`id`) ON DELETE SET NULL',
  'SELECT ''fk_adz_banner already exists'' AS info');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
