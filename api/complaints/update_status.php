<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

require_once __DIR__ . '/../common.php';
session_start();

if (empty($_SESSION['official_id'])) {
  http_response_code(401);
  echo json_encode(['success' => false, 'message' => 'Unauthorized']);
  exit;
}

require_post();

$input = read_json_input();
$id = (int)($input['id'] ?? 0);
$status = trim((string)($input['status'] ?? ''));
$officialNote = trim((string)($input['official_note'] ?? ''));
$severity = trim((string)($input['severity'] ?? ''));

if ($id <= 0 || $status === '') {
  json_error('Complaint id and status are required.');
}

$allowed = ['open', 'investigating', 'resolved'];
if (!in_array($status, $allowed, true)) {
  json_error('Invalid status. Allowed values are: open, investigating, resolved.');
}
$allowedSeverity = ['low', 'medium', 'high', 'critical'];
if ($severity !== '' && !in_array($severity, $allowedSeverity, true)) {
  json_error('Invalid severity. Allowed values are: low, medium, high, critical.');
}

$db = get_db();
$check = $db->prepare('SELECT id, resident_id, reference_no, status AS old_status FROM complaints WHERE id = ? LIMIT 1');
if (!$check) json_error('Failed to prepare complaint lookup.', 500);
$check->bind_param('i', $id);
$rows = db_query_all($check);
$check->close();
if (count($rows) === 0) {
  json_error('Complaint not found.', 404);
}
$complaint = $rows[0];
$oldStatus = (string)($complaint['old_status'] ?? 'open');

if ($severity !== '') {
  $stmt = $db->prepare('UPDATE complaints SET status = ?, official_note = ?, severity = ? WHERE id = ?');
  if (!$stmt) json_error('Failed to prepare complaint update.', 500);
  $stmt->bind_param('sssi', $status, $officialNote, $severity, $id);
} else {
  $stmt = $db->prepare('UPDATE complaints SET status = ?, official_note = ? WHERE id = ?');
  if (!$stmt) json_error('Failed to prepare complaint update.', 500);
  $stmt->bind_param('ssi', $status, $officialNote, $id);
}
if (!$stmt->execute()) {
  json_db_error('Failed to update complaint', $stmt->error);
}
$stmt->close();

$residentId = (int)($complaint['resident_id'] ?? 0);
$newStatus = $status;
$actorType = 'official';
$officialId = isset($_SESSION['official_id']) ? (int)$_SESSION['official_id'] : null;
$noteForTimeline = $officialNote !== '' ? $officialNote : null;
$updStmt = $db->prepare(
  'INSERT INTO complaint_updates (complaint_id, actor_type, actor_id, from_status, to_status, note)
   VALUES (?, ?, ?, ?, ?, ?)'
);
if ($updStmt) {
  $updStmt->bind_param('isisss', $id, $actorType, $officialId, $oldStatus, $newStatus, $noteForTimeline);
  if (!$updStmt->execute()) error_log('complaint_updates insert failed: ' . $updStmt->error);
  $updStmt->close();
}

if ($newStatus === 'investigating' && $residentId > 0) {
  $firstName = '';
  $nameStmt = $db->prepare('SELECT fname FROM residents WHERE id = ? LIMIT 1');
  if ($nameStmt) {
    $nameStmt->bind_param('i', $residentId);
    $nameRows = db_query_all($nameStmt);
    $nameStmt->close();
    $firstName = trim((string)($nameRows[0]['fname'] ?? ''));
  }
  if ($firstName === '') $firstName = 'there';
  notify_resident(
    $residentId,
    'complaint_investigating',
    "Hi {$firstName}, your complaint {$complaint['reference_no']} is now being investigated by the barangay.",
    'complaints_enabled'
  );
}

if ($residentId > 0) {
  $title = 'Complaint Update';
  $body = "Your complaint {$complaint['reference_no']} is now {$status}.";
  $createdAt = date('Y-m-d H:i:s');
  $isRead = 0;

  $notify = $db->prepare('INSERT INTO notifications (resident_id, title, body, is_read, created_at) VALUES (?, ?, ?, ?, ?)');
  if (!$notify) json_error('Failed to prepare complaint notification insert.', 500);
  $notify->bind_param('issis', $residentId, $title, $body, $isRead, $createdAt);
  if (!$notify->execute()) {
    json_db_error('Failed to create complaint notification', $notify->error);
  }
  $notify->close();
}

log_audit(
  $db,
  isset($_SESSION['official_id']) ? (int)$_SESSION['official_id'] : null,
  (string)($_SESSION['official_name'] ?? ''),
  'Updated complaint status',
  'complaint',
  (string)$complaint['reference_no'],
  "New status: {$status}"
);

json_success(['message' => "Complaint status updated to {$status}."]);
?>
