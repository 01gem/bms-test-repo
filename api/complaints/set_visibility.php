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
$targetPublic = complaint_read_bool($input, 'is_public');
$expectedPublic = complaint_read_bool($input, 'expected_is_public');
$expectedParent = complaint_read_positive_id($input, 'expected_duplicate_of_id', true);

$actorId = (int)$_SESSION['official_id'];
$actorName = (string)($_SESSION['official_name'] ?? '');
session_write_close();

$db = get_db();
$result = complaint_retry_transaction(
  $db,
  static function () use ($db, $complaintId, $targetPublic, $expectedPublic, $expectedParent, $actorId, $actorName): array {
    return complaint_apply_visibility(
      $db,
      $complaintId,
      $targetPublic,
      $expectedPublic,
      $expectedParent,
      $actorId,
      $actorName
    );
  },
  'Failed to update complaint sharing'
);

json_success($result);

function complaint_apply_visibility(
  mysqli $db,
  int $complaintId,
  bool $targetPublic,
  bool $expectedPublic,
  ?int $expectedParent,
  int $actorId,
  string $actorName
): array {
  if (!$db->begin_transaction()) throw new RuntimeException($db->error);
  $row = complaint_lock_complaint($db, $complaintId);
  if (!$row) throw new DomainException('Complaint not found.', 404);

  $targetFlag = $targetPublic ? 1 : 0;
  if ($row['is_public'] === $targetFlag) {
    if (!$db->commit()) throw new RuntimeException($db->error);
    return [
      'changed' => false,
      'is_public' => $targetPublic,
      'message' => 'Sharing is unchanged.',
    ];
  }

  $expectedFlag = $expectedPublic ? 1 : 0;
  if ($row['is_public'] !== $expectedFlag || $row['duplicate_of_id'] !== $expectedParent) {
    if ($row['is_public'] !== $expectedFlag && $row['duplicate_of_id'] !== $expectedParent) {
      throw new DomainException('Sharing and the related-report link changed. Refresh before saving again.', 409);
    }
    if ($row['is_public'] !== $expectedFlag) {
      throw new DomainException('Sharing changed. Refresh before saving again.', 409);
    }
    throw new DomainException('The related-report link changed. Refresh before saving sharing again.', 409);
  }

  $update = $db->prepare('UPDATE complaints SET is_public = ? WHERE id = ?');
  if (!$update) throw new RuntimeException($db->error);
  $update->bind_param('ii', $targetFlag, $complaintId);
  if (!$update->execute()) {
    $error = $update->error;
    $update->close();
    throw new RuntimeException($error);
  }
  $update->close();

  $note = $targetPublic
    ? 'Sharing with the resident community was enabled.'
    : 'Sharing with the resident community was disabled.';
  complaint_insert_equal_status_note($db, $complaintId, $actorId, $row['status'], $note);

  $auditOk = log_audit(
    $db,
    $actorId,
    $actorName,
    'Updated complaint sharing',
    'complaint',
    $row['reference_no'],
    'is_public ' . $row['is_public'] . ' -> ' . $targetFlag
  );
  if (!$auditOk) throw new RuntimeException('Audit log failed');
  if (!$db->commit()) throw new RuntimeException($db->error);

  return [
    'changed' => true,
    'is_public' => $targetPublic,
    'message' => $note,
  ];
}
