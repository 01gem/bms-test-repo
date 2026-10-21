<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_extra_fields.php';
session_start();

$officialSession = !empty($_SESSION['official_id']);
$residentSession = !empty($_SESSION['resident_id']);
if (!$officialSession && !$residentSession) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
}

$db = get_db();

$sql = "SELECT c.id, c.resident_id, c.reference_no, c.resident_name, c.category, c.severity, c.location_text, c.description, c.date_filed, c.status, c.official_note, c.extra_fields_json,
        c.resident_confirmation, c.confirmed_at, c.reopened_at,
        c.assigned_official_id, c.sla_due_at, c.escalated_at,
        CASE
          WHEN a.id IS NULL THEN NULL
          WHEN TRIM(CONCAT(COALESCE(a.fname, ''), ' ', COALESCE(a.lname, ''))) = '' THEN NULL
          ELSE TRIM(CONCAT(COALESCE(a.fname, ''), ' ', COALESCE(a.lname, '')))
        END AS assigned_official_name,
        a.status AS assigned_official_status,
        CASE
          WHEN c.status IN ('open', 'investigating')
           AND c.sla_due_at IS NOT NULL
           AND c.sla_due_at <= NOW()
          THEN 1 ELSE 0
        END AS is_overdue,
        COALESCE((SELECT MAX(u.id) FROM complaint_updates u
                  WHERE u.complaint_id = c.id AND u.to_status = 'resolved'
                    AND COALESCE(u.from_status, '') <> 'resolved'), 0) AS resolution_update_id
        FROM complaints c
        LEFT JOIN barangay_officials a ON a.id = c.assigned_official_id";
if ($officialSession) {
  $sql .= ' ORDER BY c.id DESC';
} else {
  $sql .= ' WHERE c.resident_id = ? ORDER BY c.id DESC';
}
$stmt = $db->prepare($sql);
if (!$stmt) json_error('Failed to prepare complaints query.', 500);
if (!$officialSession) {
  $residentId = (int)$_SESSION['resident_id'];
  $stmt->bind_param('i', $residentId);
}
$rows = db_query_all($stmt);
$stmt->close();

foreach ($rows as &$row) {
  $extraFields = complaint_extra_fields_read((string)$row['category'], $row['extra_fields_json']);
  $row['extra_fields'] = (object)$extraFields;
  $row['extra_fields_display'] = complaint_extra_fields_display((string)$row['category'], $extraFields);
  unset($row['extra_fields_json']);
}
unset($row);

json_success(['complaints' => $rows]);
?>
