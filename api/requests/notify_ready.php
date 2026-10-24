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

require_post();

$db = get_db();
$input = read_json_input();

$referenceNo = trim((string)($input['reference_no'] ?? ''));
$contactOverride = trim((string)($input['contact'] ?? ''));
if ($referenceNo === '') {
  json_error('Request reference number is required.');
}
if ($contactOverride !== '' && !preg_match('/^[+0-9][0-9\s-]{6,}$/', $contactOverride)) {
  json_error('Provided contact number is invalid.');
}

$reqStmt = $db->prepare("SELECT id, reference_no, resident_id, resident_name, document_type, status FROM document_requests WHERE reference_no = ? LIMIT 1");
if (!$reqStmt) json_error('Failed to prepare request lookup.', 500);
$reqStmt->bind_param('s', $referenceNo);
$rows = db_query_all($reqStmt);
$reqStmt->close();
if (count($rows) === 0) {
  json_error('Document request not found.', 404);
}
$request = $rows[0];

$residentId = isset($request['resident_id']) ? (int)$request['resident_id'] : 0;
if ($residentId <= 0) {
  json_error('Document request is missing resident linkage.', 500);
}

$resContactStmt = $db->prepare('SELECT contact FROM residents WHERE id = ? LIMIT 1');
if (!$resContactStmt) json_error('Failed to prepare resident contact lookup.', 500);
$resContactStmt->bind_param('i', $residentId);
$resContactRows = db_query_all($resContactStmt);
$resContactStmt->close();
if (count($resContactRows) === 0) {
  json_error('Linked resident record not found for this request.', 404);
}
$residentContact = $contactOverride !== '' ? $contactOverride : trim((string)($resContactRows[0]['contact'] ?? ''));
$residentContact = normalize_ph_phone($residentContact) ?? $residentContact;

if ($residentContact === '') {
  json_error('Resident contact number not found. Enter the number manually when sending SMS.', 400);
}

$safeResident = $request['resident_name'] !== '' ? $request['resident_name'] : 'Resident';
$smsMessage = "Hello {$safeResident}, your requested document ({$request['document_type']}) with reference number {$request['reference_no']} is now ready for pickup at the Barangay Hall of Sampaguita. Please bring a valid ID when claiming your document. - Barangay Hall of Sampaguita";

$status = 'ready';
$upStmt = $db->prepare("UPDATE document_requests SET status = ? WHERE id = ?");
if (!$upStmt) json_error('Failed to prepare request status update.', 500);
$requestId = (int)$request['id'];
$upStmt->bind_param('si', $status, $requestId);
if (!$upStmt->execute()) {
  json_db_error('Failed to update request status', $upStmt->error);
}
$upStmt->close();

log_audit(
  $db,
  isset($_SESSION['official_id']) ? (int)$_SESSION['official_id'] : null,
  (string)($_SESSION['official_name'] ?? ''),
  'Sent pickup notification',
  'document_request',
  (string)$request['reference_no'],
  "Status updated to {$status}. Resident contact: {$residentContact}. SMS outcome recorded in sms_log."
);

$smsResult = notify_resident(
  $residentId,
  'request_pickup_ready',
  $smsMessage,
  'requests_enabled',
  $residentContact
);
if ($smsResult['status'] === 'failed') {
  http_response_code(500);
  echo json_encode([
    'success' => false,
    'message' => $smsResult['reason'] ?? 'SMS send failed.',
    'sms_status' => 'failed',
    'sms_reason' => $smsResult['reason'] ?? 'SMS send failed.',
  ]);
  exit;
}
$successMessage = $smsResult['status'] === 'sent'
  ? "SMS ready notice sent to {$residentContact} from Barangay Hall of Sampaguita."
  : "Request marked ready. {$smsResult['reason']}";
json_success([
  'message' => $successMessage,
  'sms_status' => $smsResult['status'],
  'sms_reason' => $smsResult['reason'],
]);
?>
