<?php
/**
 * BarangayLink - Authorized complaint evidence.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_attachments.php';

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
  json_error('Method not allowed.', 405);
}

session_start();
enforce_active_session();

$officialSession = !empty($_SESSION['official_id']);
$residentId = (int)($_SESSION['resident_id'] ?? 0);
if (!$officialSession && $residentId <= 0) {
  json_error('Unauthorized', 401);
}
session_write_close();

$attachmentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($attachmentId === false || $attachmentId === null || $attachmentId <= 0) {
  json_error('Attachment not found.', 404);
}

$db = get_db();
$stmt = null;
try {
  $stmt = $db->prepare(
    'SELECT a.id, a.complaint_id, a.file_path, a.deleted_at, c.resident_id
       FROM complaint_attachments a
       INNER JOIN complaints c ON c.id = a.complaint_id
      WHERE a.id = ?
      LIMIT 1'
  );
  if (!$stmt) {
    error_log('Attachment view prepare failed: ' . $db->error);
    json_error('Something went wrong. Please try again.', 500);
  }
  $stmt->bind_param('i', $attachmentId);
  if (!$stmt->execute()) {
    error_log('Attachment view execute failed: ' . $stmt->error);
    json_error('Something went wrong. Please try again.', 500);
  }
  $result = $stmt->get_result();
  if (!$result) {
    error_log('Attachment view result failed: ' . $stmt->error);
    json_error('Something went wrong. Please try again.', 500);
  }
  $row = $result->fetch_assoc();
} catch (Throwable $error) {
  error_log('Attachment view failed: ' . $error->getMessage());
  json_error('Something went wrong. Please try again.', 500);
} finally {
  if ($stmt instanceof mysqli_stmt) $stmt->close();
}

if (!$row || !empty($row['deleted_at'])) {
  json_error('Attachment not found.', 404);
}
if (!$officialSession && (int)($row['resident_id'] ?? 0) !== $residentId) {
  json_error('You cannot view this attachment.', 403);
}

$resolved = complaint_attachment_resolve((string)$row['file_path'], (int)$row['complaint_id']);
if (!$resolved['ok']) {
  error_log('Attachment view rejected stored key (' . $resolved['error'] . ').');
  json_error('Attachment not found.', 404);
}

$mimeByExt = ['jpg' => 'image/jpeg', 'png' => 'image/png'];
$ext = (string)$resolved['ext'];
$detected = complaint_attachment_detect_mime($resolved['path']);
if (!isset($mimeByExt[$ext]) || $detected !== $mimeByExt[$ext]) {
  error_log('Attachment view rejected file contents.');
  json_error('Attachment not found.', 404);
}

$size = filesize($resolved['path']);
if ($size === false) {
  json_error('Attachment not found.', 404);
}

$filename = 'attachment-' . (int)$attachmentId . '.' . $ext;
header_remove('Content-Type');
header('Content-Type: ' . $mimeByExt[$ext]);
header('Content-Length: ' . (string)$size);
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . $filename . '"');
if ($method === 'HEAD') {
  exit;
}
readfile($resolved['path']);
exit;
