<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

require_once __DIR__ . '/../common.php';
session_start();

if (empty($_SESSION['official_id'])) {
  http_response_code(401);
  echo json_encode(['success' => false, 'message' => 'Unauthorized']);
  exit;
}

require_post();

$input = read_json_input();
$announcementId = (int)($input['id'] ?? $input['announcement_id'] ?? 0);
if ($announcementId <= 0) {
  json_error('Valid announcement id is required.');
}

$db = get_db();
$announcementStmt = $db->prepare('SELECT title, content FROM announcements WHERE id = ? LIMIT 1');
if (!$announcementStmt) json_error('Failed to prepare announcement lookup.', 500);
$announcementStmt->bind_param('i', $announcementId);
$announcementRows = db_query_all($announcementStmt);
$announcementStmt->close();
if (count($announcementRows) === 0) {
  json_error('Announcement not found.', 404);
}

$announcement = $announcementRows[0];
$title = trim((string)$announcement['title']);
$content = trim((string)$announcement['content']);
$excerpt = preg_replace('/\s+/', ' ', $content);
$excerpt = trim((string)$excerpt);
if (strlen($excerpt) > 120) {
  $excerpt = rtrim(substr($excerpt, 0, 120)) . '...';
}
$smsMessage = "Barangay announcement: {$title}. {$excerpt}";

$residentsStmt = $db->prepare('SELECT id, contact FROM residents');
if (!$residentsStmt) json_error('Failed to prepare resident lookup.', 500);
$residents = db_query_all($residentsStmt);
$residentsStmt->close();

$sent = 0;
$skipped = 0;
$failed = 0;

foreach ($residents as $resident) {
  $residentId = (int)$resident['id'];
  $result = notify_resident($residentId, 'announcement_broadcast', $smsMessage, 'announcements_enabled');

  if ($result['status'] === 'skipped') { $skipped++; continue; }
  if ($result['status'] === 'failed')  { $failed++;  continue; }

  $sent++;
  $notificationTitle = 'New Announcement';
  $notificationBody = $title . ': ' . $excerpt;
  $isRead = 0;
  $createdAt = date('Y-m-d H:i:s');
  $notificationStmt = $db->prepare('INSERT INTO notifications (resident_id, title, body, is_read, created_at) VALUES (?, ?, ?, ?, ?)');
  if ($notificationStmt) {
    $notificationStmt->bind_param('issis', $residentId, $notificationTitle, $notificationBody, $isRead, $createdAt);
    $notificationStmt->execute();
    $notificationStmt->close();
  } else {
    error_log('Announcement notification prepare failed: ' . $db->error);
  }
}

log_audit(
  $db,
  (int)$_SESSION['official_id'],
  (string)($_SESSION['official_name'] ?? ''),
  'Sent announcement SMS broadcast',
  'announcement',
  $title,
  "Sent: {$sent}; Skipped: {$skipped}; Failed: {$failed}"
);

json_success([
  'message' => 'Announcement SMS broadcast completed.',
  'sent' => $sent,
  'skipped' => $skipped,
  'failed' => $failed
]);
?>
