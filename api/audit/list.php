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

$officialRole = (string)($_SESSION['official_role'] ?? '');
$selectedOfficialId = null;
if ($officialRole === 'admin' && isset($_GET['official_id']) && $_GET['official_id'] !== '') {
  $selectedOfficialId = filter_var($_GET['official_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
  if ($selectedOfficialId === false) json_error('Invalid official filter.', 422);
}

$query = 'SELECT a.id, a.official_id, a.official_name, COALESCE(o.role, \'system\') AS official_role,
                 a.action, a.target_type, a.target_reference, a.details, a.created_at
          FROM audit_log a
          LEFT JOIN barangay_officials o ON o.id = a.official_id';
$params = [];
$types = '';
if ($officialRole === 'staff') {
  $query .= ' WHERE a.official_id = ?';
  $params[] = (int)$_SESSION['official_id'];
  $types .= 'i';
} elseif ($selectedOfficialId !== null) {
  $query .= ' WHERE a.official_id = ?';
  $params[] = (int)$selectedOfficialId;
  $types .= 'i';
}
$query .= ' ORDER BY a.created_at DESC, a.id DESC';

$stmt = $db->prepare($query);
if (!$stmt) json_error('Failed to prepare audit log query.', 500);
if ($params) {
  $stmt->bind_param($types, ...$params);
}
$rows = db_query_all($stmt);
$stmt->close();

$officials = [];
if ($officialRole === 'admin') {
  $officialStmt = $db->prepare('SELECT id, fname, lname, role FROM barangay_officials ORDER BY fname ASC, lname ASC, id ASC');
  if (!$officialStmt) json_error('Failed to prepare official filter query.', 500);
  $officials = db_query_all($officialStmt);
  $officialStmt->close();
}

json_success(['logs' => $rows, 'officials' => $officials]);
?>
