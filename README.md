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
    database/            schema (database.sql), household seed, engine test
    js/                  front-end controllers (one per portal)
    pages/               PHP-rendered pages (login, resident, official)

## Setup

1. Requires PHP 8.1+, MySQL 5.7+, and the PHP `mysqli`, `curl`, and
   `zip` extensions. Document generation additionally requires LibreOffice
   installed at `C:\Program Files\LibreOffice\program\soffice.exe` on
   Windows.

2. Create the schema in this order:

       mysql -uroot barangay_system_test_environment < database/database.sql
       mysql -uroot barangay_system_test_environment < database/seed_households.sql

   `database.sql` builds the complete schema and seeds the default admin
   account, the poverty thresholds, and the document prices. `seed_households.sql`
   then loads the 35-household fixture. Both files are idempotent when run
   against a database they created.

3. Create a `.env` file at the project root with:

       GEMINI_API_KEY=...              # required for AI features
       GEMINI_MODEL=gemini-3.5-flash-lite   # optional; this is the default
       HTTPSMS_API_KEY=...             # required for SMS features
       HTTPSMS_FROM_NUMBER=+63...
       HTTPSMS_SIM_SLOT=SIM1
       COMPLAINT_ATTACHMENT_STORAGE_DIR=C:/laragon/private/Capstone-System-COPY/complaint_attachments

   `COMPLAINT_ATTACHMENT_STORAGE_DIR` is required for new complaint photos.
   It must be an absolute directory outside this project and outside the
   web server's document root. The example above sits outside
   `C:/laragon/www`. Create the directory and make it writable by PHP.
   Do not point it at `complaint_attachments/` inside this project. If the
   setting is missing, unreadable, or inside a served tree, new uploads
   fail and are not stored in the public project tree. Older rows shaped
   `complaint_attachments/{complaint_id}/{uuid}.jpg|png` stay where they
   are and are readable only through `api/complaints/view_attachment.php`.

4. Block direct web access to legacy evidence. `complaint_attachments/.htaccess`
   denies all access on Apache 2.4 (`Require all denied`). Confirm a copied
   legacy URL returns 403 or 404, including a soft-deleted file. PHP's
   built-in server ignores `.htaccess`, so that check has to run on the
   deployed server. Nginx needs an equivalent denial, for example
   `location ^~ /complaint_attachments/ { deny all; return 403; }`. IIS needs
   a location rule that denies all users for `complaint_attachments`. The
   private storage directory must also stay outside every static alias.
   A denial rule that the server does not actually enforce leaves the old
   files readable by URL.

5. Serve the project root with any PHP-capable web server. The default
   admin account from the seed is `gem` / `admin123`; change it before
   exposing the app.

## Verifying the MPI engine

The engine has a hand-computed fixture of 35 seeded households. Run:

    php database/test_mpi_engine.php

The test loads households whose ids are 1-35 (the range
database/seed_households.sql owns), reads each linked profile and
threshold, and diffs the computed assessment against the seed's stored
values. Expected output ends with `Results: 35/35 match`. Any mismatch is
an engine bug, not a data bug.

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
- A pickup SMS that ends `failed` is retried by the scheduled dispatcher with
  a fixed 5 / 15 / 60 / 180-minute backoff. After four requeues the event is
  left in `failed` for operator reconciliation via
  `php project-support/scripts/dispatch-request-notices.php reconcile EVENT_ID sent|failed "evidence"`
  or by re-marking the request Ready (which creates a fresh readiness cycle).
  See [request-concurrency](project-support/docs/request-concurrency.md).
- Registries read from `enrichedRegistryRows()`, which reconciles
  residents against `HOUSEHOLD_REGISTRY` on every render. A resident
  whose `household_id` changes mid-session may show stale MPI until
  both `loadResidents()` and `loadHouseholds()` re-run.
- No CSRF protection on POST endpoints beyond `SameSite=Lax` cookies.
  See `db.php` for the current session cookie settings. Adding CSRF is
  a cross-cutting change (every POST endpoint plus a middleware check)
  and is not in scope for this deployment pass.
- No migration runner. `database.sql` is the fresh-install schema; additive
  upgrade scripts live in `project-support/migrations/`. If upgrading a database built with the old `migration_*.sql`
  chain, apply those migrations once before deleting them; then
  `database.sql` becomes the fresh-install entry point.
- Existing databases must also apply
  `project-support/migrations/20261025_resident_households.sql`. It assigns
  each orphan resident to a new household before making `residents.household_id`
  mandatory. Back up the database first and stop resident writes while applying
  the migration.
- Document-specific extra fields (e.g. civil_status for Barangay
  Clearance) are captured at request time in
  `document_requests.request_fields_json` and pre-fill the official
  generate-document modal. Fields are whitelisted per document type in
  `api/requests/create.php`; unknown keys in the resident's payload are
  silently dropped. The resident's birthdate remains the source of truth
  for age — the value the resident typed is stored for audit but the
  generator computes it fresh from birthdate when the modal left age
  blank.

## Release notes

### 2026-10-26

- **Announcement in-app notifications are now independent of SMS opt-out.**
  Residents who disabled announcement SMS now still receive in-app
  announcement notifications. The "Announcements" checkbox on the resident
  profile page suppresses only the SMS channel.
- **Pickup SMS retries.** A failed pickup SMS is retried by the scheduler up
  to four times (5 / 15 / 60 / 180 minutes, ~4h20m total from the original
  attempt). After the fourth retry the event stays `failed` for operator
  reconciliation. The panel message now says "will retry up to four times
  with backoff" instead of the earlier incorrect "the scheduler will retry."
- **`database.sql` now includes `request_notification_events.retry_count`.**
  Fresh installs no longer need to apply
  `project-support/migrations/20261026_request_notification_retry.sql` after
  `database.sql`; existing installs still do. See
  `project-support/docs/request-concurrency.md`.

## Document request concurrency rollout

Existing installations must apply the additive request migration before enabling
the updated handlers. See [rollout, dispatch/recovery and test evidence](project-support/docs/request-concurrency.md).
The consolidated schema remains the entry point for fresh installations.
The [local controlled rollout and final acceptance](project-support/docs/request-rollout-acceptance.md)
have passed; the local issue is closed.
Run `python project-support/scripts/update-source-code.py` to refresh the local source text snapshot.
See [packaging and maintenance](project-support/docs/maintenance.md) for runtime-only deployment
ZIPs, scheduled log rotation, protected backup retention and cleanup verification.

## License

GNU General Public License v3.0 or later. See `LICENSE`.
