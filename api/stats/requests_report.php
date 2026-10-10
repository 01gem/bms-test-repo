<?php
/**
 * BarangayLink - Document-request report totals for officials.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 */

require_once __DIR__ . '/../common.php';
session_start();
require_official_session();

$db = get_db();

$totalsResult = $db->query("
  SELECT
    COUNT(*) AS request_count,
    COALESCE(SUM(copies), 0) AS total_copies,
    COALESCE(SUM(total_amount), 0) AS gross_amount,
    COALESCE(SUM(CASE WHEN payment_status='paid'   THEN total_amount ELSE 0 END), 0) AS collected_amount,
    COALESCE(SUM(CASE WHEN payment_status='unpaid' THEN total_amount ELSE 0 END), 0) AS pending_amount,
    COALESCE(SUM(CASE WHEN payment_status='waived' THEN total_amount ELSE 0 END), 0) AS waived_amount
  FROM document_requests
");
if (!$totalsResult) json_error('Unable to load document-request totals.', 500);
$totals = $totalsResult->fetch_assoc() ?: [];

$byTypeResult = $db->query("
  SELECT document_type,
         COUNT(*) AS request_count,
         COALESCE(SUM(copies), 0) AS total_copies,
         COALESCE(SUM(total_amount), 0) AS gross_amount,
         COALESCE(SUM(CASE WHEN payment_status='paid' THEN total_amount ELSE 0 END), 0) AS collected_amount
    FROM document_requests
   GROUP BY document_type
   ORDER BY request_count DESC
");
if (!$byTypeResult) json_error('Unable to load document-request type totals.', 500);
$byType = $byTypeResult->fetch_all(MYSQLI_ASSOC);

$monthlyResult = $db->query("
  SELECT DATE_FORMAT(STR_TO_DATE(date_requested, '%b %d, %Y'), '%Y-%m') AS ym,
         COUNT(*) AS request_count,
         COALESCE(SUM(copies), 0) AS total_copies,
         COALESCE(SUM(total_amount), 0) AS gross_amount,
         COALESCE(SUM(CASE WHEN payment_status='paid'   THEN total_amount ELSE 0 END), 0) AS collected_amount,
         COALESCE(SUM(CASE WHEN payment_status='unpaid' THEN total_amount ELSE 0 END), 0) AS pending_amount,
         COALESCE(SUM(CASE WHEN payment_status='waived' THEN total_amount ELSE 0 END), 0) AS waived_amount
    FROM document_requests
   WHERE STR_TO_DATE(date_requested, '%b %d, %Y') IS NOT NULL
   GROUP BY ym
   ORDER BY ym DESC
   LIMIT 12
");
if (!$monthlyResult) json_error('Unable to load monthly document-request totals.', 500);
$monthly = $monthlyResult->fetch_all(MYSQLI_ASSOC);

json_success([
  'totals' => $totals,
  'by_type' => $byType,
  'monthly' => $monthly,
]);
