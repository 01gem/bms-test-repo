<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_recovery.php';
require_once __DIR__ . '/_recovery_transport.php';

require_post();
$input = read_json_input();
$accountType = trim((string)($input['account_type'] ?? ''));
$username = trim((string)($input['username'] ?? ''));

if (!in_array($accountType, ['resident', 'official'], true) || $username === '') {
  json_error('Account type and username are required.');
}

$db = get_db();
$table = recovery_account_table($accountType);
$genericMessage = 'If that username exists and has a contact number, a reset code has been sent.';
$started = false;
$code = '';
$contact = '';

try {
  if (!$db->begin_transaction()) {
    throw new RuntimeException('Could not start password recovery.');
  }
  $started = true;
  $account = recovery_lock_account($db, $table, $username);
  if ($account === null || trim((string)($account['contact'] ?? '')) === '') {
    if (!$db->rollback()) {
      throw new RuntimeException('Could not finish password recovery.');
    }
    $started = false;
    json_success(['message' => $genericMessage]);
  }

  $accountId = (int)$account['id'];
  $contact = trim((string)$account['contact']);
  $contact = normalize_ph_phone($contact) ?? $contact;
  if (recovery_recent_code($db, $accountType, $accountId) !== null) {
    if (!$db->rollback()) {
      throw new RuntimeException('Could not finish password recovery.');
    }
    $started = false;
    json_success(['message' => 'A reset code was recently sent. Please wait at least 60 seconds before requesting another.']);
  }

  recovery_consume_unused($db, $accountType, $accountId);
  $code = (string)random_int(100000, 999999);
  recovery_insert_code($db, $accountType, $accountId, $code);
  if (!$db->commit()) {
    throw new RuntimeException('Could not commit password recovery.');
  }
  $started = false;
} catch (Throwable $error) {
  if ($started) recovery_rollback($db);
  error_log('Password recovery request failed: ' . $error->getMessage());
  json_error('Unable to process password recovery.', 500);
}

$sent = recovery_send_reset_sms($contact, $code);
if (empty($sent['ok'])) {
  json_error((string)($sent['message'] ?? 'Password reset SMS could not be sent.'), (int)($sent['status'] ?? 500));
}

json_success(['message' => 'A password reset code has been sent to your registered contact number.']);
