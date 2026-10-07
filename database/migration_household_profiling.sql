-- Household profiling migration (Day 1). RUN ONCE. Not idempotent: ALTER TABLE and the 
-- INSERT will fail or duplicate if re-run. Back up and test on a copy first.
-- Run order: poverty_thresholds -> households -> household_profiles -> ALTER residents -> 
-- migration UPDATE. Select the target database before running.

CREATE TABLE IF NOT EXISTS `poverty_thresholds` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `reference_year` INT NOT NULL,
  `geography_level` ENUM('Province','Region','National') NOT NULL,
  `region` VARCHAR(100) NULL,
  `province` VARCHAR(100) NULL,
  `annual_per_capita_threshold` DECIMAL(12,2) NOT NULL,
  `source` VARCHAR(255) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lookup` (`reference_year`, `geography_level`, `province`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `poverty_thresholds`
  (`reference_year`, `geography_level`, `region`, `province`, `annual_per_capita_threshold`, `source`, `is_active`)
VALUES
  (2023, 'Province', 'Caraga', 'Surigao del Norte', 29865.00,
   'PSA 2023 Full Year Official Poverty Statistics', 1),
  (2023, 'Region',   'Caraga', NULL,                 27911.00,
   'PSA 2023 Full Year Official Poverty Statistics (placeholder - verify before use)', 0),
  (2023, 'National', NULL,      NULL,                26779.00,
   'PSA 2023 Full Year Official Poverty Statistics (placeholder - verify before use)', 0);

CREATE TABLE IF NOT EXISTS `households` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `household_code` VARCHAR(20) NOT NULL,
  `head_resident_id` INT NULL,
  `address` VARCHAR(255) NULL,
  `purok_zone` VARCHAR(50) NULL,
  `household_size` INT NOT NULL DEFAULT 0,
  `profile_status` ENUM('unprofiled','in_progress','complete') NOT NULL DEFAULT 'unprofiled',
  `profile_completeness` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `profiled_at` DATETIME NULL,
  `assessment_complete` TINYINT(1) NOT NULL DEFAULT 0,
  `assessment_method_version` VARCHAR(40) NULL,
  `mpi_score` DECIMAL(5,4) NULL,
  `mpi_status` ENUM('poor','not_poor','incomplete') NULL,
  `annual_household_income` DECIMAL(12,2) NULL,
  `annual_per_capita_income` DECIMAL(12,2) NULL,
  `income_poverty_status` ENUM('below','at_or_above','cannot_determine') NULL,
  `applied_threshold_id` INT NULL,
  `mpi_indicators_json` JSON NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `household_code` (`household_code`),
  KEY `idx_purok` (`purok_zone`),
  KEY `idx_status` (`profile_status`),
  KEY `idx_head` (`head_resident_id`),
  CONSTRAINT `fk_household_head` FOREIGN KEY (`head_resident_id`)
    REFERENCES `residents`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_household_threshold` FOREIGN KEY (`applied_threshold_id`)
    REFERENCES `poverty_thresholds`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `household_profiles` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `household_id` INT NOT NULL,
  `num_children` INT NULL,
  `num_working_age` INT NULL,
  `num_seniors` INT NULL,
  `has_4ps` TINYINT(1) NULL,
  `has_pwd_member` TINYINT(1) NULL,
  `has_senior_member` TINYINT(1) NULL,
  `is_solo_parent_hh` TINYINT(1) NULL,
  `children_5_17_total` INT NULL,
  `children_5_17_not_in_school` INT NULL,
  `members_19_plus_total` INT NULL,
  `has_19_plus_secondary_grad` TINYINT(1) NULL,
  `medical_need_6mo` TINYINT(1) NULL,
  `medical_treatment_obtained` TINYINT(1) NULL,
  `distance_health_center_km` DECIMAL(6,2) NULL,
  `distance_hospital_km` DECIMAL(6,2) NULL,
  `fies_worried_food` TINYINT(1) NULL,
  `fies_no_nutritious` TINYINT(1) NULL,
  `fies_few_food_types` TINYINT(1) NULL,
  `fies_skipped_meals` TINYINT(1) NULL,
  `fies_ate_less` TINYINT(1) NULL,
  `fies_ran_out` TINYINT(1) NULL,
  `fies_hungry` TINYINT(1) NULL,
  `fies_whole_day_no_food` TINYINT(1) NULL,
  `labor_force_total` INT NULL,
  `employed_count` INT NULL,
  `unemployed_seeking_count` INT NULL,
  `employed_wanting_more_hours` INT NULL,
  `working_children_5_17` INT NULL,
  `working_children_not_in_school` INT NULL,
  `housing_tenure` VARCHAR(50) NULL,
  `roof_material` VARCHAR(50) NULL,
  `wall_material` VARCHAR(50) NULL,
  `floor_area_sqm` DECIMAL(8,2) NULL,
  `main_water_source` VARCHAR(80) NULL,
  `toilet_facility` VARCHAR(80) NULL,
  `shared_toilet` TINYINT(1) NULL,
  `has_electricity` TINYINT(1) NULL,
  `has_internet` TINYINT(1) NULL,
  `public_transport_access` TINYINT(1) NULL,
  `assets_json` JSON NULL,
  `monthly_wages` DECIMAL(12,2) NULL,
  `monthly_business` DECIMAL(12,2) NULL,
  `monthly_remittances` DECIMAL(12,2) NULL,
  `monthly_pension` DECIMAL(12,2) NULL,
  `monthly_govt_assistance` DECIMAL(12,2) NULL,
  `monthly_other` DECIMAL(12,2) NULL,
  `contextual_flags_json` JSON NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `household_id` (`household_id`),
  CONSTRAINT `fk_profile_household` FOREIGN KEY (`household_id`)
    REFERENCES `households`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `residents`
  ADD COLUMN `household_id` INT NULL AFTER `id`,
  ADD COLUMN `relationship_to_household_head` VARCHAR(50) NULL AFTER `household_id`,
  ADD COLUMN `profile_status` ENUM('unprofiled','in_progress','complete')
    NOT NULL DEFAULT 'unprofiled' AFTER `household_id`,
  ADD KEY `idx_household` (`household_id`),
  ADD CONSTRAINT `fk_resident_household` FOREIGN KEY (`household_id`)
    REFERENCES `households`(`id`) ON DELETE SET NULL;

-- Preserve existing residents as unprofiled. Do NOT fabricate household data.
-- (Effectively a no-op after the ALTER defaults; kept as an explicit safeguard.)
UPDATE `residents`
SET `household_id` = NULL,
    `relationship_to_household_head` = NULL,
    `profile_status` = 'unprofiled'
WHERE `household_id` IS NULL;
