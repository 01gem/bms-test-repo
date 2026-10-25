# Controlled rollout and final acceptance

Status: **Closed — rollout and acceptance passed.**

Closed 25 October 2026 at 18:36:05 PHT (UTC+08:00). The user selected this local
task/report as the issue; no GitHub issue was changed. The target is the local
Laragon installation at `http://localhost/Capstone-System-COPY/`, database
`barangay_system_test_environment`. No Git commit or push was performed.

## Backup, pause and migration

Backups are retained outside the web root at
`C:\laragon\backups\BarangayLink\20261025-181809`:

- `application-before-pause.sql`: initial full database backup.
- `application-paused.sql`: second full backup while actions were paused.
- `generated_documents/` and `documents-manifest.json`: original documents/hashes.
- `implementation/`: matching implementation and operating-guide snapshot.
- Migration/acceptance receipts, scheduler evidence and the exported task XML.

The paused SQL backup was restored into a separate disposable schema. All existing
application table counts and all document hashes matched. That verification schema
was removed afterward; backups remain intact. Paused SQL SHA256:
`aa0c71ee75c01576c5c436674b10e2beeab474a331f7de7f84db6a8d5d6cdd39`.

All document-request mutation routes, including resident request creation, were
paused by a temporary Apache rule, verified with HTTP 503. There was no existing
pickup dispatcher or in-flight request transaction. Migration
`project-support/migrations/20261025_request_concurrency.sql` was applied **once**, at
**25 October 2026, 18:22:59 PHT**, after checking its columns/tables were absent.
Migration SHA256:
`cee64268842695bf39d3b36dc6261ddf4679da266ffcb5169b5a1270f91f71b4`.

All five affected tables are InnoDB. The eight original requests and paths were
preserved. Original audit and resident-notification history matched the restored
backup, and original generated files retained their SHA256 hashes. There were
**zero existing Ready requests**, so no migrated uncertain notices required review.

The temporary Apache pause was removed after migration. Apache served the exact
matching frontend bytes. Both acceptance officials' pages were reloaded and loaded
`official.js?v=1792922549`. Old clients missing concurrency fields receive 422.
No global Apache/service restart was needed. Other already-open official pages
should reload to display the new controls; the backend rejects unprotected calls.

## Pickup dispatcher

Windows Task Scheduler task **`BarangayLink-COPY-PickupDispatcher`** is enabled,
runs **every minute**, and ignores overlapping task instances. It uses the current
Windows user's limited interactive account. This local demonstration requires the
user to remain signed in and Laragon/MySQL to be running.

Persistent operational files are outside the web root:

- `C:\laragon\var\BarangayLink\Capstone-System-COPY\Run-PickupDispatcher.ps1`
- `C:\laragon\var\BarangayLink\Capstone-System-COPY\dispatcher-config.json`
- `C:\laragon\var\BarangayLink\Capstone-System-COPY\logs\dispatcher-runs.jsonl`

The wrapper now runs normal `project-support/scripts/dispatch-request-notices.php` using Laragon
PHP 8.3.30; configuration is **live**. Acceptance used a private provider mock that
refused nondesignated work. The mock bootstrap was removed after verification.
The task XML is retained with the backup.

| Actual scheduled start (PHT) | Mode | Exit | Result |
| --- | --- | --- | --- |
| 18:28:18, 25 October | Mock acceptance | 0 | One mock attempt; obsolete notice skipped |
| 18:29:19, 25 October | Mock acceptance | 0 | No queued work; no repeated attempt |
| 18:30:18 and 18:31:19, 25 October | Mock acceptance | 0 | No queued work |
| 18:32:18 and 18:33:18, 25 October | Normal dispatcher | 0 | No queued work; no provider attempt |

Further normal scheduled runs also succeeded. Task Scheduler reported
`LastTaskResult = 0`. Individual run receipts are included in the linked JSON and
persistent log. The acceptance `sent` event records a mock outcome, not verified
carrier delivery. No real carrier message was sent for acceptance.

Disable the Windows task or set the private configuration's `mode` to `paused` to
pause dispatch. Continue using the recovery/reconciliation commands in
[the concurrency guide](request-concurrency.md). Do not rerun this migration.

## Acceptance on the migrated installation

**34 HTTP/database/document checks passed**, plus the two-session browser warning
and page-reload checks. [Machine-readable results](rollout-acceptance-results.json)
record the individual checks, database receipts and scheduled-run evidence.

| Acceptance gate | Result |
| --- | --- |
| Conflicting officials | Distinct official logins and overlapping Apache/MySQL connections reached an explicit row-lock barrier. Paid/Waived produced one 200 and one 409, one version increment and one audit. A separate two-browser Mark Ready conflict displayed the exact required refresh warning. |
| Generation and Processing | Actual Apache/LibreOffice conversion produced a unique PDF/DOCX for request 9. DOCX XML and extracted PDF text contained the designated name, reference and purpose, with no unresolved placeholders. Processing, path, version, audit and resident notice persisted together. |
| Ready, payment, completion | Ready established cycle 1; payment preserved it. Completion stamped once, converted Unpaid to Paid and preserved Waived. Terminal reversal was rejected. |
| Pickup queue and dispatch | Two officials created one initial event. Replays/payment did not create another. Pending resend was rejected. The scheduler made one mock attempt and skipped an obsolete notice. |
| Refresh/retry | Generation and pickup replays returned recorded outcomes. Completion replay/unchanged completion preserved timestamp, version and effect counts. Refreshed state was Completed/Paid/version 5. |
| Existing data | Original request content/paths, audit/notification history and original document hashes matched the verified backup. |

Designated synthetic records use prefix **`ROLLOUT-20261025-102618`**: request 9
(generation/workflow), request 10 (concurrent decisions/browser conflict), request
11 (Waived completion), and request 12 (obsolete notice). The reference prefix uses
the PHP runtime's UTC naming; displayed report times are PHT. Temporary officials
2/3 and synthetic resident 101 are **inactive**. The synthetic resident is opted out
of future request SMS. Original accounts were not modified.

Final totals are 12 requests, 68 audits and 44 resident notifications, including
only the designated acceptance additions. No request operation remains running;
no pickup event is queued/sending. Request 10 remains a labeled Ready demonstration
fixture, created after migration; it is not an unresolved legacy notice.

Successful demonstration documents are retained:
`generated_documents/0fb5d265-ae09-457b-a669-e18229b4e89b.pdf` and its matching DOCX.
[Browser evidence](rollout-acceptance-conflict.jpg) shows the designated record and
“This request changed. Review the current information before trying again.”

## Cleanup and remaining limits

Removed the temporary Apache pause, cookie jars, temporary test password, one-shot
acceptance/control scripts, private mock bootstrap, PDF extraction scratch files,
path marker and disposable restore-verification schema. Temporary browser tabs
were closed. Backups, successful documents, labeled demonstration records,
regression tests and history were retained. No pre-existing application document
was deleted.

Live carrier delivery was outside acceptance scope. Exactly-once provider delivery
is not guaranteed. Timeouts/crashes after acceptance remain uncertain and require
reconciliation or an explicit acknowledged resend. Completion can race a send
already claimed by a dispatcher. The retained daily cap is best effort across
requests and uncertain/logging outcomes. Filesystem/database commits remain
separate: crashes can leave unreferenced artifacts, which need review before
cleanup. The existing artifact janitor remains dry-run by default and was not used
to remove application documents during rollout.

All requested rollout and acceptance gates passed. The local issue is closed.

## Post-closure cleanup

On 25 October 2026 the superseded disposable-browser files were archived outside
the project and removed, and generated test-server session configuration was
replaced in source control by a credential-free example. Runtime code, regression
tests, the migration, current acceptance evidence and valid documents remain.
The dispatcher now performs logs-only maintenance; backup deletion requires an
explicit reviewed apply operation. See [packaging and maintenance](maintenance.md)
for the policy, commands and verification results.
