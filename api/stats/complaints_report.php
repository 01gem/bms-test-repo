<?php
/**
 * BarangayLink - Complaints report totals for officials.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 */

require_once __DIR__ . '/../common.php';
session_start();
require_official_session();

$db = get_db();

$totalsResult = $db->query("
  SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN status='open'          THEN 1 ELSE 0 END) AS open,
    SUM(CASE WHEN status='investigating' THEN 1 ELSE 0 END) AS investigating,
    SUM(CASE WHEN status='resolved'      THEN 1 ELSE 0 END) AS resolved
  FROM complaints
");
if (!$totalsResult) json_error('Unable to load complaint totals.', 500);
$totals = $totalsResult->fetch_assoc() ?: [];

$byCategoryResult = $db->query("
  SELECT category, COUNT(*) AS count
    FROM complaints GROUP BY category ORDER BY count DESC
");
if (!$byCategoryResult) json_error('Unable to load complaint category totals.', 500);
$byCategory = $byCategoryResult->fetch_all(MYSQLI_ASSOC);

$bySeverityResult = $db->query("
  SELECT severity, COUNT(*) AS count
    FROM complaints GROUP BY severity
");
if (!$bySeverityResult) json_error('Unable to load complaint severity totals.', 500);
$bySeverity = $bySeverityResult->fetch_all(MYSQLI_ASSOC);

$crossResult = $db->query("
  SELECT category, severity, COUNT(*) AS count
    FROM complaints GROUP BY category, severity
");
if (!$crossResult) json_error('Unable to load complaint cross totals.', 500);
$cross = $crossResult->fetch_all(MYSQLI_ASSOC);

$rankResult = $db->query("
  SELECT ym, rn, category, count FROM (
    SELECT DATE_FORMAT(STR_TO_DATE(date_filed, '%b %d, %Y'), '%Y-%m') AS ym,
           category,
           COUNT(*) AS count,
           ROW_NUMBER() OVER (
             PARTITION BY DATE_FORMAT(STR_TO_DATE(date_filed, '%b %d, %Y'), '%Y-%m')
             ORDER BY COUNT(*) DESC, category ASC
           ) AS rn
      FROM complaints
     WHERE STR_TO_DATE(date_filed, '%b %d, %Y') IS NOT NULL
     GROUP BY ym, category
  ) ranked
  WHERE rn <= 5
  ORDER BY ym DESC, rn ASC
");
if (!$rankResult) json_error('Unable to load monthly complaint ranking.', 500);
$monthlyRank = $rankResult->fetch_all(MYSQLI_ASSOC);

json_success([
  'totals' => $totals,
  'by_category' => $byCategory,
  'by_severity' => $bySeverity,
  'cross' => $cross,
  'monthly_rank' => $monthlyRank,
]);
