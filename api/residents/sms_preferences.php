<?php
require_once __DIR__ . '/../common.php';
session_start();
if (empty($_SESSION['resident_id'])) {
  json_error('Resident access required.', 403);
}

$residentId = (int)$_SESSION['resident_id'];
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $stmt = $db->prepare('SELECT complaints_enabled, requests_enabled, announcements_enabled FROM resident_sms_preferences WHERE resident_id = ? LIMIT 1');
  $stmt->bind_param('i', $residentId);
  $rows = db_query_all($stmt);
  $stmt->close();
  $prefs = $rows[0] ?? ['complaints_enabled' => 1, 'requests_enabled' => 1, 'announcements_enabled' => 1];
  json_success([
    'complaints_enabled' => (int)$prefs['complaints_enabled'],
    'requests_enabled' => (int)$prefs['requests_enabled'],
    'announcements_enabled' => (int)$prefs['announcements_enabled'],
  ]);
}

require_post();
$input = read_json_input();
$complaints = !empty($input['complaints_enabled']) ? 1 : 0;
$requests   = !empty($input['requests_enabled'])   ? 1 : 0;
$ann        = !empty($input['announcements_enabled'])? 1 : 0;

$stmt = $db->prepare(
  'INSERT INTO resident_sms_preferences (resident_id, complaints_enabled, requests_enabled, announcements_enabled)
   VALUES (?, ?, ?, ?)
   ON DUPLICATE KEY UPDATE
     complaints_enabled = VALUES(complaints_enabled),
     requests_enabled   = VALUES(requests_enabled),
     announcements_enabled = VALUES(announcements_enabled)'
);
if (!$stmt) json_error('Failed to save SMS preferences.', 500);
$stmt->bind_param('iiii', $residentId, $complaints, $requests, $ann);
if (!$stmt->execute()) json_db_error('Failed to save SMS preferences', $stmt->error);
$stmt->close();

json_success(['message' => 'SMS preferences saved.']);
