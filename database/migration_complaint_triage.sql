-- Complaint assignment and elapsed-hour response targets.
-- Apply after the resident-confirmation migration.
-- Re-running adds only missing objects and does not rewrite assignee,
-- deadline, or escalation values. A pre-existing incompatible column,
-- index, or foreign key stops the script instead of coercing data.
-- MySQL 8 prepared DDL. Failure names the incompatible object.

SET @phase5_engine = (
  SELECT ENGINE FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'complaints'
);
SET @phase5_ddl = IF(
  LOWER(IFNULL(@phase5_engine, '')) = 'innodb',
  'SELECT 1',
  'SELECT `phase5_incompatible_complaints_engine` FROM `complaints` LIMIT 0'
);
PREPARE phase5_stmt FROM @phase5_ddl;
EXECUTE phase5_stmt;
DEALLOCATE PREPARE phase5_stmt;

SET @phase5_pk_state = (
  SELECT CASE
    WHEN COUNT(*) = 0 THEN 'missing'
    WHEN SUM(data_type = 'int' AND column_type NOT LIKE '%unsigned%') = 1
     AND COUNT(*) = 1 THEN 'ok'
    ELSE 'bad'
  END
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'barangay_officials'
    AND column_name = 'id'
);
SET @phase5_ddl = IF(
  @phase5_pk_state = 'ok',
  'SELECT 1',
  'SELECT `phase5_incompatible_barangay_officials_id` FROM `barangay_officials` LIMIT 0'
);
PREPARE phase5_stmt FROM @phase5_ddl;
EXECUTE phase5_stmt;
DEALLOCATE PREPARE phase5_stmt;

SET @phase5_column_state = (
  SELECT CASE
    WHEN COUNT(*) = 0 THEN 'add'
    WHEN SUM(
      data_type = 'int'
      AND is_nullable = 'YES'
      AND column_type NOT LIKE '%unsigned%'
      AND column_default IS NULL
      AND (extra IS NULL OR extra = '')
    ) = 1 AND COUNT(*) = 1 THEN 'ok'
    ELSE 'bad'
  END
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'complaints'
    AND column_name = 'assigned_official_id'
);
SET @phase5_ddl = IF(
  @phase5_column_state = 'add',
  'ALTER TABLE `complaints` ADD COLUMN `assigned_official_id` INT NULL DEFAULT NULL',
  IF(
    @phase5_column_state = 'ok',
    'SELECT 1',
    'SELECT `phase5_incompatible_assigned_official_id` FROM `complaints` LIMIT 0'
  )
);
PREPARE phase5_stmt FROM @phase5_ddl;
EXECUTE phase5_stmt;
DEALLOCATE PREPARE phase5_stmt;

SET @phase5_column_state = (
  SELECT CASE
    WHEN COUNT(*) = 0 THEN 'add'
    WHEN SUM(
      data_type = 'datetime'
      AND is_nullable = 'YES'
      AND column_default IS NULL
      AND (extra IS NULL OR extra = '')
    ) = 1 AND COUNT(*) = 1 THEN 'ok'
    ELSE 'bad'
  END
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'complaints'
    AND column_name = 'sla_due_at'
);
SET @phase5_ddl = IF(
  @phase5_column_state = 'add',
  'ALTER TABLE `complaints` ADD COLUMN `sla_due_at` DATETIME NULL DEFAULT NULL',
  IF(
    @phase5_column_state = 'ok',
    'SELECT 1',
    'SELECT `phase5_incompatible_sla_due_at` FROM `complaints` LIMIT 0'
  )
);
PREPARE phase5_stmt FROM @phase5_ddl;
EXECUTE phase5_stmt;
DEALLOCATE PREPARE phase5_stmt;

SET @phase5_column_state = (
  SELECT CASE
    WHEN COUNT(*) = 0 THEN 'add'
    WHEN SUM(
      data_type = 'datetime'
      AND is_nullable = 'YES'
      AND column_default IS NULL
      AND (extra IS NULL OR extra = '')
    ) = 1 AND COUNT(*) = 1 THEN 'ok'
    ELSE 'bad'
  END
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'complaints'
    AND column_name = 'escalated_at'
);
SET @phase5_ddl = IF(
  @phase5_column_state = 'add',
  'ALTER TABLE `complaints` ADD COLUMN `escalated_at` DATETIME NULL DEFAULT NULL',
  IF(
    @phase5_column_state = 'ok',
    'SELECT 1',
    'SELECT `phase5_incompatible_escalated_at` FROM `complaints` LIMIT 0'
  )
);
PREPARE phase5_stmt FROM @phase5_ddl;
EXECUTE phase5_stmt;
DEALLOCATE PREPARE phase5_stmt;

SET @phase5_index_state = (
  SELECT CASE
    WHEN COUNT(*) = 0 THEN 'add'
    WHEN SUM(seq_in_index = 1 AND column_name = 'status' AND non_unique = 1) = 1
     AND SUM(seq_in_index = 2 AND column_name = 'escalated_at' AND non_unique = 1) = 1
     AND SUM(seq_in_index = 3 AND column_name = 'sla_due_at' AND non_unique = 1) = 1
     AND COUNT(*) = 3 THEN 'ok'
    ELSE 'bad'
  END
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'complaints'
    AND index_name = 'idx_complaint_sla'
);
SET @phase5_ddl = IF(
  @phase5_index_state = 'add',
  'ALTER TABLE `complaints` ADD KEY `idx_complaint_sla` (`status`, `escalated_at`, `sla_due_at`)',
  IF(
    @phase5_index_state = 'ok',
    'SELECT 1',
    'SELECT `phase5_incompatible_idx_complaint_sla` FROM `complaints` LIMIT 0'
  )
);
PREPARE phase5_stmt FROM @phase5_ddl;
EXECUTE phase5_stmt;
DEALLOCATE PREPARE phase5_stmt;

SET @phase5_fk_state = (
  SELECT CASE
    WHEN COUNT(*) = 0 THEN 'add'
    WHEN SUM(
      rc.DELETE_RULE = 'SET NULL'
      AND rc.REFERENCED_TABLE_NAME = 'barangay_officials'
      AND kcu.COLUMN_NAME = 'assigned_official_id'
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
    AND rc.CONSTRAINT_NAME = 'fk_complaint_assignee'
);
SET @phase5_ddl = IF(
  @phase5_fk_state = 'add',
  'ALTER TABLE `complaints` ADD CONSTRAINT `fk_complaint_assignee` FOREIGN KEY (`assigned_official_id`) REFERENCES `barangay_officials` (`id`) ON DELETE SET NULL',
  IF(
    @phase5_fk_state = 'ok',
    'SELECT 1',
    'SELECT `phase5_incompatible_fk_complaint_assignee` FROM `complaints` LIMIT 0'
  )
);
PREPARE phase5_stmt FROM @phase5_ddl;
EXECUTE phase5_stmt;
DEALLOCATE PREPARE phase5_stmt;
