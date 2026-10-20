-- Per-resident SMS opt-out preferences. Missing row means "all enabled" (default true).
-- No backfill needed: notify_resident() treats absence as ON.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS + PK on resident_id.

CREATE TABLE IF NOT EXISTS `resident_sms_preferences` (
  `resident_id` INT NOT NULL,
  `complaints_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `requests_enabled`   TINYINT(1) NOT NULL DEFAULT 1,
  `announcements_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`resident_id`),
  CONSTRAINT `fk_sms_pref_resident` FOREIGN KEY (`resident_id`)
    REFERENCES `residents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
