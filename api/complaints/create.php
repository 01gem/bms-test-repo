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

if (empty($_SESSION['resident_id'])) {
  http_response_code(401);
  echo json_encode(['success' => false, 'message' => 'Unauthorized']);
  exit;
}

require_post();

$db = get_db();
$rawRequest = json_decode(file_get_contents('php://input'));
if (!$rawRequest instanceof stdClass) json_error('A valid JSON object is required.', 422);
$input = get_object_vars($rawRequest);
foreach (['category', 'location_text', 'description', 'severity'] as $field) {
  if (array_key_exists($field, $input) && $input[$field] !== null && !is_string($input[$field])) {
    json_error('Complaint fields must be text.', 422);
  }
}

$category = trim((string)($input['category'] ?? ''));
$location = trim((string)($input['location_text'] ?? ''));
$description = trim((string)($input['description'] ?? ''));
$severity = strtolower(trim((string)($input['severity'] ?? 'medium')));
$allowedSeverity = ['low', 'medium', 'high', 'critical'];
if (!in_array($severity, $allowedSeverity, true)) {
  json_error('Invalid severity. Allowed values are: low, medium, high, critical.', 422);
}
if ($category === '' || $location === '' || $description === '') {
  json_error('Category, location, and description are required.');
}

try {
  $extraFields = complaint_extra_fields_validate($category, $input['extra_fields'] ?? null);
  $extraFieldsJson = $extraFields
    ? json_encode((object)$extraFields, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
    : null;
} catch (InvalidArgumentException $error) {
  json_error($error->getMessage(), 422);
}

$prefix = 'CSR-' . date('Ymd') . '-';
$ref = $prefix . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 6));
$residentId = (int)$_SESSION['resident_id'];
$residentName = trim((string)($_SESSION['resident_name'] ?? ''));
if ($residentName === '') {
  json_error('Resident session expired. Please log in again.', 401);
}
$dateFiled = date('M d, Y');
$status = 'open';
$note = 'Received. Awaiting assignment.';

$stmt = $db->prepare("INSERT INTO complaints
  (resident_id, reference_no, resident_name, category, severity, location_text, description, date_filed, status, official_note, extra_fields_json)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
if (!$stmt) json_error('Failed to prepare complaint insert.', 500);
$stmt->bind_param('issssssssss',
  $residentId, $ref, $residentName, $category, $severity,
  $location, $description, $dateFiled, $status, $note, $extraFieldsJson);
if (!$stmt->execute()) {
  json_db_error('Failed to submit complaint', $stmt->error);
}
$complaintId = (int)$db->insert_id;
$stmt->close();

$actorType = 'resident';
$fromStatus = null;
$toStatus = 'open';
$filedNote = 'Complaint filed.';
$updStmt = $db->prepare(
  'INSERT INTO complaint_updates (complaint_id, actor_type, actor_id, from_status, to_status, note)
   VALUES (?, ?, ?, ?, ?, ?)'
);
if ($updStmt) {
  $updStmt->bind_param('isisss', $complaintId, $actorType, $residentId, $fromStatus, $toStatus, $filedNote);
  if (!$updStmt->execute()) error_log('complaint_updates insert failed: ' . $updStmt->error);
  $updStmt->close();
}

$residentFirstName = trim(explode(' ', $residentName)[0] ?? $residentName);
if ($residentFirstName === '') $residentFirstName = 'there';
$smsBody = "Hi {$residentFirstName}, your complaint {$ref} was received by Brgy. Sampaguita. "
         . "We'll update you on any changes.";
notify_resident($residentId, 'complaint_filed', $smsBody, 'complaints_enabled');

json_success([
    'id'           => $complaintId,
    'reference_no' => $ref,
    'message'      => 'Complaint submitted successfully.',
]);
?>
