<?php
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_sla.php';
session_start();
$isOfficial = !empty($_SESSION['official_id']);
$residentId = (int)($_SESSION['resident_id'] ?? 0);
if ($isOfficial) json_error('Resident access required.', 403);
if ($residentId <= 0) json_error('Unauthorized', 401);
require_post();
session_write_close();
$input = read_json_input();
$complaintId = filter_var($input['complaint_id'] ?? null, FILTER_VALIDATE_INT);
$expectedResolutionId = filter_var($input['expected_resolution_id'] ?? null, FILTER_VALIDATE_INT);
if ($complaintId === false || $complaintId === null || $complaintId <= 0) {
  json_error('Valid complaint id is required.', 422);
}
if ($expectedResolutionId === false || $expectedResolutionId === null || $expectedResolutionId < 0) {
  json_error('Valid expected resolution id is required.', 422);
}
if (!isset($input['reason']) || !is_string($input['reason'])) json_error('Reopen reason must be text.', 422);
$reason = trim($input['reason']);
if ($reason === '' || preg_match('/^[\s\p{Z}]*$/u', $reason) === 1) {
  json_error('Tell us what is still broken.', 422);
}
if (mb_strlen($reason, 'UTF-8') > 2000) json_error('Reasons must be 2,000 characters or fewer.', 422);
$db = get_db();
$changed = false;
try {
  if (!$db->begin_transaction()) throw new RuntimeException($db->error);
  $stmt = $db->prepare(
    'SELECT id, resident_id, reference_no, status, resident_confirmation, severity, assigned_official_id
       FROM complaints WHERE id = ? FOR UPDATE'
  );
  if (!$stmt) throw new RuntimeException($db->error);
  $stmt->bind_param('i', $complaintId);
  if (!$stmt->execute()) throw new RuntimeException($stmt->error);
  $result = $stmt->get_result();
  if (!$result) throw new RuntimeException($stmt->error);
  $complaint = $result->fetch_assoc();
  $stmt->close();
  if (!$complaint) throw new DomainException('Complaint not found.', 404);
  if ((int)$complaint['resident_id'] !== $residentId) throw new DomainException('Unauthorized', 403);
  $stmt = $db->prepare(
    "SELECT COALESCE(MAX(id), 0) AS resolution_id FROM complaint_updates
      WHERE complaint_id = ? AND to_status = 'resolved'
        AND COALESCE(from_status, '') <> 'resolved'"
  );
  if (!$stmt) throw new RuntimeException($db->error);
  $stmt->bind_param('i', $complaintId);
  if (!$stmt->execute()) throw new RuntimeException($stmt->error);
  $result = $stmt->get_result();
  if (!$result) throw new RuntimeException($stmt->error);
  $resolutionId = (int)$result->fetch_assoc()['resolution_id'];
  $stmt->close();
  if ($resolutionId !== $expectedResolutionId) {
    throw new DomainException('The resolution changed. Refresh the complaint before responding.', 409);
  }
  $alreadyReopened = $complaint['status'] === 'open' && $complaint['resident_confirmation'] === 'reopened';
  if (!$alreadyReopened && $complaint['status'] !== 'resolved') {
    throw new DomainException('Only a resolved complaint can be reopened.', 409);
  }
  if (!$alreadyReopened) {
    $hours = complaint_sla_hours((string)$complaint['severity']);
    $stmt = $db->prepare(
      "UPDATE complaints SET status = 'open', resident_confirmation = 'reopened',
              confirmed_at = NULL, reopened_at = NOW(),
              sla_due_at = CASE WHEN assigned_official_id IS NULL THEN NULL
                                ELSE DATE_ADD(NOW(), INTERVAL ? HOUR) END,
              escalated_at = NULL
        WHERE id = ?"
    );
    if (!$stmt) throw new RuntimeException($db->error);
    $stmt->bind_param('ii', $hours, $complaintId);
    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
    $stmt->close();
    $stmt = $db->prepare(
      "INSERT INTO complaint_comments (complaint_id, actor_type, actor_id, body) VALUES (?, 'resident', ?, ?)"
    );
    if (!$stmt) throw new RuntimeException($db->error);
    $stmt->bind_param('iis', $complaintId, $residentId, $reason);
    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
    $commentId = (int)$db->insert_id;
    $stmt->close();
    $note = "Complaint reopened by resident. Comment ID: {$commentId}.";
    $stmt = $db->prepare(
      "INSERT INTO complaint_updates (complaint_id, actor_type, actor_id, from_status, to_status, note)
       VALUES (?, 'resident', ?, 'resolved', 'open', ?)"
    );
    if (!$stmt) throw new RuntimeException($db->error);
    $stmt->bind_param('iis', $complaintId, $residentId, $note);
    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
    $stmt->close();
    $changed = true;
  }
  if (!$db->commit()) throw new RuntimeException($db->error);
} catch (DomainException $error) {
  $db->rollback();
  json_error($error->getMessage(), $error->getCode());
} catch (Throwable $error) {
  $db->rollback();
  json_db_error('Failed to reopen complaint', $error->getMessage());
}
$sms = ['status' => 'skipped', 'reason' => 'No SMS is sent for an unchanged complaint.'];
if ($changed) {
  try {
    $sms = notify_resident(
      $residentId, 'complaint_reopened',
      "Your complaint {$complaint['reference_no']} has been reopened. The barangay will review your update.",
      'complaints_enabled'
    );
  } catch (Throwable $error) {
    error_log('Reopened complaint SMS failed: ' . $error->getMessage());
    $sms = ['status' => 'failed', 'reason' => 'The complaint was saved, but SMS could not be sent.'];
  }
}
json_success([
  'changed' => $changed,
  'sms_status' => $sms['status'],
  'sms_reason' => $sms['reason'],
  'message' => $changed ? 'Complaint reopened. Your reason was added to the comments.' : 'This complaint was already reopened.'
]);
