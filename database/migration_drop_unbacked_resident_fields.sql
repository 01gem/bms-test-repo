-- Phase 5 cleanup. These residents columns are not collected by any
-- Philippine government instrument (PSA CPH, FIES, LFS; DSWD Listahanan;
-- NCDA PWD registry; DOH FHSIS; TESDA NC registry). They cannot be
-- weighted, cross-validated, or calibrated against a public survey, so
-- they do not belong in a profiling schema.
--
-- - monthly_income_bracket   FIES records actual peso amounts, not
--                            brackets. The MPI engine reads
--                            household_profiles.monthly_* instead.
-- - number_of_dependents     Not a CPH/FIES/LFS field; the
--                            questionnaire uses num_children /
--                            num_working_age / num_seniors.
-- - years_of_residency       Not a survey field. The Certificate of
--                            Residency template prompts for it at
--                            generation time; never read from here.
-- - has_drivers_license      LTO registry concept, not a survey field.
-- - work_experience_years    LFS records employment status and hours
--                            worked, not years of experience.
-- - is_household_head        Redundant. Authority lives in
--                            households.head_resident_id; create.php
--                            already backfills it when household_mode
--                            = 'new', and relationship_to_household_head
--                            is set to 'Head' in the same branch.
-- - skills                   Free text with no PSA/PSOC mapping.
--
-- Deliberately NOT dropped here:
--   work_availability        Kept as a project choice for the Workforce
--                            Insights panel.
--   training_certifications  Maps to the TESDA NC registry and feeds the
--                            workforce classifier.
--
-- Run once. Not idempotent.
ALTER TABLE `residents`
  DROP COLUMN `monthly_income_bracket`,
  DROP COLUMN `number_of_dependents`,
  DROP COLUMN `years_of_residency`,
  DROP COLUMN `has_drivers_license`,
  DROP COLUMN `work_experience_years`,
  DROP COLUMN `is_household_head`,
  DROP COLUMN `skills`;
