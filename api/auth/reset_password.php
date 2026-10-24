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
$newPassword = (string)($input['new_password'] ?? '');

if (!in_array($accountType, ['resident', 'official'], true) || $username === '' || !preg_match('/^\d{6}$/', $code) || $newPassword === '') {
  json_error('Account type, username, 6-digit code, and new password are required.');
}

$db = get_db();
$table = recovery_account_table($accountType);
$started = false;

try {
  if (!$db->begin_transaction()) {
    throw new RuntimeException('Could not start password reset.');
  }
  $started = true;
  $account = recovery_lock_account($db, $table, $username);
  if ($account === null) {
    if (!$db->rollback()) throw new RuntimeException('Could not finish password reset.');
    $started = false;
    json_error('Invalid or expired reset code.');
  }

  $accountId = (int)$account['id'];
  $otp = recovery_latest_code($db, $accountType, $accountId);
  if ($otp === null || (int)$otp['attempts'] >= 5) {
    if (!$db->rollback()) throw new RuntimeException('Could not finish password reset.');
    $started = false;
    json_error('Invalid or expired reset code.');
  }

  if (!hash_equals((string)$otp['code'], $code)) {
    recovery_bump_attempt($db, (int)$otp['id']);
    if (!$db->commit()) throw new RuntimeException('Could not record the reset attempt.');
    $started = false;
    json_error('Invalid or expired reset code.');
  }

  $hash = password_hash($newPassword, PASSWORD_DEFAULT);
  if ($hash === false) throw new RuntimeException('Could not hash the new password.');
  recovery_set_password($db, $table, $accountId, $hash);
  recovery_consume_unused($db, $accountType, $accountId);
  if (!$db->commit()) throw new RuntimeException('Could not commit password reset.');
  $started = false;
} catch (Throwable $error) {
  if ($started) recovery_rollback($db);
  error_log('Password reset failed: ' . $error->getMessage());
  json_error('Unable to process password reset.', 500);
}

json_success(['message' => 'Your password has been reset. You can now log in.']);
