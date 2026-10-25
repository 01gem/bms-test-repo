<?php
/** Request-specific concurrency boundary. All SQL failures throw; no exit inside transactions. */
require_once __DIR__ . '/../common.php';

final class RequestProblem extends RuntimeException {
    public int $status;
    public string $kind;
    public array $extra;
    public function __construct(string $message, int $status = 400, string $kind = 'validation', array $extra = []) {
        parent::__construct($message);
        $this->status = $status; $this->kind = $kind; $this->extra = $extra;
    }
}

function request_sql(mysqli $db, string $sql, array $values = []): mysqli_stmt {
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException($db->error, $db->errno);
    if ($values) {
        $types = ''; foreach ($values as $value) $types .= is_int($value) ? 'i' : 's';
        $stmt->bind_param($types, ...$values);
    }
    if (!$stmt->execute()) {
        $error = new RuntimeException($stmt->error, $stmt->errno); $stmt->close(); throw $error;
    }
    return $stmt;
}
function request_rows(mysqli $db, string $sql, array $values = []): array {
    $stmt = request_sql($db, $sql, $values);
    $result = $stmt->get_result(); $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close(); return $rows;
}
function request_exec(mysqli $db, string $sql, array $values = []): void {
    request_sql($db, $sql, $values)->close();
}
function request_transaction(mysqli $db, callable $work) {
    // Only database work is retried. Business conflicts and external effects are never retried.
    for ($attempt = 0; ; $attempt++) {
        if (!$db->begin_transaction()) throw new RuntimeException($db->error, $db->errno);
        try {
            $result = $work();
            if (!$db->commit()) throw new RuntimeException($db->error, $db->errno);
            return $result;
        } catch (Throwable $e) {
            $db->rollback();
            if ($attempt < 1 && in_array($e->getCode(), [1205, 1213], true)) continue;
            throw $e;
        }
    }
}
function request_text(array $input, string $key, string $default = ''): string {
    if (!array_key_exists($key, $input)) return $default;
    if (!is_string($input[$key])) throw new RequestProblem("Invalid {$key}.");
    return trim($input[$key]);
}
function request_contract(array $input): array {
    $version = $input['expected_version'] ?? null;
    $operation = request_text($input, 'operation_id');
    if (!is_int($version) || $version < 1 || $version > 4294967295 ||
        !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $operation)) {
        throw new RequestProblem('A numeric expected_version and UUID operation_id are required.', 422);
    }
    return ['expected_version' => $version, 'operation_id' => strtolower($operation)];
}
function request_actor(): array {
    session_start(); require_official_session(); require_post();
    $actor = ['id' => (int)$_SESSION['official_id'], 'name' => (string)$_SESSION['official_name']];
    session_write_close();
    return $actor;
}
function request_problem_result(Throwable $e): array {
    if ($e instanceof RequestProblem) return [$e->status, array_merge(['success' => false, 'code' => $e->kind, 'message' => $e->getMessage()], $e->extra)];
    error_log('Request operation failed: ' . $e->getMessage());
    return [503, ['success' => false, 'code' => 'operation_failed', 'message' => 'The operation could not be saved. Review and try a new action.']];
}
function request_reply(array $result): void {
    http_response_code($result[0]); echo json_encode($result[1], JSON_UNESCAPED_UNICODE); exit;
}
function request_encode(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); }
function request_canonical(array $payload): array {
    ksort($payload);
    foreach ($payload as &$value) if (is_array($value)) $value = request_canonical($value);
    return $payload;
}

// Claim is durable before any business work. A second connection can see running.
// The globally unique ID is bound to both actor and normalized intent.
function request_claim(mysqli $db, array $actor, string $action, string $key, array $payload): ?array {
    $id = $payload['operation_id'];
    $encoded = request_encode(request_canonical($payload)); $fingerprint = hash('sha256', $encoded);
    try {
        request_exec($db, 'INSERT INTO request_operations (operation_id,official_id,official_name,action,request_key,fingerprint,payload_json) VALUES (?,?,?,?,?,?,?)',
            [$id, $actor['id'], $actor['name'], $action, $key, $fingerprint, $encoded]);
        return null;
    } catch (Throwable $e) { if ($e->getCode() !== 1062) throw $e; }
    $op = request_rows($db, 'SELECT * FROM request_operations WHERE operation_id=?', [$id])[0];
    if ((int)$op['official_id'] !== $actor['id'] || $op['action'] !== $action || $op['request_key'] !== $key || !hash_equals($op['fingerprint'], $fingerprint)) {
        throw new RequestProblem('Operation ID was already used for a different action or actor.', 409, 'operation_reused');
    }
    if ($op['state'] === 'running') throw new RequestProblem('This operation is still in progress. Retry the same operation ID.', 409, 'in_progress');
    return [(int)$op['http_status'], json_decode($op['outcome_json'], true, 512, JSON_THROW_ON_ERROR)];
}
function request_finish(mysqli $db, string $operation, array $result): void {
    request_exec($db, "UPDATE request_operations SET state='done',http_status=?,outcome_json=?,finished_at=NOW() WHERE operation_id=? AND state='running'", [$result[0], request_encode($result[1]), $operation]);
}
function request_lock(mysqli $db, string $key, bool $byId = false): array {
    $rows = request_rows($db, 'SELECT * FROM document_requests WHERE ' . ($byId ? 'id' : 'reference_no') . '=? FOR UPDATE', [$key]);
    if (!$rows) throw new RequestProblem('Document request not found.', 404, 'not_found');
    return $rows[0];
}
function request_version(array $request, int $expected): void {
    if ((int)$request['row_version'] !== $expected) throw new RequestProblem(
        'This request changed. Review the current information before trying again.', 409, 'stale_version',
        ['current_version' => (int)$request['row_version'], 'current_status' => $request['status']]);
}
function request_audit(mysqli $db, array $actor, array $request, string $action, string $details): void {
    if (!log_audit($db, $actor['id'], $actor['name'], $action, 'document_request', $request['reference_no'], $details)) throw new RuntimeException('Audit insert failed.');
}
function request_resident_notice(mysqli $db, array $request, string $body): void {
    request_exec($db, 'INSERT INTO notifications (resident_id,title,body,is_read,created_at) VALUES (?,?,?,0,NOW())',
        [(int)$request['resident_id'], 'Document Request Update', $body]);
}
function request_success(array $request, bool $changed, array $extra = []): array {
    return [200, array_merge(['success' => true, 'changed' => $changed, 'row_version' => (int)$request['row_version']], $extra)];
}
function request_mutate(mysqli $db, array $actor, string $action, array $payload): array {
    request_exec($db, 'SET SESSION innodb_lock_wait_timeout=3');
    $key = $payload['reference_no']; $operation = $payload['operation_id'];
    $replay = request_claim($db, $actor, $action, $key, $payload);
    if ($replay !== null) return $replay; // Replay precedes stale-version evaluation.
    try {
        return request_transaction($db, function () use ($db, $actor, $action, $payload, $key, $operation) {
            $request = request_lock($db, $key);
            $op = request_rows($db, 'SELECT * FROM request_operations WHERE operation_id=? FOR UPDATE', [$operation])[0];
            if ($op['state'] === 'done') return [(int)$op['http_status'], json_decode($op['outcome_json'], true)];
            request_version($request, $payload['expected_version']);
            $result = $action === 'notify' ? request_queue_notice($db, $actor, $request, $payload) : request_change($db, $actor, $request, $action, $payload);
            request_finish($db, $operation, $result);
            return $result;
        });
    } catch (Throwable $e) {
        // Commit acknowledgement may be lost even though the atomic outcome exists.
        $stored = request_rows($db, 'SELECT * FROM request_operations WHERE operation_id=?', [$operation])[0];
        if ($stored['state'] === 'done') return [(int)$stored['http_status'], json_decode($stored['outcome_json'], true)];
        $result = request_problem_result($e); $result[1]['operation_finished'] = true;
        request_finish($db, $operation, $result); return $result;
    }
}
function request_change(mysqli $db, array $actor, array $r, string $action, array $p): array {
    if ($action === 'payment') {
        $target = $p['payment_status'];
        if ($target === $r['payment_status']) return request_success($r, false);
        if (in_array($r['status'], ['completed','cancelled'], true) || $r['payment_status'] !== 'unpaid') throw new RequestProblem('Payment can only change from Unpaid on a nonterminal request.', 409, 'invalid_transition');
        request_exec($db, 'UPDATE document_requests SET payment_status=?,row_version=row_version+1 WHERE id=?', [$target, (int)$r['id']]);
        request_audit($db, $actor, $r, 'Updated document request payment', "Payment status: {$target}; Operation: {$p['operation_id']}");
    } else {
        $target = $p['status'];
        if ($target === $r['status']) return request_success($r, false);
        $valid = ($r['status'] === 'processing' && $target === 'ready') || ($r['status'] === 'ready' && $target === 'completed') ||
            (in_array($r['status'], ['pending','processing'], true) && $target === 'cancelled');
        if (!$valid) throw new RequestProblem('This status transition is not permitted. Generate the document to start Processing.', 409, 'invalid_transition');
        request_exec($db, "UPDATE document_requests SET status=?,row_version=row_version+1,
            readiness_cycle=readiness_cycle+IF(?='ready',1,0),
            date_completed=IF(?='completed',NOW(),date_completed),
            payment_status=IF(?='completed' AND payment_status='unpaid','paid',payment_status) WHERE id=?",
            [$target, $target, $target, $target, (int)$r['id']]);
        $body = "Your request {$r['reference_no']} is now " . ($target === 'ready' ? 'ready for pickup' : $target) . '.';
        if ($target === 'cancelled') $body .= ' Reason: ' . $p['official_note'];
        request_resident_notice($db, $r, $body);
        request_audit($db, $actor, $r, 'Updated document request status', $body . " Operation: {$p['operation_id']}");
    }
    $r['row_version']++;
    return request_success($r, true, ['message' => ucfirst($action) . ' updated.']);
}
function request_queue_notice(mysqli $db, array $actor, array $r, array $p): array {
    if ($r['status'] !== 'ready' || (int)$r['readiness_cycle'] < 1) throw new RequestProblem('Only a Ready request can receive a pickup notice.', 409, 'invalid_transition');
    $events = request_rows($db, 'SELECT *, TIMESTAMPDIFF(SECOND,created_at,NOW()) AS age FROM request_notification_events WHERE request_id=? AND readiness_cycle=? ORDER BY sequence_no DESC LIMIT 1 FOR UPDATE', [(int)$r['id'], (int)$r['readiness_cycle']]);
    $last = $events[0] ?? null;
    if (!$p['resend'] && $last) return request_success($r, false, ['event_id' => (int)$last['id'], 'sms_status' => $last['state'], 'message' => 'An initial pickup notice is already recorded.']);
    if ($p['resend']) {
        if (!$last) throw new RequestProblem('Create an initial pickup notice first.');
        if (in_array($last['state'], ['queued','sending'], true)) throw new RequestProblem('A pickup delivery is already pending.', 409, 'delivery_pending');
        if ((int)$last['age'] < 300) throw new RequestProblem('Wait five minutes between pickup notices.', 409, 'cooldown');
        if ($last['state'] === 'uncertain' && !$p['acknowledge_uncertain']) throw new RequestProblem('Reconcile the uncertain delivery or explicitly acknowledge the risk of a duplicate SMS.', 409, 'uncertain_delivery');
    }
    $resident = request_rows($db, 'SELECT contact FROM residents WHERE id=?', [(int)$r['resident_id']]);
    if (!$resident) throw new RequestProblem('Linked resident not found.', 404);
    $contact = $p['contact'] !== '' ? $p['contact'] : trim($resident[0]['contact']);
    $normalized = normalize_ph_phone($contact);
    if ($normalized === null) throw new RequestProblem('Enter a valid Philippine mobile contact number.');
    $message = "Hello {$r['resident_name']}, your requested document ({$r['document_type']}) with reference number {$r['reference_no']} is now ready for pickup at the Barangay Hall of Sampaguita. Please bring a valid ID when claiming your document. - Barangay Hall of Sampaguita";
    request_exec($db, 'INSERT INTO request_notification_events (request_id,readiness_cycle,sequence_no,operation_id,resident_id,contact,message) VALUES (?,?,?,?,?,?,?)',
        [(int)$r['id'], (int)$r['readiness_cycle'], $last ? (int)$last['sequence_no'] + 1 : 0, $p['operation_id'], (int)$r['resident_id'], $normalized, $message]);
    $event = (int)$db->insert_id;
    request_audit($db, $actor, $r, $p['resend'] ? 'Requested pickup SMS resend' : 'Queued pickup notification', "Event: {$event}; Operation: {$p['operation_id']}; Explicit uncertain retry: " . ($p['acknowledge_uncertain'] ? 'yes' : 'no'));
    return request_success($r, true, ['event_id' => $event, 'sms_status' => 'queued', 'message' => 'Pickup notice queued. Delivery status is shown after dispatch.']);
}

function request_endpoint(string $action): void {
    $actor = request_actor();
    try {
        $input = read_json_input(); $payload = request_contract($input);
        $payload['reference_no'] = request_text($input, 'reference_no');
        if ($payload['reference_no'] === '' || strlen($payload['reference_no']) > 50) throw new RequestProblem('Valid reference number is required.');
        if ($action === 'status') {
            $payload['status'] = request_text($input, 'status');
            $payload['official_note'] = request_text($input, 'official_note');
            if (!in_array($payload['status'], ['pending','processing','ready','completed','cancelled'], true)) throw new RequestProblem('Invalid status.');
            if ($payload['status'] === 'cancelled' && ($payload['official_note'] === '' || strlen($payload['official_note']) > 150)) throw new RequestProblem('A brief cancellation reason is required (maximum 150 characters).');
        } elseif ($action === 'payment') {
            $payload['payment_status'] = request_text($input, 'payment_status');
            if (!in_array($payload['payment_status'], ['paid','waived'], true)) throw new RequestProblem('Invalid payment action.');
        } else {
            $payload['contact'] = request_text($input, 'contact');
            if ($payload['contact'] !== '') {
                $payload['contact'] = normalize_ph_phone($payload['contact']) ?? '';
                if ($payload['contact'] === '') throw new RequestProblem('Invalid contact number.');
            }
            foreach (['resend','acknowledge_uncertain'] as $key) {
                if (isset($input[$key]) && !is_bool($input[$key])) throw new RequestProblem("Invalid {$key}.");
                $payload[$key] = $input[$key] ?? false;
            }
        }
        request_reply(request_mutate(get_db(), $actor, $action, $payload));
    } catch (Throwable $e) { request_reply(request_problem_result($e)); }
}
