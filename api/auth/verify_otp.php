<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_recovery.php';

require_post();
$input = read_json_input();
$accountType = trim((string)($input['account_type'] ?? ''));
$username = trim((string)($input['username'] ?? ''));
$code = trim((string)($input['code'] ?? ''));

if (!in_array($accountType, ['resident', 'official'], true) || $username === '' || !preg_match('/^\d{6}$/', $code)) {
  json_error('Invalid or expired code.');
}

$db = get_db();
$table = recovery_account_table($accountType);
$started = false;

try {
  if (!$db->begin_transaction()) {
    throw new RuntimeException('Could not start code verification.');
  }
  $started = true;
  $account = recovery_lock_account($db, $table, $username);
  if ($account === null) {
    if (!$db->rollback()) throw new RuntimeException('Could not finish code verification.');
    $started = false;
    json_error('Invalid or expired code.');
  }

  $otp = recovery_latest_code($db, $accountType, (int)$account['id']);
  if ($otp === null || (int)$otp['attempts'] >= 5) {
    if (!$db->rollback()) throw new RuntimeException('Could not finish code verification.');
    $started = false;
    json_error('Invalid or expired code.');
  }

  if (!hash_equals((string)$otp['code'], $code)) {
    recovery_bump_attempt($db, (int)$otp['id']);
    if (!$db->commit()) throw new RuntimeException('Could not record the code attempt.');
    $started = false;
    json_error('Invalid or expired code.');
  }

  if (!$db->commit()) throw new RuntimeException('Could not finish code verification.');
  $started = false;
} catch (Throwable $error) {
  if ($started) recovery_rollback($db);
  error_log('Password recovery verify failed: ' . $error->getMessage());
  json_error('Unable to verify code.', 500);
}

json_success(['message' => 'Code verified.']);
