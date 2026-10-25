# Complaint interface regression checks

Run from the project root:

```powershell
node tests/review-2026-10-24/render-check.js
php tests/review-2026-10-24/run.php
```

The JavaScript runner checks safe rendering, tab selection and draft retention,
single-flight saves and comments, failure recovery, note/comment limits,
unsaved-edit choices, and assignment-control removal. It uses the existing
lightweight DOM fixture and Node's VM; no frontend dependencies are needed.

The PHP runner includes `api-checks.php` in its existing disposable schema.
It checks persistence, omitted fields, unchanged saves, Unicode note validation,
authorization, assignment/response-target preservation, timeline ordering,
notification counts, resident confirmation/reopening, comments, and attachment
counts. Existing access, attachment, recovery, and SMS regression checks also run.
External provider credentials are blanked and cURL is disabled in the test server.
Application database row counts are compared before and after the run.

The existing PHP runner requires local MySQL and its configured Laragon binaries.
It replaces earlier `capstone_fix24_*` disposable schemas and writes temporary
server/session configuration to `tests/review-2026-10-24/server.json`.
Do not commit that generated configuration. Its server is left running for manual
browser checks; stop that specific PHP server when finished.

`visual-fixture.php` optionally adds a clearly labelled browser fixture to the
runner's disposable schema. Its evidence images are synthetic test assets,
served through the same authorized route as real complaint photos. The script
refuses any database name outside the runner's schema pattern.

```powershell
php tests/complaint-interface/visual-fixture.php
php tests/complaint-interface/visual-fixture.php stress
php tests/complaint-interface/visual-fixture.php restore
```

For browser validation, inspect the card and all tabs at 1440px, then the stacked
content and management form at 768px and 390px. Check keyboard wrapping, tab arrow
keys, Escape, keep/discard choices, focus restoration, an offline save and retry,
multiline escaped text, empty evidence, failed images, and original-photo links.
Reset network emulation and viewport overrides after testing.
