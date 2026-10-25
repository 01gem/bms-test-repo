# Document request concurrency and rollout

Implemented 25 October 2026. The controlled local rollout and acceptance are now
complete: see [migration, scheduler and final acceptance](request-rollout-acceptance.md).
The development-phase verification below used disposable schemas and no real SMS.
No commits or pushes were performed.

## Scope and changed files

The five application writers found by searching the repository were
`api/requests/create.php`, `update_status.php`, `update_payment.php`,
`notify_ready.php`, and `generate_document.php`. Creation keeps its existing
resident-facing contract; a newly inserted request receives version 1 and cycle 0
from database defaults. The four official actions now require `expected_version`
(a JSON integer >= 1) and `operation_id` (a UUID). No other application writer was
found. Existing analytics/reporting readers continue using the same business fields.

- `_workflow.php` owns request transactions, version checks, normalized operation
  fingerprints, checked audit/notification inserts, and status/payment eligibility.
- `_generation.php` owns generation claims, publication, compensation and timeout;
  `_render.php` retains the existing template mappings, field precedence, and fixed
  signatory while isolating conversion to one private workspace/profile.
- `_dispatch.php` owns pickup-event claiming, delivery, and abandoned-work recovery.
- `list.php` returns numeric versions and official pickup state, excluding leases.
- `js/official.js` embeds reviewed versions in displayed action buttons, snapshots
  generation forms, disables pending actions, keeps retry IDs, retains conflict
  drafts, and preserves HTTP status/structured errors. Generation no longer calls
  a separate Processing endpoint. `pages/official.php` already uses filemtime
  cache busting for this script.
- `api/sms.php` adds uncertainty metadata to existing send results; its preference,
  normalization, logging and cap decision tree is retained.
- Fresh schema, additive migration, CLI dispatch/cleanup scripts, focused PHP/JS
  suites and browser fixtures are included. Complaint code/layout is preserved.

## Contract and guarantees

Operation IDs are globally unique and bound to authenticated actor, action,
request, expected version and normalized payload. The claim is committed before
business work. Exact replays return the durable outcome before checking staleness;
running duplicates return `409 in_progress`. Payload/actor reuse returns
`409 operation_reused`. A lost response must be retried with its original ID/input.
Known failed outcomes are terminal; a new reviewed attempt uses a new ID.

Business writes lock request, then operation, then any event. Audit and resident
notification inserts share the mutation transaction. SQL errors throw and cause
explicit rollback. Connections use a three-second lock wait; a deadlock/lock timeout
has at most one database-only retry. Business conflicts and external calls are not
automatically retried. Sessions are revalidated and written/closed before work.

Transitions are Pending -> Processing by successful generation, Processing ->
Ready, Ready -> Completed, and Pending/Processing -> Cancelled with a reason.
Completion stamps once, pays Unpaid, and preserves Waived. Payment allows Unpaid ->
Paid/Waived on nonterminal requests; Paid during Processing is valid. Unchanged
submissions have `changed:false` and no new audit, resident notification or SMS.

Mark Ready and Notify via SMS remain separate. Entering Ready increments a persisted
readiness cycle; payment does not change that cycle. The database enforces a unique
initial event `(request_id, readiness_cycle, sequence_no=0)`. Explicit resends have
new sequence numbers, operation IDs and audits, a five-minute cooldown, and cannot
queue while another delivery is queued/sending. An uncertain send requires provider
reconciliation or an explicit acknowledgement before a new resend intent.

Generation has one token per request, a 180-second publication lease, a 60-second
conversion timeout, an isolated LibreOffice profile and UUID filenames. Conversion
holds no database row lock. Publication rechecks version, Pending eligibility,
unexpired token and operation state, then atomically persists the path, Processing,
version, audit, resident notice and outcome. Files are created exclusively and
never overwrite a previous published document. A confirmed rollback removes only
the new operation's unreferenced files. An unavailable database/uncertain commit
retains files for recovery. Filesystem and database are not one atomic resource:
crashes may leave harmless unreferenced UUID files or private temporary workspaces.
Physical disk loss/corruption and cross-system durability are outside this guarantee.

## Deployment order (reference for other installations)

1. Back up the database and generated documents. Put request mutation routes in
   maintenance mode, drain old PHP workers/conversions, and stop dispatchers. Keep
   writers stopped throughout the migration/backend replacement; do not run old
   handlers against a partially upgraded deployment.
2. Select the existing application database and apply
   `project-support/migrations/20261025_request_concurrency.sql` **once**. Do not import
   `database/database.sql` into an existing installation. MySQL DDL is not one
   atomic transaction: inspect migration state if interrupted before proceeding.
   Existing records/paths/audits are retained. Old Ready rows receive cycle 1 and
   an uncertain legacy initial event because prior SMS history cannot reliably be
   associated with a request. Review provider records before reconciling/resending.
3. Deploy the backend helpers and all four endpoints/list together. Verify InnoDB
   tables and permissions to insert operations/events/audits/notifications. Old
   cached clients fail closed with 422 when concurrency fields are absent.
4. Deploy `js/official.js`, purge any external HTML/CDN caches, then reopen traffic.
   The existing PHP filemtime query changes the script URL. Officials should reload
   open pages; stale forms are still rejected by the backend.
5. Configure a scheduler to invoke the dispatcher every minute with the same PHP
   runtime and environment/database configuration used by this application. The
   existing `db.php` remains the source of connection configuration. Use Task
   Scheduler on Windows (hidden execution) or the installation's equivalent. The
   script performs a bounded batch of 50 events per invocation. Monitor failures
   and `uncertain` events. Concurrent invocations are safe.

```powershell
php project-support/scripts/dispatch-request-notices.php
php project-support/scripts/dispatch-request-notices.php reconcile 123 sent "Operator name; provider message ID and evidence"
php project-support/scripts/dispatch-request-notices.php reconcile 123 failed "Operator name; verified no provider acceptance"
php project-support/scripts/cleanup-request-artifacts.php
# Inspect the dry run before removing confirmed failed, unreferenced files:
php project-support/scripts/cleanup-request-artifacts.php --apply
```

The dispatcher durably marks `sending` before the provider call and records
sent/skipped/failed/uncertain afterward. A sending lease older than 120 seconds
becomes uncertain, never queued. Running operations older than ten minutes are
recovered after locking the request/operation; generation tokens are cleared and
the original operation receives a terminal conflict. An expired generation token
cannot be stolen before recovery. CLI reconciliation changes only an uncertain
event, records an audit with the supplied operator evidence, and never sends SMS.
Legacy reconciliation records the numeric request ID in audit target reference.

Cleanup is a dry run by default and only considers terminal failed generation
operations over one day old with no request reference. Unknown/committed outcomes
and previous valid documents are retained. Inspect old `barangay-doc-*` directories
under the PHP temporary directory manually after confirming that their owning
process is gone; the janitor does not recursively remove unknown workspaces.

## Delivery limits

There is no claim of exactly-once provider delivery. A timeout, process death, or
lost provider acknowledgement can follow acceptance. Such outcomes require review
or an acknowledged explicit resend. A request completed before dispatcher claim
suppresses the queued event. Completion immediately after claim can still race the
network send; avoiding that window would require holding a lock across the provider
or provider cancellation support. Status is never reset by notification delivery.

The retained shared SMS cap counts **sent sms_log rows** for resident/event during
the preceding 24 hours. Same-request deliveries are serialized by the ledger, but
different requests for the same resident can concurrently read the same remaining
allowance. Uncertain acceptance and failed sms_log writes can also undercount.
The cap remains best effort across requests; a strict global reservation scheme
was outside the requested narrow shared-SMS change. The event ledger is the durable
request-specific delivery state even when the shared log is unavailable.

## Executed verification

The development suites below used disposable schemas and CLI workers. The later
authorized application migration is recorded in the rollout report. HTTP-style mutation workers use distinct official
sessions and independent PHP processes/MySQL connections. File and database
barriers hold both operations before releasing the row; no guessed timing sleeps
determine winners. Short polling only observes barrier readiness with a deadline.
The generation service tests defer rendering/publication explicitly. Real provider
calls are replaced by in-process mocks or disabled credentials/cURL.

| Command | Result |
| --- | --- |
| `php project-support/tests/request-concurrency/baseline.php` | Two original SQL races reproduced in isolation |
| `php project-support/tests/request-concurrency/run.php` | 72 passed, 0 failed |
| `node project-support/tests/request-concurrency/frontend.js` | 14 passed, 0 failed |
| `node project-support/tests/review-2026-10-24/render-check.js` | 131 passed, 0 failed |
| `php project-support/tests/complaint-interface/storage-config-check.php` | 9 passed, 0 failed |
| `php project-support/tests/review-2026-10-24/run.php` | 117 passed, 0 failed; application row counts unchanged |
| `php -l` on changed/new PHP sources | 16 files, no syntax errors |
| `node --check js/official.js` and `node --check project-support/tests/request-concurrency/frontend.js` | Passed |
| `git diff --check` | Passed |

The baseline reproduced Paid overwritten by Waived with overlapping connections
and Completed reset to Ready by the original notification SQL. These are SQL-level
reproductions, not authenticated pre-fix browser tests. Original baseline code is
kept only in a CLI-gated disposable-schema test.

The new suite checks final status/payment/timestamps/versions, operation outcomes,
audit/resident notice counts, event counts and mocked send counts; overlapping
same-status/payment/initial-notice/resend/duplicate/generation decisions;
generation versus cancellation/payment; audit and notification rollback; malformed,
missing and stale concurrency fields; unauthenticated actions; replay before stale
checks; terminal/unchanged actions; obsolete queued deliveries; definitive failure,
uncertainty, recovery, opt-out/contact validation and daily caps; publication failures
preserving prior file bytes and cleaning new files; converter timeout/old PDFs, lost commit acknowledgements, expired leases,
abandoned missing-request recovery, stable readiness cycles across payment; and
different requests proceeding independently. An installed LibreOffice converted a
real template to a newly validated PDF/DOCX in a disposable private workspace.

Frontend VM checks cover embedded reviewed versions even after polling, retained
drafts, explicit generation review with no automatic resubmission, pending-click
suppression, reused retry IDs/new action IDs, structured HTTP errors, saved-but-
refresh-failed messaging, and generation A completing after switching/closing its
modal. These VM checks are behavioral fixtures, not a complete browser test suite.

Browser validation used the existing regression server's disposable schema with
two real logins: Request Browser A on 127.0.0.1 and Request Browser B on localhost,
which have separate host-scoped PHP session cookies. Both reviewed the same
Processing/Unpaid request. A marked Paid; B's displayed Waive action received a
conflict, refreshed to Paid, disabled payment actions, and showed exactly:
“This request changed. Review the current information before trying again.”
After reloading the final frontend, two Mark Ready decisions also produced one
transition and one conflict. The superseded disposable-browser screenshots and
one-off request fixture launcher were archived outside the project during cleanup.
Use the [final migrated-installation evidence](rollout-acceptance-conflict.jpg).
The earlier browser server was single-threaded, so it validated UI/session/conflict behavior;
genuine overlap is established by the multi-process CLI suite. SMS UI is disabled
on this fixture (provider configuration intentionally blank). Full browser
generation, resend dialogs, network interruption and responsive checks were not
executed; their relevant logic is covered by service/VM tests.

The defense is that a decision can mutate only its locked, reviewed version, while
an operation replay is answered from durable history. Unique cycles/claims guard
external intent separately. Tests demonstrate one accepted competing decision,
zero partial mutations after injected failures, and no repeated provider calls on
replay/duplicate dispatch. Files remain operation-owned until a revalidated commit.
