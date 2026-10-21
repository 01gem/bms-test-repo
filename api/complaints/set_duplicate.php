<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_community.php';
session_start();
require_official_admin();
require_post();

$input = complaint_require_json_object();
$complaintId = complaint_read_positive_id($input, 'complaint_id', false);
$targetId = complaint_read_positive_id($input, 'duplicate_of_id', true);
$expectedId = complaint_read_positive_id($input, 'expected_duplicate_of_id', true);
if ($targetId !== null && $targetId === $complaintId) {
  json_error('A complaint cannot be linked to itself.', 422);
}

$actorId = (int)$_SESSION['official_id'];
$actorName = (string)($_SESSION['official_name'] ?? '');
session_write_close();

$db = get_db();
$result = complaint_retry_transaction(
  $db,
  static function () use ($db, $complaintId, $targetId, $expectedId, $actorId, $actorName): array {
    return complaint_apply_duplicate(
      $db,
      $complaintId,
      $targetId,
      $expectedId,
      $actorId,
      $actorName
    );
  },
  'Failed to update complaint duplicate link'
);

json_success($result);

function complaint_apply_duplicate(
  mysqli $db,
  int $complaintId,
  ?int $targetId,
  ?int $expectedId,
  int $actorId,
  string $actorName
): array {
  if (!$db->begin_transaction()) throw new RuntimeException($db->error);

  $lockIds = [$complaintId];
  if ($targetId !== null) $lockIds[] = $targetId;
  sort($lockIds, SORT_NUMERIC);
  $locked = [];
  foreach ($lockIds as $lockId) {
    $locked[$lockId] = complaint_lock_complaint($db, $lockId);
  }

  $source = $locked[$complaintId] ?? null;
  if (!$source) throw new DomainException('Complaint not found.', 404);
  $target = null;
  if ($targetId !== null) {
    $target = $locked[$targetId] ?? null;
    if (!$target) throw new DomainException('Related report not found.', 404);
  }

  if ($source['duplicate_of_id'] === $targetId) {
    if (!$db->commit()) throw new RuntimeException($db->error);
    return [
      'changed' => false,
      'duplicate_of_id' => $targetId,
      'message' => 'The related-report link is unchanged.',
    ];
  }

  if ($source['duplicate_of_id'] !== $expectedId) {
    throw new DomainException('The related-report link changed. Refresh before saving the link again.', 409);
  }
  if ($source['is_public'] !== 0) {
    throw new DomainException('Hide this complaint before changing its related-report link.', 409);
  }
  if ($targetId !== null && $target['duplicate_of_id'] !== null) {
    throw new DomainException('The selected report is already linked to another report. Choose a primary report.', 409);
  }
  if ($targetId !== null && complaint_has_direct_child($db, $complaintId)) {
    throw new DomainException('This complaint already has related reports, so it cannot become a duplicate.', 409);
  }

  if ($targetId === null) {
    $update = $db->prepare('UPDATE complaints SET duplicate_of_id = NULL WHERE id = ?');
    if (!$update) throw new RuntimeException($db->error);
    $update->bind_param('i', $complaintId);
  } else {
    $update = $db->prepare('UPDATE complaints SET duplicate_of_id = ? WHERE id = ?');
    if (!$update) throw new RuntimeException($db->error);
    $update->bind_param('ii', $targetId, $complaintId);
  }
  if (!$update->execute()) {
    $error = $update->error;
    $update->close();
    throw new RuntimeException($error);
  }
  $update->close();

  $note = $targetId === null
    ? 'The related-report link was removed. This complaint remains tracked separately.'
    : 'Linked to a related report. Each complaint remains tracked separately.';
  complaint_insert_equal_status_note($db, $complaintId, $actorId, $source['status'], $note);

  $oldId = $source['duplicate_of_id'];
  $oldRef = $oldId === null ? null : complaint_reference_no($db, $oldId);
  $newRef = $target === null ? null : $target['reference_no'];
  $auditOk = log_audit(
    $db,
    $actorId,
    $actorName,
    'Updated complaint duplicate link',
    'complaint',
    $source['reference_no'],
    'duplicate_of_id ' . complaint_pointer_audit($oldId, $oldRef) . ' -> ' . complaint_pointer_audit($targetId, $newRef)
  );
  if (!$auditOk) throw new RuntimeException('Audit log failed');
  if (!$db->commit()) throw new RuntimeException($db->error);

  return [
    'changed' => true,
    'duplicate_of_id' => $targetId,
    'message' => $note,
  ];
}
