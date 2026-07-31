-- ============================================================================
-- Wintaskly — Migration : preuve serveur de présence PTC (anti-triche)
-- ============================================================================
-- Ajoute heartbeat_count + last_heartbeat_at à ptc_sessions. Idempotent.
-- ============================================================================

SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ptc_sessions' AND COLUMN_NAME = 'heartbeat_count'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `ptc_sessions` ADD COLUMN `heartbeat_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `reject_reason`',
  'SELECT ''heartbeat_count already exists'' AS info');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @col_exists2 := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ptc_sessions' AND COLUMN_NAME = 'last_heartbeat_at'
);
SET @sql2 := IF(@col_exists2 = 0,
  'ALTER TABLE `ptc_sessions` ADD COLUMN `last_heartbeat_at` DATETIME NULL AFTER `heartbeat_count`',
  'SELECT ''last_heartbeat_at already exists'' AS info');
PREPARE _stmt2 FROM @sql2; EXECUTE _stmt2; DEALLOCATE PREPARE _stmt2;
