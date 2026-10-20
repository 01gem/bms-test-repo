<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/households/mpi_engine.php';

header('Content-Type: application/json; charset=utf-8');

function json_success(array $payload = []): void {
  echo json_encode(array_merge(['success' => true], $payload));
  exit;
}

function json_error(string $message, int $status = 400): void {
  http_response_code($status);
  echo json_encode(['success' => false, 'message' => $message]);
  exit;
}

// Log the real database/exception detail server-side; never send it to the client.
function json_db_error(string $context, string $detail, int $status = 500): void {
  error_log($context . ': ' . $detail);
  json_error('Something went wrong. Please try again.', $status);
}

function read_json_input(): array {
  $raw = file_get_contents('php://input');
  if ($raw === false || $raw === '') return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function require_post(): void {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed.', 405);
  }
}

const PUROK_OPTIONS = ['Purok 1', 'Purok 2', 'Purok 3', 'Purok 4', 'Purok 5'];

/**
 * Normalize a purok value to canonical form 'Purok N' (N in 1..5).
 * Accepts "Purok 3", "purok 3", "3", "  Purok 3 ". Returns null for
 * empty input. Returns false for non-empty input that is out of range
 * or unparseable — callers must reject that case with a 422.
 */
function normalize_purok($raw) {
  $value = trim((string)($raw ?? ''));
  if ($value === '') return null;
  if (preg_match('/^(?:purok\s*)?(\d+)$/i', $value, $m)) {
    $n = (int)$m[1];
    if ($n >= 1 && $n <= 5) return 'Purok ' . $n;
  }
  return false;
}

function require_official_session(): void {
  if (empty($_SESSION['official_id'])) {
    json_error('Unauthorized', 401);
  }
}

function require_official_admin(): void {
  require_official_session();
  if (($_SESSION['official_role'] ?? '') !== 'admin') {
    json_error('Admin access required.', 403);
  }
}

function official_full_name(array $row): string {
  return trim(($row['fname'] ?? '') . ' ' . ($row['lname'] ?? ''));
}

/**
 * Normalize a Philippine mobile number to E.164 (+639XXXXXXXXX).
 * Accepts +63, 63, 0, or bare 9-prefixed numbers, with optional
 * spaces, dashes, dots, or parentheses. Returns null when the input
 * cannot be resolved to a valid PH mobile number.
 */
function normalize_ph_phone(string $raw): ?string {
  $digits = preg_replace('/[\s\-\(\)\.]/', '', trim($raw));
  if ($digits === null || $digits === '') return null;
  if (str_starts_with($digits, '+')) $digits = substr($digits, 1);
  if (!ctype_digit($digits)) return null;

  if (strlen($digits) === 12 && str_starts_with($digits, '63')) {
    $rest = substr($digits, 2);
  } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
    $rest = substr($digits, 1);
  } elseif (strlen($digits) === 10 && str_starts_with($digits, '9')) {
    $rest = $digits;
  } else {
    return null;
  }

  if (strlen($rest) !== 10 || $rest[0] !== '9') return null;
  return '+63' . $rest;
}

function db_query_all(mysqli_stmt $stmt): array {
  if (!$stmt->execute()) {
    json_db_error('Database query failed', $stmt->error);
  }
  $result = $stmt->get_result();
  return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function log_audit(
  mysqli $conn,
  ?int $officialId,
  string $officialName,
  string $action,
  string $targetType,
  ?string $targetReference,
  string $details = ''
): bool {
  $safeOfficialName = trim($officialName) !== '' ? trim($officialName) : 'Unknown Official';
  $safeAction = trim($action);
  $safeTargetType = trim($targetType);
  $safeTargetReference = $targetReference !== null ? trim($targetReference) : null;
  $safeDetails = trim($details);
  $safeOfficialId = ($officialId !== null && $officialId > 0) ? $officialId : null;

  $stmt = $conn->prepare('INSERT INTO audit_log (official_id, official_name, action, target_type, target_reference, details) VALUES (?, ?, ?, ?, ?, ?)');
  if (!$stmt) {
    error_log('Audit log prepare failed: ' . $conn->error);
    return false;
  }

  $stmt->bind_param('isssss', $safeOfficialId, $safeOfficialName, $safeAction, $safeTargetType, $safeTargetReference, $safeDetails);
  $ok = $stmt->execute();
  if (!$ok) {
    error_log('Audit log execute failed: ' . $stmt->error);
  }
  $stmt->close();
  return $ok;
}

function ai_chat(
  string $userMessage,
  string $systemPrompt = '',
  string $model = 'auto',
  bool $jsonResponse = false,
  int $timeoutSeconds = 15
): array {
  $apiKey = trim((string)(getenv('GEMINI_API_KEY') ?: ''));
  $defaultModel = trim((string)(getenv('GEMINI_MODEL') ?: ''));
  if ($defaultModel === '') {
    $defaultModel = 'gemini-3.5-flash-lite';
  }
  $modelName = $model !== '' && $model !== 'auto' ? $model : $defaultModel;

  if ($apiKey === '') {
    return ['success' => false, 'error' => 'Gemini is not configured (missing GEMINI_API_KEY).'];
  }

  if (!function_exists('curl_init')) {
    return ['success' => false, 'error' => 'cURL extension is not enabled.'];
  }

  $contents = [
    ['parts' => [['text' => $userMessage]]],
  ];
  $payload = ['contents' => $contents];
  if ($systemPrompt !== '') {
    $payload['systemInstruction'] = ['parts' => [['text' => $systemPrompt]]];
  }
  if ($jsonResponse) {
    $payload['generationConfig'] = ['responseMimeType' => 'application/json'];
  }

  $encodedPayload = json_encode($payload);
  if ($encodedPayload === false) {
    return ['success' => false, 'error' => 'Could not encode the Gemini request.'];
  }

  $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
    . rawurlencode($modelName)
    . ':generateContent';

  $maxAttempts = 2;
  $response = false;
  $httpStatus = 0;
  for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    $retryAfterHeader = null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-goog-api-key: ' . $apiKey,
      ],
      CURLOPT_POSTFIELDS => $encodedPayload,
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_TIMEOUT => max(5, $timeoutSeconds),
      CURLOPT_HEADERFUNCTION => function ($ch, string $header) use (&$retryAfterHeader): int {
        if (stripos($header, 'Retry-After:') === 0) {
          $retryAfterHeader = trim(substr($header, strlen('Retry-After:')));
        }
        return strlen($header);
      },
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
      error_log('Gemini cURL error for model ' . $modelName . ': ' . curl_error($ch));
      curl_close($ch);
      return ['success' => false, 'error' => 'Gemini request failed.'];
    }

    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpStatus !== 503 && $httpStatus !== 429) {
      break;
    }

    error_log('Gemini HTTP ' . $httpStatus . ' for model ' . $modelName . ' (attempt ' . $attempt . ' of ' . $maxAttempts . ').');
    if ($attempt < $maxAttempts) {
      $wait = 1;
      if ($httpStatus === 429) {
        $wait = 2;
        if ($retryAfterHeader !== null && is_numeric($retryAfterHeader)) {
          $wait = min(5, max(1, (int)ceil((float)$retryAfterHeader)));
        }
      }
      sleep($wait);
    }
  }

  $decoded = json_decode((string)$response, true);

  if ($httpStatus < 200 || $httpStatus >= 300) {
    return ['success' => false, 'error' => 'Gemini returned HTTP ' . $httpStatus . '.'];
  }
  if (!is_array($decoded)) {
    return ['success' => false, 'error' => 'Gemini returned an invalid response.'];
  }

  $content = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
  if (!is_string($content) || trim($content) === '') {
    return ['success' => false, 'error' => 'Gemini returned no text response.'];
  }

  return ['success' => true, 'content' => $content, 'model' => $modelName];
}

function ai_decode_json(string $content): ?array {
  $text = trim($content);
  $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
  $decoded = json_decode($text, true);
  return is_array($decoded) ? $decoded : null;
}

/**
 * Look up the poverty threshold to apply for a household.
 *
 * Lookup order (the FIRST hit wins):
 *   1. geography_level = 'Province' AND province = $province
 *   2. geography_level = 'Region'   AND region   = $region
 *   3. geography_level = 'National'
 * Every level requires is_active = 1; inactive rows are never used.
 * Within a level the newest row wins (ORDER BY reference_year DESC, id DESC).
 *
 * Returns all columns of the matching poverty_thresholds row as an associative
 * array, or null when no active row matches or the query fails. Database error
 * detail is error_log()ed only; it is never echoed or returned.
 *
 * Called by profile_save_step.php after each wizard save to resolve the active
 * threshold before calculate_mpi_assessment() is run.
 */
function lookup_poverty_threshold(string $province = 'Surigao del Norte', string $region = 'Caraga'): ?array {
  $conn = get_db();
  $levels = [
    [
      'sql' => "SELECT * FROM poverty_thresholds WHERE geography_level = 'Province' AND province = ? AND is_active = 1 ORDER BY reference_year DESC, id DESC LIMIT 1",
      'param' => $province,
    ],
    [
      'sql' => "SELECT * FROM poverty_thresholds WHERE geography_level = 'Region' AND region = ? AND is_active = 1 ORDER BY reference_year DESC, id DESC LIMIT 1",
      'param' => $region,
    ],
    [
      'sql' => "SELECT * FROM poverty_thresholds WHERE geography_level = 'National' AND is_active = 1 ORDER BY reference_year DESC, id DESC LIMIT 1",
      'param' => null,
    ],
  ];

  foreach ($levels as $level) {
    $stmt = $conn->prepare($level['sql']);
    if (!$stmt) {
      error_log('Poverty threshold prepare failed: ' . $conn->error);
      return null;
    }
    if ($level['param'] !== null) {
      $param = $level['param'];
      $stmt->bind_param('s', $param);
    }
    if (!$stmt->execute()) {
      error_log('Poverty threshold execute failed: ' . $stmt->error);
      $stmt->close();
      return null;
    }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if ($row) {
      return $row;
    }
  }

  return null;
}

/**
 * Deterministic MPI assessment for a household.
 *
 * Phase 3 spec name. Delegates to the pure engine in mpi_engine.php.
 * Both inputs and output are the same as assess_household(); the wrapper
 * exists so callers (and the spec) can use a stable, well-named entry point
 * without reaching into the engine module directly.
 *
 * @param array       $household  Row from `households` (uses household_size).
 * @param array|null  $profile    Row from `household_profiles` or null.
 * @param array|null  $threshold  Row from `poverty_thresholds` or null.
 * @return array<string,mixed> Column → value pairs ready to persist.
 */
function calculate_mpi_assessment(array $household, ?array $profile = null, ?array $threshold = null): array {
  return assess_household($household, $profile, $threshold);
}

/**
 * Generate the next household code: 'HH-' followed by a sequence number
 * zero-padded to at least 4 digits (HH-0001, HH-0002, ...). The number is
 * MAX(numeric part) + 1 over existing well-formed codes, not COUNT(*), so a
 * deleted row never causes a code to be reused. Padding never truncates:
 * HH-9999 is followed by HH-10000.
 *
 * NOT race-safe by design: two concurrent callers can receive the same code.
 * The UNIQUE KEY on households.household_code is the safety net. Callers MUST
 * catch a duplicate-key failure on INSERT (MySQL errno 1062) and retry with a
 * fresh code from this function. No locking is used here.
 *
 * @throws RuntimeException when the sequence query fails. The database error
 *                          detail is error_log()ed; the exception message is generic.
 */
function next_household_code(): string {
  $conn = get_db();
  $sql = 'SELECT COALESCE(MAX(CAST(SUBSTRING(household_code, 4) AS UNSIGNED)), 0) AS max_num'
    . ' FROM households WHERE household_code REGEXP \'^HH-[0-9]+$\'';

  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    error_log('Household code prepare failed: ' . $conn->error);
    throw new RuntimeException('Could not generate a household code.');
  }
  if (!$stmt->execute()) {
    error_log('Household code execute failed: ' . $stmt->error);
    $stmt->close();
    throw new RuntimeException('Could not generate a household code.');
  }
  $result = $stmt->get_result();
  $row = $result ? $result->fetch_assoc() : null;
  $stmt->close();
  if (!$row) {
    error_log('Household code query returned no row.');
    throw new RuntimeException('Could not generate a household code.');
  }

  $next = (int)$row['max_num'] + 1;
  return 'HH-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

// Shared SMS helper. Loaded last so normalize_ph_phone() and get_db() are
// already defined by the time sms.php's function bodies run.
require_once __DIR__ . '/sms.php';
?>
