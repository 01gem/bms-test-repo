-- Append-only event log for every status transition on a complaint.
-- complaints.official_note remains as a denormalized "latest note" cache so
-- older clients keep working; the timeline is the source of truth going forward.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS + FK ON DELETE CASCADE.

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
