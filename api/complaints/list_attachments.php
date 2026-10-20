<?php
require_once __DIR__ . '/../common.php';
session_start();

$officialSession = !empty($_SESSION['official_id']);
$residentSession = !empty($_SESSION['resident_id']);
if (!$officialSession && !$residentSession) json_error('Unauthorized', 401);

$complaintId = (int)($_GET['complaint_id'] ?? 0);
if ($complaintId <= 0) json_error('Valid complaint id is required.');

$db = get_db();

if (!$officialSession) {
  $own = $db->prepare('SELECT resident_id FROM complaints WHERE id = ? LIMIT 1');
  $own->bind_param('i', $complaintId);
  $ownRows = db_query_all($own);
  $own->close();
  if (!$ownRows) json_error('Complaint not found.', 404);
  if ((int)($ownRows[0]['resident_id'] ?? 0) !== (int)$_SESSION['resident_id']) {
    json_error('Unauthorized', 403);
  }
}

$stmt = $db->prepare(
  'SELECT id, file_path, mime, byte_size, uploaded_by_type, created_at
     FROM complaint_attachments
    WHERE complaint_id = ? AND deleted_at IS NULL
    ORDER BY created_at ASC, id ASC'
);
$stmt->bind_param('i', $complaintId);
$rows = db_query_all($stmt);
$stmt->close();

$attachments = array_map(fn(array $r) => [
  'id'               => (int)$r['id'],
  'url'              => '../' . (string)$r['file_path'],
  'mime'             => (string)$r['mime'],
  'byte_size'        => (int)$r['byte_size'],
  'uploaded_by_type' => (string)$r['uploaded_by_type'],
  'created_at'       => (string)$r['created_at'],
], $rows);

json_success(['attachments' => $attachments]);
