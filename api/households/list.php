<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

// Lists households for the resident wizard's "link to existing household" dropdown.
// Optional filters: ?purok=<purok_zone> and ?status=<unprofiled|in_progress|complete>.

require_once __DIR__ . '/../common.php';
session_start();
require_official_session();

$db = get_db();
$purok  = trim((string)($_GET['purok']  ?? ''));
$status = trim((string)($_GET['status'] ?? ''));

$sql = 'SELECT h.id, h.household_code, h.head_resident_id, h.address, h.purok_zone,
  h.household_size, h.profile_status, h.profile_completeness, h.profiled_at,
  h.assessment_complete, h.mpi_score, h.mpi_status, h.income_poverty_status,
  r.fname AS head_fname, r.lname AS head_lname
  FROM households h
  LEFT JOIN residents r ON r.id = h.head_resident_id';
$where = [];
$params = [];
$types = '';
if ($purok !== '') { $where[] = 'h.purok_zone = ?'; $params[] = $purok; $types .= 's'; }
if ($status !== '' && in_array($status, ['unprofiled', 'in_progress', 'complete'], true)) {
  $where[] = 'h.profile_status = ?'; $params[] = $status; $types .= 's';
}
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY h.household_code ASC';

$stmt = $db->prepare($sql);
if (!$stmt) json_error('Failed to prepare household query.', 500);
if ($types !== '') $stmt->bind_param($types, ...$params);
$rows = db_query_all($stmt);
$stmt->close();

$households = array_map(fn(array $r) => [
  'id'                    => (int)$r['id'],
  'code'                  => (string)$r['household_code'],
  'head_resident_id'      => $r['head_resident_id'] !== null ? (int)$r['head_resident_id'] : null,
  'head_name'             => trim(($r['head_fname'] ?? '') . ' ' . ($r['head_lname'] ?? '')),
  'address'               => (string)($r['address'] ?? ''),
  'purok_zone'            => (string)($r['purok_zone'] ?? ''),
  'household_size'        => (int)$r['household_size'],
  'profile_status'        => (string)$r['profile_status'],
  'profile_completeness'  => (int)$r['profile_completeness'],
  'profiled_at'           => $r['profiled_at'],
  'assessment_complete'   => (int)$r['assessment_complete'],
  'mpi_score'             => $r['mpi_score'] !== null ? (float)$r['mpi_score'] : null,
  'mpi_status'            => $r['mpi_status'],
  'income_poverty_status' => $r['income_poverty_status'],
], $rows);

json_success(['households' => $households]);
