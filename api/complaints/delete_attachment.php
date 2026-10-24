<?php
require_once __DIR__ . '/../common.php';
session_start();
enforce_active_session();
// Phase 3 hook: no UI exposes this endpoint yet (see upload_attachment.php).
// Resident-own and official-any paths are both live and API-tested; the future
// detail-view delete control should honor both.

$officialSession = !empty($_SESSION['official_id']);
$residentSession = !empty($_SESSION['resident_id']);
if (!$officialSession && !$residentSession) {
  json_error('Unauthorized', 401);
}
require_post();

$input = read_json_input();
$attachmentId = (int)($input['attachment_id'] ?? 0);
if ($attachmentId <= 0) json_error('Valid attachment id is required.');

$db = get_db();
$stmt = $db->prepare('SELECT id, uploaded_by_type, uploaded_by_id, deleted_at FROM complaint_attachments WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $attachmentId);
$rows = db_query_all($stmt);
$stmt->close();
if (!$rows) json_error('Attachment not found.', 404);
if (!empty($rows[0]['deleted_at'])) {
  json_success(['deleted' => 0, 'message' => 'Attachment was already deleted.']);
}

if ($residentSession && !$officialSession) {
  $uploaderType = (string)$rows[0]['uploaded_by_type'];
  $uploaderId   = (int)$rows[0]['uploaded_by_id'];
  if ($uploaderType !== 'resident' || $uploaderId !== (int)$_SESSION['resident_id']) {
    json_error('You can only delete your own attachments.', 403);
  }
}

$update = $db->prepare('UPDATE complaint_attachments SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL');
$update->bind_param('i', $attachmentId);
if (!$update->execute()) {
  json_db_error('Failed to delete attachment', $update->error);
}
$affected = (int)$update->affected_rows;
$update->close();

json_success(['deleted' => $affected]);
