<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

require_once __DIR__ . '/_extra_fields.php';

function complaint_community_filed_on(?string $raw): ?string {
  if ($raw === null || str_contains($raw, "\0")) return null;
  $raw = trim($raw);
  $date = DateTimeImmutable::createFromFormat('!M d, Y', $raw, new DateTimeZone('Asia/Manila'));
  $errors = DateTimeImmutable::getLastErrors();
  if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) return null;
  return $date->format('M d, Y') === $raw ? $date->format('Y-m-d') : null;
}

function complaint_community_projection(array $row): array {
  $category = (string)($row['category'] ?? '');
  $categories = complaint_extra_fields_schema();
  if (!array_key_exists($category, $categories)) $category = 'Other';
  $severity = (string)($row['severity'] ?? '');
  if (!in_array($severity, ['low', 'medium', 'high', 'critical'], true)) $severity = 'unknown';
  $status = (string)($row['status'] ?? '');
  if (!in_array($status, ['open', 'investigating', 'resolved'], true)) $status = 'unknown';
  return [
    'id' => (int)$row['id'],
    'category' => $category,
    'severity' => $severity,
    'status' => $status,
    'filed_on' => complaint_community_filed_on($row['date_filed'] ?? null),
    'published_report_count' => max(1, (int)($row['published_report_count'] ?? 1)),
  ];
}

function complaint_normalize_public_flag(mixed $value): int {
  return (int)$value === 1 ? 1 : 0;
}

function complaint_normalize_parent_id(mixed $value): ?int {
  if ($value === null) return null;
  $id = (int)$value;
  return $id > 0 ? $id : null;
}

/**
 * Positive id, or null when $nullable and the JSON value is explicit null.
 * Missing, false, 0, empty string, and non-scalars are rejected.
 */
function complaint_read_positive_id(array $input, string $key, bool $nullable): int|null {
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

function complaint_read_bool(array $input, string $key): bool {
  if (!array_key_exists($key, $input) || !is_bool($input[$key])) {
    json_error('A valid ' . $key . ' is required.', 422);
  }
  return $input[$key];
}

function complaint_require_json_object(): array {
  $header = (string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
  $media = strtolower(trim(explode(';', $header, 2)[0]));
  if ($media !== 'application/json') {
    json_error('Content-Type must be application/json.', 415);
  }
  $raw = file_get_contents('php://input');
  if ($raw === false || trim($raw) === '') {
    json_error('A valid JSON object is required.', 422);
  }
  $data = json_decode($raw, true);
  if (!is_array($data) || array_is_list($data)) {
    json_error('A valid JSON object is required.', 422);
  }
  return $data;
}

function complaint_lock_complaint(mysqli $db, int $complaintId): ?array {
  $stmt = $db->prepare(
    'SELECT id, reference_no, status, is_public, duplicate_of_id
       FROM complaints WHERE id = ? LIMIT 1 FOR UPDATE'
  );
  if (!$stmt) throw new RuntimeException($db->error);
  $stmt->bind_param('i', $complaintId);
  if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    throw new RuntimeException($error);
  }
  $result = $stmt->get_result();
  if (!$result) {
    $error = $stmt->error;
    $stmt->close();
    throw new RuntimeException($error);
  }
  $row = $result->fetch_assoc();
  $stmt->close();
  if (!$row) return null;
  return [
    'id' => (int)$row['id'],
    'reference_no' => (string)$row['reference_no'],
    'status' => (string)$row['status'],
    'is_public' => complaint_normalize_public_flag($row['is_public']),
    'duplicate_of_id' => complaint_normalize_parent_id($row['duplicate_of_id']),
  ];
}

function complaint_has_direct_child(mysqli $db, int $complaintId): bool {
  $stmt = $db->prepare('SELECT id FROM complaints WHERE duplicate_of_id = ? LIMIT 1 FOR UPDATE');
  if (!$stmt) throw new RuntimeException($db->error);
  $stmt->bind_param('i', $complaintId);
  if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    throw new RuntimeException($error);
  }
  $result = $stmt->get_result();
  if (!$result) {
    $error = $stmt->error;
    $stmt->close();
    throw new RuntimeException($error);
  }
  $row = $result->fetch_assoc();
  $stmt->close();
  return $row !== null;
}

function complaint_reference_no(mysqli $db, int $complaintId): ?string {
  $stmt = $db->prepare('SELECT reference_no FROM complaints WHERE id = ? LIMIT 1');
  if (!$stmt) throw new RuntimeException($db->error);
  $stmt->bind_param('i', $complaintId);
  if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    throw new RuntimeException($error);
  }
  $result = $stmt->get_result();
  if (!$result) {
    $error = $stmt->error;
    $stmt->close();
    throw new RuntimeException($error);
  }
  $row = $result->fetch_assoc();
  $stmt->close();
  if (!$row) return null;
  return (string)$row['reference_no'];
}

function complaint_insert_equal_status_note(mysqli $db, int $complaintId, int $actorId, string $status, string $note): void {
  $actorType = 'official';
  $fromStatus = $status;
  $toStatus = $status;
  $stmt = $db->prepare(
    'INSERT INTO complaint_updates (complaint_id, actor_type, actor_id, from_status, to_status, note)
     VALUES (?, ?, ?, ?, ?, ?)'
  );
  if (!$stmt) throw new RuntimeException($db->error);
  $stmt->bind_param('isisss', $complaintId, $actorType, $actorId, $fromStatus, $toStatus, $note);
  if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    throw new RuntimeException($error);
  }
  $stmt->close();
}

function complaint_pointer_audit(?int $id, ?string $reference): string {
  if ($id === null) return 'null';
  $ref = ($reference !== null && $reference !== '') ? $reference : 'unknown';
  return $id . ' (' . $ref . ')';
}

function complaint_is_deadlock(mysqli $db, Throwable $error): bool {
  return $db->errno === 1213 || str_contains($error->getMessage(), 'Deadlock');
}

function complaint_retry_transaction(mysqli $db, callable $work, string $errorContext): mixed {
  $attempt = 0;
  while (true) {
    $attempt++;
    try {
      return $work();
    } catch (DomainException $error) {
      $db->rollback();
      $code = (int)$error->getCode();
      json_error($error->getMessage(), $code > 0 ? $code : 409);
    } catch (Throwable $error) {
      $db->rollback();
      if (complaint_is_deadlock($db, $error) && $attempt < 3) continue;
      json_db_error($errorContext, $error->getMessage());
    }
  }
}
