<?php
/**
 * BarangayLink - Header-free session revalidation.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 *
 * Include this after db.php. It does not start a session, send headers,
 * write a response, or exit. Call it only after session_start() and before
 * protected work or session_write_close().
 */

require_once __DIR__ . '/../db.php';

/**
 * @return array{ok:bool,error:?string,official_active:bool,resident_active:bool}
 */
function session_revalidate_active(): array {
  static $terminal = null;
  static $success = null;
  static $successKey = null;

  if ($terminal !== null) {
    return $terminal;
  }
  if (session_status() !== PHP_SESSION_ACTIVE) {
    error_log('Session validation called before session_start().');
    $terminal = session_access_result(false, 'server', false, false);
    return $terminal;
  }

  $key = session_identity_cache_key();
  if ($success !== null && $successKey === $key) {
    return $success;
  }

  $result = session_revalidate_query();
  if (!empty($result['ok'])) {
    $success = $result;
    $successKey = $key;
    return $result;
  }

  $terminal = $result;
  return $result;
}

function session_access_failure_html(): string {
  return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Something went wrong</title></head><body><p>Something went wrong. Please try again.</p></body></html>';
}

/**
 * @return array{ok:bool,error:?string,official_active:bool,resident_active:bool}
 */
function session_access_result(bool $ok, ?string $error, bool $officialActive, bool $residentActive): array {
  return [
    'ok' => $ok,
    'error' => $error,
    'official_active' => $officialActive,
    'resident_active' => $residentActive,
  ];
}

function session_identity_cache_key(): string {
  $official = array_key_exists('official_id', $_SESSION) ? (string)$_SESSION['official_id'] : '';
  $resident = array_key_exists('resident_id', $_SESSION) ? (string)$_SESSION['resident_id'] : '';
  return $official . "\n" . $resident;
}

/**
 * @return array{present:bool,id:int}
 */
function session_present_id(string $key): array {
  if (!array_key_exists($key, $_SESSION) || $_SESSION[$key] === null || $_SESSION[$key] === '') {
    return ['present' => false, 'id' => 0];
  }
  $raw = $_SESSION[$key];
  if (is_int($raw)) {
    if ($raw > 0) return ['present' => true, 'id' => $raw];
    if ($raw === 0) return ['present' => false, 'id' => 0];
    return ['present' => true, 'id' => 0];
  }
  if (is_string($raw) && preg_match('/^[1-9][0-9]*$/', $raw) === 1) {
    return ['present' => true, 'id' => (int)$raw];
  }
  if (is_string($raw) && ($raw === '0' || $raw === '0.0')) {
    return ['present' => false, 'id' => 0];
  }
  return ['present' => true, 'id' => 0];
}

function session_clear_official(): void {
  unset(
    $_SESSION['official_id'],
    $_SESSION['official_name'],
    $_SESSION['official_role'],
    $_SESSION['official_position']
  );
}

function session_clear_resident(): void {
  unset(
    $_SESSION['resident_id'],
    $_SESSION['resident_name'],
    $_SESSION['resident_username']
  );
}

/**
 * @return array{ok:bool,error:?string,official_active:bool,resident_active:bool}
 */
function session_revalidate_query(): array {
  $official = session_present_id('official_id');
  $resident = session_present_id('resident_id');
  if (!$official['present'] && !$resident['present']) {
    return session_access_result(true, null, false, false);
  }

  try {
    $db = db_connection();
  } catch (Throwable $error) {
    error_log('Session validation connection failed: ' . $error->getMessage());
    return session_access_result(false, 'server', false, false);
  }

  $officialActive = false;
  $residentActive = false;
  $invalid = false;

  if ($official['present']) {
    $checked = session_confirm_official($db, $official['id']);
    if ($checked === 'server') {
      return session_access_result(false, 'server', false, false);
    }
    if ($checked === 'invalid') {
      $invalid = true;
    } else {
      $officialActive = true;
    }
  }

  if ($resident['present']) {
    $checked = session_confirm_resident($db, $resident['id']);
    if ($checked === 'server') {
      return session_access_result(false, 'server', false, false);
    }
    if ($checked === 'invalid') {
      $invalid = true;
    } else {
      $residentActive = true;
    }
  }

  if ($invalid) {
    return session_access_result(false, 'unauthorized', false, false);
  }

  return session_access_result(true, null, $officialActive, $residentActive);
}

function session_confirm_official(mysqli $db, int $id): string {
  if ($id <= 0) {
    session_clear_official();
    return 'invalid';
  }
  $loaded = session_query_account($db, 'official', $id);
  if ($loaded['error'] === 'server') {
    return 'server';
  }
  if ($loaded['error'] === 'missing') {
    session_clear_official();
    return 'invalid';
  }
  $row = $loaded['row'];
  if ((string)($row['status'] ?? '') !== 'active') {
    session_clear_official();
    return 'invalid';
  }
  $_SESSION['official_id'] = (int)$row['id'];
  $_SESSION['official_name'] = trim(((string)($row['fname'] ?? '')) . ' ' . ((string)($row['lname'] ?? '')));
  $_SESSION['official_role'] = (string)($row['role'] ?? '');
  $_SESSION['official_position'] = trim((string)($row['position'] ?? ''));
  return 'active';
}

function session_confirm_resident(mysqli $db, int $id): string {
  if ($id <= 0) {
    session_clear_resident();
    return 'invalid';
  }
  $loaded = session_query_account($db, 'resident', $id);
  if ($loaded['error'] === 'server') {
    return 'server';
  }
  if ($loaded['error'] === 'missing') {
    session_clear_resident();
    return 'invalid';
  }
  $row = $loaded['row'];
  if ((string)($row['status'] ?? '') !== 'active') {
    session_clear_resident();
    return 'invalid';
  }
  $_SESSION['resident_id'] = (int)$row['id'];
  $_SESSION['resident_name'] = trim(((string)($row['fname'] ?? '')) . ' ' . ((string)($row['lname'] ?? '')));
  $_SESSION['resident_username'] = (string)($row['username'] ?? '');
  return 'active';
}

/**
 * @return array{error:?string,row?:array<string,mixed>}
 */
function session_query_account(mysqli $db, string $kind, int $id): array {
  if ($kind === 'official') {
    $sql = 'SELECT id, fname, lname, role, position, status FROM barangay_officials WHERE id = ? LIMIT 1';
  } elseif ($kind === 'resident') {
    $sql = 'SELECT id, fname, lname, username, status FROM residents WHERE id = ? LIMIT 1';
  } else {
    error_log('Session validation rejected an unknown account kind.');
    return ['error' => 'server'];
  }

  $stmt = null;
  try {
    $stmt = $db->prepare($sql);
    if (!$stmt) {
      error_log('Session validation prepare failed: ' . $db->error);
      return ['error' => 'server'];
    }
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
      error_log('Session validation execute failed: ' . $stmt->error);
      return ['error' => 'server'];
    }
    $result = $stmt->get_result();
    if (!$result) {
      error_log('Session validation result failed: ' . $stmt->error);
      return ['error' => 'server'];
    }
    $row = $result->fetch_assoc();
    if (!$row) {
      return ['error' => 'missing'];
    }
    return ['error' => null, 'row' => $row];
  } catch (Throwable $error) {
    error_log('Session validation failed: ' . $error->getMessage());
    return ['error' => 'server'];
  } finally {
    if ($stmt instanceof mysqli_stmt) {
      $stmt->close();
    }
  }
}
