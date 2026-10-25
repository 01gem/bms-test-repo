<?php
/**
 * Isolated regression runner for the 24 October review fixes.
 * Not product code. Uses a disposable schema and never calls a real SMS provider.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

const FIX_ROOT = 'C:/laragon/www/Capstone-System-COPY';
const FIX_PHP = 'C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe';
const FIX_MYSQL = 'C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe';
const FIX_DUMP = 'C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysqldump.exe';
const FIX_APP_DB = 'barangay_system_test_environment';

foreach (['HTTPSMS_API_KEY', 'HTTPSMS_FROM_NUMBER', 'HTTPSMS_SIM_SLOT', 'GEMINI_API_KEY', 'GEMINI_MODEL'] as $blocked) {
  putenv($blocked . '=');
  $_ENV[$blocked] = '';
  $_SERVER[$blocked] = '';
}

$mode = $argv[1] ?? 'all';
if ($mode === 'getdb-die') {
  require FIX_ROOT . '/db.php';
  $DB_NAME = 'capstone_fix24_missing_database';
  echo "BEFORE\n";
  get_db();
  echo "AFTER\n";
  exit(0);
}
if ($mode === 'conn-retry') {
  require FIX_ROOT . '/db.php';
  $DB_NAME = 'capstone_fix24_missing_database';
  $threw = false;
  try {
    db_connection();
    echo "FIRST_OK\n";
  } catch (Throwable $error) {
    $threw = true;
    echo "THREW\n";
  }
  $DB_NAME = getenv('FIX_DB') ?: '';
  try {
    $conn = db_connection();
    echo 'SECOND ' . $conn->query('SELECT DATABASE() AS d')->fetch_assoc()['d'] . "\n";
  } catch (Throwable $error) {
    echo "SECOND_FAIL\n";
  }
  exit($threw ? 0 : 1);
}
if ($mode === 'upload-worker') {
  worker_boot();
  require FIX_ROOT . '/api/complaints/_attachments.php';
  $ready = getenv('FIX_READY') ?: '';
  $go = getenv('FIX_GO') ?: '';
  file_put_contents($ready, '1');
  $deadline = time() + 20;
  while (!is_file($go) && time() < $deadline) usleep(20000);
  $jpeg = getenv('FIX_JPEG') ?: '';
  $db = db_connection();
  $result = complaint_attachment_store_upload(
    $db,
    ['type' => (string)getenv('FIX_ACTOR_TYPE'), 'id' => (int)getenv('FIX_ACTOR_ID')],
    (int)getenv('FIX_COMPLAINT'),
    $jpeg,
    'image/jpeg',
    (int)filesize($jpeg),
    false
  );
  file_put_contents(getenv('FIX_RESULT') ?: 'php://stdout', json_encode($result));
  exit(0);
}
if ($mode === 'reset-worker') {
  worker_boot();
  $GLOBALS['FIX_PHP_INPUT'] = getenv('FIX_BODY') ?: '';
  stream_wrapper_unregister('php');
  stream_wrapper_register('php', FixPhpInput::class);
  $_SERVER['REQUEST_METHOD'] = 'POST';
  $_SERVER['HTTP_HOST'] = '127.0.0.1';
  require FIX_ROOT . '/api/auth/reset_password.php';
  exit(0);
}
if ($mode === 'stmt-fail') {
  worker_boot();
  $db = db_connection();
  $db->query('CREATE TABLE IF NOT EXISTS fix_canary (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY)');
  $db->query('ALTER TABLE barangay_officials CHANGE status status_hold ENUM("active","inactive") NOT NULL');
  session_save_path(getenv('FIX_SESSION_PATH') ?: '');
  session_id('stmtfail');
  session_start();
  $_SESSION = [
    'official_id' => (int)getenv('FIX_OFFICIAL'),
    'official_role' => 'admin',
    'official_name' => 'Ada Admin',
    'official_position' => 'Captain',
  ];
  require FIX_ROOT . '/api/_session.php';
  $result = session_revalidate_active();
  $canary = (int)($db->query('SELECT COUNT(*) AS c FROM fix_canary')->fetch_assoc()['c'] ?? -1);
  $still = (int)($_SESSION['official_id'] ?? 0);
  session_write_close();
  $db->query('ALTER TABLE barangay_officials CHANGE status_hold status ENUM("active","inactive") NOT NULL');
  echo json_encode(['error' => $result['error'] ?? null, 'ok' => $result['ok'] ?? null, 'canary' => $canary, 'still' => $still]);
  exit(0);
}
if ($mode === 'craft') {
  ini_set('session.use_strict_mode', '0');
  session_save_path(getenv('FIX_SESSION_PATH') ?: '');
  session_id(getenv('FIX_SESSION_ID') ?: '');
  session_start();
  $_SESSION = json_decode(getenv('FIX_SESSION_JSON') ?: '{}', true) ?: [];
  session_write_close();
  echo "CRAFTED\n";
  exit(0);
}
if ($mode === 'verify-worker') {
  worker_boot();
  $GLOBALS['FIX_PHP_INPUT'] = getenv('FIX_BODY') ?: '';
  stream_wrapper_unregister('php');
  stream_wrapper_register('php', FixPhpInput::class);
  $_SERVER['REQUEST_METHOD'] = 'POST';
  $_SERVER['HTTP_HOST'] = '127.0.0.1';
  require FIX_ROOT . '/api/auth/verify_otp.php';
  exit(0);
}

final class FixPhpInput {
  public $context;
  private string $data = '';
  private int $pos = 0;
  public function stream_open($path, $mode, $options, &$opened): bool {
    if ($path !== 'php://input') return false;
    $this->data = (string)($GLOBALS['FIX_PHP_INPUT'] ?? '');
    $this->pos = 0;
    return true;
  }
  public function stream_read($count) {
    $chunk = substr($this->data, $this->pos, (int)$count);
    $this->pos += strlen($chunk);
    return $chunk;
  }
  public function stream_eof() { return $this->pos >= strlen($this->data); }
  public function stream_stat() { return ['size' => strlen($this->data)]; }
  public function stream_tell() { return $this->pos; }
  public function stream_seek($offset, $whence) { return false; }
  public function stream_set_option($option, $arg1, $arg2) { return false; }
}

function worker_boot(): void {
  require FIX_ROOT . '/db.php';
  $name = getenv('FIX_DB') ?: '';
  if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
    fwrite(STDERR, "bad db\n");
    exit(2);
  }
  $GLOBALS['DB_HOST'] = $DB_HOST;
  $GLOBALS['DB_USER'] = $DB_USER;
  $GLOBALS['DB_PASS'] = $DB_PASS;
  $GLOBALS['DB_NAME'] = $name;
  $storage = getenv('COMPLAINT_ATTACHMENT_STORAGE_DIR') ?: '';
  if ($storage !== '') putenv('COMPLAINT_ATTACHMENT_STORAGE_DIR=' . $storage);
}

$passed = 0;
$failed = 0;
$notes = [];
function check(string $name, bool $ok, string $detail = ''): void {
  global $passed, $failed, $notes;
  if ($ok) {
    $passed++;
    echo "PASS $name\n";
  } else {
    $failed++;
    $line = "FAIL $name" . ($detail !== '' ? " :: $detail" : '');
    $notes[] = $line;
    echo $line . "\n";
  }
}

function mysql_exec(string $sql, ?string $database = null): string {
  $args = [FIX_MYSQL, '-uroot', '--default-character-set=utf8mb4', '-N', '-e', $sql];
  if ($database !== null) array_splice($args, 3, 0, [$database]);
  $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
  $proc = proc_open($args, $spec, $pipes);
  if (!is_resource($proc)) return '';
  fclose($pipes[0]);
  $out = stream_get_contents($pipes[1]);
  $err = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $code = proc_close($proc);
  if ($code !== 0) throw new RuntimeException(trim($err !== '' ? $err : $out));
  return (string)$out;
}

function app_counts(): array {
  $out = mysql_exec(
    "SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA='" . FIX_APP_DB . "' AND TABLE_NAME IN ('complaints','complaint_attachments','otp_codes','residents','barangay_officials','sms_log')"
  );
  $exact = mysql_exec(
    'SELECT '
    . "(SELECT COUNT(*) FROM complaints),"
    . "(SELECT COUNT(*) FROM complaint_attachments),"
    . "(SELECT COUNT(*) FROM otp_codes),"
    . "(SELECT COUNT(*) FROM residents),"
    . "(SELECT COUNT(*) FROM barangay_officials),"
    . "(SELECT COUNT(*) FROM sms_log)",
    FIX_APP_DB
  );
  return ['raw' => trim($exact)];
}

function q(mysqli $db, string $sql, string $types = '', array $values = []): array {
  $stmt = $db->prepare($sql);
  if (!$stmt) throw new RuntimeException($db->error);
  if ($types !== '') {
    $bound = [];
    foreach ($values as $i => $value) $bound[$i] = $value;
    $args = [$types];
    foreach ($bound as $i => $_) $args[] = &$bound[$i];
    if (!call_user_func_array([$stmt, 'bind_param'], $args)) throw new RuntimeException($stmt->error);
  }
  if (!$stmt->execute()) {
    $detail = $stmt->error;
    $stmt->close();
    throw new RuntimeException($detail);
  }
  $result = $stmt->get_result();
  $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
  $stmt->close();
  return $rows;
}

function exec_sql(mysqli $db, string $sql, string $types = '', array $values = []): void {
  $stmt = $db->prepare($sql);
  if (!$stmt) throw new RuntimeException($db->error);
  if ($types !== '') {
    $bound = [];
    foreach ($values as $i => $value) $bound[$i] = $value;
    $args = [$types];
    foreach ($bound as $i => $_) $args[] = &$bound[$i];
    call_user_func_array([$stmt, 'bind_param'], $args);
  }
  if (!$stmt->execute()) {
    $detail = $stmt->error;
    $stmt->close();
    throw new RuntimeException($detail);
  }
  $stmt->close();
}

function private_attachment_file(string $storage, string $key): string {
  if (preg_match('#^private:v1/([1-9][0-9]*)/([0-9a-f]{32})\.(jpg|png)$#', $key, $match) !== 1) {
    return '';
  }
  return $storage . DIRECTORY_SEPARATOR . $match[1] . DIRECTORY_SEPARATOR . $match[2] . '.' . $match[3];
}

function make_image(string $path, string $kind): void {
  $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
  $jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/9oACAEBAAA/AH//2Q==');
  file_put_contents($path, $kind === 'png' ? $png : $jpeg);
}

function http(string $url, string $method = 'GET', ?string $body = null, array $headers = [], ?string $cookie = null): array {
  if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
  $context = stream_context_create([
    'http' => [
      'method' => $method,
      'header' => implode("\r\n", $headers),
      'content' => $body ?? '',
      'ignore_errors' => true,
      'follow_location' => 0,
      'max_redirects' => 0,
      'timeout' => 30,
    ],
  ]);
  $response = @file_get_contents($url, false, $context);
  $rawHeaders = $http_response_header ?? [];
  $status = 0;
  if (isset($rawHeaders[0]) && preg_match('/\s(\d{3})\s/', $rawHeaders[0], $match)) $status = (int)$match[1];
  return [
    'status' => $status,
    'headers' => implode("\n", $rawHeaders),
    'body' => $response === false ? '' : $response,
  ];
}

function spawn_php(array $env, array $args): array {
  $env = [
    'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
    'PATH' => getenv('PATH') ?: '',
    'TEMP' => sys_get_temp_dir(),
    'TMP' => sys_get_temp_dir(),
  ] + $env;
  $cmd = array_merge([FIX_PHP, '-d', 'disable_functions=curl_init,curl_exec,curl_setopt,curl_setopt_array', __FILE__], $args);
  $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
  $proc = proc_open($cmd, $spec, $pipes, FIX_ROOT, $env);
  return [$proc, $pipes];
}

$old = mysql_exec("SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'capstone_fix24\\_%'");
foreach (preg_split('/\R/', trim($old)) as $oldName) {
  if ($oldName !== '' && preg_match('/^capstone_fix24_[A-Za-z0-9_]+$/', $oldName)) {
    mysql_exec('DROP DATABASE IF EXISTS `' . $oldName . '`');
  }
}
$dbName = 'capstone_fix24_' . getmypid();
$storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fix24-evidence-' . getmypid();
$sessionPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fix24-sessions-' . getmypid();
$smsLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fix24-sms-' . getmypid() . '.log';
$server = null;
$serverPipes = null;
$before = app_counts();
$legacyCreated = [];

try {
  mysql_exec("DROP DATABASE IF EXISTS `$dbName`; CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
  $dumpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $dbName . '.sql';
  $dumpCmd = '"' . FIX_DUMP . '" -uroot --no-data --skip-lock-tables --set-gtid-purged=OFF --column-statistics=0 ' . FIX_APP_DB . ' > "' . $dumpFile . '"';
  exec($dumpCmd, $dumpOut, $dumpCode);
  if ($dumpCode !== 0) throw new RuntimeException('schema dump failed');
  $import = '"' . FIX_MYSQL . '" -uroot ' . $dbName . ' < "' . $dumpFile . '"';
  exec($import, $importOut, $importCode);
  if ($importCode !== 0) throw new RuntimeException('schema import failed');
  @unlink($dumpFile);
  mkdir($storage, 0777, true);
  mkdir($sessionPath, 0777, true);
  putenv('COMPLAINT_ATTACHMENT_STORAGE_DIR=' . $storage);
  $_ENV['COMPLAINT_ATTACHMENT_STORAGE_DIR'] = $storage;
  putenv('FIX_SMS_LOG=' . $smsLog);
  putenv('BARANGAY_DB_NAME=' . $dbName);

  require FIX_ROOT . '/db.php';
  $DB_NAME = $dbName;
  $db = db_connection();
  check('runner uses disposable schema', ($db->query('SELECT DATABASE() AS d')->fetch_assoc()['d'] ?? '') === $dbName);

  $hash = password_hash('Fix24-pass', PASSWORD_DEFAULT);
  exec_sql($db, 'INSERT INTO barangay_officials (fname,lname,username,role,address,contact,password,position,status) VALUES (?,?,?,?,?,?,?,?,?)',
    'sssssssss', ['Ada', 'Admin', 'fix-admin-a', 'admin', 'Purok 1', '+639171110001', $hash, 'Captain', 'active']);
  $adminA = (int)$db->insert_id;
  exec_sql($db, 'INSERT INTO barangay_officials (fname,lname,username,role,address,contact,password,position,status) VALUES (?,?,?,?,?,?,?,?,?)',
    'sssssssss', ['Bea', 'Boss', 'fix-admin-b', 'admin', 'Purok 1', '+639171110002', $hash, 'Secretary', 'active']);
  $adminB = (int)$db->insert_id;
  exec_sql($db, 'INSERT INTO barangay_officials (fname,lname,username,role,address,contact,password,position,status) VALUES (?,?,?,?,?,?,?,?,?)',
    'sssssssss', ['Sam', 'Staff', 'fix-staff', 'staff', 'Purok 2', '+639171110003', $hash, 'Clerk', 'active']);
  $staff = (int)$db->insert_id;
  exec_sql($db, 'INSERT INTO households (household_code,address,purok_zone,household_size) VALUES (?,?,?,?)',
    'sssi', ['HH-FIX-0001', 'Purok 1', 'Purok 1', 1]);
  $residentHousehold = (int)$db->insert_id;
  exec_sql($db, 'INSERT INTO households (household_code,address,purok_zone,household_size) VALUES (?,?,?,?)',
    'sssi', ['HH-FIX-0002', 'Purok 2', 'Purok 2', 1]);
  $otherHousehold = (int)$db->insert_id;
  exec_sql($db, 'INSERT INTO residents (fname,lname,username,address,contact,password,status,household_id,relationship_to_household_head) VALUES (?,?,?,?,?,?,?,?,?)',
    'sssssssis', ['Rae', 'Resident', 'fix-resident', 'Purok 1', '+639171110004', $hash, 'active', $residentHousehold, 'Head']);
  $resident = (int)$db->insert_id;
  exec_sql($db, 'INSERT INTO residents (fname,lname,username,address,contact,password,status,household_id,relationship_to_household_head) VALUES (?,?,?,?,?,?,?,?,?)',
    'sssssssis', ['Ott', 'Other', 'fix-other', 'Purok 2', '+639171110005', $hash, 'active', $otherHousehold, 'Head']);
  $other = (int)$db->insert_id;
  exec_sql($db, 'INSERT INTO complaints (resident_id,reference_no,resident_name,category,severity,location_text,description,date_filed,status,official_note,is_public) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
    'isssssssssi', [$resident, 'FIX-OWN', 'Rae Resident', 'Noise / Public Disturbance', 'low', 'Purok 1', 'Owned complaint', 'Oct 24, 2026', 'open', '', 0]);
  $ownComplaint = (int)$db->insert_id;

  // Connection failure is separate from statement failure.
  [$proc, $pipes] = spawn_php(['FIX_DB' => $dbName], ['conn-retry']);
  fclose($pipes[0]);
  $connOut = stream_get_contents($pipes[1]);
  fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
  check('failed connection is not cached', str_contains($connOut, 'THREW') && str_contains($connOut, 'SECOND ' . $dbName), trim($connOut));
  [$proc, $pipes] = spawn_php([], ['getdb-die']);
  fclose($pipes[0]);
  $dieOut = stream_get_contents($pipes[1]);
  fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
  check('get_db still terminates without a success marker', str_contains($dieOut, 'BEFORE') && str_contains($dieOut, 'Database connection failed.') && !str_contains($dieOut, 'AFTER'), trim($dieOut));

  // Statement failure: break the lookup column, with no business insert.
  $stmtEnv = [
    'FIX_DB' => $dbName,
    'FIX_SESSION_PATH' => $sessionPath,
    'FIX_OFFICIAL' => (string)$adminA,
    'COMPLAINT_ATTACHMENT_STORAGE_DIR' => $storage,
  ];
  [$stmtProc, $stmtPipes] = spawn_php($stmtEnv, ['stmt-fail']);
  fclose($stmtPipes[0]);
  $stmtOut = stream_get_contents($stmtPipes[1]);
  $stmtErr = stream_get_contents($stmtPipes[2]);
  fclose($stmtPipes[1]); fclose($stmtPipes[2]); proc_close($stmtProc);
  $stmtJson = json_decode($stmtOut, true);
  check('lookup failure is a server error', is_array($stmtJson) && ($stmtJson['error'] ?? '') === 'server' && empty($stmtJson['ok']), trim($stmtOut . $stmtErr));
  check('lookup failure does not clear the session', is_array($stmtJson) && (int)($stmtJson['still'] ?? 0) === $adminA);
  check('lookup failure performs no business insert', is_array($stmtJson) && (int)($stmtJson['canary'] ?? -1) === 0);

  // Fresh process so the terminal failure cache does not stick.
  $portSocket = stream_socket_server('tcp://127.0.0.1:0', $portErrno, $portError);
  if ($portSocket === false) throw new RuntimeException('Could not reserve a port');
  $portName = stream_socket_get_name($portSocket, false);
  fclose($portSocket);
  $port = (int)substr((string)$portName, (int)strrpos((string)$portName, ':') + 1);
  $serverEnv = [
    'BARANGAY_DB_NAME' => $dbName,
    'COMPLAINT_ATTACHMENT_STORAGE_DIR' => $storage,
    'FIX_SMS_LOG' => $smsLog,
    'HTTPSMS_API_KEY' => '',
    'HTTPSMS_FROM_NUMBER' => '',
    'HTTPSMS_SIM_SLOT' => '',
    'GEMINI_API_KEY' => '',
    'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
    'PATH' => getenv('PATH') ?: '',
  ];
  $serverCmd = [FIX_PHP, '-d', 'disable_functions=curl_init,curl_exec,curl_setopt,curl_setopt_array', '-d', 'session.save_path=' . $sessionPath, '-d', 'auto_prepend_file=' . __DIR__ . '/prepend.php', '-S', '127.0.0.1:' . $port, '-t', FIX_ROOT];
  $serverLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fix24-server-' . getmypid() . '.log';
  $server = proc_open($serverCmd, [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']], $serverPipes, FIX_ROOT, $serverEnv + ['SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows', 'PATH' => getenv('PATH') ?: '', 'TEMP' => sys_get_temp_dir(), 'TMP' => sys_get_temp_dir()]);
  $ready = false;
  for ($i = 0; $i < 30; $i++) {
    $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if (is_resource($probe)) { fclose($probe); $ready = true; break; }
    usleep(200000);
  }
  check('isolated server started', $ready);
  $base = 'http://127.0.0.1:' . $port;

  $public = [
    '/api/announcements/list.php',
    '/api/services/list.php',
    '/api/stats/landing.php',
    '/index.php',
  ];
  foreach ($public as $path) {
    $res = http($base . $path);
    check('public ' . $path, $res['status'] === 200 && !str_contains($res['body'], 'Unauthorized'), (string)$res['status']);
  }
  $otp = http($base . '/api/auth/request_otp.php', 'POST', '{}', ['Content-Type: application/json']);
  check('recovery stays public', $otp['status'] === 400 && !str_contains($otp['body'], 'Unauthorized'), (string)$otp['status'] . ' ' . $otp['body']);

  $protected = [
    '/api/officials/list.php',
    '/api/complaints/list.php',
    '/api/complaints/public_list.php',
    '/api/complaints/upload_attachment.php',
    '/api/document_prices/list.php',
    '/api/audit/list.php',
    '/api/notifications/list.php',
    '/api/residents/sms_preferences.php',
    '/api/requests/create.php',
    '/api/ai/echo_test.php',
  ];
  foreach ($protected as $path) {
    if ($path === '/api/requests/create.php') {
      $res = http($base . $path, 'POST', '{"document_type":"Barangay Clearance","purpose":"test"}', ['Content-Type: application/json']);
    } else {
      $res = http($base . $path);
      if ($res['status'] === 405) {
        $res = http($base . $path, 'POST', '{}', ['Content-Type: application/json']);
      }
    }
    check('anonymous protected ' . $path, in_array($res['status'], [401, 403], true), (string)$res['status'] . ' ' . substr($res['body'], 0, 180));
  }
  foreach (['/pages/official.php', '/pages/resident.php', '/pages/community_complaints.php'] as $path) {
    $res = http($base . $path);
    check('anonymous page ' . $path, $res['status'] === 302 && str_contains($res['headers'], 'login.php'), (string)$res['status']);
  }

  $sid = [];
  foreach (['adminA', 'adminB', 'staff', 'resident', 'other', 'dual'] as $sidName) {
    $sid[$sidName] = bin2hex(random_bytes(16));
  }
  $cookieFor = static function (string $name) use (&$sid): string {
    return 'PHPSESSID=' . $sid[$name];
  };
  $craft = function (string $name, array $data) use ($sessionPath, $dbName, $storage, &$sid): void {
    $env = [
      'FIX_DB' => $dbName,
      'FIX_SESSION_PATH' => $sessionPath,
      'FIX_SESSION_ID' => $sid[$name],
      'FIX_SESSION_JSON' => json_encode($data),
      'COMPLAINT_ATTACHMENT_STORAGE_DIR' => $storage,
    ];
    [$proc, $pipes] = spawn_php($env, ['craft']);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
  };
  $craft('adminA', ['official_id' => $adminA, 'official_role' => 'admin', 'official_name' => 'Old Name', 'official_position' => 'Old Title']);
  $craft('adminB', ['official_id' => $adminB, 'official_role' => 'admin', 'official_name' => 'Bea Boss', 'official_position' => 'Secretary']);
  $craft('staff', ['official_id' => $staff, 'official_role' => 'staff', 'official_name' => 'Sam Staff', 'official_position' => 'Clerk']);
  $craft('resident', ['resident_id' => $resident, 'resident_name' => 'Rae Resident', 'resident_username' => 'fix-resident']);
  $craft('other', ['resident_id' => $other, 'resident_name' => 'Ott Other', 'resident_username' => 'fix-other']);
  $craft('dual', [
    'official_id' => $adminA, 'official_role' => 'admin', 'official_name' => 'Ada Admin', 'official_position' => 'Captain',
    'resident_id' => $resident, 'resident_name' => 'Rae Resident', 'resident_username' => 'fix-resident',
  ]);

  $adminList = http($base . '/api/officials/list.php', 'GET', null, [], $cookieFor('adminA'));
  check('admin list refreshes access', $adminList['status'] === 200 && str_contains($adminList['body'], 'Ada'), (string)$adminList['status']);
  $page = http($base . '/pages/official.php', 'GET', null, [], $cookieFor('adminA'));
  check('official page uses refreshed name', $page['status'] === 200 && str_contains($page['body'], 'Ada Admin') && str_contains($page['body'], 'Captain'), (string)$page['status']);
  exec_sql($db, "UPDATE barangay_officials SET role='staff', fname='Ada', lname='Demoted', position='Clerk' WHERE id=?", 'i', [$adminA]);
  $demoted = http($base . '/api/officials/list.php', 'GET', null, [], $cookieFor('adminA'));
  check('demoted admin loses admin API', $demoted['status'] === 403, (string)$demoted['status'] . ' ' . $demoted['body']);
  $stillOfficial = http($base . '/api/complaints/list.php', 'GET', null, [], $cookieFor('adminA'));
  check('demoted admin keeps official API', $stillOfficial['status'] === 200, (string)$stillOfficial['status']);
  $demotedPage = http($base . '/pages/official.php', 'GET', null, [], $cookieFor('adminA'));
  check('demoted page is staff', $demotedPage['status'] === 200 && str_contains($demotedPage['body'], 'IS_OFFICIAL_ADMIN = false'), (string)$demotedPage['status']);
  exec_sql($db, "UPDATE barangay_officials SET role='admin' WHERE id=?", 'i', [$staff]);
  $promoted = http($base . '/api/officials/list.php', 'GET', null, [], $cookieFor('staff'));
  check('promoted staff gains admin API', $promoted['status'] === 200, (string)$promoted['status']);
  exec_sql($db, "UPDATE barangay_officials SET role='staff' WHERE id=?", 'i', [$staff]);
  exec_sql($db, "UPDATE barangay_officials SET status='inactive' WHERE id=?", 'i', [$adminA]);
  $deadApi = http($base . '/api/complaints/list.php', 'GET', null, [], $cookieFor('adminA'));
  $deadPage = http($base . '/pages/official.php', 'GET', null, [], $cookieFor('adminA'));
  check('deactivated official loses API', $deadApi['status'] === 401, (string)$deadApi['status']);
  check('deactivated official loses page', $deadPage['status'] === 302 && str_contains($deadPage['headers'], 'login.php'), (string)$deadPage['status']);
  exec_sql($db, "UPDATE residents SET status='inactive' WHERE id=?", 'i', [$resident]);
  $deadResident = http($base . '/api/notifications/list.php', 'GET', null, [], $cookieFor('resident'));
  $deadResidentPage = http($base . '/pages/resident.php', 'GET', null, [], $cookieFor('resident'));
  check('deactivated resident loses API', $deadResident['status'] === 401, (string)$deadResident['status']);
  check('deactivated resident loses page', $deadResidentPage['status'] === 302, (string)$deadResidentPage['status']);
  exec_sql($db, "UPDATE barangay_officials SET status='active', role='admin', fname='Ada', lname='Admin', position='Captain' WHERE id=?", 'i', [$adminA]);
  exec_sql($db, "UPDATE residents SET status='active' WHERE id=?", 'i', [$resident]);
  $craft('adminA', ['official_id' => $adminA, 'official_role' => 'admin', 'official_name' => 'Ada Admin', 'official_position' => 'Captain']);
  $craft('resident', ['resident_id' => $resident, 'resident_name' => 'Rae Resident', 'resident_username' => 'fix-resident']);
  $dual = http($base . '/pages/community_complaints.php', 'GET', null, [], $cookieFor('dual'));
  check('dual session community stays official-blocked', $dual['status'] === 403 && str_contains($dual['body'], 'Resident access required'), (string)$dual['status']);
  exec_sql($db, "UPDATE barangay_officials SET status='inactive' WHERE id=?", 'i', [$adminA]);
  $dualFallback = http($base . '/pages/community_complaints.php', 'GET', null, [], $cookieFor('dual'));
  check('invalid official does not fall back to resident', $dualFallback['status'] === 302 && !str_contains($dualFallback['body'], 'Community concerns'), (string)$dualFallback['status']);
  exec_sql($db, "UPDATE barangay_officials SET status='active' WHERE id=?", 'i', [$adminA]);
  exec_sql($db, 'DELETE FROM barangay_officials WHERE id=?', 'i', [$staff]);
  $deleted = http($base . '/api/complaints/list.php', 'GET', null, [], $cookieFor('staff'));
  check('deleted official loses access', $deleted['status'] === 401, (string)$deleted['status']);

  // Evidence.
  $png = $storage . DIRECTORY_SEPARATOR . 'seed.png';
  $jpg = $storage . DIRECTORY_SEPARATOR . 'seed.jpg';
  make_image($png, 'png');
  make_image($jpg, 'jpeg');
  $legacyId = $ownComplaint;
  $legacyUuid = bin2hex(random_bytes(16));
  $legacyDir = FIX_ROOT . '/complaint_attachments/' . $legacyId;
  if (!is_dir($legacyDir)) mkdir($legacyDir, 0777, true);
  $legacyFile = $legacyDir . '/' . $legacyUuid . '.png';
  copy($png, $legacyFile);
  $legacyCreated[] = $legacyFile;
  $legacyKey = 'complaint_attachments/' . $legacyId . '/' . $legacyUuid . '.png';
  exec_sql($db, 'INSERT INTO complaint_attachments (complaint_id,file_path,mime,byte_size,uploaded_by_type,uploaded_by_id) VALUES (?,?,?,?,?,?)',
    'issisi', [$legacyId, $legacyKey, 'image/png', filesize($legacyFile), 'resident', $resident]);
  $legacyAttachment = (int)$db->insert_id;
  $view = http($base . '/api/complaints/view_attachment.php?id=' . $legacyAttachment, 'GET', null, [], $cookieFor('resident'));
  check('owner reads legacy evidence', $view['status'] === 200 && str_contains($view['headers'], 'image/png') && str_contains($view['headers'], 'private, no-store') && str_contains($view['headers'], 'nosniff') && str_starts_with($view['body'], "\x89PNG"), (string)$view['status']);
  check('legacy body hides storage key', !str_contains($view['body'], $legacyKey) && !str_contains($view['headers'], $legacyKey));
  $officialView = http($base . '/api/complaints/view_attachment.php?id=' . $legacyAttachment, 'GET', null, [], $cookieFor('adminA'));
  check('official reads legacy evidence', $officialView['status'] === 200, (string)$officialView['status']);
  $anon = http($base . '/api/complaints/view_attachment.php?id=' . $legacyAttachment);
  check('anonymous evidence is denied', $anon['status'] === 401 && $anon['body'] === '' || ($anon['status'] === 401 && !str_starts_with($anon['body'], "\x89PNG")), (string)$anon['status']);
  $otherView = http($base . '/api/complaints/view_attachment.php?id=' . $legacyAttachment, 'GET', null, [], $cookieFor('other'));
  check('other resident evidence is denied', $otherView['status'] === 403 && !str_starts_with($otherView['body'], "\x89PNG"), (string)$otherView['status']);
  exec_sql($db, "UPDATE residents SET status='inactive' WHERE id=?", 'i', [$resident]);
  $craft('resident', ['resident_id' => $resident, 'resident_name' => 'Rae Resident', 'resident_username' => 'fix-resident']);
  $inactiveView = http($base . '/api/complaints/view_attachment.php?id=' . $legacyAttachment, 'GET', null, [], $cookieFor('resident'));
  check('inactive owner evidence is denied', $inactiveView['status'] === 401 && !str_starts_with($inactiveView['body'], "\x89PNG"), (string)$inactiveView['status']);
  exec_sql($db, "UPDATE residents SET status='active' WHERE id=?", 'i', [$resident]);
  $craft('resident', ['resident_id' => $resident, 'resident_name' => 'Rae Resident', 'resident_username' => 'fix-resident']);
  exec_sql($db, 'UPDATE complaint_attachments SET deleted_at=NOW() WHERE id=?', 'i', [$legacyAttachment]);
  $deletedView = http($base . '/api/complaints/view_attachment.php?id=' . $legacyAttachment, 'GET', null, [], $cookieFor('resident'));
  check('soft-deleted evidence is denied', $deletedView['status'] === 404 && !str_starts_with($deletedView['body'], "\x89PNG"), (string)$deletedView['status']);
  exec_sql($db, 'UPDATE complaint_attachments SET deleted_at=NULL WHERE id=?', 'i', [$legacyAttachment]);
  $secret = 'C:/secret/not-evidence.png';
  exec_sql($db, 'UPDATE complaint_attachments SET file_path=? WHERE id=?', 'si', [$secret, $legacyAttachment]);
  $bad = http($base . '/api/complaints/view_attachment.php?id=' . $legacyAttachment, 'GET', null, [], $cookieFor('adminA'));
  check('unsafe key is rejected without the path', $bad['status'] === 404 && !str_contains($bad['body'], 'secret') && !str_contains($bad['body'], $storage), $bad['body']);
  exec_sql($db, 'UPDATE complaint_attachments SET file_path=? WHERE id=?', 'si', [$legacyKey, $legacyAttachment]);

  require FIX_ROOT . '/api/complaints/_attachments.php';
  $savedStorage = getenv('COMPLAINT_ATTACHMENT_STORAGE_DIR');
  putenv('COMPLAINT_ATTACHMENT_STORAGE_DIR');
  unset($_ENV['COMPLAINT_ATTACHMENT_STORAGE_DIR'], $_SERVER['COMPLAINT_ATTACHMENT_STORAGE_DIR']);
  $legacyResolved = complaint_attachment_resolve($legacyKey, $legacyId);
  check('legacy read does not need private storage', !empty($legacyResolved['ok']));
  $privateMissing = complaint_attachment_resolve('private:v1/' . $legacyId . '/' . $legacyUuid . '.png', $legacyId);
  check('private read requires the setting', empty($privateMissing['ok']));
  $noConfig = complaint_attachment_private_root(true);
  check('missing storage fails closed', empty($noConfig['ok']) && $noConfig['message'] === 'Complaint evidence storage is not configured.');
  putenv('COMPLAINT_ATTACHMENT_STORAGE_DIR=' . FIX_ROOT);
  $_ENV['COMPLAINT_ATTACHMENT_STORAGE_DIR'] = FIX_ROOT;
  $inside = complaint_attachment_private_root(true);
  check('project storage is rejected', empty($inside['ok']) && $inside['message'] === 'Complaint evidence storage is not available.');
  putenv('COMPLAINT_ATTACHMENT_STORAGE_DIR=' . $savedStorage);
  $_ENV['COMPLAINT_ATTACHMENT_STORAGE_DIR'] = $savedStorage;
  $_SERVER['DOCUMENT_ROOT'] = FIX_ROOT;
  $okRoot = complaint_attachment_private_root(true);
  check('temp storage outside the project is accepted', !empty($okRoot['ok']), $okRoot['message']);
  foreach (['complaint_attachments/1/../../' . $legacyUuid . '.png', 'C:/windows/win.ini', 'private:v1/01/' . $legacyUuid . '.png', 'private:v1/1/' . strtoupper($legacyUuid) . '.PNG'] as $unsafe) {
    check('parser rejects ' . $unsafe, empty(complaint_attachment_parse_key($unsafe)['ok']));
  }

  // Login and real multipart upload.
  $login = http($base . '/pages/login.php', 'POST', http_build_query(['action' => 'resident-login', 'username' => 'fix-resident', 'password' => 'Fix24-pass']), ['Content-Type: application/x-www-form-urlencoded']);
  $cookie = '';
  if (preg_match('/PHPSESSID=([^;]+)/', $login['headers'], $cookieMatch)) $cookie = 'PHPSESSID=' . $cookieMatch[1];
  check('resident login sets a session', $login['status'] === 302 && $cookie !== '', (string)$login['status']);
  $uploadFile = $storage . DIRECTORY_SEPARATOR . 'upload.jpg';
  copy($jpg, $uploadFile);
  $boundary = 'fix24boundary';
  $payload = "--$boundary\r\nContent-Disposition: form-data; name=\"complaint_id\"\r\n\r\n$ownComplaint\r\n"
    . "--$boundary\r\nContent-Disposition: form-data; name=\"photo\"; filename=\"a.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n"
    . file_get_contents($uploadFile) . "\r\n--$boundary--\r\n";
  $uploaded = http($base . '/api/complaints/upload_attachment.php', 'POST', $payload, ['Content-Type: multipart/form-data; boundary=' . $boundary], $cookie);
  $uploadedJson = json_decode($uploaded['body'], true);
  check('endpoint upload returns the authorized URL', $uploaded['status'] === 200 && is_array($uploadedJson) && str_contains((string)($uploadedJson['url'] ?? ''), 'view_attachment.php?id='), $uploaded['body']);
  $newId = (int)($uploadedJson['attachment_id'] ?? 0);
  $served = http($base . '/api/complaints/view_attachment.php?id=' . $newId, 'GET', null, [], $cookie);
  check('new private file is served', $served['status'] === 200 && str_contains($served['headers'], 'image/jpeg'), (string)$served['status']);
  $list = http($base . '/api/complaints/list_attachments.php?complaint_id=' . $ownComplaint, 'GET', null, [], $cookie);
  check('list uses the authorized URL', $list['status'] === 200 && str_contains($list['body'], 'view_attachment.php?id=') && !str_contains($list['body'], 'private:v1') && !str_contains($list['body'], $storage), $list['body']);

  // Concurrent cap from four live rows. Two OS processes, two connections.
  $capComplaint = $ownComplaint;
  $live = (int)(q($db, 'SELECT COUNT(*) AS c FROM complaint_attachments WHERE complaint_id=? AND deleted_at IS NULL', 'i', [$capComplaint])[0]['c'] ?? 0);
  while ($live < 4) {
    exec_sql($db, 'INSERT INTO complaint_attachments (complaint_id,file_path,mime,byte_size,uploaded_by_type,uploaded_by_id) VALUES (?,?,?,?,?,?)',
      'issisi', [$capComplaint, 'complaint_attachments/' . $capComplaint . '/' . bin2hex(random_bytes(16)) . '.png', 'image/png', 10, 'official', $adminB]);
    $live++;
  }
  $go = $storage . DIRECTORY_SEPARATOR . 'go';
  $jpeg1 = $storage . DIRECTORY_SEPARATOR . 'w1.jpg';
  $jpeg2 = $storage . DIRECTORY_SEPARATOR . 'w2.jpg';
  copy($jpg, $jpeg1);
  copy($jpg, $jpeg2);
  $ready1 = $storage . DIRECTORY_SEPARATOR . 'ready1';
  $ready2 = $storage . DIRECTORY_SEPARATOR . 'ready2';
  $result1 = $storage . DIRECTORY_SEPARATOR . 'result1.json';
  $result2 = $storage . DIRECTORY_SEPARATOR . 'result2.json';
  $envBase = $serverEnv;
  $envBase['FIX_DB'] = $dbName;
  $envBase['FIX_GO'] = $go;
  $envBase['FIX_COMPLAINT'] = (string)$capComplaint;
  $envBase['FIX_ACTOR_TYPE'] = 'official';
  $envBase['FIX_ACTOR_ID'] = (string)$adminB;
  $env1 = $envBase + ['FIX_READY' => $ready1, 'FIX_RESULT' => $result1, 'FIX_JPEG' => $jpeg1];
  $env2 = $envBase + ['FIX_READY' => $ready2, 'FIX_RESULT' => $result2, 'FIX_JPEG' => $jpeg2];
  [$p1, $pipes1] = spawn_php($env1, ['upload-worker']);
  [$p2, $pipes2] = spawn_php($env2, ['upload-worker']);
  $deadline = time() + 20;
  while ((!is_file($ready1) || !is_file($ready2)) && time() < $deadline) usleep(20000);
  file_put_contents($go, '1');
  fclose($pipes1[0]); fclose($pipes2[0]);
  stream_get_contents($pipes1[1]); stream_get_contents($pipes2[1]);
  fclose($pipes1[1]); fclose($pipes1[2]); fclose($pipes2[1]); fclose($pipes2[2]);
  proc_close($p1); proc_close($p2);
  $r1 = json_decode((string)@file_get_contents($result1), true);
  $r2 = json_decode((string)@file_get_contents($result2), true);
  $statuses = [($r1['status'] ?? 0), ($r2['status'] ?? 0)];
  sort($statuses);
  $liveAfter = (int)(q($db, 'SELECT COUNT(*) AS c FROM complaint_attachments WHERE complaint_id=? AND deleted_at IS NULL', 'i', [$capComplaint])[0]['c'] ?? 0);
  check('concurrent uploads allow exactly one', $statuses === [200, 422] && $liveAfter === 5, json_encode($statuses) . ' live=' . $liveAfter);

  // Move failure and confirmed insert rollback leave no orphan. Uncertain commit keeps the file.
  $moveJpeg = $storage . DIRECTORY_SEPARATOR . 'move.jpg';
  copy($jpg, $moveJpeg);
  $moveResult = complaint_attachment_store_upload($db, ['type' => 'official', 'id' => $adminB], $capComplaint, $moveJpeg, 'image/jpeg', (int)filesize($moveJpeg), true);
  check('non-upload move failure adds no row', empty($moveResult['ok']) && $liveAfter === (int)(q($db, 'SELECT COUNT(*) AS c FROM complaint_attachments WHERE complaint_id=? AND deleted_at IS NULL', 'i', [$capComplaint])[0]['c'] ?? 0));
  exec_sql($db, 'DELETE FROM complaint_attachments WHERE complaint_id=? AND file_path LIKE "private:v1/%"', 'i', [$capComplaint]);
  // Keep the complaint under the cap for the trigger test by removing two live rows' effect: delete extras down to 4.
  $extraIds = q($db, 'SELECT id FROM complaint_attachments WHERE complaint_id=? AND deleted_at IS NULL ORDER BY id DESC LIMIT 2', 'i', [$capComplaint]);
  if ($extraIds !== []) {
    $idList = implode(',', array_map(static fn(array $row): string => (string)(int)$row['id'], $extraIds));
    $db->query('UPDATE complaint_attachments SET deleted_at=NOW() WHERE id IN (' . $idList . ')');
  }
  $beforeTrigger = (int)(q($db, 'SELECT COUNT(*) AS c FROM complaint_attachments WHERE complaint_id=? AND deleted_at IS NULL', 'i', [$capComplaint])[0]['c'] ?? 0);
  if (!$db->query('CREATE TRIGGER fix_attach_fail BEFORE INSERT ON complaint_attachments FOR EACH ROW SIGNAL SQLSTATE "45000" SET MESSAGE_TEXT="injected insert failure"')) {
    throw new RuntimeException($db->error);
  }
  $failJpeg = $storage . DIRECTORY_SEPARATOR . 'fail.jpg';
  copy($jpg, $failJpeg);
  $beforeFiles = glob($storage . DIRECTORY_SEPARATOR . $capComplaint . DIRECTORY_SEPARATOR . '*') ?: [];
  $failResult = complaint_attachment_store_upload($db, ['type' => 'official', 'id' => $adminB], $capComplaint, $failJpeg, 'image/jpeg', (int)filesize($failJpeg), false);
  $afterTrigger = (int)(q($db, 'SELECT COUNT(*) AS c FROM complaint_attachments WHERE complaint_id=? AND deleted_at IS NULL', 'i', [$capComplaint])[0]['c'] ?? 0);
  $afterFiles = glob($storage . DIRECTORY_SEPARATOR . $capComplaint . DIRECTORY_SEPARATOR . '*') ?: [];
  $newFiles = array_values(array_diff($afterFiles, $beforeFiles));
  check('confirmed insert failure removes the new file', empty($failResult['ok']) && $afterTrigger === $beforeTrigger && $newFiles === [], json_encode($failResult) . ' new=' . count($newFiles));
  $db->query('DROP TRIGGER IF EXISTS fix_attach_fail');
  $ackLog = $storage . DIRECTORY_SEPARATOR . 'ack.log';
  ini_set('error_log', $ackLog);
  $uncertainJpeg = $storage . DIRECTORY_SEPARATOR . 'uncertain.jpg';
  copy($jpg, $uncertainJpeg);
  $beforeUncertain = glob($storage . DIRECTORY_SEPARATOR . $capComplaint . DIRECTORY_SEPARATOR . '*') ?: [];
  file_put_contents($ackLog, '');
  $uncertain = complaint_attachment_store_upload(
    $db,
    ['type' => 'official', 'id' => $adminB],
    $capComplaint,
    $uncertainJpeg,
    'image/jpeg',
    (int)filesize($uncertainJpeg),
    false,
    ['commit' => static fn(mysqli $conn): bool => false, 'rollback' => static fn(mysqli $conn): bool => false]
  );
  $addedUncertain = array_values(array_diff(glob($storage . DIRECTORY_SEPARATOR . $capComplaint . DIRECTORY_SEPARATOR . '*') ?: [], $beforeUncertain));
  $uncertainLog = (string)@file_get_contents($ackLog);
  check(
    'uncertain rollback keeps this request file',
    empty($uncertain['ok']) && count($addedUncertain) === 1 && is_file($addedUncertain[0]) && str_contains($uncertainLog, $addedUncertain[0]),
    'added=' . count($addedUncertain)
  );
  $db->rollback();
  if (isset($addedUncertain[0])) @unlink($addedUncertain[0]);

  exec_sql(
    $db,
    'INSERT INTO complaints (resident_id,reference_no,resident_name,category,severity,location_text,description,date_filed,status,official_note,is_public) VALUES (?,?,?,?,?,?,?,?,?,?,0)',
    'isssssssss',
    [$resident, 'FIX-ACK', 'Rae Resident', 'Noise / Public Disturbance', 'low', 'Purok 1', 'Ack complaint', 'Oct 24, 2026', 'open', '']
  );
  $ackComplaint = (int)$db->insert_id;
  $ackDir = $storage . DIRECTORY_SEPARATOR . $ackComplaint;

  $successJpeg = $storage . DIRECTORY_SEPARATOR . 'ack-success.jpg';
  copy($jpg, $successJpeg);
  $success = complaint_attachment_store_upload($db, ['type' => 'official', 'id' => $adminB], $ackComplaint, $successJpeg, 'image/jpeg', (int)filesize($successJpeg), false);
  $successRow = q($db, 'SELECT id, file_path FROM complaint_attachments WHERE complaint_id=? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', 'i', [$ackComplaint]);
  $successFile = $successRow ? private_attachment_file($storage, (string)$successRow[0]['file_path']) : '';
  check(
    'successful upload keeps its row and file',
    !empty($success['ok']) && str_contains((string)($success['url'] ?? ''), 'view_attachment.php?id=') && $successFile !== '' && is_readable($successFile),
    json_encode($success)
  );

  $falseJpeg = $storage . DIRECTORY_SEPARATOR . 'ack-false.jpg';
  copy($jpg, $falseJpeg);
  file_put_contents($ackLog, '');
  $falseResult = complaint_attachment_store_upload(
    $db,
    ['type' => 'official', 'id' => $adminB],
    $ackComplaint,
    $falseJpeg,
    'image/jpeg',
    (int)filesize($falseJpeg),
    false,
    ['commit' => static function (mysqli $conn): bool {
      if ($conn->commit() !== true) {
        throw new RuntimeException('inner commit failed');
      }
      return false;
    }]
  );
  $falseRow = q($db, 'SELECT id, file_path FROM complaint_attachments WHERE complaint_id=? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', 'i', [$ackComplaint]);
  $falseFile = $falseRow ? private_attachment_file($storage, (string)$falseRow[0]['file_path']) : '';
  $falseLog = (string)@file_get_contents($ackLog);
  check(
    'commit then false keeps that row and file',
    empty($falseResult['ok'])
      && $falseRow && (int)$falseRow[0]['id'] !== (int)($successRow[0]['id'] ?? 0)
      && $falseFile !== '' && is_readable($falseFile)
      && str_contains($falseLog, $falseFile)
      && str_contains($falseLog, 'preserved new file'),
    json_encode($falseResult) . ' file=' . $falseFile
  );

  $throwJpeg = $storage . DIRECTORY_SEPARATOR . 'ack-throw.jpg';
  copy($jpg, $throwJpeg);
  file_put_contents($ackLog, '');
  $throwResult = complaint_attachment_store_upload(
    $db,
    ['type' => 'official', 'id' => $adminB],
    $ackComplaint,
    $throwJpeg,
    'image/jpeg',
    (int)filesize($throwJpeg),
    false,
    ['commit' => static function (mysqli $conn): bool {
      if ($conn->commit() !== true) {
        throw new RuntimeException('inner commit failed');
      }
      throw new RuntimeException('commit acknowledgement lost');
    }]
  );
  $throwRow = q($db, 'SELECT id, file_path FROM complaint_attachments WHERE complaint_id=? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', 'i', [$ackComplaint]);
  $throwFile = $throwRow ? private_attachment_file($storage, (string)$throwRow[0]['file_path']) : '';
  $throwLog = (string)@file_get_contents($ackLog);
  check(
    'commit then throw keeps that row and file',
    empty($throwResult['ok'])
      && $throwRow && (int)$throwRow[0]['id'] !== (int)($falseRow[0]['id'] ?? 0)
      && $throwFile !== '' && is_readable($throwFile)
      && str_contains($throwLog, $throwFile)
      && str_contains($throwLog, 'preserved new file'),
    json_encode($throwResult) . ' file=' . $throwFile
  );

  $openJpeg = $storage . DIRECTORY_SEPARATOR . 'ack-open.jpg';
  copy($jpg, $openJpeg);
  $beforeOpenCount = (int)(q($db, 'SELECT COUNT(*) AS c FROM complaint_attachments WHERE complaint_id=? AND deleted_at IS NULL', 'i', [$ackComplaint])[0]['c'] ?? 0);
  $beforeOpenFiles = glob($ackDir . DIRECTORY_SEPARATOR . '*') ?: [];
  file_put_contents($ackLog, '');
  $openResult = complaint_attachment_store_upload(
    $db,
    ['type' => 'official', 'id' => $adminB],
    $ackComplaint,
    $openJpeg,
    'image/jpeg',
    (int)filesize($openJpeg),
    false,
    ['commit' => static fn(mysqli $conn): bool => false]
  );
  $afterOpenCount = (int)(q($db, 'SELECT COUNT(*) AS c FROM complaint_attachments WHERE complaint_id=? AND deleted_at IS NULL', 'i', [$ackComplaint])[0]['c'] ?? 0);
  $addedOpen = array_values(array_diff(glob($ackDir . DIRECTORY_SEPARATOR . '*') ?: [], $beforeOpenFiles));
  $openLog = (string)@file_get_contents($ackLog);
  check(
    'uncommitted attempt plus successful rollback keeps only the new file',
    empty($openResult['ok']) && $afterOpenCount === $beforeOpenCount && count($addedOpen) === 1 && is_readable($addedOpen[0]) && str_contains($openLog, $addedOpen[0]) && str_contains($openLog, 'preserved new file'),
    'count ' . $beforeOpenCount . '->' . $afterOpenCount . ' added=' . count($addedOpen)
  );

  // Recovery.
  file_put_contents($smsLog, '');
  $bodyA = json_encode(['account_type' => 'resident', 'username' => 'fix-resident']);
  $request = http($base . '/api/auth/request_otp.php', 'POST', $bodyA, ['Content-Type: application/json']);
  $again = http($base . '/api/auth/request_otp.php', 'POST', $bodyA, ['Content-Type: application/json']);
  check('first recovery code is issued', $request['status'] === 200 && str_contains($request['body'], 'reset code has been sent'), $request['body']);
  check('resend inside 60 seconds does not mint another code', $again['status'] === 200 && str_contains($again['body'], '60 seconds'), $again['body']);
  $codes = q($db, "SELECT id, code, used FROM otp_codes WHERE account_type='resident' AND account_id=? ORDER BY id", 'i', [$resident]);
  check('only one live code after the resend guard', count($codes) === 1 && (int)$codes[0]['used'] === 0);
  exec_sql($db, 'UPDATE otp_codes SET created_at=DATE_SUB(NOW(), INTERVAL 2 MINUTE) WHERE id=?', 'i', [(int)$codes[0]['id']]);
  $requestB = http($base . '/api/auth/request_otp.php', 'POST', $bodyA, ['Content-Type: application/json']);
  $codes = q($db, "SELECT id, code, used FROM otp_codes WHERE account_type='resident' AND account_id=? ORDER BY id", 'i', [$resident]);
  $codeA = (string)$codes[0]['code'];
  $codeB = (string)$codes[1]['code'];
  check('issuing B invalidates A', $requestB['status'] === 200 && (int)$codes[0]['used'] === 1 && (int)$codes[1]['used'] === 0, json_encode($codes));
  $resetB = http($base . '/api/auth/reset_password.php', 'POST', json_encode(['account_type' => 'resident', 'username' => 'fix-resident', 'code' => $codeB, 'new_password' => 'Fix24-new']), ['Content-Type: application/json']);
  $resetA = http($base . '/api/auth/reset_password.php', 'POST', json_encode(['account_type' => 'resident', 'username' => 'fix-resident', 'code' => $codeA, 'new_password' => 'Fix24-old']), ['Content-Type: application/json']);
  $resetB2 = http($base . '/api/auth/reset_password.php', 'POST', json_encode(['account_type' => 'resident', 'username' => 'fix-resident', 'code' => $codeB, 'new_password' => 'Fix24-again']), ['Content-Type: application/json']);
  $pw = q($db, 'SELECT password FROM residents WHERE id=?', 'i', [$resident])[0]['password'];
  check('reset with B works and A cannot reset again', $resetB['status'] === 200 && $resetA['status'] === 400 && password_verify('Fix24-new', $pw), $resetA['body']);
  check('B cannot be reused', $resetB2['status'] === 400 && password_verify('Fix24-new', $pw), $resetB2['body']);

  exec_sql($db, 'UPDATE otp_codes SET created_at=DATE_SUB(NOW(), INTERVAL 3 MINUTE) WHERE account_id=?', 'i', [$resident]);
  $verifyIssue = http($base . '/api/auth/request_otp.php', 'POST', $bodyA, ['Content-Type: application/json']);
  $verifyCode = (string)(q($db, "SELECT code FROM otp_codes WHERE account_type='resident' AND account_id=? AND used=0 ORDER BY id DESC LIMIT 1", 'i', [$resident])[0]['code'] ?? '');
  $verified = http($base . '/api/auth/verify_otp.php', 'POST', json_encode(['account_type' => 'resident', 'username' => 'fix-resident', 'code' => $verifyCode]), ['Content-Type: application/json']);
  $still = (int)(q($db, "SELECT used FROM otp_codes WHERE account_type='resident' AND account_id=? AND code=?", 'is', [$resident, $verifyCode])[0]['used'] ?? 1);
  $verifiedReset = http($base . '/api/auth/reset_password.php', 'POST', json_encode(['account_type' => 'resident', 'username' => 'fix-resident', 'code' => $verifyCode, 'new_password' => 'Fix24-verified']), ['Content-Type: application/json']);
  $pw = q($db, 'SELECT password FROM residents WHERE id=?', 'i', [$resident])[0]['password'];
  check('verify does not consume and reset still works', $verifyIssue['status'] === 200 && $verified['status'] === 200 && $still === 0 && $verifiedReset['status'] === 200 && password_verify('Fix24-verified', $pw));

  exec_sql($db, "INSERT INTO otp_codes (account_type,account_id,code,expires_at,used,created_at,attempts) VALUES ('official',?,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE),0,'2026-10-24 10:00:00',0)", 'is', [$adminB, '111111']);
  exec_sql($db, "INSERT INTO otp_codes (account_type,account_id,code,expires_at,used,created_at,attempts) VALUES ('official',?,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE),0,'2026-10-24 10:00:00',0)", 'is', [$adminB, '222222']);
  $equalReset = http($base . '/api/auth/reset_password.php', 'POST', json_encode(['account_type' => 'official', 'username' => 'fix-admin-b', 'code' => '111111', 'new_password' => 'Fix24-lower']), ['Content-Type: application/json']);
  $equalResetNew = http($base . '/api/auth/reset_password.php', 'POST', json_encode(['account_type' => 'official', 'username' => 'fix-admin-b', 'code' => '222222', 'new_password' => 'Fix24-higher']), ['Content-Type: application/json']);
  $officialPw = q($db, 'SELECT password FROM barangay_officials WHERE id=?', 'i', [$adminB])[0]['password'];
  check('equal timestamps select the later id', $equalReset['status'] === 400 && $equalResetNew['status'] === 200 && password_verify('Fix24-higher', $officialPw), $equalReset['body']);

  exec_sql($db, "INSERT INTO otp_codes (account_type,account_id,code,expires_at,used,attempts) VALUES ('official',?,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE),0,0)", 'is', [$adminB, '333333']);
  $raceBody = json_encode(['account_type' => 'official', 'username' => 'fix-admin-b', 'code' => '333333', 'new_password' => 'Race-one']);
  $raceBody2 = json_encode(['account_type' => 'official', 'username' => 'fix-admin-b', 'code' => '333333', 'new_password' => 'Race-two']);
  $envRace = $envBase;
  $envRace['FIX_DB'] = $dbName;
  $envRace['FIX_BODY'] = $raceBody;
  $envRace2 = $envRace;
  $envRace2['FIX_BODY'] = $raceBody2;
  [$rp1, $rpipes1] = spawn_php($envRace, ['reset-worker']);
  [$rp2, $rpipes2] = spawn_php($envRace2, ['reset-worker']);
  fclose($rpipes1[0]); fclose($rpipes2[0]);
  $raceOut1 = stream_get_contents($rpipes1[1]);
  $raceOut2 = stream_get_contents($rpipes2[1]);
  fclose($rpipes1[1]); fclose($rpipes1[2]); fclose($rpipes2[1]); fclose($rpipes2[2]);
  proc_close($rp1); proc_close($rp2);
  $raceSuccess = (int)str_contains($raceOut1, 'has been reset') + (int)str_contains($raceOut2, 'has been reset');
  $racePw = q($db, 'SELECT password FROM barangay_officials WHERE id=?', 'i', [$adminB])[0]['password'];
  $raceUsed = (int)(q($db, "SELECT used FROM otp_codes WHERE account_id=? AND code='333333'", 'i', [$adminB])[0]['used'] ?? 0);
  check('concurrent resets allow one success', $raceSuccess === 1 && $raceUsed === 1 && (password_verify('Race-one', $racePw) || password_verify('Race-two', $racePw)), trim($raceOut1 . $raceOut2));

  exec_sql($db, "INSERT INTO otp_codes (account_type,account_id,code,expires_at,used,attempts) VALUES ('official',?,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE),0,0)", 'is', [$adminB, '444444']);
  $wrongEnv = $envBase;
  $wrongEnv['FIX_DB'] = $dbName;
  $wrongEnv['FIX_BODY'] = json_encode(['account_type' => 'official', 'username' => 'fix-admin-b', 'code' => '000000']);
  $wrongEnv2 = $wrongEnv;
  [$wp1, $wpipes1] = spawn_php($wrongEnv, ['verify-worker']);
  [$wp2, $wpipes2] = spawn_php($wrongEnv2, ['verify-worker']);
  fclose($wpipes1[0]); fclose($wpipes2[0]);
  stream_get_contents($wpipes1[1]); stream_get_contents($wpipes2[1]);
  fclose($wpipes1[1]); fclose($wpipes1[2]); fclose($wpipes2[1]); fclose($wpipes2[2]);
  proc_close($wp1); proc_close($wp2);
  $attempts = (int)(q($db, "SELECT attempts FROM otp_codes WHERE account_id=? AND code='444444'", 'i', [$adminB])[0]['attempts'] ?? 0);
  check('concurrent wrong guesses share the cap', $attempts === 2, 'attempts=' . $attempts);
  for ($i = 0; $i < 3; $i++) {
    http($base . '/api/auth/verify_otp.php', 'POST', json_encode(['account_type' => 'official', 'username' => 'fix-admin-b', 'code' => '000000']), ['Content-Type: application/json']);
  }
  $capped = http($base . '/api/auth/verify_otp.php', 'POST', json_encode(['account_type' => 'official', 'username' => 'fix-admin-b', 'code' => '444444']), ['Content-Type: application/json']);
  $attempts = (int)(q($db, "SELECT attempts FROM otp_codes WHERE account_id=? AND code='444444'", 'i', [$adminB])[0]['attempts'] ?? 0);
  check('five wrong attempts block the right code', $capped['status'] === 400 && $attempts >= 5, 'attempts=' . $attempts);

  $beforeConsumePw = q($db, 'SELECT password FROM residents WHERE id=?', 'i', [$resident])[0]['password'];
  exec_sql($db, "INSERT INTO otp_codes (account_type,account_id,code,expires_at,used,attempts) VALUES ('resident',?,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE),0,0)", 'is', [$resident, '555555']);
  if (!$db->query('CREATE TRIGGER fix_consume_fail BEFORE UPDATE ON otp_codes FOR EACH ROW SIGNAL SQLSTATE "45000" SET MESSAGE_TEXT="consume failed"')) {
    throw new RuntimeException($db->error);
  }
  $consume = http($base . '/api/auth/reset_password.php', 'POST', json_encode(['account_type' => 'resident', 'username' => 'fix-resident', 'code' => '555555', 'new_password' => 'Should-Not-Stick']), ['Content-Type: application/json']);
  $afterConsumePw = q($db, 'SELECT password FROM residents WHERE id=?', 'i', [$resident])[0]['password'];
  $consumeUsed = (int)(q($db, "SELECT used FROM otp_codes WHERE account_id=? AND code='555555'", 'i', [$resident])[0]['used'] ?? 1);
  check('failed code consumption rolls back the password', $consume['status'] === 500 && hash_equals($beforeConsumePw, $afterConsumePw) && $consumeUsed === 0, $consume['body']);
  $db->query('DROP TRIGGER IF EXISTS fix_consume_fail');

  // SMS lookup failures stay non-fatal. send_sms is stubbed by defining it before the library in a child.
  $smsScript = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fix24-sms-' . getmypid() . '.php';
  file_put_contents($smsScript, <<<'PHP'
<?php
function send_sms(string $to, string $content): array {
  $GLOBALS['sms_calls'] = ($GLOBALS['sms_calls'] ?? 0) + 1;
  return ['ok' => true, 'error' => '', 'message_id' => 'stub'];
}
foreach (['HTTPSMS_API_KEY','HTTPSMS_FROM_NUMBER','HTTPSMS_SIM_SLOT','GEMINI_API_KEY'] as $k) {
  putenv($k.'='); $_ENV[$k]=''; $_SERVER[$k]='';
}
putenv('HTTPSMS_API_KEY=dummy'); $_ENV['HTTPSMS_API_KEY']='dummy'; $_SERVER['HTTPSMS_API_KEY']='dummy';
putenv('HTTPSMS_FROM_NUMBER=+639170000000'); $_ENV['HTTPSMS_FROM_NUMBER']='+639170000000'; $_SERVER['HTTPSMS_FROM_NUMBER']='+639170000000';
require 'C:/laragon/www/Capstone-System-COPY/db.php';
$DB_NAME = getenv('FIX_DB');
require 'C:/laragon/www/Capstone-System-COPY/api/common.php';
$mode = getenv('FIX_SMS_MODE');
$db = db_connection();
if ($mode === 'drop-contact') $db->query('ALTER TABLE residents DROP COLUMN contact');
if ($mode === 'drop-pref') $db->query('ALTER TABLE resident_sms_preferences DROP COLUMN complaints_enabled');
if ($mode === 'rename-log') $db->query('RENAME TABLE sms_log TO sms_log_hold');
$before = (int)$db->query('SELECT COUNT(*) AS c FROM complaints')->fetch_assoc()['c'];
$db->query("INSERT INTO complaints (resident_id,reference_no,resident_name,category,severity,location_text,description,date_filed,status,official_note,is_public) VALUES (NULL,'SMS-".$mode."','Saved','Noise / Public Disturbance','low','Purok 1','saved','Oct 24, 2026','open','',0)");
$notice = notify_resident((int)getenv('FIX_RESIDENT'), 'complaint_filed', 'hello', 'complaints_enabled');
$after = (int)$db->query('SELECT COUNT(*) AS c FROM complaints')->fetch_assoc()['c'];
echo json_encode(['notice'=>$notice,'before'=>$before,'after'=>$after,'calls'=>$GLOBALS['sms_calls'] ?? 0]);
PHP);
  foreach (['drop-contact' => 'Contact lookup failed.', 'drop-pref' => 'SMS preference lookup failed.', 'rename-log' => 'SMS cap lookup failed.'] as $smsMode => $reason) {
    $smsEnv = $envBase;
    $smsEnv['FIX_DB'] = $dbName;
    $smsEnv['FIX_SMS_MODE'] = $smsMode;
    $smsEnv['FIX_RESIDENT'] = (string)$resident;
    $proc = proc_open([FIX_PHP, '-d', 'disable_functions=curl_init,curl_exec', $smsScript], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, FIX_ROOT, $smsEnv);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
    $decoded = json_decode($out, true);
    check('sms ' . $smsMode . ' keeps the saved row', is_array($decoded) && ($decoded['notice']['reason'] ?? '') === $reason && (int)$decoded['after'] === (int)$decoded['before'] + 1 && (int)$decoded['calls'] === 0, trim($out . $err));
    if ($smsMode === 'drop-contact') {
      exec_sql($db, 'ALTER TABLE residents ADD COLUMN contact varchar(50) NOT NULL DEFAULT ""');
      exec_sql($db, 'UPDATE residents SET contact=? WHERE id=?', 'si', ['+639171110004', $resident]);
      exec_sql($db, 'UPDATE residents SET contact=? WHERE id=?', 'si', ['+639171110005', $other]);
    }
    if ($smsMode === 'drop-pref') $db->query('ALTER TABLE resident_sms_preferences ADD COLUMN complaints_enabled tinyint(1) NOT NULL DEFAULT 1');
    if ($smsMode === 'rename-log') $db->query('RENAME TABLE sms_log_hold TO sms_log');
  }
  @unlink($smsScript);

  $blank = proc_open([FIX_PHP, '-r', 'putenv("HTTPSMS_API_KEY="); $_ENV["HTTPSMS_API_KEY"]=""; $_SERVER["HTTPSMS_API_KEY"]=""; putenv("HTTPSMS_FROM_NUMBER="); $_ENV["HTTPSMS_FROM_NUMBER"]=""; $_SERVER["HTTPSMS_FROM_NUMBER"]=""; require "C:/laragon/www/Capstone-System-COPY/db.php"; $DB_NAME=getenv("FIX_DB"); require "C:/laragon/www/Capstone-System-COPY/api/common.php"; $n=notify_resident(1,"complaint_filed","x"); echo json_encode($n);'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, FIX_ROOT, ['FIX_DB' => $dbName, 'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows']);
  $blankOut = stream_get_contents($pipes[1]);
  fclose($pipes[1]); fclose($pipes[2]); proc_close($blank);
  check('sms not configured keeps its shape', str_contains($blankOut, 'not configured'), $blankOut);

  require __DIR__ . '/../complaint-interface/api-checks.php';

  // Community board.
  exec_sql($db, 'INSERT INTO complaints (resident_id,reference_no,resident_name,category,severity,location_text,description,date_filed,status,official_note,is_public) VALUES (?,?,?,?,?,?,?,?,?,?,1)',
    'isssssssss', [$resident, 'FIX-PUBLIC', 'SECRET-NAME', 'Noise / Public Disturbance', 'low', 'SECRET-PLACE', 'SECRET-TEXT', 'not-a-date', 'open', 'SECRET-NOTE']);
  $publicId = (int)$db->insert_id;
  exec_sql($db, 'INSERT INTO complaints (resident_id,reference_no,resident_name,category,severity,location_text,description,date_filed,status,official_note,is_public,duplicate_of_id) VALUES (?,?,?,?,?,?,?,?,?,?,1,?)',
    'isssssssssi', [$resident, 'FIX-CHILD', 'SECRET-CHILD', 'Noise / Public Disturbance', 'low', 'SECRET-PLACE', 'SECRET-CHILD-TEXT', 'Oct 24, 2026', 'open', '', $publicId]);
  $board = http($base . '/api/complaints/public_list.php', 'GET', null, [], $cookieFor('resident'));
  $boardJson = json_decode($board['body'], true);
  $card = null;
  foreach (($boardJson['complaints'] ?? []) as $item) if ((int)$item['id'] === $publicId) $card = $item;
  $keys = is_array($card) ? array_keys($card) : [];
  sort($keys);
  check('community card has the six public keys', $keys === ['category', 'filed_on', 'id', 'published_report_count', 'severity', 'status'], implode(',', $keys));
  check('malformed date stays null and private text stays out', is_array($card) && $card['filed_on'] === null && (int)$card['published_report_count'] === 2 && !str_contains($board['body'], 'SECRET-'));
  $officialBoard = http($base . '/api/complaints/public_list.php', 'GET', null, [], $cookieFor('adminB'));
  check('community board rejects officials', $officialBoard['status'] === 403, (string)$officialBoard['status']);

  file_put_contents(__DIR__ . '/server.json', json_encode([
    'base' => $base,
    'db' => $dbName,
    'storage' => $storage,
    'session' => $sessionPath,
    'resident_cookie' => $cookieFor('resident'),
    'admin_cookie' => $cookieFor('adminB'),
    'complaint_id' => $ownComplaint,
    'legacy_url' => '/complaint_attachments/' . $legacyId . '/' . $legacyUuid . '.png',
  ]));
  echo "SERVER $base\n";
  echo "DB $dbName\n";
} catch (Throwable $error) {
  check('runner exception', false, $error->getMessage());
}

$after = app_counts();
check('application database counts unchanged', $before['raw'] === $after['raw'], $before['raw'] . ' -> ' . $after['raw']);
foreach ($legacyCreated as $file) {
  if (is_file($file)) unlink($file);
  $dir = dirname($file);
  if (is_dir($dir) && count(array_diff(scandir($dir) ?: [], ['.', '..'])) === 0) rmdir($dir);
}
if (is_resource($server)) {
  // Leave the isolated server up for the browser pass. Cleanup is explicit.
  echo "SERVER_LEFT_RUNNING\n";
}
echo "TOTAL $passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
