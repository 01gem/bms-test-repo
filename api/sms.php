<?php
/**
 * BarangayLink - Shared SMS helper.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Requires api/common.php to be loaded first (uses db_connection(),
 * normalize_ph_phone()). Never throws. Resident lookups do not use
 * db_query_all(), so a failed lookup cannot exit an already-saved caller.
 * All callers log through sms_log.
 */

/**
 * Send an SMS via httpSMS. Never throws.
 * A test process may define send_sms() before this file loads.
 * HTTP requests cannot do that.
 * @return array{ok:bool,error:string,message_id:?string}
 */
if (!function_exists('send_sms')) {
function send_sms(string $to, string $content): array {
  $apiKey = getenv('HTTPSMS_API_KEY') ?: '';
  $fromNumber = getenv('HTTPSMS_FROM_NUMBER') ?: '';
  $simSlot = getenv('HTTPSMS_SIM_SLOT') ?: 'SIM1';

  if ($apiKey === '' || $fromNumber === '') {
    return ['ok' => false, 'error' => 'sms_not_configured', 'message_id' => null];
  }
  if ($to === '' || $content === '') {
    return ['ok' => false, 'error' => 'missing_recipient_or_body', 'message_id' => null];
  }
  if (!function_exists('curl_init')) {
    return ['ok' => false, 'error' => 'curl_disabled', 'message_id' => null];
  }

  $payload = json_encode([
    'from' => $fromNumber,
    'to' => $to,
    'content' => $content,
    'sim' => $simSlot ?: 'SIM1',
  ]);
  if ($payload === false) {
    return ['ok' => false, 'error' => 'payload_encode_failed', 'message_id' => null];
  }

  $ch = curl_init('https://api.httpsms.com/v1/messages/send');
  if ($ch === false) {
    return ['ok' => false, 'error' => 'curl_init_failed', 'message_id' => null];
  }
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Accept: application/json',
      'x-api-key: ' . $apiKey,
      'Content-Length: ' . strlen($payload),
    ],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 25,
  ]);
  $response = curl_exec($ch);
  $curlError = $response === false ? curl_error($ch) : '';
  $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($response === false) {
    $masked = preg_replace('/^(\+63\d{3})\d{4}(\d{3})$/', '$1****$2', $to) ?? '***';
    error_log("SMS cURL failure to {$masked}: {$curlError}");
    return ['ok' => false, 'error' => $curlError !== '' ? $curlError : 'unreachable', 'message_id' => null];
  }
  if (!in_array($httpStatus, [200, 201, 202], true)) {
    $decoded = json_decode($response, true);
    $msg = is_array($decoded) && !empty($decoded['message']) ? (string)$decoded['message'] : "HTTP {$httpStatus}";
    return ['ok' => false, 'error' => mb_substr($msg, 0, 200), 'message_id' => null];
  }

  $decoded = json_decode($response, true);
  $messageId = is_array($decoded) && isset($decoded['id']) ? (string)$decoded['id'] : null;
  return ['ok' => true, 'error' => '', 'message_id' => $messageId];
}
}

/**
 * Fire-and-forget SMS to a resident. Runs the decision tree:
 * config -> contact -> normalize -> preference -> per-resident/event cap (5/24h) -> send -> log.
 *
 * @param int    $residentId
 * @param string $endpoint    Short label for sms_log, e.g. 'complaint_filed'.
 * @param string $body        Message text (<160 chars keeps it one segment).
 * @param string $prefKey     One of complaints_enabled, requests_enabled, announcements_enabled.
 * @param string|null $toOverride  Skip the DB contact lookup (used by notify_ready).
 * @return array{status:string,reason:string}
 */
/**
 * Checked resident-notification lookup. Null means the query failed.
 * An empty array means the query succeeded and returned no row.
 *
 * @param array<int,int|string> $values
 * @return array<string,mixed>|null
 */
function sms_resident_query(mysqli $db, string $sql, string $types, array $values, string $logLabel): ?array {
  $stmt = null;
  try {
    $stmt = $db->prepare($sql);
    if (!$stmt) {
      error_log($logLabel . ' prepare failed: ' . $db->error);
      return null;
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
        error_log($logLabel . ' bind failed: ' . $stmt->error);
        return null;
      }
    }
    if (!$stmt->execute()) {
      error_log($logLabel . ' execute failed: ' . $stmt->error);
      return null;
    }
    $result = $stmt->get_result();
    if (!$result) {
      error_log($logLabel . ' result failed: ' . $stmt->error);
      return null;
    }
    $row = $result->fetch_assoc();
    return $row ?: [];
  } catch (Throwable $error) {
    error_log($logLabel . ' failed: ' . $error->getMessage());
    return null;
  } finally {
    if ($stmt instanceof mysqli_stmt) $stmt->close();
  }
}

function notify_resident(
  int $residentId,
  string $endpoint,
  string $body,
  string $prefKey = 'complaints_enabled',
  ?string $toOverride = null
): array {
  $apiKey = getenv('HTTPSMS_API_KEY') ?: '';
  $fromNumber = getenv('HTTPSMS_FROM_NUMBER') ?: '';
  try {
    $db = db_connection();
  } catch (Throwable $error) {
    error_log('notify_resident connection failed: ' . $error->getMessage());
    if ($apiKey === '' || $fromNumber === '') {
      return ['status' => 'skipped', 'reason' => 'SMS is not configured on this server.'];
    }
    return ['status' => 'skipped', 'reason' => 'SMS notification could not be prepared.'];
  }

  try {
    if ($apiKey === '' || $fromNumber === '') {
      sms_log_write($db, $residentId, $endpoint, '', 'skipped', 'sms_not_configured');
      return ['status' => 'skipped', 'reason' => 'SMS is not configured on this server.'];
    }

    $toRaw = trim((string)($toOverride ?? ''));
    if ($toRaw === '') {
      $contact = sms_resident_query(
        $db,
        'SELECT contact FROM residents WHERE id = ? LIMIT 1',
        'i',
        [$residentId],
        'notify_resident contact'
      );
      if ($contact === null) {
        sms_log_write($db, $residentId, $endpoint, '', 'skipped', 'contact_lookup_failed');
        return ['status' => 'skipped', 'reason' => 'Contact lookup failed.'];
      }
      $toRaw = trim((string)($contact['contact'] ?? ''));
    }
    if ($toRaw === '') {
      sms_log_write($db, $residentId, $endpoint, '', 'skipped', 'no_contact');
      return ['status' => 'skipped', 'reason' => 'Resident has no contact number.'];
    }

    $to = normalize_ph_phone($toRaw);
    if ($to === null) {
      sms_log_write($db, $residentId, $endpoint, $toRaw, 'skipped', 'invalid_contact');
      return ['status' => 'skipped', 'reason' => 'Resident contact number is invalid.'];
    }

    $col = in_array($prefKey, ['complaints_enabled', 'requests_enabled', 'announcements_enabled'], true)
      ? $prefKey : 'complaints_enabled';
    $pref = sms_resident_query(
      $db,
      "SELECT `{$col}` AS enabled FROM resident_sms_preferences WHERE resident_id = ? LIMIT 1",
      'i',
      [$residentId],
      'notify_resident preference'
    );
    if ($pref === null) {
      sms_log_write($db, $residentId, $endpoint, $to, 'skipped', 'preference_lookup_failed');
      return ['status' => 'skipped', 'reason' => 'SMS preference lookup failed.'];
    }
    if ($pref !== [] && (int)($pref['enabled'] ?? 1) !== 1) {
      sms_log_write($db, $residentId, $endpoint, $to, 'skipped', 'pref_off');
      return ['status' => 'skipped', 'reason' => 'Resident has disabled this SMS category.'];
    }

    $cap = sms_resident_query(
      $db,
      "SELECT COUNT(*) AS c FROM sms_log WHERE resident_id = ? AND endpoint = ? AND status = 'sent' AND created_at > NOW() - INTERVAL 1 DAY",
      'is',
      [$residentId, $endpoint],
      'notify_resident cap'
    );
    if ($cap === null || !array_key_exists('c', $cap)) {
      sms_log_write($db, $residentId, $endpoint, $to, 'skipped', 'cap_lookup_failed');
      return ['status' => 'skipped', 'reason' => 'SMS cap lookup failed.'];
    }
    if ((int)$cap['c'] >= 5) {
      sms_log_write($db, $residentId, $endpoint, $to, 'skipped', 'daily_cap');
      return ['status' => 'skipped', 'reason' => 'Daily SMS limit reached for this resident and event type.'];
    }

    $result = send_sms($to, $body);
    if (!empty($result['ok'])) {
      sms_log_write($db, $residentId, $endpoint, $to, 'sent', null, $result['message_id'] ?? null);
      return ['status' => 'sent', 'reason' => 'SMS sent.'];
    }
    sms_log_write($db, $residentId, $endpoint, $to, 'failed', $result['error'] ?? 'unknown', null);
    return ['status' => 'failed', 'reason' => 'SMS send failed: ' . ($result['error'] ?? 'unknown')];
  } catch (Throwable $error) {
    error_log('notify_resident failed: ' . $error->getMessage());
    return ['status' => 'failed', 'reason' => 'SMS notification could not be prepared.'];
  }
}

/** Append-only sms_log writer. Never throws. */
function sms_log_write(
  mysqli $db,
  ?int $residentId,
  string $endpoint,
  string $to,
  string $status,
  ?string $reason,
  ?string $messageId = null,
  ?int $officialId = null
): void {
  $stmt = null;
  try {
    $stmt = $db->prepare(
      'INSERT INTO sms_log (resident_id, official_id, endpoint, to_number, status, reason, http_message_id)
       VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
      error_log('sms_log prepare failed: ' . $db->error);
      return;
    }
    $stmt->bind_param('iisssss', $residentId, $officialId, $endpoint, $to, $status, $reason, $messageId);
    if (!$stmt->execute()) error_log('sms_log execute failed: ' . $stmt->error);
  } catch (Throwable $error) {
    error_log('sms_log write failed: ' . $error->getMessage());
  } finally {
    if ($stmt instanceof mysqli_stmt) $stmt->close();
  }
}

/**
 * Operational SMS to one official. No preference table in this build.
 * Same status/reason shape as notify_resident. Never throws.
 * Does not use db_query_all(): a lookup failure must not exit an already-saved caller.
 *
 * @return array{status:string,reason:string}
 */
function notify_official(int $officialId, string $endpoint, string $body): array {
  try {
    $db = get_db();
    $apiKey = getenv('HTTPSMS_API_KEY') ?: '';
    $fromNumber = getenv('HTTPSMS_FROM_NUMBER') ?: '';
    if ($apiKey === '' || $fromNumber === '') {
      sms_log_write($db, null, $endpoint, '', 'skipped', 'sms_not_configured', null, $officialId);
      return ['status' => 'skipped', 'reason' => 'SMS is not configured on this server.'];
    }

    $stmt = $db->prepare('SELECT status, contact FROM barangay_officials WHERE id = ? LIMIT 1');
    if (!$stmt) {
      error_log('notify_official lookup prepare failed: ' . $db->error);
      sms_log_write($db, null, $endpoint, '', 'skipped', 'official_lookup_failed', null, $officialId);
      return ['status' => 'skipped', 'reason' => 'Official lookup failed.'];
    }
    $stmt->bind_param('i', $officialId);
    if (!$stmt->execute()) {
      error_log('notify_official lookup execute failed: ' . $stmt->error);
      $stmt->close();
      sms_log_write($db, null, $endpoint, '', 'skipped', 'official_lookup_failed', null, $officialId);
      return ['status' => 'skipped', 'reason' => 'Official lookup failed.'];
    }
    $result = $stmt->get_result();
    $official = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$result) {
      sms_log_write($db, null, $endpoint, '', 'skipped', 'official_lookup_failed', null, $officialId);
      return ['status' => 'skipped', 'reason' => 'Official lookup failed.'];
    }
    if (!$official || (string)($official['status'] ?? '') !== 'active') {
      sms_log_write($db, null, $endpoint, '', 'skipped', 'official_unavailable', null, $officialId);
      return ['status' => 'skipped', 'reason' => 'Official is unavailable.'];
    }

    $toRaw = trim((string)($official['contact'] ?? ''));
    if ($toRaw === '') {
      sms_log_write($db, null, $endpoint, '', 'skipped', 'no_contact', null, $officialId);
      return ['status' => 'skipped', 'reason' => 'Official has no contact number.'];
    }
    $to = normalize_ph_phone($toRaw);
    if ($to === null) {
      sms_log_write($db, null, $endpoint, $toRaw, 'skipped', 'invalid_contact', null, $officialId);
      return ['status' => 'skipped', 'reason' => 'Official contact number is invalid.'];
    }

    $cap = $db->prepare(
      "SELECT COUNT(*) AS c FROM sms_log
        WHERE official_id = ? AND endpoint = ? AND status = 'sent'
          AND resident_id IS NULL
          AND created_at > NOW() - INTERVAL 1 DAY"
    );
    if (!$cap) {
      error_log('notify_official cap prepare failed: ' . $db->error);
      sms_log_write($db, null, $endpoint, $to, 'skipped', 'cap_lookup_failed', null, $officialId);
      return ['status' => 'skipped', 'reason' => 'SMS cap lookup failed.'];
    }
    $cap->bind_param('is', $officialId, $endpoint);
    if (!$cap->execute()) {
      error_log('notify_official cap execute failed: ' . $cap->error);
      $cap->close();
      sms_log_write($db, null, $endpoint, $to, 'skipped', 'cap_lookup_failed', null, $officialId);
      return ['status' => 'skipped', 'reason' => 'SMS cap lookup failed.'];
    }
    $capResult = $cap->get_result();
    $capRow = $capResult ? $capResult->fetch_assoc() : null;
    $cap->close();
    if (!$capResult || !$capRow) {
      sms_log_write($db, null, $endpoint, $to, 'skipped', 'cap_lookup_failed', null, $officialId);
      return ['status' => 'skipped', 'reason' => 'SMS cap lookup failed.'];
    }
    if ((int)$capRow['c'] >= 5) {
      sms_log_write($db, null, $endpoint, $to, 'skipped', 'daily_cap', null, $officialId);
      return ['status' => 'skipped', 'reason' => 'Daily SMS limit reached for this official and event type.'];
    }

    $sent = send_sms($to, $body);
    if (!empty($sent['ok'])) {
      sms_log_write($db, null, $endpoint, $to, 'sent', null, $sent['message_id'] ?? null, $officialId);
      return ['status' => 'sent', 'reason' => 'SMS sent.'];
    }
    $errorText = (string)($sent['error'] ?? 'unknown');
    sms_log_write($db, null, $endpoint, $to, 'failed', $errorText, null, $officialId);
    return ['status' => 'failed', 'reason' => 'SMS send failed: ' . $errorText];
  } catch (Throwable $error) {
    error_log('notify_official failed: ' . $error->getMessage());
    try {
      sms_log_write(get_db(), null, $endpoint, '', 'failed', 'notify_official_failed', null, $officialId);
    } catch (Throwable $logError) {
      error_log('notify_official log failed: ' . $logError->getMessage());
    }
    return ['status' => 'failed', 'reason' => 'SMS could not be sent.'];
  }
}
