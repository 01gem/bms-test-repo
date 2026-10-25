<?php
/**
 * BarangayLink - Complaint evidence storage.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 *
 * New files live in COMPLAINT_ATTACHMENT_STORAGE_DIR under a private:v1 key.
 * Legacy complaint_attachments/{id}/{uuid}.{jpg|png} rows stay where they are
 * and are readable through the authorized endpoint without that setting.
 */

const COMPLAINT_ATTACHMENT_LEGACY_PATTERN = '/^complaint_attachments\/([1-9][0-9]*)\/([0-9a-f]{32})\.(jpg|png)$/';
const COMPLAINT_ATTACHMENT_PRIVATE_PATTERN = '/^private:v1\/([1-9][0-9]*)\/([0-9a-f]{32})\.(jpg|png)$/';

function complaint_attachment_project_root(): string {
  return dirname(__DIR__, 2);
}

function complaint_attachment_client_url(int $id): string {
  return '../api/complaints/view_attachment.php?id=' . $id;
}

function complaint_attachment_detect_mime(string $path): string|false {
  if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = ($finfo && $path !== '') ? finfo_file($finfo, $path) : false;
    if ($finfo) finfo_close($finfo);
    if (is_string($mime) && $mime !== '') return $mime;
  }
  $info = @getimagesize($path);
  if (is_array($info) && isset($info['mime']) && is_string($info['mime']) && $info['mime'] !== '') {
    return $info['mime'];
  }
  return false;
}

/**
 * @return array{ok:bool,message:string,path:string,error:string}
 */
function complaint_attachment_private_root(bool $forWrite): array {
  // mod_php requests can lose putenv() values when another thread finishes.
  // Prefer the configuration snapshot loaded for this request by db.php.
  $key = 'COMPLAINT_ATTACHMENT_STORAGE_DIR';
  $configured = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
  $configured = is_string($configured) ? trim($configured) : '';
  if ($configured === '') {
    error_log('Complaint attachment storage is not configured.');
    return complaint_attachment_root_result(false, 'Complaint evidence storage is not configured.', '', 'missing');
  }
  if (str_contains($configured, "\0") || !complaint_attachment_is_absolute($configured)) {
    error_log('Complaint attachment storage is not an absolute path.');
    return complaint_attachment_root_result(false, 'Complaint evidence storage is not available.', '', 'unusable');
  }

  $real = realpath($configured);
  if ($real === false || !is_dir($real)) {
    error_log('Complaint attachment storage is missing or not a directory.');
    return complaint_attachment_root_result(false, 'Complaint evidence storage is not available.', '', 'unusable');
  }

  $project = realpath(complaint_attachment_project_root());
  $documentRoot = complaint_attachment_document_root();
  if ($project === false || complaint_attachment_overlaps_served_tree($real, $project, $documentRoot)) {
    error_log('Complaint attachment storage overlaps a served directory.');
    return complaint_attachment_root_result(false, 'Complaint evidence storage is not available.', '', 'served');
  }
  if (!is_readable($real) || ($forWrite && !is_writable($real))) {
    error_log('Complaint attachment storage is not readable or writable.');
    return complaint_attachment_root_result(false, 'Complaint evidence storage is not available.', '', 'unusable');
  }

  return complaint_attachment_root_result(true, '', $real, '');
}

/**
 * @return array{ok:bool,message:string,path:string,error:string}
 */
function complaint_attachment_root_result(bool $ok, string $message, string $path, string $error): array {
  return ['ok' => $ok, 'message' => $message, 'path' => $path, 'error' => $error];
}

function complaint_attachment_document_root(): ?string {
  $root = $_SERVER['DOCUMENT_ROOT'] ?? '';
  if (!is_string($root) || trim($root) === '') return null;
  $real = realpath($root);
  return $real === false ? null : $real;
}

function complaint_attachment_is_absolute(string $path): bool {
  if (preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1) return true;
  if (str_starts_with($path, '\\\\')) return true;
  return str_starts_with($path, '/');
}

function complaint_attachment_overlaps_served_tree(string $storage, string $project, ?string $documentRoot): bool {
  if (complaint_attachment_path_within($storage, $project) || complaint_attachment_path_within($project, $storage)) {
    return true;
  }
  if ($documentRoot === null) return false;
  return complaint_attachment_path_within($storage, $documentRoot)
    || complaint_attachment_path_within($documentRoot, $storage);
}

function complaint_attachment_normalize_path(string $path): string {
  $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
  return rtrim($path, DIRECTORY_SEPARATOR);
}

function complaint_attachment_path_within(string $candidate, string $root): bool {
  $candidate = complaint_attachment_normalize_path($candidate);
  $root = complaint_attachment_normalize_path($root);
  if ($candidate === '' || $root === '') return false;
  $prefix = $root . DIRECTORY_SEPARATOR;
  if (DIRECTORY_SEPARATOR === '\\') {
    return strcasecmp($candidate, $root) === 0
      || strncasecmp($candidate, $prefix, strlen($prefix)) === 0;
  }
  return $candidate === $root || str_starts_with($candidate, $prefix);
}

/**
 * @return array{ok:bool,kind:string,complaint_id:int,uuid:string,ext:string}
 */
function complaint_attachment_parse_key(string $key): array {
  $empty = ['ok' => false, 'kind' => '', 'complaint_id' => 0, 'uuid' => '', 'ext' => ''];
  if ($key === '' || str_contains($key, "\0") || str_contains($key, '\\') || str_contains($key, '..')) {
    return $empty;
  }
  if (preg_match(COMPLAINT_ATTACHMENT_LEGACY_PATTERN, $key, $match) === 1) {
    return [
      'ok' => true,
      'kind' => 'legacy',
      'complaint_id' => (int)$match[1],
      'uuid' => $match[2],
      'ext' => $match[3],
    ];
  }
  if (preg_match(COMPLAINT_ATTACHMENT_PRIVATE_PATTERN, $key, $match) === 1) {
    return [
      'ok' => true,
      'kind' => 'private',
      'complaint_id' => (int)$match[1],
      'uuid' => $match[2],
      'ext' => $match[3],
    ];
  }
  return $empty;
}

/**
 * Resolve a stored key to a contained file path.
 * Legacy reads do not require private storage. Private reads do.
 *
 * @return array{ok:bool,path:string,ext:string,error:string}
 */
function complaint_attachment_resolve(string $key, int $complaintId): array {
  $parsed = complaint_attachment_parse_key($key);
  if (!$parsed['ok'] || $parsed['complaint_id'] !== $complaintId || $complaintId <= 0) {
    return ['ok' => false, 'path' => '', 'ext' => '', 'error' => 'rejected'];
  }

  if ($parsed['kind'] === 'legacy') {
    $root = complaint_attachment_legacy_root();
  } else {
    $storage = complaint_attachment_private_root(false);
    if (!$storage['ok']) {
      return ['ok' => false, 'path' => '', 'ext' => '', 'error' => $storage['error'] !== '' ? $storage['error'] : 'config'];
    }
    $root = $storage['path'];
  }
  if ($root === null || $root === '') {
    return ['ok' => false, 'path' => '', 'ext' => '', 'error' => 'rejected'];
  }

  $dest = $root . DIRECTORY_SEPARATOR . $parsed['complaint_id'] . DIRECTORY_SEPARATOR . $parsed['uuid'] . '.' . $parsed['ext'];
  $real = realpath($dest);
  if ($real === false || !is_file($real) || !complaint_attachment_path_within($real, $root)) {
    return ['ok' => false, 'path' => '', 'ext' => '', 'error' => 'rejected'];
  }
  if (is_link($dest)) {
    error_log('Complaint attachment path is a symlink.');
    return ['ok' => false, 'path' => '', 'ext' => '', 'error' => 'rejected'];
  }
  return ['ok' => true, 'path' => $real, 'ext' => $parsed['ext'], 'error' => ''];
}

function complaint_attachment_legacy_root(): ?string {
  $project = realpath(complaint_attachment_project_root());
  $base = $project === false ? false : realpath($project . DIRECTORY_SEPARATOR . 'complaint_attachments');
  if ($project === false || $base === false || !is_dir($base)) return null;
  if (!complaint_attachment_path_within($base, $project)) {
    error_log('Legacy complaint attachment directory escapes the project root.');
    return null;
  }
  return $base;
}

/**
 * Store one validated image while holding the complaint row lock.
 * $hooks is for in-process tests. The HTTP endpoint does not pass request data here.
 *
 * @param array{type:string,id:int} $actor
 * @param array{commit?:callable,rollback?:callable} $hooks
 * @return array{ok:bool,status:int,message:string,attachment_id?:int,url?:string}
 */
function complaint_attachment_store_upload(
  mysqli $db,
  array $actor,
  int $complaintId,
  string $tmpPath,
  string $mime,
  int $byteSize,
  bool $isUploadedFile,
  array $hooks = []
): array {
  $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
  if ($complaintId <= 0 || !isset($extensions[$mime]) || $byteSize < 0 || $tmpPath === '' || !is_file($tmpPath)) {
    return ['ok' => false, 'status' => 422, 'message' => 'Uploaded file is not a valid image.'];
  }
  $actorType = (string)($actor['type'] ?? '');
  $actorId = (int)($actor['id'] ?? 0);
  if (($actorType !== 'official' && $actorType !== 'resident') || $actorId <= 0) {
    return ['ok' => false, 'status' => 401, 'message' => 'Unauthorized'];
  }

  $storage = complaint_attachment_private_root(true);
  if (!$storage['ok']) {
    return ['ok' => false, 'status' => 500, 'message' => $storage['message']];
  }

  $createdPath = null;
  $started = false;
  $commitAttempted = false;
  $complaintStmt = null;
  $countStmt = null;
  $insert = null;
  try {
    if (!$db->begin_transaction()) {
      error_log('Complaint attachment transaction failed to start: ' . $db->error);
      return ['ok' => false, 'status' => 500, 'message' => 'Unable to save attachment.'];
    }
    $started = true;

    $complaintStmt = $db->prepare('SELECT id, resident_id FROM complaints WHERE id = ? LIMIT 1 FOR UPDATE');
    if (!$complaintStmt) {
      throw new RuntimeException('Complaint lock prepare failed: ' . $db->error);
    }
    $complaintStmt->bind_param('i', $complaintId);
    if (!$complaintStmt->execute()) {
      throw new RuntimeException('Complaint lock execute failed: ' . $complaintStmt->error);
    }
    $complaintResult = $complaintStmt->get_result();
    if (!$complaintResult) {
      throw new RuntimeException('Complaint lock result failed: ' . $complaintStmt->error);
    }
    $complaint = $complaintResult->fetch_assoc();
    $complaintStmt->close();
    $complaintStmt = null;
    if (!$complaint) {
      complaint_attachment_finish_transaction($db, $started, true, null, $hooks);
      $started = false;
      return ['ok' => false, 'status' => 404, 'message' => 'Complaint not found.'];
    }
    if ($actorType === 'resident' && (int)($complaint['resident_id'] ?? 0) !== $actorId) {
      complaint_attachment_finish_transaction($db, $started, true, null, $hooks);
      $started = false;
      return ['ok' => false, 'status' => 403, 'message' => 'You can only attach photos to your own complaints.'];
    }

    $countStmt = $db->prepare('SELECT COUNT(*) AS c FROM complaint_attachments WHERE complaint_id = ? AND deleted_at IS NULL');
    if (!$countStmt) {
      throw new RuntimeException('Attachment count prepare failed: ' . $db->error);
    }
    $countStmt->bind_param('i', $complaintId);
    if (!$countStmt->execute()) {
      throw new RuntimeException('Attachment count execute failed: ' . $countStmt->error);
    }
    $countResult = $countStmt->get_result();
    if (!$countResult) {
      throw new RuntimeException('Attachment count result failed: ' . $countStmt->error);
    }
    $countRow = $countResult->fetch_assoc();
    $countStmt->close();
    $countStmt = null;
    if ((int)($countRow['c'] ?? 0) >= 5) {
      complaint_attachment_finish_transaction($db, $started, true, null, $hooks);
      $started = false;
      return ['ok' => false, 'status' => 422, 'message' => 'This complaint already has the maximum of 5 attachments.'];
    }

    $extension = $extensions[$mime];
    $uuid = bin2hex(random_bytes(16));
    $key = 'private:v1/' . $complaintId . '/' . $uuid . '.' . $extension;
    $directory = $storage['path'] . DIRECTORY_SEPARATOR . $complaintId;
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
      throw new RuntimeException('Could not create the private attachment directory.');
    }
    $createdPath = $directory . DIRECTORY_SEPARATOR . $uuid . '.' . $extension;
    if (file_exists($createdPath)) {
      throw new RuntimeException('Generated attachment name already exists.');
    }
    $moved = $isUploadedFile
      ? move_uploaded_file($tmpPath, $createdPath)
      : rename($tmpPath, $createdPath);
    if (!$moved) {
      $createdPath = null;
      throw new RuntimeException('Could not move the attachment into private storage.');
    }
    $createdReal = realpath($createdPath);
    if ($createdReal === false || !complaint_attachment_path_within($createdReal, $storage['path']) || is_link($createdPath)) {
      throw new RuntimeException('Attachment path escaped private storage.');
    }

    $insert = $db->prepare(
      'INSERT INTO complaint_attachments
         (complaint_id, file_path, mime, byte_size, uploaded_by_type, uploaded_by_id)
       VALUES (?, ?, ?, ?, ?, ?)'
    );
    if (!$insert) {
      throw new RuntimeException('Attachment insert prepare failed: ' . $db->error);
    }
    $insert->bind_param('issisi', $complaintId, $key, $mime, $byteSize, $actorType, $actorId);
    if (!$insert->execute()) {
      throw new RuntimeException('Attachment insert failed: ' . $insert->error);
    }
    $attachmentId = (int)$insert->insert_id;
    $insert->close();
    $insert = null;

    // Set before the call so a false return and a thrown commit share one outcome.
    $commitAttempted = true;
    $committed = complaint_attachment_commit_transaction($db, $hooks);
    $started = false;
    if (!$committed) {
      complaint_attachment_rollback_transaction($db, $hooks, true);
      complaint_attachment_release_created_file($createdPath, false);
      $createdPath = null;
      return ['ok' => false, 'status' => 500, 'message' => 'Failed to save attachment record.'];
    }

    return [
      'ok' => true,
      'status' => 200,
      'message' => '',
      'attachment_id' => $attachmentId,
      'url' => complaint_attachment_client_url($attachmentId),
    ];
  } catch (Throwable $error) {
    error_log('Complaint attachment save failed: ' . $error->getMessage());
    if ($complaintStmt instanceof mysqli_stmt) $complaintStmt->close();
    if ($countStmt instanceof mysqli_stmt) $countStmt->close();
    if ($insert instanceof mysqli_stmt) $insert->close();
    if ($started || $commitAttempted) {
      $rolledBack = complaint_attachment_rollback_transaction($db, $hooks, $commitAttempted);
      $started = false;
      if ($createdPath !== null) {
        complaint_attachment_release_created_file($createdPath, $commitAttempted ? false : $rolledBack);
        $createdPath = null;
      }
    } elseif ($createdPath !== null) {
      complaint_attachment_release_created_file($createdPath, false);
      $createdPath = null;
    }
    return ['ok' => false, 'status' => 500, 'message' => 'Failed to save attachment record.'];
  }
}

/**
 * @param array{commit?:callable,rollback?:callable} $hooks
 */
function complaint_attachment_commit_transaction(mysqli $db, array $hooks): bool {
  if (isset($hooks['commit']) && is_callable($hooks['commit'])) {
    return $hooks['commit']($db) === true;
  }
  return $db->commit() === true;
}

/**
 * @param array{commit?:callable,rollback?:callable} $hooks
 */
function complaint_attachment_rollback_transaction(mysqli $db, array $hooks, bool $commitWasAttempted = false): bool {
  $housekeepingConfirmed = false;
  try {
    if (isset($hooks['rollback']) && is_callable($hooks['rollback'])) {
      $housekeepingConfirmed = $hooks['rollback']($db) === true;
    } elseif ($db->rollback() === true) {
      $housekeepingConfirmed = true;
    } elseif (!$commitWasAttempted) {
      // A statement error can end the transaction before rollback() is called.
      // That is safe to treat as confirmed only when commit was never attempted.
      $state = $db->query('SELECT @@in_transaction AS open_tx');
      $row = $state ? $state->fetch_assoc() : null;
      $housekeepingConfirmed = is_array($row) && (int)($row['open_tx'] ?? 1) === 0;
    }
  } catch (Throwable $error) {
    error_log('Complaint attachment rollback failed: ' . $error->getMessage());
    $housekeepingConfirmed = false;
  }
  // A later ROLLBACK can succeed after the server already committed.
  // That result is not proof the new file may be deleted.
  if ($commitWasAttempted) return false;
  return $housekeepingConfirmed;
}

/**
 * @param array{commit?:callable,rollback?:callable} $hooks
 */
function complaint_attachment_finish_transaction(
  mysqli $db,
  bool $started,
  bool $rollback,
  ?string $createdPath,
  array $hooks,
  bool $commitWasAttempted = false
): void {
  if (!$started && !$commitWasAttempted) return;
  if ($rollback) {
    $confirmed = complaint_attachment_rollback_transaction($db, $hooks, $commitWasAttempted);
    if ($createdPath !== null) {
      complaint_attachment_release_created_file($createdPath, $commitWasAttempted ? false : $confirmed);
    }
    return;
  }
  $commitWasAttempted = true;
  try {
    $committed = complaint_attachment_commit_transaction($db, $hooks);
  } catch (Throwable $error) {
    complaint_attachment_rollback_transaction($db, $hooks, true);
    if ($createdPath !== null) {
      complaint_attachment_release_created_file($createdPath, false);
    }
    throw $error;
  }
  if (!$committed && $createdPath !== null) {
    complaint_attachment_rollback_transaction($db, $hooks, true);
    complaint_attachment_release_created_file($createdPath, false);
  }
}

function complaint_attachment_release_created_file(?string $path, bool $rollbackConfirmed): void {
  if ($path === null || $path === '') return;
  if (!$rollbackConfirmed) {
    error_log('complaint attachment reconciliation: preserved new file after uncertain transaction: ' . $path);
    return;
  }
  if (is_file($path) && !unlink($path)) {
    error_log('complaint attachment reconciliation: could not remove uncommitted file: ' . $path);
  }
}
