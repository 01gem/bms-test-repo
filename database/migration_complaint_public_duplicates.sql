-- Resident-community sharing and one-level duplicate links.
-- Apply after the extra-fields migration.
-- Re-running preserves sharing flags and duplicate links.
-- A pre-existing incompatible column, index, or foreign key stops the script.
-- MySQL 8 prepared DDL. Failure names the incompatible object.
-- No backfill. Existing rows stay private roots until an admin shares them.

SET @phase7_engine = (
  SELECT ENGINE FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'complaints'
);
SET @phase7_ddl = IF(
  LOWER(IFNULL(@phase7_engine, '')) = 'innodb',
  'SELECT 1',
  'SELECT `phase7_incompatible_complaints_engine` FROM `complaints` LIMIT 0'
);
PREPARE phase7_stmt FROM @phase7_ddl;
EXECUTE phase7_stmt;
DEALLOCATE PREPARE phase7_stmt;

SET @phase7_pk_state = (
  SELECT CASE
    WHEN COUNT(*) = 0 THEN 'missing'
    WHEN SUM(
      data_type = 'int'
      AND column_type NOT LIKE '%unsigned%'
      AND is_nullable = 'NO'
    ) = 1 AND COUNT(*) = 1 THEN 'ok'
    ELSE 'bad'
  END
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'complaints'
    AND column_name = 'id'
);
SET @phase7_ddl = IF(
  @phase7_pk_state = 'ok',
  'SELECT 1',
  'SELECT `phase7_incompatible_complaints_id` FROM `complaints` LIMIT 0'
);
PREPARE phase7_stmt FROM @phase7_ddl;
EXECUTE phase7_stmt;
DEALLOCATE PREPARE phase7_stmt;

SET @phase7_column_state = (
  SELECT CASE
    WHEN COUNT(*) = 0 THEN 'add'
    WHEN SUM(
      data_type = 'tinyint'
      AND column_type = 'tinyint(1)'
      AND is_nullable = 'NO'
      AND column_default = '0'
      AND (extra IS NULL OR extra = '')
    ) = 1 AND COUNT(*) = 1 THEN 'ok'
    ELSE 'bad'
  END
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'complaints'
    AND column_name = 'is_public'
);
SET @phase7_ddl = IF(
  @phase7_column_state = 'add',
  'ALTER TABLE `complaints` ADD COLUMN `is_public` TINYINT(1) NOT NULL DEFAULT 0',
  IF(
    @phase7_column_state = 'ok',
    'SELECT 1',
    'SELECT `phase7_incompatible_is_public` FROM `complaints` LIMIT 0'
  )
);
PREPARE phase7_stmt FROM @phase7_ddl;
EXECUTE phase7_stmt;
DEALLOCATE PREPARE phase7_stmt;

SET @phase7_column_state = (
  SELECT CASE
    WHEN COUNT(*) = 0 THEN 'add'
    WHEN SUM(
      data_type = 'int'
      AND column_type NOT LIKE '%unsigned%'
      AND is_nullable = 'YES'
      AND column_default IS NULL
      AND (extra IS NULL OR extra = '')
    ) = 1 AND COUNT(*) = 1 THEN 'ok'
    ELSE 'bad'
  END
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'complaints'
    AND column_name = 'duplicate_of_id'
);
SET @phase7_ddl = IF(
  @phase7_column_state = 'add',
  'ALTER TABLE `complaints` ADD COLUMN `duplicate_of_id` INT NULL DEFAULT NULL',
  IF(
    @phase7_column_state = 'ok',
    'SELECT 1',
    'SELECT `phase7_incompatible_duplicate_of_id` FROM `complaints` LIMIT 0'
  )
);
PREPARE phase7_stmt FROM @phase7_ddl;
EXECUTE phase7_stmt;
DEALLOCATE PREPARE phase7_stmt;

SET @phase7_index_state = (
  SELECT CASE
    WHEN COUNT(*) = 0 THEN 'add'
    WHEN SUM(seq_in_index = 1 AND column_name = 'is_public' AND non_unique = 1 AND sub_part IS NULL) = 1
     AND SUM(seq_in_index = 2 AND column_name = 'duplicate_of_id' AND non_unique = 1 AND sub_part IS NULL) = 1
     AND SUM(seq_in_index = 3 AND column_name = 'id' AND non_unique = 1 AND sub_part IS NULL) = 1
     AND COUNT(*) = 3 THEN 'ok'
    ELSE 'bad'
  END
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'complaints'
    AND index_name = 'idx_complaint_public'
);
SET @phase7_ddl = IF(
  @phase7_index_state = 'add',
  'ALTER TABLE `complaints` ADD KEY `idx_complaint_public` (`is_public`, `duplicate_of_id`, `id`)',
  IF(
    @phase7_index_state = 'ok',
    'SELECT 1',
    'SELECT `phase7_incompatible_idx_complaint_public` FROM `complaints` LIMIT 0'
  )
);
PREPARE phase7_stmt FROM @phase7_ddl;
EXECUTE phase7_stmt;
DEALLOCATE PREPARE phase7_stmt;

SET @phase7_index_state = (
  SELECT CASE
    WHEN COUNT(*) = 0 THEN 'add'
    WHEN SUM(seq_in_index = 1 AND column_name = 'duplicate_of_id' AND non_unique = 1 AND sub_part IS NULL) = 1
     AND SUM(seq_in_index = 2 AND column_name = 'is_public' AND non_unique = 1 AND sub_part IS NULL) = 1
     AND SUM(seq_in_index = 3 AND column_name = 'id' AND non_unique = 1 AND sub_part IS NULL) = 1
     AND COUNT(*) = 3 THEN 'ok'
    ELSE 'bad'
  END
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'complaints'
    AND index_name = 'idx_complaint_duplicates'
);
SET @phase7_ddl = IF(
  @phase7_index_state = 'add',
  'ALTER TABLE `complaints` ADD KEY `idx_complaint_duplicates` (`duplicate_of_id`, `is_public`, `id`)',
  IF(
    @phase7_index_state = 'ok',
    'SELECT 1',
    'SELECT `phase7_incompatible_idx_complaint_duplicates` FROM `complaints` LIMIT 0'
  )
);
PREPARE phase7_stmt FROM @phase7_ddl;
EXECUTE phase7_stmt;
DEALLOCATE PREPARE phase7_stmt;

SET @phase7_fk_state = (
  SELECT CASE
    WHEN COUNT(*) = 0 THEN 'add'
    WHEN SUM(
      rc.DELETE_RULE = 'RESTRICT'
      AND rc.UPDATE_RULE = 'NO ACTION'
      AND rc.REFERENCED_TABLE_NAME = 'complaints'
      AND kcu.COLUMN_NAME = 'duplicate_of_id'
      AND kcu.REFERENCED_COLUMN_NAME = 'id'
      AND kcu.ORDINAL_POSITION = 1
    ) = 1 AND COUNT(*) = 1 THEN 'ok'
    ELSE 'bad'
  END
  FROM information_schema.referential_constraints rc
  JOIN information_schema.key_column_usage kcu
    ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
   AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
   AND kcu.TABLE_NAME = rc.TABLE_NAME
  WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
    AND rc.TABLE_NAME = 'complaints'
    AND rc.CONSTRAINT_NAME = 'fk_complaint_duplicate'
);
SET @phase7_ddl = IF(
  @phase7_fk_state = 'add',
  'ALTER TABLE `complaints` ADD CONSTRAINT `fk_complaint_duplicate` FOREIGN KEY (`duplicate_of_id`) REFERENCES `complaints` (`id`) ON DELETE RESTRICT',
  IF(
    @phase7_fk_state = 'ok',
    'SELECT 1',
    'SELECT `phase7_incompatible_fk_complaint_duplicate` FROM `complaints` LIMIT 0'
  )
);
PREPARE phase7_stmt FROM @phase7_ddl;
EXECUTE phase7_stmt;
DEALLOCATE PREPARE phase7_stmt;
