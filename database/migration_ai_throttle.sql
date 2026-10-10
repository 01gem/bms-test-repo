CREATE TABLE IF NOT EXISTS `ai_request_log` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `official_id` INT NOT NULL,
  `endpoint` VARCHAR(64) NOT NULL,
  `household_count` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_official_time` (`official_id`, `created_at`),
  CONSTRAINT `fk_ai_log_official` FOREIGN KEY (`official_id`)
    REFERENCES `barangay_officials`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
