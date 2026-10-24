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

$isOfficial = !empty($_SESSION['official_id']);
$isResident = !empty($_SESSION['resident_id']);
if (!$isOfficial && !$isResident) json_error('Unauthorized', 401);
if (!$isOfficial) json_error('Official access required.', 403);
require_post();
session_write_close();

$db = get_db();
$escalated = 0;
$attempt = 0;
while (true) {
  $attempt++;
  try {
    $escalated = escalate_overdue_complaints($db);
    break;
  } catch (Throwable $error) {
    $db->rollback();
    $deadlock = $db->errno === 1213 || str_contains($error->getMessage(), 'Deadlock');
    if ($deadlock && $attempt < 3) continue;
    json_db_error('Failed to check complaint response targets', $error->getMessage());
  }
}

json_success(['escalated_count' => $escalated]);

function escalate_overdue_complaints(mysqli $db): int {
  if (!$db->begin_transaction()) throw new RuntimeException($db->error);
  $stmt = $db->prepare(
    "SELECT id, status, reference_no, sla_due_at
       FROM complaints
      WHERE status IN ('open', 'investigating')
        AND sla_due_at IS NOT NULL AND sla_due_at <= NOW()
        AND escalated_at IS NULL
      ORDER BY sla_due_at ASC, id ASC
      LIMIT 100
      FOR UPDATE"
  );
  if (!$stmt) throw new RuntimeException($db->error);
  if (!$stmt->execute()) throw new RuntimeException($stmt->error);
  $result = $stmt->get_result();
  if (!$result) throw new RuntimeException($stmt->error);
  $rows = $result->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  $update = $db->prepare(
    "UPDATE complaints
        SET escalated_at = NOW()
      WHERE id = ?
        AND status IN ('open', 'investigating')
        AND sla_due_at IS NOT NULL AND sla_due_at <= NOW()
        AND escalated_at IS NULL"
  );
  if (!$update) throw new RuntimeException($db->error);
  $timeline = $db->prepare(
    'INSERT INTO complaint_updates (complaint_id, actor_type, actor_id, from_status, to_status, note)
     VALUES (?, ?, ?, ?, ?, ?)'
  );
  if (!$timeline) throw new RuntimeException($db->error);

  $escalated = 0;
  foreach ($rows as $row) {
    $id = (int)$row['id'];
    $update->bind_param('i', $id);
    if (!$update->execute()) throw new RuntimeException($update->error);
    if ($update->affected_rows !== 1) continue;

    $actorType = 'system';
    $actorId = null;
    $status = (string)$row['status'];
    $note = 'Response target missed; flagged for administrator review.';
    $timeline->bind_param('isisss', $id, $actorType, $actorId, $status, $status, $note);
    if (!$timeline->execute()) throw new RuntimeException($timeline->error);

    $auditOk = log_audit(
      $db,
      null,
      'System',
      'Escalated complaint SLA',
      'complaint',
      (string)$row['reference_no'],
      'Response target ' . (string)$row['sla_due_at'] . ' was missed.'
    );
    if (!$auditOk) throw new RuntimeException('Audit log failed');
    $escalated++;
  }
  $update->close();
  $timeline->close();
  if (!$db->commit()) throw new RuntimeException($db->error);
  return $escalated;
}
