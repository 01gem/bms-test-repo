<?php
/**
 * BarangayLink - Update payment status on a document request.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 */

require_once __DIR__ . '/../common.php';
session_start();
require_official_session();
require_post();

$input = read_json_input();
$referenceNo = trim((string)($input['reference_no'] ?? ''));
$paymentStatus = trim((string)($input['payment_status'] ?? ''));

if ($referenceNo === '' || $paymentStatus === '') {
  json_error('Reference number and payment status are required.');
}

$allowed = ['unpaid', 'paid', 'waived'];
if (!in_array($paymentStatus, $allowed, true)) {
  json_error('Invalid payment status.');
}

$db = get_db();
$lookup = $db->prepare('SELECT id, reference_no FROM document_requests WHERE reference_no = ? LIMIT 1');
if (!$lookup) json_error('Failed to prepare request lookup.', 500);
$lookup->bind_param('s', $referenceNo);
$rows = db_query_all($lookup);
$lookup->close();
if (!$rows) {
  json_error('Document request not found.', 404);
}

$id = (int)$rows[0]['id'];
$stmt = $db->prepare('UPDATE document_requests SET payment_status = ? WHERE id = ?');
if (!$stmt) json_error('Failed to prepare payment update.', 500);
$stmt->bind_param('si', $paymentStatus, $id);
if (!$stmt->execute()) {
  json_db_error('Failed to update payment status', $stmt->error);
}
$stmt->close();

log_audit(
  $db,
  isset($_SESSION['official_id']) ? (int)$_SESSION['official_id'] : null,
  (string)($_SESSION['official_name'] ?? ''),
  'Updated document request payment',
  'document_request',
  (string)$rows[0]['reference_no'],
  'Payment status: ' . $paymentStatus
);

json_success(['message' => "Payment status updated to {$paymentStatus}."]);
