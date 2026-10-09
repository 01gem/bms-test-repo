<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

// Saves one step of the household profiling wizard.
//
// Payload: { "household_id": 1, "step": 3, "fields": { ... } }
// Only fields owned by the given step are written. After the write, the MPI
// engine is run and its full assessment row is persisted to `households`.
// Returns the new completeness so the progress bar can advance.

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/profile_steps.php';
require_once __DIR__ . '/mpi_engine.php';
session_start();
require_official_session();
require_post();

$input = read_json_input();
$householdId = (int)($input['household_id'] ?? 0);
$step        = (int)($input['step'] ?? 0);
$fields      = is_array($input['fields'] ?? null) ? $input['fields'] : [];

if ($householdId <= 0) json_error('Valid household id is required.');

$stepFields = profile_step_fields($step);
if (!$stepFields) json_error('Invalid profile step.');

$db = get_db();

// ── 1. Household exists? ──
$hStmt = $db->prepare('SELECT id, household_size FROM households WHERE id = ? LIMIT 1');
if (!$hStmt) json_error('Failed to prepare household lookup.', 500);
$hStmt->bind_param('i', $householdId);
$households = db_query_all($hStmt);
$hStmt->close();
if (!$households) json_error('Household not found.', 404);
$household = $households[0];

// ── 2. Filter + normalize incoming fields ──
$types = profile_field_types();
$accepted = [];
foreach ($fields as $key => $raw) {
  if (!is_string($key)) continue;
  if (!in_array($key, $stepFields, true)) continue;   // step allowlist
  if (!isset($types[$key])) continue;                 // safety net
  $value = profile_normalize_value($types[$key], $raw);
  // JSON columns reject malformed text at the storage layer; fail fast with
  // a 422 instead of surfacing a generic database error.
  if ($types[$key] === 'json' && $value !== null) {
    json_decode($value, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
      json_error("Field '{$key}' must be valid JSON.", 422);
    }
  }
  $accepted[$key] = $value;
}
if (!$accepted) json_error('No valid fields in payload for this step.', 422);

// ── 3. Ensure the profile row exists (idempotent) ──
$bootstrap = $db->prepare('INSERT IGNORE INTO household_profiles (household_id) VALUES (?)');
if (!$bootstrap) json_error('Failed to prepare profile bootstrap.', 500);
$bootstrap->bind_param('i', $householdId);
if (!$bootstrap->execute()) {
  $err = $bootstrap->error;
  $bootstrap->close();
  json_db_error('Failed to bootstrap profile row', $err);
}
$bootstrap->close();

// ── 4. UPDATE only the columns owned by this step ──
$setParts = [];
$bindTypes = '';
$bindValues = [];
foreach ($accepted as $col => $value) {
  $setParts[]  = "`$col` = ?";
  $bindTypes  .= profile_bind_char($types[$col]);
  $bindValues[] = $value;
}
$bindTypes  .= 'i';
$bindValues[] = $householdId;

$sql = 'UPDATE household_profiles SET ' . implode(', ', $setParts) . ' WHERE household_id = ?';
$stmt = $db->prepare($sql);
if (!$stmt) json_error('Failed to prepare profile update.', 500);
$stmt->bind_param($bindTypes, ...$bindValues);
if (!$stmt->execute()) {
  $err = $stmt->error;
  $stmt->close();
  json_db_error('Failed to save profile step', $err);
}
$stmt->close();

// ── 5. Reload the profile and run the deterministic assessment ──
$pStmt = $db->prepare('SELECT * FROM household_profiles WHERE household_id = ? LIMIT 1');
if (!$pStmt) json_error('Failed to prepare profile reload.', 500);
$pStmt->bind_param('i', $householdId);
$profiles = db_query_all($pStmt);
$pStmt->close();
$profile = $profiles[0] ?? null;

$threshold   = lookup_poverty_threshold();
$assessment  = assess_household($household, $profile, $threshold);

// ── 6. Persist the assessment to `households` ──
$setParts = [];
$bindTypes = '';
$bindValues = [];
foreach ($assessment as $col => $value) {
  $setParts[] = "`$col` = ?";
  if (is_int($value))        $bindTypes .= 'i';
  elseif (is_float($value))  $bindTypes .= 'd';
  else                       $bindTypes .= 's';
  $bindValues[] = $value;
}
// Stamp profiled_at the first time the profile completes.
if (($assessment['profile_status'] ?? '') === 'complete') {
  $setParts[] = '`profiled_at` = COALESCE(`profiled_at`, NOW())';
}
$bindTypes .= 'i';
$bindValues[] = $householdId;

$upd = $db->prepare('UPDATE households SET ' . implode(', ', $setParts) . ' WHERE id = ?');
if (!$upd) json_error('Failed to prepare assessment persist.', 500);
$upd->bind_param($bindTypes, ...$bindValues);
if (!$upd->execute()) {
  $err = $upd->error;
  $upd->close();
  json_db_error('Failed to persist assessment', $err);
}
$upd->close();

// ── 7. Respond with the new derived state ──
json_success([
  'step'                  => $step,
  'saved_fields'          => array_keys($accepted),
  'profile_completeness'  => $assessment['profile_completeness'],
  'profile_status'        => $assessment['profile_status'],
  'mpi_score'             => $assessment['mpi_score'],
  'mpi_status'            => $assessment['mpi_status'],
  'annual_per_capita_income' => $assessment['annual_per_capita_income'],
  'income_poverty_status' => $assessment['income_poverty_status'],
  'assessment_complete'   => $assessment['assessment_complete'],
  'next_incomplete_step'  => profile_next_incomplete_step($profile),
]);
