<?php
require_once __DIR__ . '/../common.php';
session_start();
enforce_active_session();
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
$db = get_db();
$changed = false;
try {
  if (!$db->begin_transaction()) throw new RuntimeException($db->error);
  $stmt = $db->prepare(
    'SELECT id, resident_id, status, resident_confirmation, confirmed_at, reopened_at
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
  if ($complaint['status'] !== 'resolved') {
    throw new DomainException('Only a resolved complaint can be confirmed.', 409);
  }
  if ($complaint['resident_confirmation'] !== 'confirmed') {
    $stmt = $db->prepare(
      "UPDATE complaints SET resident_confirmation = 'confirmed', confirmed_at = NOW() WHERE id = ?"
    );
    if (!$stmt) throw new RuntimeException($db->error);
    $stmt->bind_param('i', $complaintId);
    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
    $stmt->close();
    $stmt = $db->prepare(
      "INSERT INTO complaint_updates (complaint_id, actor_type, actor_id, from_status, to_status, note)
       VALUES (?, 'resident', ?, 'resolved', 'resolved', 'Resident confirmed the resolution.')"
    );
    if (!$stmt) throw new RuntimeException($db->error);
    $stmt->bind_param('ii', $complaintId, $residentId);
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
  json_db_error('Failed to confirm complaint resolution', $error->getMessage());
}
json_success([
  'changed' => $changed,
  'message' => $changed ? 'Resolution confirmed. Thank you.' : 'This resolution was already confirmed.'
]);
