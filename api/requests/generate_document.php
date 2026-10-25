<?php
require_once __DIR__ . '/_render.php';
$actor = request_actor();
$db = get_db();
try {
    $input = read_json_input(); $payload = request_contract($input);
    $payload['id'] = $input['id'] ?? null;
    if (!is_int($payload['id']) || $payload['id'] < 1) throw new RequestProblem('Valid numeric request id is required.');
    $fields = $input['fields'] ?? [];
    if (!is_array($fields) || count($fields) > 40) throw new RequestProblem('Invalid fields payload.');
    $payload['fields'] = [];
    foreach ($fields as $key => $value) {
        if (!is_string($key) || !preg_match('/^[a-z_]{1,50}$/', $key) || !is_string($value) || strlen($value) > 2000) throw new RequestProblem('Invalid document field.');
        $payload['fields'][$key] = trim($value);
    }
    request_exec($db, 'SET SESSION innodb_lock_wait_timeout=3');
    $result = request_generation_run($db, $actor, $payload, 'request_render_document', dirname(__DIR__, 2));
} catch (Throwable $e) { $result = request_problem_result($e); }
request_reply($result);
