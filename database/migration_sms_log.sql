-- Every SMS attempt, successful or skipped. Reason column explains skips and
-- failures without polluting audit_log with SMS bodies.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS `sms_log` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `resident_id` INT NULL,
  `official_id` INT NULL,
  `endpoint` VARCHAR(64) NOT NULL,
  `to_number` VARCHAR(20) NOT NULL DEFAULT '',
  `status` ENUM('sent','failed','skipped') NOT NULL,
  `reason` VARCHAR(255) NULL,
  `http_message_id` VARCHAR(64) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_resident_time` (`resident_id`, `created_at`),
  KEY `idx_endpoint_time` (`endpoint`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
