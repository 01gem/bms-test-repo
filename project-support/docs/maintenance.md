# Packaging and maintenance

The concurrency implementation, migration, dispatcher, source-export utility,
regression tests and current rollout evidence are retained. Closing the issue does
not make the runtime helpers or operation/event ledgers disposable.

## Workspace cleanup

The superseded request browser fixture launcher and two disposable-browser
screenshots were archived outside the served workspace, then removed from the
project. The final migrated-installation screenshot remains in this directory.
Historical links now point to that final evidence.

Generated `project-support/tests/review-2026-10-24/server.json` is no longer tracked. It contains
temporary session cookies, so the runner regenerates it locally and Git ignores
it. `server.example.json` documents the shape with no usable credentials. The
example is not a working test configuration; run the PHP suite to create one.

## Deployment packages

Preview a runtime package without creating files:

```powershell
python project-support/scripts/build-deployment.py
python project-support/scripts/build-deployment.py --list
python project-support/scripts/build-deployment.py --output C:/laragon/backups/BarangayLink/deployment-packages/barangaylink.zip
```

The builder reads current Git-listed tracked/untracked source files. It includes
the active PHP/JS/CSS, assets, templates, schema/migration, runtime scaffolding and
dispatcher/maintenance tools. It excludes tests, historical reports/screenshots,
generated session configuration, `.env` files, uploaded/generated documents,
`source-code.txt`, development tools and Git metadata. Existing ZIPs are never
overwritten, and output must be outside the served project. Keep environment
configuration and existing data on the installation; a deployment ZIP is not a
database restore or a replacement for a backup. Credentials must not be embedded
in runtime source files.

## Log rotation and backup retention

The installed dispatcher wrapper invokes logs-only maintenance after dispatch.
Its configuration includes an explicit Python executable path. The versioned
wrapper source is `project-support/scripts/run-pickup-dispatcher.ps1`; its installed copy remains
`C:/laragon/var/BarangayLink/Capstone-System-COPY/Run-PickupDispatcher.ps1`.
The task's cadence and SMS behavior are unchanged.

- Rotate `dispatcher-runs.jsonl` at 5 MiB into a uniquely named JSONL archive.
- Delete matching log archives older than 90 days, retaining the newest three.
- Never remove unrelated files, linked/junction trees, or unknown backup folders.
- Report maintenance failures separately from a completed dispatch result.
- The scheduled command uses `--logs-only`: it cannot delete a backup.

Backups are preview-only unless an operator explicitly passes `--apply`:

```powershell
python project-support/scripts/maintain-request-storage.py
# Review every candidate before applying:
python project-support/scripts/maintain-request-storage.py --apply
```

Backup retention applies only to timestamp-named folders (`YYYYMMDD-HHMMSS`) whose
`.backup-complete.json` contains `{"kind":"barangaylink-backup","complete":true}`.
Create this marker only after verifying a complete database/document backup.
Incomplete/unmarked folders are left alone. Keep at least the newest three managed
backups and any backup containing a `.keep` file; only other managed backups older
than 90 days are candidates. The verified rollout backup at
`C:/laragon/backups/BarangayLink/20261025-181809` is explicitly pinned with `.keep`.
No existing backup was deleted during this cleanup.

The tool validates resolved target boundaries before recursive removal, refuses
links/junctions anywhere inside a backup, and serializes maintenance with a lock.
A stale `.storage-maintenance.lock` is not automatically stolen: confirm the old
process has stopped before removing it manually.

## Generated documents and history

Continue using `php project-support/scripts/cleanup-request-artifacts.php` as a dry run. It only
considers confirmed failed, unreferenced generation outputs older than one day.
Do not broadly delete `generated_documents/`, audit history, request operations or
notification events: the latter records protect replay and initial-notice uniqueness.
Artifact cleanup remains manual; published documents and the demonstration PDF/DOCX
are retained.

## Verification

```powershell
python -B project-support/tests/maintenance/test_cleanup.py
```

These tests use only temporary directories and verify packaging exclusions,
preview behavior, exclusive ZIP creation, log byte preservation, newest/pinned/
recent/incomplete backup retention, locking, and refusal of linked/outside targets.

On 25 October 2026 all **9 safety tests passed**. A real runtime ZIP containing
**125 files** was built and inspected at
`C:/laragon/backups/BarangayLink/deployment-packages/barangaylink-runtime-20261025.zip`.
Its runtime helpers/migration were present and test/evidence/environment/generated
document content was absent. No ZIP was deployed to the running application.
Every archived runtime file was also verified byte-for-byte against current source.

The installed task completed its 18:53 PHT scheduled run with both `exitCode: 0`
and `maintenanceExitCode: 0`. Rotation itself was exercised with temporary fixture
logs; the current small live log did not need rotation. Live storage/artifact dry
runs found no eligible removals. No existing backup or published document was
deleted. The optional complaint visual fixture also fails cleanly with instructions
when its generated server configuration is absent.
