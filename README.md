# BarangayLink

A web-based integrated management information system for Barangay
Sampaguita, Tagana-an, Surigao del Norte. Resident self-service plus an
official operations console, with deterministic MPI-based household
assessment and on-demand AI commentary.

Copyright (C) 2026 Jun Gem Riege M. Dulduco.
Licensed under the GNU General Public License v3.0 (or later).
See the LICENSE file in the project root for the full text.

## What it does

- Resident portal: document requests, complaint filing, announcements,
  local services directory, notifications.
- Official console: population management, request processing, complaint
  triage, announcements, services management, audit log.
- Household profiling and Multidimensional Poverty Index (MPI) assessment
  per Philippine Statistics Authority methodology. Deterministic; the
  engine is pure and lives in `api/households/mpi_engine.php`.
- Optional Gemini-generated commentary on each household's MPI result.
  Commentary explains the deterministic score; it does not re-score.
- Optional SMS via httpSMS for pickup notices, complaint resolutions,
  password resets, and announcement broadcasts.

## Directory layout

    api/                 JSON endpoints, grouped by resource
      ai/                AI commentary, connectivity test, workforce classifier
      auth/              login, OTP, password reset
      households/        profiling wizard, registry, MPI engine
      ...
    css/                 stylesheets (one per portal)
    database/            schema, migrations, demo seed, engine test
    js/                  front-end controllers (one per portal)
    pages/               PHP-rendered pages (login, resident, official)

## Setup

1. Requires PHP 8.1+, MySQL 5.7+, and the PHP `mysqli`, `curl`, and
   `zip` extensions. Document generation additionally requires LibreOffice
   installed at `C:\Program Files\LibreOffice\program\soffice.exe` on
   Windows.

2. Create the schema in this order:

       mysql -uroot barangay_system_test_environment < database/database.sql
       mysql -uroot barangay_system_test_environment < database/migration_household_profiling.sql
       mysql -uroot barangay_system_test_environment < database/seed_demo_households.sql

   The seed is demonstration data only; do not run it against production.

3. Create a `.env` file at the project root with:

       GEMINI_API_KEY=...              # required for AI features
       GEMINI_MODEL=gemini-3.5-flash-lite   # optional; this is the default
       HTTPSMS_API_KEY=...             # required for SMS features
       HTTPSMS_FROM_NUMBER=+63...
       HTTPSMS_SIM_SLOT=SIM1

4. Serve the project root with any PHP-capable web server. The default
   admin account from the seed is `gem` / `admin123`; change it before
   exposing the app.

## Verifying the MPI engine

The engine has a hand-computed fixture of 35 demonstration households. Run:

    php database/test_mpi_engine.php

Expected output ends with `Results: 35/35 match`. Any mismatch is an
engine bug, not a data bug.

## Known caveats

- AI commentary persists in `household_ai_reports` and loads on page
  open. Regenerating over an existing row overwrites it. Per-row × and
  the header "Clear Commentaries" button remove reports. The batch and
  single endpoints are throttled per official via `ai_throttle_or_die()`
  in `api/ai/_shared.php` (200 households/hour default; workforce
  classifier 400). Raw call log lives in `ai_request_log`; one audit_log
  entry is written per Generate Reports click.
- Generated documents carry a static placeholder signatory
  (`Hon. Erwin Astronomo`). There is no designated-signing-official
  concept yet. Known product gap, not a bug.
- Polling uses a 20-second interval, section-scoped, with exponential
  backoff to 2 minutes on failure and paused while a modal is open or
  the tab is hidden. Sustained cost is ~0.05 req/s per active client.
  Past 100 concurrent users, replace polling with Server-Sent Events
  against a `/api/events/stream.php` endpoint.
- Registries read from `enrichedRegistryRows()`, which reconciles
  residents against `HOUSEHOLD_REGISTRY` on every render. A resident
  whose `household_id` changes mid-session may show stale MPI until
  both `loadResidents()` and `loadHouseholds()` re-run.
- No CSRF protection on POST endpoints beyond `SameSite=Lax` cookies.
  See `db.php` for the current session cookie settings. Adding CSRF is
  a cross-cutting change (every POST endpoint plus a middleware check)
  and is not in scope for this deployment pass.
- No migration runner. Migrations are `.sql` files applied by hand.
  Each file is idempotent and safe to re-run.

## License

GNU General Public License v3.0 or later. See `LICENSE`.
