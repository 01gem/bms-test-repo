-- Capture document-specific requirements at request time so the resident,
-- not the official, supplies fields the templates need.
--
--   Barangay Clearance          age, civil_status
--   Certificate of Residency    years_of_residency
--   Business Permit Endorsement business_name, business_type
--
-- Stored as JSON so a new document type does not require a schema change.
-- Run once. Idempotent via information_schema guard (MySQL 8.4 rejects
-- ADD COLUMN IF NOT EXISTS).
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'document_requests'
    AND COLUMN_NAME = 'request_fields_json'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `document_requests` ADD COLUMN `request_fields_json` JSON NULL AFTER `purpose`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
