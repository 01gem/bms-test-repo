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
$officialNote = trim((string)($input['official_note'] ?? ''));
$severity = trim((string)($input['severity'] ?? ''));
if ($id <= 0) {
  json_error('Valid complaint id is required.');
}
$allowedSeverity = ['low', 'medium', 'high', 'critical'];
if ($severity !== '' && !in_array($severity, $allowedSeverity, true)) {
  json_error('Invalid severity. Allowed values are: low, medium, high, critical.');
}

$db = get_db();
$complaintStmt = $db->prepare('SELECT id, reference_no, resident_id, status AS old_status FROM complaints WHERE id = ? LIMIT 1');
if (!$complaintStmt) json_error('Failed to prepare complaint lookup.', 500);
$complaintStmt->bind_param('i', $id);
$complaintRows = db_query_all($complaintStmt);
$complaintStmt->close();
if (count($complaintRows) === 0) {
  json_error('Complaint not found.', 404);
}
$complaint = $complaintRows[0];
$oldStatus = (string)($complaint['old_status'] ?? 'open');

$status = 'resolved';
if ($severity !== '') {
  $updateStmt = $db->prepare('UPDATE complaints SET status = ?, official_note = ?, severity = ? WHERE id = ?');
  if (!$updateStmt) json_error('Failed to prepare complaint resolution update.', 500);
  $updateStmt->bind_param('sssi', $status, $officialNote, $severity, $id);
} else {
  $updateStmt = $db->prepare('UPDATE complaints SET status = ?, official_note = ? WHERE id = ?');
  if (!$updateStmt) json_error('Failed to prepare complaint resolution update.', 500);
  $updateStmt->bind_param('ssi', $status, $officialNote, $id);
}
if (!$updateStmt->execute()) {
  json_db_error('Failed to resolve complaint', $updateStmt->error);
}
$updateStmt->close();

$actorType = 'official';
$officialId = isset($_SESSION['official_id']) ? (int)$_SESSION['official_id'] : null;
$timelineNote = $officialNote !== '' ? $officialNote : null;
$updStmt = $db->prepare(
  'INSERT INTO complaint_updates (complaint_id, actor_type, actor_id, from_status, to_status, note)
   VALUES (?, ?, ?, ?, ?, ?)'
);
if ($updStmt) {
  $updStmt->bind_param('isisss', $id, $actorType, $officialId, $oldStatus, $status, $timelineNote);
  if (!$updStmt->execute()) error_log('complaint_updates insert failed: ' . $updStmt->error);
  $updStmt->close();
}

$sms = ['status' => 'skipped', 'reason' => 'No SMS was sent because this complaint has no linked resident.'];
$residentId = (int)($complaint['resident_id'] ?? 0);
if ($residentId > 0) {
  $noteText = $officialNote !== '' ? " Official note: {$officialNote}" : '';
  $smsMessage = "Your complaint with reference number {$complaint['reference_no']} has been resolved by Barangay Hall of Sampaguita." . $noteText;
  $smsResult = notify_resident(
    $residentId,
    'complaint_resolved',
    $smsMessage,
    'complaints_enabled'
  );
  $sms = ['status' => $smsResult['status'], 'reason' => $smsResult['reason']];
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
  'Resolved complaint',
  'complaint',
  (string)$complaint['reference_no'],
  $officialNote !== '' ? "Official note: {$officialNote}" : ''
);

json_success([
  'status_updated' => true,
  'sms_status' => $sms['status'],
  'sms_reason' => $sms['reason'],
  'message' => "Complaint resolved. {$sms['reason']}"
]);
?>
