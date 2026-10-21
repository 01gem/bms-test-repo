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
$isOfficial = !empty($_SESSION['official_id']);
$residentId = (int)($_SESSION['resident_id'] ?? 0);
if ($isOfficial) json_error('Resident access required.', 403);
if ($residentId <= 0) json_error('Unauthorized', 401);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('Method not allowed.', 405);
session_write_close();
header('Cache-Control: private, no-store');

$limit = complaint_public_limit();
$beforeId = complaint_public_before_id();
$category = complaint_public_category();
$status = complaint_public_status();

$where = ['c.is_public = 1', 'c.duplicate_of_id IS NULL'];
$types = '';
$params = [];

if ($beforeId !== null) {
  $where[] = 'c.id < ?';
  $types .= 'i';
  $params[] = $beforeId;
}
if ($status !== null) {
  $where[] = 'CAST(c.status AS BINARY) = CAST(? AS BINARY)';
  $types .= 's';
  $params[] = $status;
}
if ($category !== null && $category !== 'Other') {
  $where[] = 'CAST(c.category AS BINARY) = CAST(? AS BINARY)';
  $types .= 's';
  $params[] = $category;
}
if ($category === 'Other') {
  $keys = array_keys(complaint_extra_fields_schema());
  $marks = implode(', ', array_fill(0, count($keys), 'CAST(? AS BINARY)'));
  $where[] = '(c.category IS NULL OR CAST(c.category AS BINARY) = CAST(? AS BINARY) OR CAST(c.category AS BINARY) NOT IN (' . $marks . '))';
  $types .= 's';
  $params[] = 'Other';
  foreach ($keys as $key) {
    $types .= 's';
    $params[] = $key;
  }
}

$fetchLimit = $limit + 1;
$types .= 'i';
$params[] = $fetchLimit;

$sql = 'SELECT c.id, c.category, c.severity, c.status, c.date_filed,
               1 + (SELECT COUNT(*) FROM complaints child
                     WHERE child.duplicate_of_id = c.id AND child.is_public = 1)
                 AS published_report_count
          FROM complaints c
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY c.id DESC
         LIMIT ?';

try {
  $db = get_db();
  $stmt = $db->prepare($sql);
  if (!$stmt) throw new RuntimeException($db->error);
  complaint_public_bind($stmt, $types, $params);
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
  $rows = $result->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
} catch (Throwable $error) {
  json_db_error('Failed to list community concerns', $error->getMessage());
}

$hasMore = count($rows) > $limit;
if ($hasMore) $rows = array_slice($rows, 0, $limit);
$complaints = array_map('complaint_community_projection', $rows);
$nextBeforeId = null;
if ($hasMore && $complaints !== []) {
  $nextBeforeId = (int)$complaints[count($complaints) - 1]['id'];
}

json_success([
  'complaints' => $complaints,
  'has_more' => $hasMore,
  'next_before_id' => $nextBeforeId,
]);

function complaint_public_query_scalar(string $key): ?string {
  if (!array_key_exists($key, $_GET)) return null;
  $value = $_GET[$key];
  if (!is_string($value)) json_error('Invalid ' . $key . '.', 422);
  return $value;
}

function complaint_public_limit(): int {
  $raw = complaint_public_query_scalar('limit');
  if ($raw === null) return 20;
  if ($raw === '' || !preg_match('/^[1-9][0-9]*$/', $raw)) json_error('Invalid limit.', 422);
  $limit = (int)$raw;
  if ($limit < 1 || $limit > 50) json_error('Invalid limit.', 422);
  return $limit;
}

function complaint_public_before_id(): ?int {
  $raw = complaint_public_query_scalar('before_id');
  if ($raw === null) return null;
  if ($raw === '' || !preg_match('/^[1-9][0-9]*$/', $raw)) json_error('Invalid before_id.', 422);
  return (int)$raw;
}

function complaint_public_category(): ?string {
  $raw = complaint_public_query_scalar('category');
  if ($raw === null || $raw === '' || $raw === 'all') {
    if ($raw === '') json_error('Invalid category.', 422);
    return null;
  }
  $keys = array_keys(complaint_extra_fields_schema());
  if (!in_array($raw, $keys, true)) json_error('Invalid category.', 422);
  return $raw;
}

function complaint_public_status(): ?string {
  $raw = complaint_public_query_scalar('status');
  if ($raw === null || $raw === '' || $raw === 'all') {
    if ($raw === '') json_error('Invalid status.', 422);
    return null;
  }
  if (!in_array($raw, ['open', 'investigating', 'resolved'], true)) {
    json_error('Invalid status.', 422);
  }
  return $raw;
}

function complaint_public_bind(mysqli_stmt $stmt, string $types, array $values): void {
  $refs = [];
  foreach ($values as $index => $value) {
    $refs[$index] = $value;
  }
  $args = [$types];
  foreach ($refs as $index => $_) {
    $args[] = &$refs[$index];
  }
  if (!call_user_func_array([$stmt, 'bind_param'], $args)) {
    throw new RuntimeException($stmt->error ?: 'Could not bind community list parameters.');
  }
}
