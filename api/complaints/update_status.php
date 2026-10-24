<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

require_once __DIR__ . '/../common.php';
session_start();
enforce_active_session();

if (empty($_SESSION['official_id'])) {
  http_response_code(401);
  echo json_encode(['success' => false, 'message' => 'Unauthorized']);
  exit;
}

require_post();

$input = read_json_input();
$id = (int)($input['id'] ?? 0);
$status = trim((string)($input['status'] ?? ''));
$hasOfficialNote = array_key_exists('official_note', $input);
$officialNote = $hasOfficialNote ? trim((string)$input['official_note']) : null;
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

$officialId = (int)$_SESSION['official_id'];
$officialName = (string)($_SESSION['official_name'] ?? '');
session_write_close();

$db = get_db();
$changed = false;
$statusChanged = false;
$residentId = 0;
$referenceNo = '';
try {
  if (!$db->begin_transaction()) throw new RuntimeException($db->error);
  $stmt = $db->prepare(
    'SELECT id, status, official_note, severity, resident_confirmation, confirmed_at, resident_id, reference_no
       FROM complaints WHERE id = ? FOR UPDATE'
  );
  if (!$stmt) throw new RuntimeException($db->error);
  $stmt->bind_param('i', $id);
  if (!$stmt->execute()) throw new RuntimeException($stmt->error);
  $result = $stmt->get_result();
  if (!$result) throw new RuntimeException($stmt->error);
  $complaint = $result->fetch_assoc();
  $stmt->close();
  if (!$complaint) throw new DomainException('Complaint not found.', 404);

  $residentId = (int)($complaint['resident_id'] ?? 0);
  $referenceNo = (string)$complaint['reference_no'];
  $storedNote = (string)($complaint['official_note'] ?? '');
  $storedSeverity = (string)($complaint['severity'] ?? '');
  $effectiveSeverity = $severity !== '' ? $severity : $storedSeverity;
  $oldStatus = (string)$complaint['status'];
  $statusChanged = $oldStatus !== $status;
  $effectiveOfficialNote = $hasOfficialNote ? $officialNote : $storedNote;
  $noteChanged = $hasOfficialNote && $storedNote !== $effectiveOfficialNote;
  $severityChanged = $effectiveSeverity !== $storedSeverity;
  $confirmation = $complaint['resident_confirmation'];
  $confirmedAt = $complaint['confirmed_at'];
  if ($statusChanged) {
    if ($status === 'resolved') {
      $confirmation = 'pending';
    } else {
      $confirmation = $oldStatus !== 'resolved' && $confirmation === 'reopened' ? 'reopened' : null;
    }
    $confirmedAt = null;
  }
  $changed = $statusChanged || $noteChanged || $severityChanged;
  if ($changed) {
    $confirmationIsNull = $confirmation === null ? 1 : 0;
    $confirmationValue = $confirmation === null ? '' : (string)$confirmation;
    $confirmedIsNull = $confirmedAt === null ? 1 : 0;
    $confirmedValue = $confirmedAt === null ? '1000-01-01 00:00:00' : (string)$confirmedAt;
    $update = $db->prepare(
      'UPDATE complaints
          SET status = ?, official_note = ?, severity = ?,
              resident_confirmation = CASE WHEN ? = 1 THEN NULL ELSE ? END,
              confirmed_at = CASE WHEN ? = 1 THEN NULL ELSE ? END
        WHERE id = ?'
    );
    if (!$update) throw new RuntimeException($db->error);
    $update->bind_param(
      'sssisisi',
      $status,
      $effectiveOfficialNote,
      $effectiveSeverity,
      $confirmationIsNull,
      $confirmationValue,
      $confirmedIsNull,
      $confirmedValue,
      $id
    );
    if (!$update->execute()) {
      $updateError = $update->error;
      $update->close();
      throw new RuntimeException($updateError);
    }
    $update->close();

    $actorType = 'official';
    $timelineNoteIsNull = $hasOfficialNote && $effectiveOfficialNote === '' ? 1 : 0;
    $timelineNote = $effectiveOfficialNote;
    $timeline = $db->prepare(
      'INSERT INTO complaint_updates (complaint_id, actor_type, actor_id, from_status, to_status, note)
       VALUES (?, ?, ?, ?, ?, IF(? = 1, NULL, ?))'
    );
    if (!$timeline) throw new RuntimeException($db->error);
    $timeline->bind_param('isissis', $id, $actorType, $officialId, $oldStatus, $status, $timelineNoteIsNull, $timelineNote);
    if (!$timeline->execute()) {
      $timelineError = $timeline->error;
      $timeline->close();
      throw new RuntimeException($timelineError);
    }
    $timeline->close();

    $auditOk = log_audit(
      $db,
      $officialId,
      $officialName,
      'Updated complaint status',
      'complaint',
      $referenceNo,
      "New status: {$status}"
    );
    if (!$auditOk) throw new RuntimeException('Audit log failed');

    if ($statusChanged && $residentId > 0) {
      $title = 'Complaint Update';
      $body = "Your complaint {$referenceNo} is now {$status}.";
      $createdAt = date('Y-m-d H:i:s');
      $isRead = 0;
      $notify = $db->prepare('INSERT INTO notifications (resident_id, title, body, is_read, created_at) VALUES (?, ?, ?, ?, ?)');
      if (!$notify) throw new RuntimeException($db->error);
      $notify->bind_param('issis', $residentId, $title, $body, $isRead, $createdAt);
      if (!$notify->execute()) {
        $notifyError = $notify->error;
        $notify->close();
        throw new RuntimeException($notifyError);
      }
      $notify->close();
    }
  }
  if (!$db->commit()) throw new RuntimeException($db->error);
} catch (DomainException $error) {
  $db->rollback();
  json_error($error->getMessage(), $error->getCode());
} catch (Throwable $error) {
  $db->rollback();
  json_db_error('Failed to update complaint', $error->getMessage());
}

$sms = ['status' => 'skipped', 'reason' => 'No SMS is sent when nothing changed.'];
if ($changed && !$statusChanged) {
  $sms = ['status' => 'skipped', 'reason' => 'No SMS is sent when the status is unchanged.'];
} elseif ($statusChanged && $residentId <= 0) {
  $sms = ['status' => 'skipped', 'reason' => 'No SMS was sent because this complaint has no linked resident.'];
} elseif ($statusChanged && $residentId > 0 && ($status === 'resolved' || $status === 'investigating')) {
  try {
    if ($status === 'resolved') {
      $noteText = $hasOfficialNote && $effectiveOfficialNote !== '' ? " Official note: {$effectiveOfficialNote}" : '';
      $sms = notify_resident(
        $residentId,
        'complaint_resolved',
        "Your complaint with reference number {$referenceNo} has been resolved by Barangay Hall of Sampaguita." . $noteText,
        'complaints_enabled'
      );
    } else {
      $firstName = 'there';
      $nameStmt = $db->prepare('SELECT fname FROM residents WHERE id = ? LIMIT 1');
      if ($nameStmt) {
        $nameStmt->bind_param('i', $residentId);
        if ($nameStmt->execute()) {
          $nameResult = $nameStmt->get_result();
          $nameRow = $nameResult ? $nameResult->fetch_assoc() : null;
          $candidate = trim((string)($nameRow['fname'] ?? ''));
          if ($candidate !== '') $firstName = $candidate;
        }
        $nameStmt->close();
      }
      $sms = notify_resident(
        $residentId,
        'complaint_investigating',
        "Hi {$firstName}, your complaint {$referenceNo} is now being investigated by the barangay.",
        'complaints_enabled'
      );
    }
  } catch (Throwable $error) {
    error_log('Complaint status SMS failed: ' . $error->getMessage());
    $sms = ['status' => 'failed', 'reason' => 'The complaint was saved, but SMS could not be sent.'];
  }
} elseif ($statusChanged) {
  $sms = ['status' => 'skipped', 'reason' => 'No SMS is sent for this status.'];
}

if (!$changed) {
  $message = 'No changes were saved.';
} elseif (!$statusChanged) {
  $message = 'Complaint details saved.';
} else {
  $message = "Complaint status updated to {$status}.";
}

json_success([
  'changed' => $changed,
  'sms_status' => $sms['status'],
  'sms_reason' => $sms['reason'],
  'message' => $message
]);
?>
