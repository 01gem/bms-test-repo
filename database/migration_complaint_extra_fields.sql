-- Optional category-specific complaint values. Legacy rows remain SQL NULL.
-- MySQL 8 native JSON, consistent with the verified development server.
-- Re-running preserves all existing values; incompatible definitions fail.
SET @phase6_column_state = (
  SELECT CASE
    WHEN COUNT(*) = 0 THEN 'add'
    WHEN SUM(data_type = 'json' AND is_nullable = 'YES'
             AND column_default IS NULL AND (extra IS NULL OR extra = '')) = 1
     AND COUNT(*) = 1 THEN 'ok'
    ELSE 'bad'
  END
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'complaints'
    AND column_name = 'extra_fields_json'
);
SET @phase6_ddl = IF(
  @phase6_column_state = 'add',
  'ALTER TABLE `complaints` ADD COLUMN `extra_fields_json` JSON NULL DEFAULT NULL',
  IF(@phase6_column_state = 'ok', 'SELECT 1',
     'SELECT `phase6_incompatible_extra_fields_json` FROM `complaints` LIMIT 0')
);
PREPARE phase6_stmt FROM @phase6_ddl;
EXECUTE phase6_stmt;
DEALLOCATE PREPARE phase6_stmt;
