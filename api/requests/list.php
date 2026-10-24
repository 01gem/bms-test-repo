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

$db = get_db();

$officialSession = !empty($_SESSION['official_id']);
$residentSession = !empty($_SESSION['resident_id']);
if (!$officialSession && !$residentSession) {
  http_response_code(401);
  echo json_encode(['success' => false, 'message' => 'Unauthorized']);
  exit;
}

if ($officialSession) {
  $stmt = $db->prepare('SELECT * FROM document_requests ORDER BY id DESC');
  if (!$stmt) json_error('Failed to prepare requests query.', 500);
} else {
  $residentId = !empty($_SESSION['resident_id']) ? (int)$_SESSION['resident_id'] : 0;
  if ($residentId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
  }
  $stmt = $db->prepare('SELECT * FROM document_requests WHERE resident_id = ? ORDER BY id DESC');
  if (!$stmt) json_error('Failed to prepare requests query.', 500);
  $stmt->bind_param('i', $residentId);
}
$rows = db_query_all($stmt);
$stmt->close();

json_success(['requests' => $rows]);
?>