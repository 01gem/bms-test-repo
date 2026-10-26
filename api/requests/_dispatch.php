<?php
require_once __DIR__ . '/_workflow.php';

// Never holds a request/database lock across a provider call. Claim persisted first.
function request_dispatch(mysqli $db, int $eventId, ?callable $deliver = null): array {
    $lookup = request_rows($db, 'SELECT request_id FROM request_notification_events WHERE id=?', [$eventId]);
    if (!$lookup) throw new RequestProblem('Pickup event not found.', 404);
    $event = request_transaction($db, function () use ($db, $eventId, $lookup) {
        $r = request_lock($db, (string)$lookup[0]['request_id'], true);
        $e = request_rows($db, 'SELECT * FROM request_notification_events WHERE id=? FOR UPDATE', [$eventId])[0];
        if ($e['state'] !== 'queued') return $e;
        if ($r['status'] !== 'ready' || (int)$r['readiness_cycle'] !== (int)$e['readiness_cycle']) {
            request_exec($db, "UPDATE request_notification_events SET state='skipped',reason='Request is no longer Ready in this cycle.',finished_at=NOW() WHERE id=?", [$eventId]);
            $e['state'] = 'skipped'; return $e;
        }
        request_exec($db, "UPDATE request_notification_events SET state='sending',claimed_at=NOW() WHERE id=?", [$eventId]);
        $e['claimed'] = true; return $e;
    });
    if (empty($event['claimed'])) {
        return [
            'state' => $event['state'],
            'reason' => (string)($event['reason'] ?? ''),
            'dispatched' => false,
        ];
    }
    try {
        $result = $deliver ? $deliver($event) : notify_resident((int)$event['resident_id'], 'request_pickup_ready', $event['message'], 'requests_enabled', $event['contact']);
        $state = $result['status'] ?? 'uncertain';
        if ($state === 'failed' && !empty($result['uncertain'])) $state = 'uncertain';
        if (!in_array($state, ['sent','skipped','failed','uncertain'], true)) $state = 'uncertain';
        $reason = (string)($result['reason'] ?? 'Delivery result unavailable; reconciliation required.');
    } catch (Throwable $e) {
        $state = 'uncertain'; $reason = 'Delivery interrupted; reconciliation required.';
        error_log('Pickup delivery exception: ' . $e->getMessage());
    }
    request_exec($db, "UPDATE request_notification_events SET state=?,reason=?,finished_at=NOW() WHERE id=? AND state='sending'", [$state, mb_substr($reason, 0, 255), $eventId]);
    $recorded = request_rows($db, 'SELECT state, reason FROM request_notification_events WHERE id=?', [$eventId])[0];
    return [
        'state' => $recorded['state'],
        'reason' => (string)($recorded['reason'] ?? ''),
        'dispatched' => true,
    ];
}

function request_recover(mysqli $db): void {
    // A crashed/slow provider attempt is never automatically returned to queued.
    request_exec($db, "UPDATE request_notification_events SET state='uncertain',reason='Dispatch lease elapsed; reconcile before retry.',finished_at=NOW() WHERE state='sending' AND claimed_at<NOW()-INTERVAL 120 SECOND");
    $ops = request_rows($db, "SELECT * FROM request_operations WHERE state='running' AND created_at<NOW()-INTERVAL 10 MINUTE ORDER BY created_at LIMIT 100");
    foreach ($ops as $op) {
        request_transaction($db, function () use ($db, $op) {
            $r = null;
            try { $r = request_lock($db, $op['request_key'], $op['action'] === 'generation'); }
            catch (RequestProblem $e) { if ($e->status !== 404) throw $e; }
            $current = request_rows($db, 'SELECT * FROM request_operations WHERE operation_id=? FOR UPDATE', [$op['operation_id']])[0];
            if ($current['state'] !== 'running') return;
            if ($r !== null) request_exec($db, 'UPDATE document_requests SET generation_token=NULL,generation_expires_at=NULL WHERE id=? AND generation_token=?', [(int)$r['id'], $op['operation_id']]);
            request_finish($db, $op['operation_id'], [409, ['success' => false, 'code' => 'recovered_operation', 'message' => 'An abandoned operation was recovered. Review the request before a new action.']]);
        });
    }
}

/**
 * Requeue transient pickup-SMS failures for another dispatcher attempt.
 *
 * Backoff per retry_count value:
 *   0 -> wait 5 min   (first retry after the original failure)
 *   1 -> wait 15 min
 *   2 -> wait 60 min
 *   3 -> wait 180 min
 * After retry_count reaches 4 the row stays in `failed` for operator
 * reconciliation; this function never requeues it again.
 *
 * Only `state='failed'` rows are considered. `uncertain` rows are handled
 * by `request_recover()` (lease expiry) or by CLI reconcile.
 *
 * @return int Number of events requeued.
 */
function request_retry_failed(mysqli $db): int {
    $requeued = 0;
    $rows = request_rows(
        $db,
        "SELECT id FROM request_notification_events
          WHERE state = 'failed'
            AND retry_count < 4
            AND finished_at IS NOT NULL
            AND finished_at < NOW() - INTERVAL
                CASE retry_count
                  WHEN 0 THEN 5
                  WHEN 1 THEN 15
                  WHEN 2 THEN 60
                  ELSE 180
                END MINUTE
          ORDER BY id
          LIMIT 20"
    );
    foreach ($rows as $row) {
        $stmt = request_sql(
            $db,
            "UPDATE request_notification_events
                SET state = 'queued', retry_count = retry_count + 1
              WHERE id = ? AND state = 'failed' AND retry_count < 4",
            [(int)$row['id']]
        );
        $changed = $db->affected_rows;
        $stmt->close();
        if ($changed === 1) $requeued++;
    }
    return $requeued;
}
