-- MySQL 8.4 on this host rejects ADD COLUMN IF NOT EXISTS.
-- information_schema guard: second run is a no-op, no error, no data change.
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'document_requests'
    AND COLUMN_NAME = 'date_completed'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `document_requests` ADD COLUMN `date_completed` DATETIME NULL DEFAULT NULL AFTER `status`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
