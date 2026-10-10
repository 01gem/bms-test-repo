<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

require_once __DIR__ . '/../common.php';
session_start();

require_post();

$db = get_db();
$input = read_json_input();

$documentType = trim((string)($input['document_type'] ?? ''));
$purpose = trim((string)($input['purpose'] ?? ''));
$copies = (int)($input['copies'] ?? 1);
if ($documentType === '' || $purpose === '') {
  json_error('Document type and purpose are required.');
}
if ($copies < 1) $copies = 1;
if ($copies > 5) $copies = 5;

$residentId = !empty($_SESSION['resident_id']) ? (int)$_SESSION['resident_id'] : 0;
$residentName = trim((string)($_SESSION['resident_name'] ?? ''));

if ($residentId <= 0) {
  json_error('Resident session is missing required account linkage. Please log in again.', 401);
}
if ($residentName === '') {
  json_error('Resident session expired. Please log in again.', 401);
}

$prefix = 'REQ-' . date('Ymd') . '-';
$ref = $prefix . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 6));
$dateRequested = date('M d, Y');
$status = 'pending';

$priceStmt = $db->prepare('SELECT unit_price FROM document_prices WHERE document_type = ? LIMIT 1');
if (!$priceStmt) json_error('Failed to prepare document price lookup.', 500);
$priceStmt->bind_param('s', $documentType);
$priceRows = db_query_all($priceStmt);
$priceStmt->close();
if (!isset($priceRows[0]['unit_price'])) {
  json_error('This document type has no posted price. Please contact the barangay hall.', 422);
}
$unitPrice = (float)$priceRows[0]['unit_price'];
$totalAmount = round($unitPrice * $copies, 2);
$paymentStatus = $totalAmount <= 0 ? 'waived' : 'unpaid';

try {
  $stmt = $db->prepare("INSERT INTO document_requests (reference_no, resident_id, resident_name, document_type, purpose, copies, total_amount, payment_status, date_requested, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
  if (!$stmt) json_error('Failed to prepare request insert.', 500);
  $stmt->bind_param('sisssidsss', $ref, $residentId, $residentName, $documentType, $purpose, $copies, $totalAmount, $paymentStatus, $dateRequested, $status);

  if (!$stmt->execute()) {
    json_db_error('Failed to submit request', $stmt->error);
  }
  $stmt->close();
} catch (Throwable $e) {
  json_db_error('Failed to submit request', $e->getMessage());
}

json_success(['reference_no' => $ref, 'message' => 'Request submitted successfully.']);
?>