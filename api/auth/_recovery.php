<?php
/**
 * BarangayLink - Locked password-recovery operations.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 *
 * Callers start the transaction, lock the account, then commit or roll back.
 * These helpers throw instead of emitting a response.
 */

function recovery_account_table(string $accountType): string {
  if ($accountType === 'resident') return 'residents';
  if ($accountType === 'official') return 'barangay_officials';
  error_log('Password recovery rejected an account type.');
  throw new RuntimeException('Could not process password recovery.');
}

function recovery_rollback(mysqli $db): void {
  try {
    if (!$db->rollback()) {
      error_log('Password recovery rollback failed: ' . $db->error);
    }
  } catch (Throwable $error) {
    error_log('Password recovery rollback failed: ' . $error->getMessage());
  }
}

function recovery_lock_account(mysqli $db, string $table, string $username): ?array {
  if ($table === 'residents') {
    $sql = 'SELECT id, contact FROM residents WHERE username = ? LIMIT 1 FOR UPDATE';
  } elseif ($table === 'barangay_officials') {
    $sql = 'SELECT id, contact FROM barangay_officials WHERE username = ? LIMIT 1 FOR UPDATE';
  } else {
    error_log('Password recovery rejected an account table.');
    throw new RuntimeException('Could not process password recovery.');
  }
  return recovery_execute($db, $sql, 's', [$username], true);
}

function recovery_recent_code(mysqli $db, string $accountType, int $accountId): ?array {
  return recovery_execute(
    $db,
    'SELECT id FROM otp_codes
      WHERE account_type = ? AND account_id = ? AND used = 0 AND expires_at > NOW()
        AND created_at >= DATE_SUB(NOW(), INTERVAL 60 SECOND)
      ORDER BY created_at DESC, id DESC
      LIMIT 1
      FOR UPDATE',
    'si',
    [$accountType, $accountId],
    true
  );
}

function recovery_latest_code(mysqli $db, string $accountType, int $accountId): ?array {
  return recovery_execute(
    $db,
    'SELECT id, code, attempts FROM otp_codes
      WHERE account_type = ? AND account_id = ? AND used = 0 AND expires_at > NOW()
      ORDER BY created_at DESC, id DESC
      LIMIT 1
      FOR UPDATE',
    'si',
    [$accountType, $accountId],
    true
  );
}

function recovery_consume_unused(mysqli $db, string $accountType, int $accountId): void {
  recovery_execute(
    $db,
    'UPDATE otp_codes SET used = 1 WHERE account_type = ? AND account_id = ? AND used = 0',
    'si',
    [$accountType, $accountId],
    false
  );
}

function recovery_insert_code(mysqli $db, string $accountType, int $accountId, string $code): void {
  recovery_execute(
    $db,
    'INSERT INTO otp_codes (account_type, account_id, code, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL 10 MINUTE)',
    'sis',
    [$accountType, $accountId, $code],
    false
  );
}

function recovery_bump_attempt(mysqli $db, int $otpId): void {
  recovery_execute(
    $db,
    'UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?',
    'i',
    [$otpId],
    false
  );
}

function recovery_set_password(mysqli $db, string $table, int $accountId, string $hash): void {
  if ($table === 'residents') {
    $sql = 'UPDATE residents SET password = ?, failed_logins = 0, locked_until = NULL WHERE id = ?';
  } elseif ($table === 'barangay_officials') {
    $sql = 'UPDATE barangay_officials SET password = ?, failed_logins = 0, locked_until = NULL WHERE id = ?';
  } else {
    error_log('Password recovery rejected an account table.');
    throw new RuntimeException('Could not process password recovery.');
  }
  recovery_execute($db, $sql, 'si', [$hash, $accountId], false);
}

function recovery_execute(mysqli $db, string $sql, string $types, array $values, bool $fetch): ?array {
  $stmt = $db->prepare($sql);
  if (!$stmt) {
    error_log('Password recovery prepare failed: ' . $db->error);
    throw new RuntimeException('Could not process password recovery.');
  }
  if ($types !== '') {
    $bound = [];
    foreach ($values as $index => $value) {
      $bound[$index] = $value;
    }
    $args = [$types];
    foreach ($bound as $index => $_) {
      $args[] = &$bound[$index];
    }
    if (!call_user_func_array([$stmt, 'bind_param'], $args)) {
      $detail = $stmt->error;
      $stmt->close();
      error_log('Password recovery bind failed: ' . $detail);
      throw new RuntimeException('Could not process password recovery.');
    }
  }
  if (!$stmt->execute()) {
    $detail = $stmt->error;
    $stmt->close();
    error_log('Password recovery execute failed: ' . $detail);
    throw new RuntimeException('Could not process password recovery.');
  }
  if (!$fetch) {
    $stmt->close();
    return null;
  }
  $result = $stmt->get_result();
  if (!$result) {
    $detail = $stmt->error;
    $stmt->close();
    error_log('Password recovery result failed: ' . $detail);
    throw new RuntimeException('Could not process password recovery.');
  }
  $row = $result->fetch_assoc();
  $stmt->close();
  return $row ?: null;
}
