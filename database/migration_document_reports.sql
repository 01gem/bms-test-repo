-- Document-request billing and complaint severity for AI Analytics reports.
-- RUN ONCE. Not idempotent: ALTER TABLE will fail if the columns already exist.
-- Select barangay_system_test_environment before running.

ALTER TABLE `document_requests`
  ADD COLUMN `copies` INT NOT NULL DEFAULT 1 AFTER `purpose`,
  ADD COLUMN `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `copies`,
  ADD COLUMN `payment_status` ENUM('unpaid','paid','waived') NOT NULL DEFAULT 'unpaid' AFTER `total_amount`;

ALTER TABLE `complaints`
  ADD COLUMN `severity` ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium' AFTER `category`;

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

-- Existing rows: one copy at the current unit price. Completed requests count as paid.
UPDATE `document_requests` dr
LEFT JOIN `document_prices` dp ON dp.document_type = dr.document_type
SET
  dr.copies = 1,
  dr.total_amount = ROUND(COALESCE(dp.unit_price, 20.00) * 1, 2),
  dr.payment_status = CASE
    WHEN dr.status = 'completed' THEN 'paid'
    WHEN COALESCE(dp.unit_price, 20.00) <= 0 THEN 'waived'
    ELSE 'unpaid'
  END;
