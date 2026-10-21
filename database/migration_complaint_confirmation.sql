-- Resident response to a complaint resolution. Apply after Phases 1 and 3.
-- Re-running preserves confirmed/reopened responses and historical timestamps.
SET @phase4_ddl = IF(
  EXISTS(SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'complaints'
           AND column_name = 'resident_confirmation'),
  'SELECT 1',
  'ALTER TABLE `complaints` ADD COLUMN `resident_confirmation` ENUM(''pending'',''confirmed'',''reopened'') NULL DEFAULT NULL'
);
PREPARE phase4_stmt FROM @phase4_ddl;
EXECUTE phase4_stmt;
DEALLOCATE PREPARE phase4_stmt;

SET @phase4_ddl = IF(
  EXISTS(SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'complaints'
           AND column_name = 'confirmed_at'),
  'SELECT 1',
  'ALTER TABLE `complaints` ADD COLUMN `confirmed_at` DATETIME NULL DEFAULT NULL'
);
PREPARE phase4_stmt FROM @phase4_ddl;
EXECUTE phase4_stmt;
DEALLOCATE PREPARE phase4_stmt;

SET @phase4_ddl = IF(
  EXISTS(SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'complaints'
           AND column_name = 'reopened_at'),
  'SELECT 1',
  'ALTER TABLE `complaints` ADD COLUMN `reopened_at` DATETIME NULL DEFAULT NULL'
);
PREPARE phase4_stmt FROM @phase4_ddl;
EXECUTE phase4_stmt;
DEALLOCATE PREPARE phase4_stmt;

UPDATE complaints SET resident_confirmation = 'pending'
 WHERE status = 'resolved' AND resident_confirmation IS NULL;
