-- One-time migration for databases created before every resident required a household.
-- Back up the database before applying. This migration creates one household for
-- each orphan resident, then makes the relationship mandatory and non-detachable.

SET @orphan_household_code_prefix = 'HH-ORPH-';

INSERT INTO households
  (household_code, head_resident_id, address, purok_zone, household_size, profile_status)
SELECT CONCAT(@orphan_household_code_prefix, r.id), r.id, r.address, r.purok_zone, 1, 'unprofiled'
FROM residents r
WHERE r.household_id IS NULL;

UPDATE residents r
JOIN households h
  ON h.household_code = CONCAT(@orphan_household_code_prefix, r.id)
SET r.household_id = h.id,
    r.relationship_to_household_head = 'Head'
WHERE r.household_id IS NULL;

ALTER TABLE residents
  DROP FOREIGN KEY fk_resident_household;

ALTER TABLE residents
  MODIFY household_id INT NOT NULL;

ALTER TABLE residents
  ADD CONSTRAINT fk_resident_household FOREIGN KEY (household_id)
  REFERENCES households(id) ON DELETE RESTRICT;
