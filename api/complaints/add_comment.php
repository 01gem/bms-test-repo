<?php
require_once __DIR__ . '/../common.php';
session_start();
$isOfficial = !empty($_SESSION['official_id']);
$residentId = (int)($_SESSION['resident_id'] ?? 0);
if (!$isOfficial && $residentId <= 0) json_error('Unauthorized', 401);
require_post();
$actorType = $isOfficial ? 'official' : 'resident';
$actorId = $isOfficial ? (int)$_SESSION['official_id'] : $residentId;
$officialName = (string)($_SESSION['official_name'] ?? '');
session_write_close();
$input = read_json_input();
$complaintId = filter_var($input['complaint_id'] ?? null, FILTER_VALIDATE_INT);
if ($complaintId === false || $complaintId === null || $complaintId <= 0) {
  json_error('Valid complaint id is required.', 422);
}
if (!isset($input['body']) || !is_string($input['body'])) {
  json_error('Comment body must be text.', 422);
}
$body = trim($input['body']);
if ($body === '' || preg_match('/^[\s\p{Z}]*$/u', $body) === 1) {
  json_error('Comment body is required.', 422);
}
if (mb_strlen($body, 'UTF-8') > 2000) {
  json_error('Comments must be 2,000 characters or fewer.', 422);
}
$db = get_db();
$stmt = $db->prepare('SELECT resident_id, reference_no FROM complaints WHERE id = ? LIMIT 1');
if (!$stmt) json_db_error('Comment complaint lookup', $db->error);
$stmt->bind_param('i', $complaintId);
$rows = db_query_all($stmt);
$stmt->close();
if (!$rows) json_error('Complaint not found.', 404);
$complaint = $rows[0];
if (!$isOfficial && (int)$complaint['resident_id'] !== $residentId) {
  json_error('Unauthorized', 403);
}
try {
  if (!$db->begin_transaction()) throw new RuntimeException($db->error);
  $stmt = $db->prepare(
    'INSERT INTO complaint_comments (complaint_id, actor_type, actor_id, body) VALUES (?, ?, ?, ?)'
  );
  if (!$stmt) throw new RuntimeException($db->error);
  $stmt->bind_param('isis', $complaintId, $actorType, $actorId, $body);
  if (!$stmt->execute()) throw new RuntimeException($stmt->error);
  $commentId = (int)$db->insert_id;
  $stmt->close();
  if ($isOfficial && !log_audit(
    $db, $actorId, $officialName, 'Added complaint comment', 'complaint',
    (string)$complaint['reference_no'], "Comment ID: {$commentId}"
  )) throw new RuntimeException('Official comment audit failed.');
  if (!$db->commit()) throw new RuntimeException($db->error);
} catch (Throwable $error) {
  $db->rollback();
  json_db_error('Failed to add complaint comment', $error->getMessage());
}
json_success(['id' => $commentId, 'message' => 'Comment posted.']);
