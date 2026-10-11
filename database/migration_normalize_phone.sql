-- Normalize existing PH mobile numbers to +63 format.
-- Idempotent: rows already starting with +63 are untouched.
UPDATE `residents`
SET `contact` = CONCAT('+63', RIGHT(REGEXP_REPLACE(`contact`, '[^0-9]', ''), 10))
WHERE `contact` NOT LIKE '+63%'
  AND REGEXP_REPLACE(`contact`, '[^0-9]', '') REGEXP '^(63|0)?9[0-9]{9}$';

UPDATE `barangay_officials`
SET `contact` = CONCAT('+63', RIGHT(REGEXP_REPLACE(`contact`, '[^0-9]', ''), 10))
WHERE `contact` NOT LIKE '+63%'
  AND REGEXP_REPLACE(`contact`, '[^0-9]', '') REGEXP '^(63|0)?9[0-9]{9}$';
