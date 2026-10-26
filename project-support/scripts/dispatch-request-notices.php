<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// The script lives at project-support/scripts/ in the source tree but at
// <root>/scripts/ after build-deployment.py flattens it. Both layouts have
// db.php at the project root, so probe for it once and use the answer as the
// anchor for every relative path below.
$projectRoot = is_file(dirname(__DIR__, 2) . '/db.php')
    ? dirname(__DIR__, 2)   // source:   project-support/scripts/  → project root
    : dirname(__DIR__);     // deployed: scripts/                 → project root

require_once $projectRoot . '/api/requests/_dispatch.php';
try {
    $db = get_db();
    request_exec($db, 'SET SESSION innodb_lock_wait_timeout=3');
    request_recover($db);
    request_retry_failed($db);
    // Explicit human reconciliation; never queues a new provider call.
    if (($argv[1] ?? '') === 'reconcile') {
        $id = filter_var($argv[2] ?? '', FILTER_VALIDATE_INT);
        $state = $argv[3] ?? ''; $note = trim($argv[4] ?? '');
        if (!$id || !in_array($state, ['sent','failed'], true) || $note === '') throw new RuntimeException('Usage: reconcile EVENT_ID sent|failed "operator and provider evidence"');
        request_transaction($db, function () use ($db, $id, $state, $note) {
            $e = request_rows($db, 'SELECT * FROM request_notification_events WHERE id=? FOR UPDATE', [$id])[0] ?? null;
            if (!$e || $e['state'] !== 'uncertain') throw new RuntimeException('Only uncertain events can be reconciled.');
            request_exec($db, 'UPDATE request_notification_events SET state=?,reason=?,finished_at=NOW() WHERE id=?', [$state, mb_substr($note,0,255), $id]);
            if (!log_audit($db, null, 'CLI operator', 'Reconciled pickup delivery', 'document_request', (string)$e['request_id'], "Event {$id}: {$state}; {$note}")) throw new RuntimeException('Audit failed.');
        });
        echo "Reconciled {$id}.\n"; exit;
    }
    foreach (request_rows($db, "SELECT id FROM request_notification_events WHERE state='queued' ORDER BY id LIMIT 20") as $row) {
        echo $row['id'] . ' ' . request_encode(request_dispatch($db, (int)$row['id'])) . "\n";
    }
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
