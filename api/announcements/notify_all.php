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

// Capture session values and release the lock *before* the long loop so the
// official can log out, open another tab, or close the browser without
// blocking on this request.
$officialId   = (int)$_SESSION['official_id'];
$officialName = (string)($_SESSION['official_name'] ?? '');
session_write_close();

$planned = count($residents);
$canFinishEarly = function_exists('fastcgi_finish_request')
               || function_exists('litespeed_finish_request');

if ($canFinishEarly) {
    // FastCGI / LiteSpeed: reply with a "started" acknowledgment in
    // milliseconds, then keep the worker alive to drain the loop.
    ignore_user_abort(true);
    set_time_limit(0);
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'started' => true,
        'total'   => $planned,
        'message' => "Broadcast started for {$planned} resident"
                   . ($planned === 1 ? '' : 's') . '. Delivery continues in the background.',
    ]);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    }
} else {
    // mod_php: still prevent the script from being killed at 30 s.
    ignore_user_abort(true);
    set_time_limit(0);
}

$sent = 0;
$skipped = 0;
$failed = 0;

foreach ($residents as $resident) {
    $residentId = (int)$resident['id'];

    // The in-app notification is unconditional. SMS preference only gates
    // the SMS channel; a resident who opted out of announcement SMS still
    // receives the in-app notification and can read it on next login.
    $notificationTitle = 'New Announcement';
    $notificationBody  = $title . ': ' . $excerpt;
    $isRead    = 0;
    $createdAt = date('Y-m-d H:i:s');
    $notificationStmt = $db->prepare('INSERT INTO notifications (resident_id, title, body, is_read, created_at) VALUES (?, ?, ?, ?, ?)');
    if ($notificationStmt) {
        $notificationStmt->bind_param('issis', $residentId, $notificationTitle, $notificationBody, $isRead, $createdAt);
        $notificationStmt->execute();
        $notificationStmt->close();
    } else {
        error_log('Announcement notification prepare failed: ' . $db->error);
    }

    $result = notify_resident($residentId, 'announcement_broadcast', $smsMessage, 'announcements_enabled');
    if ($result['status'] === 'skipped') { $skipped++; continue; }
    if ($result['status'] === 'failed')  { $failed++;  continue; }
    $sent++;
}

log_audit(
    $db,
    $officialId,
    $officialName,
    'Sent announcement SMS broadcast',
    'announcement',
    $title,
    "Sent: {$sent}; Skipped: {$skipped}; Failed: {$failed}"
);

if (!$canFinishEarly) {
    // mod_php still has the connection open; report the final tally.
    json_success([
        'message' => 'Announcement SMS broadcast completed.',
        'sent'    => $sent,
        'skipped' => $skipped,
        'failed'  => $failed,
    ]);
}
exit; // FPM path: the "started" response was already flushed.
