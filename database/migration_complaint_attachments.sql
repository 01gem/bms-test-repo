-- Photo/document evidence attached to a complaint. Soft delete: the row and the
-- file both stay on disk for audit even after a user "deletes" it.
--
-- Path convention: complaint_attachments/{complaint_id}/{uuid}.{ext}
-- relative to project root. The front-end renders it as ../{path}, matching
-- the profile_img/ pattern already used by residents/upload_photo.php.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS.

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
