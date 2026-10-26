<?php
/**
 * BarangayLink - Password-recovery SMS transport.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 *
 * A test process may define recovery_send_reset_sms() before this file loads.
 * HTTP requests cannot do that. The default sends through httpSMS.
 */

if (!function_exists('recovery_send_reset_sms')) {
  /**
   * @return array{ok:bool,status:int,message:string}
   */
  function recovery_send_reset_sms(string $to, string $code): array {
    $apiKey = getenv('HTTPSMS_API_KEY') ?: '';
    $fromNumber = getenv('HTTPSMS_FROM_NUMBER') ?: '';
    $simSlot = getenv('HTTPSMS_SIM_SLOT') ?: 'SIM1';
    $payload = json_encode([
      'from' => $fromNumber,
      'to' => $to,
      'content' => "Your BarangayLink password reset code is {$code}. It expires in 10 minutes.",
      'sim' => $simSlot,
    ]);
    if ($payload === false || !function_exists('curl_init')) {
      return ['ok' => false, 'status' => 503, 'message' => 'Password reset SMS could not be sent.'];
    }
    $ch = curl_init('https://api.httpsms.com/v1/messages/send');
    if ($ch === false) {
      return ['ok' => false, 'status' => 503, 'message' => 'Password reset SMS could not be sent.'];
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
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $curlError = $response === false ? curl_error($ch) : '';
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || !in_array($httpStatus, [200, 201, 202], true)) {
      error_log('Password reset SMS failed: ' . ($curlError !== '' ? $curlError : "httpSMS HTTP {$httpStatus}"));
      return ['ok' => false, 'status' => 500, 'message' => 'Password reset SMS could not be sent. Please try again later.'];
    }
    return ['ok' => true, 'status' => 200, 'message' => ''];
  }
}
