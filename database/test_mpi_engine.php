<?php
/**
 * BarangayLink — MPI engine integration test.
 *
 * Loads every HH-DEMO-* household from the test database, runs assess_household()
 * on the linked profile and threshold, and diffs the result against the values
 * the seed hard-codes. Any mismatch is an engine bug.
 *
 * Run:  php database/test_mpi_engine.php
 * Exit: 0 when every household matches, 1 on any mismatch or setup error.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../api/households/mpi_engine.php';

try {
  $db = get_db();
  $db->set_charset('utf8mb4');
} catch (Throwable $e) {
  fwrite(STDERR, "Could not connect to the database: {$e->getMessage()}\n");
  exit(1);
}

// get_db() resets reporting to MYSQLI_REPORT_OFF; re-enable strict mode after
// connecting so query/prepare failures below throw instead of returning false.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$result = $db->query("SELECT * FROM households WHERE household_code LIKE 'HH-DEMO-%' ORDER BY id ASC");
$households = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

if (!$households) {
  fwrite(STDERR, "No HH-DEMO-* households found. Run database/seed_demo_households.sql first.\n");
  exit(1);
}

$checked = 0;
$failed = 0;

foreach ($households as $h) {
  $householdId = (int)$h['id'];

  $stmt = $db->prepare('SELECT * FROM household_profiles WHERE household_id = ? LIMIT 1');
  $stmt->bind_param('i', $householdId);
  $stmt->execute();
  $profileRow = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  $threshold = null;
  if (!empty($h['applied_threshold_id'])) {
    $thresholdId = (int)$h['applied_threshold_id'];
    $stmt = $db->prepare('SELECT * FROM poverty_thresholds WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $thresholdId);
    $stmt->execute();
    $threshold = $stmt->get_result()->fetch_assoc();
    $stmt->close();
  }

  $computed = assess_household($h, $profileRow ?: null, $threshold);

  $expected = [
    'mpi_score'               => $h['mpi_score'] === null ? null : (float)$h['mpi_score'],
    'mpi_status'              => $h['mpi_status'],
    'annual_household_income' => $h['annual_household_income'] === null ? null : (float)$h['annual_household_income'],
    'annual_per_capita_income'=> $h['annual_per_capita_income'] === null ? null : (float)$h['annual_per_capita_income'],
    'income_poverty_status'   => $h['income_poverty_status'],
    'profile_completeness'    => (int)$h['profile_completeness'],
    'profile_status'          => $h['profile_status'],
    'assessment_complete'     => (int)$h['assessment_complete'],
  ];

  $mismatches = [];
  foreach ($expected as $key => $want) {
    $got = $computed[$key] ?? null;
    $same = (is_float($want) || is_float($got))
      ? (abs((float)$want - (float)$got) < 0.0001)
      : ($want === $got);
    if (!$same) {
      $mismatches[] = sprintf('%s: expected %s, got %s', $key, var_export($want, true), var_export($got, true));
    }
  }

  $checked++;
  $label = $h['household_code'];
  if ($mismatches) {
    $failed++;
    printf("[FAIL] %s (%s)\n", $label, $h['purok_zone'] ?? '-');
    foreach ($mismatches as $m) {
      printf("         %s\n", $m);
    }
  } else {
    printf(
      "[ OK ] %s  mpi=%-7s status=%-9s income=%-16s profile=%d%%\n",
      $label,
      $computed['mpi_score'] === null ? 'null' : number_format($computed['mpi_score'], 4),
      $computed['mpi_status'],
      $computed['income_poverty_status'],
      $computed['profile_completeness']
    );
  }
}

echo "\n";
if ($failed === 0) {
  printf("Results: %d/%d match\n", $checked, $checked);
  exit(0);
}
printf("Results: %d/%d match, %d failed\n", $checked - $failed, $checked, $failed);
exit(1);
