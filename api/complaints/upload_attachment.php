<?php
require_once __DIR__ . '/../common.php';
session_start();
// Phase 3 hook: delete_attachment.php and the official upload path are wired
// server-side but have no UI. Expose delete controls in both portals alongside
// the Phase 3 comments feature so the soft-delete pattern stays consistent.

$officialSession = !empty($_SESSION['official_id']);
$residentSession = !empty($_SESSION['resident_id']);
if (!$officialSession && !$residentSession) {
  json_error('Unauthorized', 401);
}
require_post();

$complaintId = (int)($_POST['complaint_id'] ?? 0);
if ($complaintId <= 0) json_error('Valid complaint id is required.');

$db = get_db();
$stmt = $db->prepare('SELECT id, resident_id FROM complaints WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $complaintId);
$rows = db_query_all($stmt);
$stmt->close();
if (!$rows) json_error('Complaint not found.', 404);
$complaint = $rows[0];

// Authorization: officials can attach to any complaint, residents only to their own.
$uploaderType = $officialSession ? 'official' : 'resident';
$uploaderId   = $officialSession ? (int)$_SESSION['official_id'] : (int)$_SESSION['resident_id'];
if ($residentSession && !$officialSession) {
  if ((int)($complaint['resident_id'] ?? 0) !== $uploaderId) {
    json_error('You can only attach photos to your own complaints.', 403);
  }
}

// Cap: 5 live attachments per complaint.
$countStmt = $db->prepare('SELECT COUNT(*) AS c FROM complaint_attachments WHERE complaint_id = ? AND deleted_at IS NULL');
$countStmt->bind_param('i', $complaintId);
$countRows = db_query_all($countStmt);
$countStmt->close();
// Server-side cap is 5 (higher than the resident UI's 3) to leave headroom
// for the official-console upload path and any future retry flow. The UI
// limit is a UX guide; this is the guard. Do not tighten to 3.
if ((int)($countRows[0]['c'] ?? 0) >= 5) {
  json_error('This complaint already has the maximum of 5 attachments.', 422);
}

if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
  json_error('Please select a photo.');
}
$upload = $_FILES['photo'];
if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  json_error('Photo upload failed.');
}
if (($upload['size'] ?? 0) > 5 * 1024 * 1024) {
  json_error('Photo must be 5MB or smaller.', 422);
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo ? finfo_file($finfo, $upload['tmp_name']) : false;
if ($finfo) finfo_close($finfo);
$extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
if (!isset($extensions[$mime])) {
  json_error('Only JPEG and PNG images are allowed.', 422);
}
if (@getimagesize($upload['tmp_name']) === false) {
  json_error('Uploaded file is not a valid image.', 422);
}

$dir = __DIR__ . '/../../complaint_attachments/' . $complaintId;
if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
  json_error('Unable to create attachment directory.', 500);
}

$extension = $extensions[$mime];
$uuid = bin2hex(random_bytes(16));
$filename = $uuid . '.' . $extension;
$absolutePath = $dir . DIRECTORY_SEPARATOR . $filename;
if (!move_uploaded_file($upload['tmp_name'], $absolutePath)) {
  json_error('Unable to save attachment.', 500);
}

$relativePath = 'complaint_attachments/' . $complaintId . '/' . $filename;
$byteSize = (int)filesize($absolutePath);

$insert = $db->prepare(
  'INSERT INTO complaint_attachments
     (complaint_id, file_path, mime, byte_size, uploaded_by_type, uploaded_by_id)
   VALUES (?, ?, ?, ?, ?, ?)'
);
if (!$insert) {
  @unlink($absolutePath);
  json_error('Failed to save attachment record.', 500);
}
$insert->bind_param('issisi', $complaintId, $relativePath, $mime, $byteSize, $uploaderType, $uploaderId);
if (!$insert->execute()) {
  $err = $insert->error;
  $insert->close();
  @unlink($absolutePath);
  json_db_error('Failed to save attachment record', $err);
}
$attachmentId = (int)$insert->insert_id;
$insert->close();

json_success([
  'attachment_id' => $attachmentId,
  'url'           => '../' . $relativePath,
]);
