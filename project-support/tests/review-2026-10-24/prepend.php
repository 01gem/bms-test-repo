<?php
/**
 * Test-server prepend. Not part of the product.
 * Points this process at the disposable schema and blocks provider calls.
 */
foreach (['HTTPSMS_API_KEY', 'HTTPSMS_FROM_NUMBER', 'HTTPSMS_SIM_SLOT', 'GEMINI_API_KEY', 'GEMINI_MODEL'] as $key) {
  putenv($key . '=');
  $_ENV[$key] = '';
  $_SERVER[$key] = '';
}

if (!function_exists('recovery_send_reset_sms')) {
  function recovery_send_reset_sms(string $to, string $code): array {
    $log = getenv('FIX_SMS_LOG');
    if (is_string($log) && $log !== '') {
      file_put_contents($log, json_encode(['to' => $to, 'code' => $code]) . PHP_EOL, FILE_APPEND);
    }
    return ['ok' => true, 'status' => 200, 'message' => ''];
  }
}

// Preserve the isolated evidence root even when php.ini omits E from variables_order.
$testStorage = getenv('COMPLAINT_ATTACHMENT_STORAGE_DIR');
if (is_string($testStorage) && $testStorage !== '') {
  $_ENV['COMPLAINT_ATTACHMENT_STORAGE_DIR'] = $testStorage;
  $_SERVER['COMPLAINT_ATTACHMENT_STORAGE_DIR'] = $testStorage;
}
require_once dirname(__DIR__, 2) . '/db.php';
$configured = getenv('BARANGAY_DB_NAME');
if (is_string($configured) && preg_match('/^[A-Za-z0-9_]+$/', $configured) === 1) {
  $DB_NAME = $configured;
}
