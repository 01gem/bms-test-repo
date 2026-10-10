CREATE TABLE IF NOT EXISTS `household_ai_reports` (
  `household_id` INT NOT NULL,
  `summary` TEXT NOT NULL,
  `key_deprivations_json` JSON NULL,
  `data_gaps_json` JSON NULL,
  `suggested_followups_json` JSON NULL,
  `model` VARCHAR(80) NULL,
  `generated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`household_id`),
  CONSTRAINT `fk_ai_report_household` FOREIGN KEY (`household_id`)
    REFERENCES `households`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
