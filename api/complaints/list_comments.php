<?php
require_once __DIR__ . '/../common.php';
session_start();
$isOfficial = !empty($_SESSION['official_id']);
$residentId = (int)($_SESSION['resident_id'] ?? 0);
if (!$isOfficial && $residentId <= 0) json_error('Unauthorized', 401);
session_write_close();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('Method not allowed.', 405);
$complaintId = filter_var($_GET['complaint_id'] ?? null, FILTER_VALIDATE_INT);
if ($complaintId === false || $complaintId === null || $complaintId <= 0) {
  json_error('Valid complaint id is required.', 422);
}
$db = get_db();
$stmt = $db->prepare('SELECT resident_id FROM complaints WHERE id = ? LIMIT 1');
if (!$stmt) json_db_error('Comment complaint lookup', $db->error);
$stmt->bind_param('i', $complaintId);
$rows = db_query_all($stmt);
$stmt->close();
if (!$rows) json_error('Complaint not found.', 404);
if (!$isOfficial && (int)$rows[0]['resident_id'] !== $residentId) {
  json_error('Unauthorized', 403);
}
$stmt = $db->prepare(
  "SELECT c.id, c.actor_type, c.actor_id, c.body, c.created_at,
          CASE WHEN c.actor_type = 'resident'
            THEN CONCAT(r.fname, ' ', r.lname)
            ELSE CONCAT(o.fname, ' ', o.lname)
          END AS actor_name
     FROM complaint_comments c
     LEFT JOIN residents r ON c.actor_type = 'resident' AND r.id = c.actor_id
     LEFT JOIN barangay_officials o ON c.actor_type = 'official' AND o.id = c.actor_id
    WHERE c.complaint_id = ? AND c.deleted_at IS NULL
    ORDER BY c.created_at ASC, c.id ASC"
);
if (!$stmt) json_db_error('Comment list prepare', $db->error);
$stmt->bind_param('i', $complaintId);
$comments = db_query_all($stmt);
$stmt->close();
foreach ($comments as &$comment) {
  $comment['id'] = (int)$comment['id'];
  $comment['actor_id'] = (int)$comment['actor_id'];
  $comment['actor_name'] = trim((string)($comment['actor_name'] ?? ''));
  if ($comment['actor_name'] === '') {
    $comment['actor_name'] = $comment['actor_type'] === 'official' ? 'Official' : 'Resident';
  }
}
unset($comment);
json_success(['comments' => $comments]);
