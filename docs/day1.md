# Day 1 — Household Profiling Foundation

Scope: schema + fixtures only. No engine, no UI.

## Shipped
- `database/migration_household_profiling.sql`:
  - `poverty_thresholds`, `households`, `household_profiles` tables
  - `residents.household_id`, `residents.relationship_to_household_head`, `residents.profile_status`
- `database/seed_demo_households.sql`: 35 households, 100 residents, hand-computed MPI
- `api/common.php`: `next_household_code()`, `lookup_poverty_threshold()`

## Deferred to Day 2
- MPI computation engine
- Household API endpoints (create / list / get / update / save_profile / assess)
- Household tab in the official panel
- `profile_completeness` and `contextual_flags_json` UI

## Test DB
- Admin: username `gem`, password `admin123` (test env only)
- Residents: usernames `demo_<surname><nn>`, password `demo1234`

## Verification
Fresh DB + migration + seed should produce:
- 35 households, 14 MPI poor, 13 below poverty line, 5 in progress
- 100 residents linked to households