<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

// Loads everything the profiling wizard needs for a household:
//   - the household row (for header display and current assessment)
//   - the household_profiles row (or null when unprofiled)
//   - the step definitions (so the frontend never hardcodes field lists)
//   - the suggested resume step

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/profile_steps.php';
session_start();
require_official_session();

$householdId = (int)($_GET['household_id'] ?? 0);
if ($householdId <= 0) {
  json_error('Valid household id is required.');
}

$db = get_db();

$hStmt = $db->prepare(
  'SELECT h.id, h.household_code, h.household_size, h.purok_zone,
          h.profile_status, h.profile_completeness, h.profiled_at,
          h.assessment_complete, h.mpi_score, h.mpi_status,
          h.annual_per_capita_income, h.income_poverty_status,
          h.head_resident_id,
          r.fname AS head_fname, r.lname AS head_lname
     FROM households h
     LEFT JOIN residents r ON r.id = h.head_resident_id
     WHERE h.id = ? LIMIT 1'
);
if (!$hStmt) json_error('Failed to prepare household lookup.', 500);
$hStmt->bind_param('i', $householdId);
$households = db_query_all($hStmt);
$hStmt->close();
if (!$households) json_error('Household not found.', 404);
$h = $households[0];

$pStmt = $db->prepare('SELECT * FROM household_profiles WHERE household_id = ? LIMIT 1');
if (!$pStmt) json_error('Failed to prepare profile lookup.', 500);
$pStmt->bind_param('i', $householdId);
$profiles = db_query_all($pStmt);
$pStmt->close();
$profile = $profiles[0] ?? null;

// Build a trimmed view of the profile containing only the questionnaire
// fields (excludes id, household_id, updated_at).
$questionnaire = [];
if ($profile !== null) {
  foreach (profile_steps() as $def) {
    foreach ($def['fields'] as $f) {
      $questionnaire[$f] = $profile[$f] ?? null;
    }
  }
}

$stepDefs = [];
foreach (profile_steps() as $n => $def) {
  $stepDefs[] = [
    'step'   => $n,
    'slug'   => $def['slug'],
    'title'  => $def['title'],
    'fields' => $def['fields'],
  ];
}

json_success([
  'household' => [
    'id'                    => (int)$h['id'],
    'code'                  => (string)$h['household_code'],
    'household_size'        => (int)$h['household_size'],
    'purok_zone'            => (string)($h['purok_zone'] ?? ''),
    'head_resident_id'      => $h['head_resident_id'] !== null ? (int)$h['head_resident_id'] : null,
    'head_name'             => trim(($h['head_fname'] ?? '') . ' ' . ($h['head_lname'] ?? '')),
    'profile_status'        => (string)$h['profile_status'],
    'profile_completeness'  => (int)$h['profile_completeness'],
    'profiled_at'           => $h['profiled_at'],
    'assessment_complete'   => (int)$h['assessment_complete'],
    'mpi_score'             => $h['mpi_score'] !== null ? (float)$h['mpi_score'] : null,
    'mpi_status'            => $h['mpi_status'],
    'annual_per_capita_income' => $h['annual_per_capita_income'] !== null ? (float)$h['annual_per_capita_income'] : null,
    'income_poverty_status' => $h['income_poverty_status'],
  ],
  'profile'              => $questionnaire ?: null,
  'steps'                => $stepDefs,
  'field_types'          => profile_field_types(),
  'suggested_start_step' => profile_next_incomplete_step($profile),
]);
