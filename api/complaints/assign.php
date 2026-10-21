<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_sla.php';
session_start();
require_official_admin();
require_post();

$input = read_json_input();
$complaintId = read_positive_id($input, 'complaint_id', false);
$targetId = read_positive_id($input, 'official_id', true);
$expectedId = read_positive_id($input, 'expected_assigned_official_id', true);

$actorId = (int)$_SESSION['official_id'];
$actorName = (string)($_SESSION['official_name'] ?? '');
session_write_close();

$db = get_db();
$changed = false;
$residentId = 0;
$referenceNo = '';

try {
  if (!$db->begin_transaction()) throw new RuntimeException($db->error);
  $stmt = $db->prepare(
    'SELECT id, assigned_official_id, status, severity, sla_due_at, escalated_at, resident_id, reference_no
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
  $status = (string)$complaint['status'];
  if ($status !== 'open' && $status !== 'investigating') {
    throw new DomainException('Only an open or investigating complaint can be assigned.', 409);
  }

  $currentAssignee = $complaint['assigned_official_id'] === null ? null : (int)$complaint['assigned_official_id'];
  $residentId = (int)($complaint['resident_id'] ?? 0);
  $referenceNo = (string)$complaint['reference_no'];

  if ($currentAssignee !== $targetId) {
    if ($currentAssignee !== $expectedId) {
      throw new DomainException('Assignment changed. Refresh the complaint before assigning again.', 409);
    }

    $targetName = '';
    if ($targetId !== null) {
      $officialStmt = $db->prepare(
        'SELECT id, fname, lname, status FROM barangay_officials WHERE id = ? LIMIT 1 FOR SHARE'
      );
      if (!$officialStmt) throw new RuntimeException($db->error);
      $officialStmt->bind_param('i', $targetId);
      if (!$officialStmt->execute()) throw new RuntimeException($officialStmt->error);
      $officialResult = $officialStmt->get_result();
      if (!$officialResult) throw new RuntimeException($officialStmt->error);
      $official = $officialResult->fetch_assoc();
      $officialStmt->close();
      if (!$official) throw new DomainException('Official not found.', 404);
      if ((string)$official['status'] !== 'active') {
        throw new DomainException('Only an active official can be assigned.', 422);
      }
      $targetName = trim(((string)$official['fname']) . ' ' . ((string)$official['lname']));
    }

    if ($targetId === null) {
      $update = $db->prepare('UPDATE complaints SET assigned_official_id = NULL WHERE id = ?');
      if (!$update) throw new RuntimeException($db->error);
      $update->bind_param('i', $complaintId);
    } else {
      $hours = complaint_sla_hours((string)$complaint['severity']);
      $update = $db->prepare(
        'UPDATE complaints
            SET assigned_official_id = ?,
                sla_due_at = CASE
                  WHEN sla_due_at IS NULL THEN DATE_ADD(NOW(), INTERVAL ? HOUR)
                  ELSE sla_due_at
                END
          WHERE id = ?'
      );
      if (!$update) throw new RuntimeException($db->error);
      $update->bind_param('iii', $targetId, $hours, $complaintId);
    }
    if (!$update->execute()) {
      $updateError = $update->error;
      $update->close();
      throw new RuntimeException($updateError);
    }
    $update->close();

    if ($targetId === null) {
      $note = 'Assignment removed'
        . ($currentAssignee === null ? '' : ' (official ID ' . $currentAssignee . ')')
        . '. Awaiting reassignment.';
    } elseif ($currentAssignee === null) {
      $shownName = $targetName !== '' ? $targetName : 'Official';
      $note = 'Assigned to ' . $shownName . ' (official ID ' . $targetId . ').';
    } else {
      $shownName = $targetName !== '' ? $targetName : 'Official';
      $note = 'Reassigned to ' . $shownName . ' (official ID ' . $targetId . '). Previous official ID: ' . $currentAssignee . '.';
    }
    $actorType = 'official';
    $timeline = $db->prepare(
      'INSERT INTO complaint_updates (complaint_id, actor_type, actor_id, from_status, to_status, note)
       VALUES (?, ?, ?, ?, ?, ?)'
    );
    if (!$timeline) throw new RuntimeException($db->error);
    $timeline->bind_param('isisss', $complaintId, $actorType, $actorId, $status, $status, $note);
    if (!$timeline->execute()) {
      $timelineError = $timeline->error;
      $timeline->close();
      throw new RuntimeException($timelineError);
    }
    $timeline->close();

    $oldText = $currentAssignee === null ? 'null' : (string)$currentAssignee;
    $newText = $targetId === null ? 'null' : (string)$targetId;
    $auditOk = log_audit(
      $db,
      $actorId,
      $actorName,
      'Updated complaint assignment',
      'complaint',
      $referenceNo,
      "assigned_official_id {$oldText} -> {$newText}"
    );
    if (!$auditOk) throw new RuntimeException('Audit log failed');

    if ($residentId > 0) {
      if ($targetId === null) {
        $title = 'Complaint awaiting assignment';
        $body = "Your complaint {$referenceNo} is awaiting reassignment.";
      } else {
        $title = 'Complaint assigned';
        $body = "Your complaint {$referenceNo} was assigned to a barangay official.";
      }
      if (mb_strlen($body, 'UTF-8') > 255) $body = mb_substr($body, 0, 255, 'UTF-8');
      $createdAt = date('Y-m-d H:i:s');
      $isRead = 0;
      $notify = $db->prepare(
        'INSERT INTO notifications (resident_id, title, body, is_read, created_at) VALUES (?, ?, ?, ?, ?)'
      );
      if (!$notify) throw new RuntimeException($db->error);
      $notify->bind_param('issis', $residentId, $title, $body, $isRead, $createdAt);
      if (!$notify->execute()) {
        $notifyError = $notify->error;
        $notify->close();
        throw new RuntimeException($notifyError);
      }
      $notify->close();
    }
    $changed = true;
  }

  if (!$db->commit()) throw new RuntimeException($db->error);
} catch (DomainException $error) {
  $db->rollback();
  json_error($error->getMessage(), (int)$error->getCode());
} catch (Throwable $error) {
  $db->rollback();
  json_db_error('Failed to assign complaint', $error->getMessage());
}

$residentSms = ['status' => 'skipped', 'reason' => 'No SMS is sent when the assignment is unchanged.'];
$officialSms = ['status' => 'skipped', 'reason' => 'No SMS is sent when the assignment is unchanged.'];
if (!$changed) {
  $message = 'Assignment unchanged.';
} elseif ($targetId === null) {
  $message = 'Complaint unassigned. It is awaiting reassignment.';
  $residentSms = ['status' => 'skipped', 'reason' => 'No SMS is sent when a complaint is unassigned.'];
  $officialSms = ['status' => 'skipped', 'reason' => 'No SMS is sent when a complaint is unassigned.'];
} else {
  $message = 'Complaint assignment saved.';
  try {
    $officialSms = notify_official(
      $targetId,
      'complaint_assigned_official',
      "Complaint {$referenceNo} was assigned to you. Sign in to BarangayLink to review it."
    );
  } catch (Throwable $error) {
    error_log('Assignment official SMS failed: ' . $error->getMessage());
    $officialSms = ['status' => 'failed', 'reason' => 'The assignment was saved, but SMS could not be sent.'];
  }
  if ($residentId > 0) {
    try {
      $residentSms = notify_resident(
        $residentId,
        'complaint_assigned',
        "Your complaint {$referenceNo} was assigned to a barangay official. Sign in to BarangayLink for updates.",
        'complaints_enabled'
      );
    } catch (Throwable $error) {
      error_log('Assignment resident SMS failed: ' . $error->getMessage());
      $residentSms = ['status' => 'failed', 'reason' => 'The assignment was saved, but SMS could not be sent.'];
    }
  } else {
    $residentSms = ['status' => 'skipped', 'reason' => 'No SMS was sent because this complaint has no linked resident.'];
  }
}

json_success([
  'changed' => $changed,
  'message' => $message,
  'assigned_official_id' => $targetId,
  'resident_sms' => $residentSms,
  'official_sms' => $officialSms,
]);

/**
 * Positive id, or null when $nullable and the JSON value is explicit null.
 * Missing, false, 0, empty string, and non-scalars are rejected.
 */
function read_positive_id(array $input, string $key, bool $nullable): int|null {
  if (!array_key_exists($key, $input)) {
    json_error('A valid ' . $key . ' is required.', 422);
  }
  $value = $input[$key];
  if ($value === null) {
    if ($nullable) return null;
    json_error('A valid ' . $key . ' is required.', 422);
  }
  if (is_bool($value) || is_array($value) || is_object($value)) {
    json_error('A valid ' . $key . ' is required.', 422);
  }
  $id = filter_var($value, FILTER_VALIDATE_INT);
  if ($id === false || $id === null || $id <= 0) {
    json_error('A valid ' . $key . ' is required.', 422);
  }
  return (int)$id;
}
