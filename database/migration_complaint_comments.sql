-- Two-way complaint discussion. Soft deletion preserves the original row.
-- Actor IDs are polymorphic, matching complaint_updates.
-- Idempotent; apply after the complaints table exists.
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
    REFERENCES `complaints` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
