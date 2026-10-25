<?php
// Included by the existing isolated review runner; never use application data.
if (PHP_SAPI !== 'cli' || !isset($dbName) || !str_starts_with($dbName, 'capstone_fix24_')) exit(1);
$complaintPost = static function (string $endpoint, array $body, ?string $cookie) use ($base): array {
  $response = http($base . '/api/complaints/' . $endpoint . '.php', 'POST', json_encode($body), ['Content-Type: application/json'], $cookie);
  $response['json'] = json_decode($response['body'], true);
  return $response;
};
$created = $complaintPost('create', [
  'category' => 'Noise / Public Disturbance', 'location_text' => 'Test location',
  'description' => 'Isolated interface regression complaint', 'severity' => 'medium',
], $cookieFor('resident'));
$interfaceId = (int)($created['json']['id'] ?? 0);
check('interface: resident creates isolated complaint', $created['status'] === 200 && $interfaceId > 0, $created['body']);
if (!$interfaceId) throw new RuntimeException('Interface fixture failed');
$interfaceRow = static fn() => q($db, 'SELECT * FROM complaints WHERE id=?', 'i', [$interfaceId])[0];
$interfaceCounts = static fn() => [
  q($db, 'SELECT COUNT(*) AS n FROM complaint_updates WHERE complaint_id=?', 'i', [$interfaceId])[0]['n'],
  q($db, 'SELECT COUNT(*) AS n FROM notifications WHERE resident_id=?', 'i', [$resident])[0]['n'],
];
check('interface: new default note is pending review', $interfaceRow()['official_note'] === 'Received. Pending review.');
exec_sql($db, 'UPDATE complaints SET assigned_official_id=?,sla_due_at=DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE id=?', 'ii', [$adminB, $interfaceId]);
$assignmentBefore = [$interfaceRow()['assigned_official_id'], $interfaceRow()['sla_due_at']];
$initial = $interfaceCounts();
$unchanged = $complaintPost('update_status', ['id' => $interfaceId, 'status' => 'open'], $cookieFor('adminB'));
check('interface: omitted fields and unchanged submissions preserve history', $unchanged['status'] === 200 && $unchanged['json']['changed'] === false && $initial === $interfaceCounts() && $interfaceRow()['severity'] === 'medium');
$note = "Reviewed <script>alert('test')</script> & evidence.\nFollow up tomorrow.";
$saved = $complaintPost('update_status', ['id' => $interfaceId, 'status' => 'investigating', 'severity' => 'high', 'official_note' => $note], $cookieFor('adminB'));
check('interface: status severity multiline note persist', $saved['status'] === 200 && $interfaceRow()['official_note'] === $note && $interfaceRow()['severity'] === 'high' && $interfaceRow()['status'] === 'investigating');
check('interface: management updates preserve assignment and response target', $assignmentBefore === [$interfaceRow()['assigned_official_id'], $interfaceRow()['sla_due_at']]);
$afterSave = $interfaceCounts();
check('interface: one history entry and one resident notification on transition', (int)$afterSave[0] === (int)$initial[0] + 1 && (int)$afterSave[1] === (int)$initial[1] + 1);
$same = $complaintPost('update_status', ['id' => $interfaceId, 'status' => 'investigating', 'severity' => 'high', 'official_note' => $note], $cookieFor('adminB'));
check('interface: identical save creates no duplicate history or notification', $same['json']['changed'] === false && $afterSave === $interfaceCounts());
foreach (['update_status', 'notify_resolved'] as $endpoint) {
  foreach ([str_repeat('x', 256), ['bad'], null] as $badNote) {
    $bad = $complaintPost($endpoint, ['id' => $interfaceId, 'status' => 'investigating', 'official_note' => $badNote], $cookieFor('adminB'));
    check('interface: ' . $endpoint . ' rejects invalid note ' . gettype($badNote), $bad['status'] === 400 && $interfaceRow()['official_note'] === $note && $afterSave === $interfaceCounts());
  }
  foreach ([null, $cookieFor('resident'), $cookieFor('other')] as $unauthorized) {
    $bad = $complaintPost($endpoint, ['id' => $interfaceId, 'status' => 'resolved', 'official_note' => 'forbidden'], $unauthorized);
    check('interface: ' . $endpoint . ' refuses unauthorized mutation', in_array($bad['status'], [401, 403], true) && $interfaceRow()['official_note'] === $note);
  }
}
$unicodeNote = str_repeat('😀', 255);
$unicode = $complaintPost('update_status', ['id' => $interfaceId, 'status' => 'investigating', 'official_note' => $unicodeNote], $cookieFor('adminB'));
check('interface: 255 Unicode characters fit without truncation', $unicode['status'] === 200 && $interfaceRow()['official_note'] === $unicodeNote);
$resolved = $complaintPost('notify_resolved', ['id' => $interfaceId, 'severity' => 'critical', 'official_note' => $note], $cookieFor('adminB'));
check('interface: resolve preserves pending resident response and skips external SMS', $resolved['status'] === 200 && $interfaceRow()['resident_confirmation'] === 'pending' && $resolved['json']['sms_status'] === 'skipped');
$resolvedCounts = $interfaceCounts();
$resolutionId = (int)q($db, "SELECT MAX(id) AS n FROM complaint_updates WHERE complaint_id=? AND to_status='resolved' AND from_status<>'resolved'", 'i', [$interfaceId])[0]['n'];
$again = $complaintPost('notify_resolved', ['id' => $interfaceId, 'severity' => 'critical', 'official_note' => $note], $cookieFor('adminB'));
check('interface: repeat resolution is idempotent', $again['json']['changed'] === false && $resolvedCounts === $interfaceCounts());
$confirmed = $complaintPost('confirm_resolution', ['complaint_id' => $interfaceId, 'expected_resolution_id' => $resolutionId], $cookieFor('resident'));
check('interface: resident can confirm resolution', $confirmed['status'] === 200 && $interfaceRow()['resident_confirmation'] === 'confirmed');
$onlyNote = $complaintPost('notify_resolved', ['id' => $interfaceId, 'official_note' => 'Follow-up note'], $cookieFor('adminB'));
check('interface: note-only resolved edit preserves confirmation and omitted severity', $onlyNote['status'] === 200 && $interfaceRow()['resident_confirmation'] === 'confirmed' && $interfaceRow()['severity'] === 'critical');
$reopened = $complaintPost('reopen_complaint', ['complaint_id' => $interfaceId, 'expected_resolution_id' => $resolutionId, 'reason' => 'Issue remains'], $cookieFor('resident'));
check('interface: resident can reopen resolution', $reopened['status'] === 200 && $interfaceRow()['status'] === 'open' && $interfaceRow()['resident_confirmation'] === 'reopened');
$clear = $complaintPost('update_status', ['id' => $interfaceId, 'status' => 'open', 'official_note' => ''], $cookieFor('adminB'));
check('interface: explicit empty note clears it and preserves reopened response', $clear['status'] === 200 && $interfaceRow()['official_note'] === '' && $interfaceRow()['resident_confirmation'] === 'reopened');
$timeline = http($base . '/api/complaints/timeline.php?complaint_id=' . $interfaceId, 'GET', null, [], $cookieFor('adminB'));
$updates = json_decode($timeline['body'], true)['updates'] ?? [];
$keys = array_map(static fn($u) => $u['created_at'] . str_pad((string)$u['id'], 12, '0', STR_PAD_LEFT), $updates);
$sorted = $keys; sort($sorted);
check('interface: timeline reads chronologically', count($keys) > 2 && $keys === $sorted);
$commentBefore = (int)q($db, 'SELECT COUNT(*) AS n FROM complaint_comments WHERE complaint_id=?', 'i', [$interfaceId])[0]['n'];
$comment = $complaintPost('add_comment', ['complaint_id' => $interfaceId, 'body' => "Safe text <img src=x onerror=alert(1)>\nSecond line"], $cookieFor('adminB'));
$tooLong = $complaintPost('add_comment', ['complaint_id' => $interfaceId, 'body' => str_repeat('x', 2001)], $cookieFor('adminB'));
check('interface: one comment persists and 2000-character limit remains', $comment['status'] === 200 && $tooLong['status'] === 422 && (int)q($db, 'SELECT COUNT(*) AS n FROM complaint_comments WHERE complaint_id=?', 'i', [$interfaceId])[0]['n'] === $commentBefore + 1);
$list = http($base . '/api/complaints/list.php', 'GET', null, [], $cookieFor('adminB'));
$rows = json_decode($list['body'], true)['complaints'] ?? [];
$testRows = array_values(array_filter($rows, static fn($row) => (int)$row['id'] === $interfaceId));
check('interface: list returns real attachment count and stored note', count($testRows) === 1 && (int)$testRows[0]['attachment_count'] === 0 && $testRows[0]['official_note'] === '');
$unauthorizedPhotos = http($base . '/api/complaints/list_attachments.php?complaint_id=' . $interfaceId, 'GET', null, [], $cookieFor('other'));
check('interface: other resident cannot read attachment list', $unauthorizedPhotos['status'] === 403);
