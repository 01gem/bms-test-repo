<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

if (!defined('MPI_INDICATOR_LABELS')) {
  define('MPI_INDICATOR_LABELS', [
    'school_attendance' => 'Out-of-school children',
    'educational_attainment' => 'Low education',
    'hunger' => 'Hunger',
    'food_insecurity' => 'Food insecurity',
    'health_access' => 'Poor health access',
    'assets' => 'Few assets',
    'toilet' => 'Poor sanitation',
    'water' => 'Unsafe water',
    'tenure' => 'Insecure tenure',
    'materials' => 'Weak housing',
    'electricity' => 'No electricity',
    'underemployment' => 'Underemployment',
    'working_children' => 'Child labor',
  ]);
}

if (!function_exists('report_string_list')) {
  function report_string_list($value, int $maxItems, int $maxChars): array {
    $items = [];
    foreach (is_array($value) ? $value : [] as $item) {
      if (!is_string($item) || trim($item) === '') continue;
      $items[] = mb_substr(trim($item), 0, $maxChars);
      if (count($items) === $maxItems) break;
    }
    return $items;
  }
}

/**
 * Reject the request when the calling official has burned too much AI budget
 * in the last hour. Logs every accepted call. Non-fatal if the log insert
 * fails — the throttle is a guard, not a hard requirement.
 *
 * @param string $endpoint   Short label, e.g. 'vuln_batch'
 * @param int    $cost       Units this call will consume (1 per household)
 * @param int    $maxPerHour Hourly budget per official
 */
function ai_throttle_or_die(mysqli $db, int $officialId, string $endpoint, int $cost, int $maxPerHour = 200): void {
  $stmt = $db->prepare(
    'SELECT COALESCE(SUM(household_count), 0) AS used
       FROM ai_request_log
      WHERE official_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)'
  );
  if (!$stmt) json_error('AI throttle check failed.', 500);
  $stmt->bind_param('i', $officialId);
  $used = (int)(db_query_all($stmt)[0]['used'] ?? 0);
  $stmt->close();

  if ($used + $cost > $maxPerHour) {
    json_error(
      'Hourly AI budget reached for your account (' . $used . '/' . $maxPerHour . '). Please wait before generating more reports.',
      429
    );
  }

  $log = $db->prepare('INSERT INTO ai_request_log (official_id, endpoint, household_count) VALUES (?, ?, ?)');
  if (!$log) return;
  $log->bind_param('isi', $officialId, $endpoint, $cost);
  $log->execute();
  $log->close();
}
