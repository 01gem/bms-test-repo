<?php
require_once __DIR__ . '/../common.php';
session_start();
$isOfficial = !empty($_SESSION['official_id']);
$residentId = (int)($_SESSION['resident_id'] ?? 0);
if (!$isOfficial && $residentId <= 0) json_error('Unauthorized', 401);
require_post();
$officialId = (int)($_SESSION['official_id'] ?? 0);
$officialName = (string)($_SESSION['official_name'] ?? '');
session_write_close();
$input = read_json_input();
$commentId = filter_var($input['comment_id'] ?? null, FILTER_VALIDATE_INT);
if ($commentId === false || $commentId === null || $commentId <= 0) {
  json_error('Valid comment id is required.', 422);
}
$db = get_db();
$stmt = $db->prepare(
  'SELECT c.actor_type, c.actor_id, p.resident_id, p.reference_no
     FROM complaint_comments c JOIN complaints p ON p.id = c.complaint_id
    WHERE c.id = ? LIMIT 1'
);
if (!$stmt) json_db_error('Comment delete lookup', $db->error);
$stmt->bind_param('i', $commentId);
$rows = db_query_all($stmt);
$stmt->close();
if (!$rows) json_error('Comment not found.', 404);
$comment = $rows[0];
if (!$isOfficial && (
  (int)$comment['resident_id'] !== $residentId ||
  $comment['actor_type'] !== 'resident' || (int)$comment['actor_id'] !== $residentId
)) json_error('You can only delete your own comments.', 403);
try {
  if (!$db->begin_transaction()) throw new RuntimeException($db->error);
  $stmt = $db->prepare(
    'UPDATE complaint_comments SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL'
  );
  if (!$stmt) throw new RuntimeException($db->error);
  $stmt->bind_param('i', $commentId);
  if (!$stmt->execute()) throw new RuntimeException($stmt->error);
  $deleted = (int)$stmt->affected_rows;
  $stmt->close();
  if ($isOfficial && $deleted > 0 && !log_audit(
    $db, $officialId, $officialName, 'Deleted complaint comment', 'complaint',
    (string)$comment['reference_no'], "Comment ID: {$commentId}"
  )) throw new RuntimeException('Official comment deletion audit failed.');
  if (!$db->commit()) throw new RuntimeException($db->error);
} catch (Throwable $error) {
  $db->rollback();
  json_db_error('Failed to delete complaint comment', $error->getMessage());
}
json_success(['deleted' => $deleted]);
