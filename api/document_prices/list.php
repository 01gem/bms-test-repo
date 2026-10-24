<?php
/**
 * BarangayLink - List document unit prices.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 */

require_once __DIR__ . '/../common.php';
session_start();
enforce_active_session();
if (empty($_SESSION['official_id']) && empty($_SESSION['resident_id'])) {
  json_error('Unauthorized', 401);
}

$db = get_db();
$stmt = $db->prepare('SELECT id, document_type, unit_price, updated_at FROM document_prices ORDER BY document_type ASC');
if (!$stmt) json_error('Failed to prepare document prices query.', 500);
$rows = db_query_all($stmt);
$stmt->close();

json_success(['prices' => $rows]);
