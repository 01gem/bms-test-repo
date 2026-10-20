-- Cap purok values to Purok 1 through Purok 5.
-- Brgy. Sampaguita has five puroks. Seed data used Purok 6 and Purok 7.
--
-- Mapping (Purok 1, Purok 2, and Purok 5 are not updated):
--   Purok 6 -> Purok 3
--   Purok 7 -> Purok 4
-- The same substring replacement is applied to households.address and
-- residents.address. Example: 'Purok 6, Brgy. Sampaguita' becomes
-- 'Purok 3, Brgy. Sampaguita'.
--
-- From a 5-household-per-purok seed this yields:
--   Purok 1 = 5, Purok 2 = 5, Purok 3 = 10, Purok 4 = 10, Purok 5 = 5.
--
-- Idempotent: every UPDATE matches only the old value, so a second run
-- changes zero rows.
--
-- Revert is not safe from purok_zone alone. After this file runs, an
-- original Purok 3 row and a former Purok 6 row both read 'Purok 3'
-- (same for Purok 4 and former Purok 7). Restore a backup taken before
-- this migration.
--
-- Seeded rows only, if no later edit mixed them with original Purok 3/4
-- rows, were:
--   households.id 26-30 and their residents: Purok 6,
--     address 'Purok 6, Brgy. Sampaguita'
--   households.id 31-35 and their residents: Purok 7,
--     address 'Purok 7, Brgy. Sampaguita'
-- Any non-seed row that was Purok 6 or Purok 7 cannot be separated from
-- an original Purok 3 or Purok 4 row after this migration.
--
-- Run: mysql -uroot barangay_system_test_environment < database/migration_cap_puroks_to_five.sql

START TRANSACTION;

UPDATE `households`
   SET `purok_zone` = 'Purok 3'
 WHERE `purok_zone` = 'Purok 6';

UPDATE `households`
   SET `purok_zone` = 'Purok 4'
 WHERE `purok_zone` = 'Purok 7';

UPDATE `households`
   SET `address` = REPLACE(`address`, 'Purok 6', 'Purok 3')
 WHERE `address` LIKE '%Purok 6%';

UPDATE `households`
   SET `address` = REPLACE(`address`, 'Purok 7', 'Purok 4')
 WHERE `address` LIKE '%Purok 7%';

UPDATE `residents`
   SET `purok_zone` = 'Purok 3'
 WHERE `purok_zone` = 'Purok 6';

UPDATE `residents`
   SET `purok_zone` = 'Purok 4'
 WHERE `purok_zone` = 'Purok 7';

UPDATE `residents`
   SET `address` = REPLACE(`address`, 'Purok 6', 'Purok 3')
 WHERE `address` LIKE '%Purok 6%';

UPDATE `residents`
   SET `address` = REPLACE(`address`, 'Purok 7', 'Purok 4')
 WHERE `address` LIKE '%Purok 7%';

COMMIT;
