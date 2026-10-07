<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

require_once __DIR__ . '/../common.php';

$db = get_db();
$residentResult = $db->query("SELECT COUNT(*) AS total FROM residents WHERE status = 'active'");
$serviceResult = $db->query('SELECT COUNT(*) AS total FROM local_services');
if (!$residentResult || !$serviceResult) {
  json_error('Unable to load landing page statistics.', 500);
}

$residentRow = $residentResult->fetch_assoc();
$serviceRow = $serviceResult->fetch_assoc();

// NOTE (Day 1): fulfillment rate and average response time are placeholders.
// Replace with real computations once enough request history exists.
$requestFulfillmentRate = null;
$avgResponseTimeMinutes = null;

json_success([
  'registered_residents' => (int)$residentRow['total'],
  'verified_local_services' => (int)$serviceRow['total'],
  'request_fulfillment_rate' => $requestFulfillmentRate,
  'avg_response_time_minutes' => $avgResponseTimeMinutes
]);
?>
