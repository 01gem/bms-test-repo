<?php
// Conservative janitor. Default is dry-run; only terminal failed operations older
// than one day, with no request reference, are eligible. Unknown outcomes stay put.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Same resolver as dispatch-request-notices.php. Both source and deployed
// layouts have db.php at the project root; the probe picks the right anchor.
$projectRoot = is_file(dirname(__DIR__, 2) . '/db.php')
    ? dirname(__DIR__, 2)   // source:   project-support/scripts/  → project root
    : dirname(__DIR__);     // deployed: scripts/                 → project root

require_once $projectRoot . '/api/requests/_workflow.php';
$db = get_db(); $apply = ($argv[1] ?? '') === '--apply';
$operations = request_rows($db, "SELECT operation_id FROM request_operations WHERE action='generation' AND state='done' AND http_status>=400 AND finished_at<NOW()-INTERVAL 1 DAY");
foreach ($operations as $op) {
    $id = $op['operation_id'];
    if (!preg_match('/^[0-9a-f-]{36}$/', $id)) continue;
    $relative = 'generated_documents/' . $id . '.pdf';
    if (request_rows($db,'SELECT id FROM document_requests WHERE generated_document_path=?',[$relative])) continue;
    foreach (['pdf','docx'] as $extension) {
        $path = $projectRoot . '/generated_documents/' . $id . '.' . $extension;
        if (!is_file($path)) continue;
        echo ($apply ? 'Remove ' : 'Would remove ') . $path . "\n";
        if ($apply && !unlink($path)) throw new RuntimeException('Artifact cleanup failed.');
    }
}
