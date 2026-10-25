<?php
// Optional browser fixture, restricted to the disposable regression schema.
if (PHP_SAPI !== 'cli') exit(1);
$serverConfig = __DIR__ . '/../review-2026-10-24/server.json';
if (!is_file($serverConfig)) {
  fwrite(STDERR, "Run php project-support/tests/review-2026-10-24/run.php first to generate the private test-server configuration.\n");
  exit(1);
}
$config = json_decode(file_get_contents($serverConfig), true);
if (!preg_match('/^capstone_fix24_[0-9]+$/', $config['db'] ?? '')) exit(1);
require dirname(__DIR__, 3) . '/db.php';
$DB_NAME = $config['db'];
$db = db_connection();
require dirname(__DIR__, 3) . '/api/complaints/_attachments.php';
putenv('COMPLAINT_ATTACHMENT_STORAGE_DIR=' . $config['storage']);
$_ENV['COMPLAINT_ATTACHMENT_STORAGE_DIR'] = $config['storage'];
$resident = (int)$db->query("SELECT id FROM residents WHERE username='fix-resident'")->fetch_assoc()['id'];
$official = (int)$db->query("SELECT id FROM barangay_officials WHERE username='fix-admin-a'")->fetch_assoc()['id'];
$existing = $db->query("SELECT id FROM complaints WHERE reference_no LIKE 'UI-VISUAL-CHECK%'")->fetch_assoc();
if ($existing) {
  $id = (int)$existing['id'];
  if (in_array($argv[1] ?? '', ['stress', 'restore'], true)) {
    $stress = $argv[1] === 'stress';
    $ref = $stress ? str_pad('UI-VISUAL-CHECK-', 50, 'R') : 'UI-VISUAL-CHECK';
    $description = $stress ? str_repeat("Long multiline report for scrolling.\n", 100) . str_repeat('LongWord', 60) : 'Road surface damage near the crossing. Please inspect the affected area.';
    $stmt = $db->prepare('UPDATE complaints SET reference_no=?,description=? WHERE id=?');
    $stmt->bind_param('ssi', $ref, $description, $id); $stmt->execute();
  }
  echo 'Fixture ready: ' . $id; exit;
}
$stmt = $db->prepare("INSERT INTO complaints (resident_id,reference_no,resident_name,category,severity,location_text,description,date_filed,status,official_note,extra_fields_json,assigned_official_id,sla_due_at) VALUES (?,'UI-VISUAL-CHECK','Rae Resident','Road / Infrastructure','medium','Test street, Purok 1','Road surface damage near the crossing. Please inspect the affected area.','Oct 25, 2026','open','Received. Pending review.',?, ?,DATE_ADD(NOW(), INTERVAL 24 HOUR))");
$extra = '{"road_issue":"pothole","access_impact":"partly_blocked"}';
$stmt->bind_param('isi', $resident, $extra, $official);
if (!$stmt->execute()) throw new RuntimeException($stmt->error);
$id = (int)$db->insert_id;
$stmt = $db->prepare("INSERT INTO complaint_updates (complaint_id,actor_type,actor_id,to_status,note) VALUES (?,'resident',?,'open','Complaint filed. Browser test fixture.')");
$stmt->bind_param('ii', $id, $resident); $stmt->execute();
for ($i = 1; $i <= 3; $i++) {
  // Neutral, clearly labelled test evidence; never use mockup photos as records.
  $im = imagecreatetruecolor(420, 260);
  imagefill($im, 0, 0, imagecolorallocate($im, 216, 228, 240));
  imagefilledrectangle($im, 30, 30, 390, 200, imagecolorallocate($im, 157, 181, 204));
  imagestring($im, 5, 80, 110, 'TEST EVIDENCE ' . $i, imagecolorallocate($im, 25, 48, 76));
  $file = $config['storage'] . '/ui-test-' . $i . '.png'; imagepng($im, $file); imagedestroy($im);
  $stored = complaint_attachment_store_upload($db, ['type' => 'official', 'id' => $official], $id, $file, 'image/png', filesize($file), false);
  if (empty($stored['ok'])) throw new RuntimeException(json_encode($stored));
}
echo "Visual fixture complaint: {$id}\n";
