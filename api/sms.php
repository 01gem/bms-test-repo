<?php
/**
 * BarangayLink - Shared SMS helper.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Requires api/common.php to be loaded first (uses get_db(), db_query_all(),
 * normalize_ph_phone()). Never throws. All callers log through sms_log.
 */

/**
 * Send an SMS via httpSMS. Never throws.
 * @return array{ok:bool,error:string,message_id:?string}
 */
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
function notify_resident(
  int $residentId,
  string $endpoint,
  string $body,
  string $prefKey = 'complaints_enabled',
  ?string $toOverride = null
): array {
  $db = get_db();

  $apiKey = getenv('HTTPSMS_API_KEY') ?: '';
  $fromNumber = getenv('HTTPSMS_FROM_NUMBER') ?: '';
  if ($apiKey === '' || $fromNumber === '') {
    sms_log_write($db, $residentId, $endpoint, '', 'skipped', 'sms_not_configured');
    return ['status' => 'skipped', 'reason' => 'SMS is not configured on this server.'];
  }

  $toRaw = trim((string)($toOverride ?? ''));
  if ($toRaw === '') {
    $stmt = $db->prepare('SELECT contact FROM residents WHERE id = ? LIMIT 1');
    if (!$stmt) {
      sms_log_write($db, $residentId, $endpoint, '', 'skipped', 'db_prepare_failed');
      return ['status' => 'skipped', 'reason' => 'Contact lookup failed.'];
    }
    $stmt->bind_param('i', $residentId);
    $rows = db_query_all($stmt);
    $stmt->close();
    $toRaw = trim((string)($rows[0]['contact'] ?? ''));
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

  $col = in_array($prefKey, ['complaints_enabled','requests_enabled','announcements_enabled'], true)
    ? $prefKey : 'complaints_enabled';
  $prefOk = true;
  $pStmt = $db->prepare("SELECT `{$col}` AS enabled FROM resident_sms_preferences WHERE resident_id = ? LIMIT 1");
  if ($pStmt) {
    $pStmt->bind_param('i', $residentId);
    $pRows = db_query_all($pStmt);
    $pStmt->close();
    if ($pRows) $prefOk = (int)($pRows[0]['enabled'] ?? 1) === 1;
  }
  if (!$prefOk) {
    sms_log_write($db, $residentId, $endpoint, $to, 'skipped', 'pref_off');
    return ['status' => 'skipped', 'reason' => 'Resident has disabled this SMS category.'];
  }

  $cStmt = $db->prepare("SELECT COUNT(*) AS c FROM sms_log WHERE resident_id = ? AND endpoint = ? AND status = 'sent' AND created_at > NOW() - INTERVAL 1 DAY");
  if ($cStmt) {
    $cStmt->bind_param('is', $residentId, $endpoint);
    $cRows = db_query_all($cStmt);
    $cStmt->close();
    if ((int)($cRows[0]['c'] ?? 0) >= 5) {
      sms_log_write($db, $residentId, $endpoint, $to, 'skipped', 'daily_cap');
      return ['status' => 'skipped', 'reason' => 'Daily SMS limit reached for this resident and event type.'];
    }
  }

  $result = send_sms($to, $body);

  if ($result['ok']) {
    sms_log_write($db, $residentId, $endpoint, $to, 'sent', null, $result['message_id'] ?? null);
    return ['status' => 'sent', 'reason' => 'SMS sent.'];
  }
  sms_log_write($db, $residentId, $endpoint, $to, 'failed', $result['error'] ?? 'unknown', null);
  return ['status' => 'failed', 'reason' => 'SMS send failed: ' . ($result['error'] ?? 'unknown')];
}

/** Append-only sms_log writer. Never throws. */
function sms_log_write(
  mysqli $db,
  ?int $residentId,
  string $endpoint,
  string $to,
  string $status,
  ?string $reason,
  ?string $messageId = null
): void {
  $stmt = $db->prepare(
    'INSERT INTO sms_log (resident_id, official_id, endpoint, to_number, status, reason, http_message_id)
     VALUES (?, NULL, ?, ?, ?, ?, ?)'
  );
  if (!$stmt) { error_log('sms_log prepare failed: ' . $db->error); return; }
  $stmt->bind_param('isssss', $residentId, $endpoint, $to, $status, $reason, $messageId);
  if (!$stmt->execute()) error_log('sms_log execute failed: ' . $stmt->error);
  $stmt->close();
}
