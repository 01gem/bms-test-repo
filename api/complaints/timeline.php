<?php
require_once __DIR__ . '/../common.php';
session_start();

$officialSession = !empty($_SESSION['official_id']);
$residentSession = !empty($_SESSION['resident_id']);
if (!$officialSession && !$residentSession) {
  json_error('Unauthorized', 401);
}

$complaintId = (int)($_GET['complaint_id'] ?? 0);
if ($complaintId <= 0) {
  json_error('Valid complaint id is required.');
}

$db = get_db();

// Authorization: residents can only see their own complaints.
if (!$officialSession) {
  $stmt = $db->prepare('SELECT resident_id FROM complaints WHERE id = ? LIMIT 1');
  $stmt->bind_param('i', $complaintId);
  $rows = db_query_all($stmt);
  $stmt->close();
  if (!$rows) json_error('Complaint not found.', 404);
  if ((int)($rows[0]['resident_id'] ?? 0) !== (int)$_SESSION['resident_id']) {
    json_error('Unauthorized', 403);
  }
}

$sql = "SELECT u.id, u.actor_type, u.actor_id, u.from_status, u.to_status, u.note, u.created_at,
  CASE
    WHEN u.actor_type = 'resident' THEN (SELECT CONCAT(fname,' ',lname) FROM residents WHERE id = u.actor_id)
    WHEN u.actor_type = 'official' THEN (SELECT CONCAT(fname,' ',lname) FROM barangay_officials WHERE id = u.actor_id)
    ELSE 'System'
  END AS actor_name
  FROM complaint_updates u
  WHERE u.complaint_id = ?
  ORDER BY u.created_at ASC, u.id ASC";
$stmt = $db->prepare($sql);
if (!$stmt) json_error('Failed to prepare timeline query.', 500);
$stmt->bind_param('i', $complaintId);
$rows = db_query_all($stmt);
$stmt->close();

json_success(['updates' => $rows]);
