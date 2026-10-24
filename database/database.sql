-- ============================================================================
-- BarangayLink - Consolidated schema
-- Copyright (C) 2026 Jun Gem Riege M. Dulduco
-- Licensed under the GNU General Public License v3.0 (or later).
-- ============================================================================
-- This file replaces database.sql plus every migration_*.sql that shipped
-- with the project. A fresh install is:
--
--     mysql -uroot barangay_system_test_environment < database/database.sql
--     mysql -uroot barangay_system_test_environment < database/seed_households.sql
--
-- seed_households.sql depends on poverty_thresholds.id = 1 being the active
-- Province-level threshold for Surigao del Norte. See the INSERT below.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `barangay_system_test_environment`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `barangay_system_test_environment`;

SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- residents
-- household_id FK added after households exists (circular reference).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `residents` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `fname` VARCHAR(50) NOT NULL,
  `lname` VARCHAR(50) NOT NULL,
  `username` VARCHAR(50) NOT NULL,
  `address` VARCHAR(100) NOT NULL,
  `contact` VARCHAR(50) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `profile_photo` VARCHAR(255) DEFAULT NULL,
  `birthdate` DATE NULL,
  `civil_status` VARCHAR(30) NULL,
  `purok_zone` VARCHAR(50) NULL,
  `household_size` INT DEFAULT 1,
  `is_solo_parent` TINYINT(1) DEFAULT 0,
  `is_pwd` TINYINT(1) DEFAULT 0,
  `is_4ps_member` TINYINT(1) DEFAULT 0,
  `educational_attainment` VARCHAR(50) NULL,
  `employment_status` ENUM('Employed','Unemployed','Self-Employed','Student','Retired') NULL,
  `occupation` VARCHAR(150) NULL,
  `training_certifications` TEXT NULL,
  `work_availability` ENUM('Full-time','Part-time','Seasonal','Not looking') NULL,
  `skill_category` VARCHAR(50) NULL,
  `failed_logins` INT NOT NULL DEFAULT 0,
  `locked_until` DATETIME NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `household_id` INT NULL,
  `relationship_to_household_head` VARCHAR(50) NULL,
  `profile_status` ENUM('unprofiled','in_progress','complete') NOT NULL DEFAULT 'unprofiled',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_household` (`household_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- barangay_officials
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `barangay_officials` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `fname` VARCHAR(50) NOT NULL,
  `lname` VARCHAR(50) NOT NULL,
  `username` VARCHAR(50) NOT NULL,
  `role` ENUM('staff','admin') NOT NULL DEFAULT 'staff',
  `address` VARCHAR(100) NOT NULL,
  `contact` VARCHAR(50) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `position` VARCHAR(100) NOT NULL DEFAULT '',
  `profile_photo` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `failed_logins` INT NOT NULL DEFAULT 0,
  `locked_until` DATETIME NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- poverty_thresholds
-- IMPORTANT: The Province row must be id = 1 for seed_households.sql.
-- Insert order below guarantees this on a fresh database.
-- ----------------------------------------------------------------------------
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
  (`reference_year`, `geography_level`, `region`, `province`,
   `annual_per_capita_threshold`, `source`, `is_active`)
VALUES
  (2023, 'Province', 'Caraga', 'Surigao del Norte', 29865.00,
   'PSA 2023 Full Year Official Poverty Statistics', 1),
  (2023, 'Region',   'Caraga', NULL,                 27911.00,
   'PSA 2023 Full Year Official Poverty Statistics (placeholder - verify before use)', 0),
  (2023, 'National', NULL,      NULL,                26779.00,
   'PSA 2023 Full Year Official Poverty Statistics (placeholder - verify before use)', 0);

-- ----------------------------------------------------------------------------
-- households
-- FKs to residents and poverty_thresholds both exist; residents.household_id
-- FK is added after this table so the circular reference resolves cleanly.
-- ----------------------------------------------------------------------------
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

ALTER TABLE `residents`
  ADD CONSTRAINT `fk_resident_household` FOREIGN KEY (`household_id`)
    REFERENCES `households`(`id`) ON DELETE SET NULL;

-- ----------------------------------------------------------------------------
-- household_profiles
-- ----------------------------------------------------------------------------
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

-- ----------------------------------------------------------------------------
-- otp_codes
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `otp_codes` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `account_type` ENUM('resident','official') NOT NULL,
  `account_id` INT NOT NULL,
  `code` VARCHAR(6) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `attempts` TINYINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_otp_account` (`account_type`,`account_id`,`used`,`expires_at`),
  KEY `idx_otp_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- document_requests
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `document_requests` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `reference_no` VARCHAR(50) NOT NULL,
  `resident_id` INT NOT NULL,
  `resident_name` VARCHAR(100) NOT NULL,
  `document_type` VARCHAR(100) NOT NULL,
  `purpose` VARCHAR(150) NOT NULL,
  `request_fields_json` JSON NULL,
  `copies` INT NOT NULL DEFAULT 1,
  `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `payment_status` ENUM('unpaid','paid','waived') NOT NULL DEFAULT 'unpaid',
  `date_requested` VARCHAR(30) NOT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
  `date_completed` DATETIME NULL DEFAULT NULL,
  `generated_document_path` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference_no` (`reference_no`),
  CONSTRAINT `fk_request_resident` FOREIGN KEY (`resident_id`)
    REFERENCES `residents`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- document_prices
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `document_prices` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `document_type` VARCHAR(100) NOT NULL,
  `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 20.00,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_type` (`document_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `document_prices` (`document_type`, `unit_price`) VALUES
  ('Barangay Clearance', 20.00),
  ('Certificate of Residency', 20.00),
  ('Certificate of Indigency', 20.00),
  ('Certificate of Good Moral Character', 20.00),
  ('Business Permit Endorsement', 20.00);

-- ----------------------------------------------------------------------------
-- complaints
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `complaints` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `resident_id` INT DEFAULT NULL,
  `reference_no` VARCHAR(50) NOT NULL,
  `resident_name` VARCHAR(100) NOT NULL,
  `category` VARCHAR(100) NOT NULL,
  `severity` ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `location_text` VARCHAR(150) NOT NULL,
  `description` TEXT NOT NULL,
  `date_filed` VARCHAR(30) NOT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'open',
  `official_note` VARCHAR(255) NOT NULL DEFAULT '',
  `resident_confirmation` ENUM('pending','confirmed','reopened') NULL DEFAULT NULL,
  `confirmed_at` DATETIME NULL DEFAULT NULL,
  `reopened_at` DATETIME NULL DEFAULT NULL,
  `extra_fields_json` JSON NULL DEFAULT NULL,
  `assigned_official_id` INT NULL DEFAULT NULL,
  `sla_due_at` DATETIME NULL DEFAULT NULL,
  `escalated_at` DATETIME NULL DEFAULT NULL,
  `is_public` TINYINT(1) NOT NULL DEFAULT 0,
  `duplicate_of_id` INT NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference_no` (`reference_no`),
  KEY `idx_complaint_sla` (`status`, `escalated_at`, `sla_due_at`),
  KEY `idx_complaint_public` (`is_public`, `duplicate_of_id`, `id`),
  KEY `idx_complaint_duplicates` (`duplicate_of_id`, `is_public`, `id`),
  CONSTRAINT `fk_complaint_resident` FOREIGN KEY (`resident_id`)
    REFERENCES `residents`(`id`),
  CONSTRAINT `fk_complaint_duplicate` FOREIGN KEY (`duplicate_of_id`)
    REFERENCES `complaints`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_complaint_assignee` FOREIGN KEY (`assigned_official_id`)
    REFERENCES `barangay_officials`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- notifications
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `resident_id` INT DEFAULT NULL,
  `title` VARCHAR(120) NOT NULL,
  `body` VARCHAR(255) NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` VARCHAR(30) NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_notification_resident` FOREIGN KEY (`resident_id`)
    REFERENCES `residents`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- audit_log
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `official_id` INT DEFAULT NULL,
  `official_name` VARCHAR(100) NOT NULL,
  `action` VARCHAR(100) NOT NULL,
  `target_type` VARCHAR(50) NOT NULL,
  `target_reference` VARCHAR(100) DEFAULT NULL,
  `details` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_audit_official` FOREIGN KEY (`official_id`)
    REFERENCES `barangay_officials`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- announcements
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `announcements` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `content` TEXT NOT NULL,
  `expires_at` DATETIME NULL,
  `is_pinned` TINYINT(1) DEFAULT 0,
  `posted_by` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_announcement_official` FOREIGN KEY (`posted_by`)
    REFERENCES `barangay_officials`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- local_services
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `local_services` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `service_name` VARCHAR(255) NOT NULL,
  `category` VARCHAR(100) NOT NULL,
  `contact_number` VARCHAR(20),
  `address` VARCHAR(255),
  `operating_hours` VARCHAR(100),
  `description` TEXT,
  `posted_by` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_service_official` FOREIGN KEY (`posted_by`)
    REFERENCES `barangay_officials`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- complaint_updates
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `complaint_updates` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `complaint_id` INT NOT NULL,
  `actor_type` ENUM('resident','official','system') NOT NULL,
  `actor_id` INT NULL,
  `from_status` VARCHAR(30) NULL,
  `to_status` VARCHAR(30) NOT NULL,
  `note` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_complaint_time` (`complaint_id`, `created_at`),
  CONSTRAINT `fk_update_complaint` FOREIGN KEY (`complaint_id`)
    REFERENCES `complaints`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- complaint_comments
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `complaint_comments` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `complaint_id` INT NOT NULL,
  `actor_type` ENUM('resident','official') NOT NULL,
  `actor_id` INT NOT NULL,
  `body` TEXT NOT NULL,
  `deleted_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_comment_thread` (`complaint_id`, `deleted_at`, `created_at`, `id`),
  CONSTRAINT `fk_comment_complaint` FOREIGN KEY (`complaint_id`)
    REFERENCES `complaints`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- complaint_attachments
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `complaint_attachments` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `complaint_id` INT NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `mime` VARCHAR(64) NOT NULL,
  `byte_size` INT NOT NULL,
  `uploaded_by_type` ENUM('resident','official') NOT NULL,
  `uploaded_by_id` INT NOT NULL,
  `deleted_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_complaint` (`complaint_id`, `deleted_at`),
  CONSTRAINT `fk_attach_complaint` FOREIGN KEY (`complaint_id`)
    REFERENCES `complaints`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- sms_log
-- ----------------------------------------------------------------------------
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

-- ----------------------------------------------------------------------------
-- resident_sms_preferences
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `resident_sms_preferences` (
  `resident_id` INT NOT NULL,
  `complaints_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `requests_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `announcements_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`resident_id`),
  CONSTRAINT `fk_sms_pref_resident` FOREIGN KEY (`resident_id`)
    REFERENCES `residents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- household_ai_reports
-- ----------------------------------------------------------------------------
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

-- ----------------------------------------------------------------------------
-- ai_request_log
-- ----------------------------------------------------------------------------
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

SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------------------------
-- Seed: default admin account
-- CHANGE BEFORE PRODUCTION. Test-environment default.
-- Credentials: gem / admin123
-- Copy the bcrypt hash verbatim from the previous database.sql. Do NOT
-- regenerate it — the hash is already a valid bcrypt of "admin123".
-- ----------------------------------------------------------------------------
INSERT INTO `barangay_officials`
  (`fname`, `lname`, `username`, `role`, `address`, `contact`,
   `password`, `position`, `status`, `created_at`)
VALUES
  ('Gem', 'Lander', 'gem', 'admin', 'Purok 1, Barangay Sampaguita',
   '+639999999999',
   '$2y$10$tdR115sHmFzrezadn3Pxfe9PLZVzaz7FS4Vvh7dAN4JPPfIjNtlm.',
   'Barangay Captain', 'active', CURRENT_TIMESTAMP);
