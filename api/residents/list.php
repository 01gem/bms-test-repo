<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

require_once __DIR__ . '/../common.php';
session_start();
enforce_active_session();
if (empty($_SESSION['official_id'])) {
	http_response_code(401);
	echo json_encode(['success' => false, 'message' => 'Unauthorized']);
	exit;
}

$db = get_db();

$sql = "SELECT r.id, r.fname, r.lname, r.address, r.contact, r.username, r.profile_photo, r.status,
  r.birthdate, r.civil_status, r.purok_zone, r.household_size,
  r.is_solo_parent, r.is_pwd, r.is_4ps_member,
  r.educational_attainment, r.employment_status, r.occupation,
  r.training_certifications, r.work_availability,
  r.skill_category,
  r.household_id, r.relationship_to_household_head, r.profile_status,
  h.household_code,
  h.profile_status         AS household_profile_status,
  h.profile_completeness   AS household_profile_completeness,
  h.mpi_score              AS household_mpi_score,
  h.mpi_status             AS household_mpi_status,
  h.income_poverty_status  AS household_income_poverty_status,
  h.mpi_indicators_json    AS household_mpi_indicators_json
  FROM residents r
  LEFT JOIN households h ON h.id = r.household_id
  ORDER BY r.id DESC";
$stmt = $db->prepare($sql);
if (!$stmt) json_error('Failed to prepare residents query.', 500);
$rows = db_query_all($stmt);
$stmt->close();

json_success(['residents' => $rows]);
