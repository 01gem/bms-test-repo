<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

// Household Registry view: one row per household with profile status and the
// stored MPI / income results. Unprofiled households sort first so they are
// visible to officials. Optional filter: ?purok=<purok_zone>.

require_once __DIR__ . '/../common.php';
session_start();
require_official_session();

$db = get_db();
$purok = trim((string)($_GET['purok'] ?? ''));

$sql = 'SELECT h.id, h.household_code, h.head_resident_id, h.address, h.purok_zone,
  h.household_size, h.profile_status, h.profile_completeness, h.profiled_at,
  h.assessment_complete, h.assessment_method_version, h.mpi_score, h.mpi_status,
  h.annual_household_income, h.annual_per_capita_income, h.income_poverty_status,
  h.mpi_indicators_json,
  r.fname AS head_fname, r.lname AS head_lname
  FROM households h
  LEFT JOIN residents r ON r.id = h.head_resident_id';
if ($purok !== '') $sql .= ' WHERE h.purok_zone = ?';
$sql .= ' ORDER BY
  CASE h.profile_status WHEN \'unprofiled\' THEN 0 WHEN \'in_progress\' THEN 1 ELSE 2 END,
  h.household_code ASC';

$stmt = $db->prepare($sql);
if (!$stmt) json_error('Failed to prepare registry query.', 500);
if ($purok !== '') $stmt->bind_param('s', $purok);
$rows = db_query_all($stmt);
$stmt->close();

$items = array_map(fn(array $r) => [
  'id'                        => (int)$r['id'],
  'code'                      => (string)$r['household_code'],
  'head_resident_id'          => $r['head_resident_id'] !== null ? (int)$r['head_resident_id'] : null,
  'head_name'                 => trim(($r['head_fname'] ?? '') . ' ' . ($r['head_lname'] ?? '')),
  'address'                   => (string)($r['address'] ?? ''),
  'purok_zone'                => (string)($r['purok_zone'] ?? ''),
  'household_size'            => (int)$r['household_size'],
  'profile_status'            => (string)$r['profile_status'],
  'profile_completeness'      => (int)$r['profile_completeness'],
  'profiled_at'               => $r['profiled_at'],
  'assessment_complete'       => (int)$r['assessment_complete'],
  'assessment_method_version' => $r['assessment_method_version'],
  'mpi_score'                 => $r['mpi_score'] !== null ? (float)$r['mpi_score'] : null,
  'mpi_status'                => $r['mpi_status'],
  'annual_household_income'   => $r['annual_household_income'] !== null ? (float)$r['annual_household_income'] : null,
  'annual_per_capita_income'  => $r['annual_per_capita_income'] !== null ? (float)$r['annual_per_capita_income'] : null,
  'income_poverty_status'     => $r['income_poverty_status'],
  'mpi_indicators_json'       => $r['mpi_indicators_json'],
], $rows);

json_success(['households' => $items]);
