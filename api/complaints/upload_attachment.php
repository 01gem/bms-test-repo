<?php
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_attachments.php';
session_start();
// Phase 3 hook: delete_attachment.php and the official upload path are wired
// server-side but have no UI. Expose delete controls in both portals alongside
// the Phase 3 comments feature so the soft-delete pattern stays consistent.
enforce_active_session();

$officialSession = !empty($_SESSION['official_id']);
$residentSession = !empty($_SESSION['resident_id']);
if (!$officialSession && !$residentSession) {
  json_error('Unauthorized', 401);
}
require_post();

$complaintId = (int)($_POST['complaint_id'] ?? 0);
if ($complaintId <= 0) json_error('Valid complaint id is required.');

if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
  json_error('Please select a photo.');
}
$upload = $_FILES['photo'];
if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  json_error('Photo upload failed.');
}
if (($upload['size'] ?? 0) > 50 * 1024 * 1024) {
  json_error('Photo must be 50MB or smaller.', 422);
}

$tmpPath = (string)($upload['tmp_name'] ?? '');
$mime = $tmpPath !== '' ? complaint_attachment_detect_mime($tmpPath) : false;
$extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
if (!is_string($mime) || !isset($extensions[$mime])) {
  json_error('Only JPEG and PNG images are allowed.', 422);
}
if ($tmpPath === '' || @getimagesize($tmpPath) === false) {
  json_error('Uploaded file is not a valid image.', 422);
}

$storage = complaint_attachment_private_root(true);
if (!$storage['ok']) {
  json_error($storage['message'], 500);
}

$actor = [
  'type' => $officialSession ? 'official' : 'resident',
  'id' => $officialSession ? (int)$_SESSION['official_id'] : (int)$_SESSION['resident_id'],
];
session_write_close();

$result = complaint_attachment_store_upload(
  get_db(),
  $actor,
  $complaintId,
  $tmpPath,
  $mime,
  (int)($upload['size'] ?? 0),
  true
);
if (empty($result['ok'])) {
  json_error((string)$result['message'], (int)$result['status']);
}

json_success([
  'attachment_id' => (int)$result['attachment_id'],
  'url' => (string)$result['url'],
]);
