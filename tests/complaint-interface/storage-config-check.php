<?php
// CLI-only regression for request-local private evidence configuration.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__, 2) . '/api/complaints/_attachments.php';

$storageKey = 'COMPLAINT_ATTACHMENT_STORAGE_DIR';
$originalProcess = getenv($storageKey);
$originalEnv = $_ENV;
$originalServer = $_SERVER;
$privateDirectory = sys_get_temp_dir() . '/complaint-storage-' . bin2hex(random_bytes(6));
$otherDirectory = sys_get_temp_dir() . '/complaint-storage-' . bin2hex(random_bytes(6));
mkdir($privateDirectory, 0755);
mkdir($otherDirectory, 0755);
$_SERVER['DOCUMENT_ROOT'] = dirname(dirname(__DIR__, 2));
$passed = 0;
$failed = 0;
$check = static function (string $name, bool $ok) use (&$passed, &$failed): void {
  if ($ok) { $passed++; echo "PASS $name\n"; }
  else { $failed++; echo "FAIL $name\n"; }
};
try {
  // Reproduce Apache losing the process environment while the request snapshot remains.
  putenv($storageKey);
  unset($_SERVER[$storageKey]);
  $_ENV[$storageKey] = $privateDirectory;
  $root = complaint_attachment_private_root(true);
  $check('upload uses request-local configuration after getenv is lost', $root['ok'] && $root['path'] === realpath($privateDirectory));
  $check('attachment reads use the same stable configuration', complaint_attachment_private_root(false)['ok']);

  putenv($storageKey . '=' . $otherDirectory);
  $root = complaint_attachment_private_root(true);
  $check('request snapshot takes precedence over another process value', $root['ok'] && $root['path'] === realpath($privateDirectory));

  unset($_ENV[$storageKey]);
  putenv($storageKey);
  $_SERVER[$storageKey] = $privateDirectory;
  $check('server-provided request configuration is supported', complaint_attachment_private_root(true)['ok']);

  putenv($storageKey . '=' . $privateDirectory);
  $_ENV[$storageKey] = '';
  $root = complaint_attachment_private_root(true);
  $check('explicit empty request configuration still fails closed', !$root['ok'] && $root['error'] === 'missing');

  unset($_ENV[$storageKey], $_SERVER[$storageKey]);
  putenv($storageKey);
  $root = complaint_attachment_private_root(true);
  $check('missing configuration still fails closed', !$root['ok'] && $root['error'] === 'missing');

  putenv($storageKey . '=' . $privateDirectory);
  $check('process-only configuration remains compatible', complaint_attachment_private_root(true)['ok']);

  $_ENV[$storageKey] = dirname(__DIR__, 2);
  $root = complaint_attachment_private_root(true);
  $check('a served request-local directory is rejected without process fallback', !$root['ok'] && $root['error'] === 'served');

  $_ENV[$storageKey] = $privateDirectory . '/missing';
  $root = complaint_attachment_private_root(true);
  $check('an unavailable private directory is rejected', !$root['ok'] && $root['error'] === 'unusable');
} finally {
  if ($originalProcess === false) putenv($storageKey);
  else putenv($storageKey . '=' . $originalProcess);
  $_ENV = $originalEnv;
  $_SERVER = $originalServer;
  rmdir($privateDirectory);
  rmdir($otherDirectory);
}
echo "STORAGE $passed passed, $failed failed\n";
exit($failed ? 1 : 0);
