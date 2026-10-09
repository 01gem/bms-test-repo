<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

require_once __DIR__ . '/../common.php';
session_start();
// Allow both staff and admin officials (401 if no official session at all)
if (empty($_SESSION['official_id'])) {
  http_response_code(401);
  echo json_encode(['success' => false, 'message' => 'Unauthorized']);
  exit;
}

require_post();
$db = get_db();
$input = read_json_input();

$fname = trim((string)($input['fname'] ?? ''));
$lname = trim((string)($input['lname'] ?? ''));
$address = trim((string)($input['address'] ?? ''));
$contact = trim((string)($input['contact'] ?? ''));
$username = trim((string)($input['username'] ?? ''));
$password = (string)($input['password'] ?? '');

if ($fname === '' || $lname === '' || $address === '' || $contact === '' || $username === '' || $password === '') {
  json_error('All resident fields are required.');
}
if (!preg_match('/^[a-zA-Z0-9._]{3,50}$/', $username)) {
  json_error('Username must be 3-50 characters and contain only letters, numbers, dots, and underscores.');
}

$check = $db->prepare("SELECT id FROM residents WHERE username = ? LIMIT 1");
if (!$check) json_error('Failed to validate resident username.', 500);
$check->bind_param('s', $username);
$rows = db_query_all($check);
$check->close();
if (count($rows) > 0) {
  json_error('Resident username already exists.');
}

// ── Household linkage (wizard step 2) ──
// household_mode:
//   'new'  — create a household, this resident becomes its head.
//   'link' — attach to an existing household_id.
//   'skip' — no linkage (default; resident stays unprofiled).
// profile_status is intentionally not set here — the DB default 'unprofiled' applies
// until the household workflows promote it.
$householdMode = (string)($input['household_mode'] ?? 'skip');
if (!in_array($householdMode, ['new', 'link', 'skip'], true)) $householdMode = 'skip';

$householdId = null;
$relationshipToHead = trim((string)($input['relationship_to_household_head'] ?? '')) ?: null;
$householdSizeInput = isset($input['household_size']) && $input['household_size'] !== '' ? (int)$input['household_size'] : 1;

if ($householdMode === 'link') {
  $candidateId = (int)($input['household_id'] ?? 0);
  if ($candidateId <= 0) json_error('Please select a household to link.');
  $exists = $db->prepare('SELECT id FROM households WHERE id = ? LIMIT 1');
  if (!$exists) json_error('Failed to validate household.', 500);
  $exists->bind_param('i', $candidateId);
  $found = db_query_all($exists);
  $exists->close();
  if (count($found) === 0) json_error('Selected household was not found.');
  $householdId = $candidateId;
} elseif ($householdMode === 'new') {
  $relationshipToHead = 'Head';
}

// ── Profiling fields (wizard step 3; persisted only when profile_now = 1) ──
$profileNow = !empty($input['profile_now']) && (string)$input['profile_now'] !== '0';

$birthdate = $civil_status = $purok_zone = $educational_attainment = null;
$employment_status = $occupation = $monthly_income_bracket = $skills = null;
$training_certifications = $work_availability = null;
$number_of_dependents = 0; $years_of_residency = 0; $work_experience_years = 0;
$is_household_head = 0; $is_solo_parent = 0; $is_pwd = 0; $is_4ps_member = 0; $has_drivers_license = 0;
$household_size = max(1, $householdSizeInput);

if ($profileNow) {
  $birthdate = trim((string)($input['birthdate'] ?? '')) ?: null;
  $civil_status = trim((string)($input['civil_status'] ?? '')) ?: null;
  $purok_zone = trim((string)($input['purok_zone'] ?? '')) ?: null;
  $number_of_dependents = isset($input['number_of_dependents']) && $input['number_of_dependents'] !== '' ? (int)$input['number_of_dependents'] : 0;
  $is_household_head = !empty($input['is_household_head']) ? 1 : 0;
  $is_solo_parent = !empty($input['is_solo_parent']) ? 1 : 0;
  $is_pwd = !empty($input['is_pwd']) ? 1 : 0;
  $is_4ps_member = !empty($input['is_4ps_member']) ? 1 : 0;
  $years_of_residency = isset($input['years_of_residency']) && $input['years_of_residency'] !== '' ? (int)$input['years_of_residency'] : 0;
  $educational_attainment = trim((string)($input['educational_attainment'] ?? '')) ?: null;
  $employment_status = trim((string)($input['employment_status'] ?? '')) ?: null;
  $occupation = trim((string)($input['occupation'] ?? '')) ?: null;
  $monthly_income_bracket = trim((string)($input['monthly_income_bracket'] ?? '')) ?: null;
  $skills = trim((string)($input['skills'] ?? '')) ?: null;
  $work_experience_years = isset($input['work_experience_years']) && $input['work_experience_years'] !== '' ? (int)$input['work_experience_years'] : 0;
  $training_certifications = trim((string)($input['training_certifications'] ?? '')) ?: null;
  $work_availability = trim((string)($input['work_availability'] ?? '')) ?: null;
  $has_drivers_license = !empty($input['has_drivers_license']) ? 1 : 0;
}
// The head of a newly created household is always flagged as household head.
if ($householdMode === 'new') $is_household_head = 1;

$hash = password_hash($password, PASSWORD_DEFAULT);

$householdInsertSql = 'INSERT INTO households (household_code, address, purok_zone, household_size, profile_status)'
  . ' VALUES (?, ?, ?, ?, \'unprofiled\')';

$db->begin_transaction();
try {
  if ($householdMode === 'new') {
    $hAddress = trim((string)($input['household_address'] ?? '')) ?: $address;
    $hPurok = trim((string)($input['household_purok_zone'] ?? '')) ?: $purok_zone;
    $hSize = $household_size;

    // next_household_code() is not race-safe; retry once on a UNIQUE KEY collision (errno 1062).
    $inserted = false;
    for ($attempt = 1; $attempt <= 2 && !$inserted; $attempt++) {
      $newCode = next_household_code();
      $hStmt = $db->prepare($householdInsertSql);
      if (!$hStmt) throw new RuntimeException('household prepare failed: ' . $db->error);
      $hStmt->bind_param('sssi', $newCode, $hAddress, $hPurok, $hSize);
      if ($hStmt->execute()) {
        $householdId = (int)$hStmt->insert_id;
        $inserted = true;
      } elseif ((int)$hStmt->errno !== 1062 || $attempt === 2) {
        $err = $hStmt->error;
        $hStmt->close();
        throw new RuntimeException('household insert failed: ' . $err);
      }
      $hStmt->close();
    }
  }

  $insert = $db->prepare("INSERT INTO residents (
    fname, lname, address, contact, username, password,
    birthdate, civil_status, purok_zone, household_size, number_of_dependents,
    is_household_head, is_solo_parent, is_pwd, is_4ps_member, years_of_residency,
    educational_attainment, employment_status, occupation, monthly_income_bracket,
    skills, work_experience_years, training_certifications, work_availability,
    has_drivers_license,
    household_id, relationship_to_household_head
  ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
  if (!$insert) throw new RuntimeException('resident prepare failed: ' . $db->error);
  $insert->bind_param('sssssssssiiiiiiisssssissiis',
    $fname, $lname, $address, $contact, $username, $hash,
    $birthdate, $civil_status, $purok_zone, $household_size, $number_of_dependents,
    $is_household_head, $is_solo_parent, $is_pwd, $is_4ps_member, $years_of_residency,
    $educational_attainment, $employment_status, $occupation, $monthly_income_bracket,
    $skills, $work_experience_years, $training_certifications, $work_availability, $has_drivers_license,
    $householdId, $relationshipToHead
  );
  if (!$insert->execute()) {
    $err = $insert->error;
    $insert->close();
    throw new RuntimeException('resident insert failed: ' . $err);
  }
  $residentId = (int)$insert->insert_id;
  $insert->close();

  // Backfill the head pointer now that the resident row exists.
  if ($householdMode === 'new' && $householdId !== null) {
    $u = $db->prepare('UPDATE households SET head_resident_id = ? WHERE id = ?');
    if (!$u) throw new RuntimeException('household head prepare failed: ' . $db->error);
    $u->bind_param('ii', $residentId, $householdId);
    if (!$u->execute()) {
      $err = $u->error;
      $u->close();
      throw new RuntimeException('household head update failed: ' . $err);
    }
    $u->close();
  }

  $db->commit();
} catch (Throwable $e) {
  $db->rollback();
  json_db_error('Failed to create resident', $e->getMessage());
}

log_audit(
  $db,
  (int)$_SESSION['official_id'],
  (string)($_SESSION['official_name'] ?? ''),
  'Created resident',
  'resident',
  "{$fname} {$lname} (ID: {$residentId})",
  'Household mode: ' . $householdMode
    . ($householdId !== null ? " (household ID: {$householdId})" : '')
    . '; profiling: ' . ($profileNow ? 'completed now' : 'deferred')
);

json_success([
  'message' => 'Resident created successfully.',
  'resident_id' => $residentId,
  'household_id' => $householdId,
]);
?>
