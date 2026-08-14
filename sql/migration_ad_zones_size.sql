-- ============================================================================
-- Wintaskly — Migration : rotation automatique des bannières par format
-- ============================================================================
-- Ajoute ad_zones.size_key : le format IAB (ex: 300x250) qu'une zone
-- attend en repli quand ni régie publicitaire ni bannière spécifique n'y
-- sont configurées. Dans ce cas, wt_ad_zone() sélectionne désormais
-- automatiquement TOUTES les bannières actives de ce format et les fait
-- tourner côté client (voir media/wintaskly/js/ad-rotator.js).
-- Idempotent : la colonne n'est ajoutée qu'une fois, le seed de valeurs
-- par défaut ne tourne qu'à ce moment-là (n'écrase jamais un choix admin
-- déjà fait sur une exécution ultérieure de cette migration).
-- ============================================================================

SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_zones' AND COLUMN_NAME = 'size_key'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `ad_zones` ADD COLUMN `size_key` VARCHAR(20) NULL '
  'COMMENT ''Format IAB pour la rotation automatique (ex: 300x250) si ni regie ni banniere specifique'' '
  'AFTER `banner_id`',
  'SELECT ''size_key already exists'' AS info');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- Seed uniquement à la création de la colonne (@col_exists valait 0 juste
-- avant l'ALTER ci-dessus) : format par défaut selon le nom de la zone,
-- librement modifiable ensuite via admin/banners.php.
UPDATE `ad_zones` SET `size_key` = '300x250'
 WHERE @col_exists = 0 AND `size_key` IS NULL
   AND (`k` LIKE '%sidebar%' OR `k` LIKE '%_center' OR `k` = 'shortlink_gateway');

UPDATE `ad_zones` SET `size_key` = '728x90'
 WHERE @col_exists = 0 AND `size_key` IS NULL;
