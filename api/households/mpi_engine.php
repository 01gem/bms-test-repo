<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * MPI (Multidimensional Poverty Index) computation engine.
 *
 * Pure functions. No database access, no session, no HTTP output.
 * Include from endpoint files that own the DB/HTTP concerns, and from
 * database/test_mpi_engine.php for verification against the seed.
 *
 * Spec source: database/seed_households.sql header comment.
 *
 * Weights are counted in 24ths. Four dimensions, 6/24 each, split evenly
 * inside a dimension:
 *   Education              (2 indicators x 3) = 6
 *   Health / Nutrition     (3 indicators x 2) = 6
 *   Housing/Water/Sanitation (6 indicators x 1) = 6
 *   Employment             (2 indicators x 3) = 6
 *
 * MPI score = deprived_24ths / 24, rounded to 4 dp.
 * MPI status = 'poor' when score > 0.25, 'not_poor' otherwise.
 *              'incomplete' when any indicator input is NULL.
 */

const MPI_METHOD_VERSION = 'PSA-MPI-2025-v1';
const MPI_CUTOFF = 0.25;
const MPI_QUESTIONNAIRE_FIELDS = 47;

/** A value counts as present when it is not null and not the empty string. */
function mpi_present($value): bool {
  return $value !== null && $value !== '';
}

/** Cast a stored TINYINT (or null) to 0/1, treating null as 0. */
function mpi_bool($value): int {
  return mpi_present($value) ? ((int)(bool)$value) : 0;
}

/**
 * Names of the questionnaire fields on household_profiles, in schema order.
 * Excludes id, household_id and updated_at. Exactly 47 entries; keep in sync
 * with database/migration_household_profiling.sql.
 */
function mpi_questionnaire_field_names(): array {
  return [
    'num_children', 'num_working_age', 'num_seniors',
    'has_4ps', 'has_pwd_member', 'has_senior_member', 'is_solo_parent_hh',
    'children_5_17_total', 'children_5_17_not_in_school',
    'members_19_plus_total', 'has_19_plus_secondary_grad',
    'medical_need_6mo', 'medical_treatment_obtained',
    'distance_health_center_km', 'distance_hospital_km',
    'fies_worried_food', 'fies_no_nutritious', 'fies_few_food_types',
    'fies_skipped_meals', 'fies_ate_less', 'fies_ran_out',
    'fies_hungry', 'fies_whole_day_no_food',
    'labor_force_total', 'employed_count',
    'unemployed_seeking_count', 'employed_wanting_more_hours',
    'working_children_5_17', 'working_children_not_in_school',
    'housing_tenure', 'roof_material', 'wall_material', 'floor_area_sqm',
    'main_water_source', 'toilet_facility', 'shared_toilet',
    'has_electricity', 'has_internet', 'public_transport_access',
    'assets_json',
    'monthly_wages', 'monthly_business', 'monthly_remittances',
    'monthly_pension', 'monthly_govt_assistance', 'monthly_other',
    'contextual_flags_json',
  ];
}

/**
 * Percentage of the 47 questionnaire fields that are non-NULL.
 * Returns 0 for a missing profile. Matches the seed's hand-computed values.
 */
function mpi_profile_completeness(?array $profile): int {
  if ($profile === null) return 0;
  $filled = 0;
  foreach (mpi_questionnaire_field_names() as $key) {
    if (mpi_present($profile[$key] ?? null)) $filled++;
  }
  return (int)round($filled / MPI_QUESTIONNAIRE_FIELDS * 100);
}

/** Sum of the 8 FIES indicator booleans. Returns null when any input is missing. */
function mpi_fies_sum(?array $profile): ?int {
  if ($profile === null) return null;
  $keys = [
    'fies_worried_food', 'fies_no_nutritious', 'fies_few_food_types',
    'fies_skipped_meals', 'fies_ate_less', 'fies_ran_out',
    'fies_hungry', 'fies_whole_day_no_food',
  ];
  $sum = 0;
  foreach ($keys as $k) {
    $v = $profile[$k] ?? null;
    if (!mpi_present($v)) return null;
    $sum += ((int)(bool)$v);
  }
  return $sum;
}

/**
 * Number of items in the assets JSON array. Returns null on missing or
 * unparseable input. Accepts both a JSON string (as MySQL returns) and an
 * already-decoded array.
 */
function mpi_assets_count($assetsJson): ?int {
  if (!mpi_present($assetsJson)) return null;
  if (is_string($assetsJson)) {
    $decoded = json_decode($assetsJson, true);
  } elseif (is_array($assetsJson)) {
    $decoded = $assetsJson;
  } else {
    return null;
  }
  return is_array($decoded) ? count($decoded) : null;
}

/**
 * Compute a single indicator's value.
 *
 * @return int|null 1 = deprived, 0 = not deprived, null = missing input
 */
function mpi_indicator_value(string $name, ?array $profile): ?int {
  if ($profile === null) return null;

  switch ($name) {
    case 'school_attendance':
      $v = $profile['children_5_17_not_in_school'] ?? null;
      if (!mpi_present($v)) return null;
      return ((int)$v > 0) ? 1 : 0;

    case 'educational_attainment':
      $members = $profile['members_19_plus_total'] ?? null;
      $hasGrad = $profile['has_19_plus_secondary_grad'] ?? null;
      if (!mpi_present($members) || !mpi_present($hasGrad)) return null;
      return ((int)$members > 0 && mpi_bool($hasGrad) === 0) ? 1 : 0;

    case 'hunger':
      $a = $profile['fies_hungry'] ?? null;
      $b = $profile['fies_whole_day_no_food'] ?? null;
      if (!mpi_present($a) || !mpi_present($b)) return null;
      return (mpi_bool($a) === 1 || mpi_bool($b) === 1) ? 1 : 0;

    case 'food_insecurity':
      $sum = mpi_fies_sum($profile);
      if ($sum === null) return null;
      return ($sum >= 4) ? 1 : 0;

    case 'health_access':
      $need = $profile['medical_need_6mo'] ?? null;
      $got  = $profile['medical_treatment_obtained'] ?? null;
      $dist = $profile['distance_health_center_km'] ?? null;
      if (!mpi_present($need) || !mpi_present($got) || !mpi_present($dist)) return null;
      return ((mpi_bool($need) === 1 && mpi_bool($got) === 0) || ((float)$dist > 5)) ? 1 : 0;

    case 'assets':
      $count = mpi_assets_count($profile['assets_json'] ?? null);
      if ($count === null) return null;
      return ($count < 2) ? 1 : 0;

    case 'toilet':
      $tf = $profile['toilet_facility'] ?? null;
      $sh = $profile['shared_toilet'] ?? null;
      if (!mpi_present($tf) || !mpi_present($sh)) return null;
      return (in_array((string)$tf, ['None', 'Open pit'], true) || mpi_bool($sh) === 1) ? 1 : 0;

    case 'water':
      $w = $profile['main_water_source'] ?? null;
      if (!mpi_present($w)) return null;
      return in_array(
        (string)$w,
        ['Unprotected well', 'Unprotected spring', 'River/stream', 'Rainwater', 'Peddler'],
        true
      ) ? 1 : 0;

    case 'tenure':
      $t = $profile['housing_tenure'] ?? null;
      if (!mpi_present($t)) return null;
      return in_array((string)$t, ['Informal settler', 'Rent-free without consent'], true) ? 1 : 0;

    case 'materials':
      $roof = $profile['roof_material'] ?? null;
      $wall = $profile['wall_material'] ?? null;
      if (!mpi_present($roof) && !mpi_present($wall)) return null;
      $flags = ['Light', 'Salvaged', 'Makeshift'];
      return (in_array((string)$roof, $flags, true) || in_array((string)$wall, $flags, true)) ? 1 : 0;

    case 'electricity':
      $e = $profile['has_electricity'] ?? null;
      if (!mpi_present($e)) return null;
      return (mpi_bool($e) === 0) ? 1 : 0;

    case 'underemployment':
      $u = $profile['unemployed_seeking_count'] ?? null;
      $w = $profile['employed_wanting_more_hours'] ?? null;
      if (!mpi_present($u) || !mpi_present($w)) return null;
      return ((int)$u > 0 || (int)$w > 0) ? 1 : 0;

    case 'working_children':
      $wc = $profile['working_children_not_in_school'] ?? null;
      if (!mpi_present($wc)) return null;
      return ((int)$wc > 0) ? 1 : 0;
  }
  return null;
}

/**
 * Weights and dimension grouping.
 * Each entry: indicator name => weight in 24ths.
 */
function mpi_dimension_map(): array {
  return [
    'education' => [
      'school_attendance' => 3,
      'educational_attainment' => 3,
    ],
    'health_nutrition' => [
      'hunger' => 2,
      'food_insecurity' => 2,
      'health_access' => 2,
    ],
    'housing_water_sanitation' => [
      'assets' => 1,
      'toilet' => 1,
      'water' => 1,
      'tenure' => 1,
      'materials' => 1,
      'electricity' => 1,
    ],
    'employment' => [
      'underemployment' => 3,
      'working_children' => 3,
    ],
  ];
}

/**
 * Compute the MPI result for a household.
 *
 * Returns an array:
 *   indicators         name => 0|1|null
 *   dimension_scores   name => float|null (fraction of 24ths, 4 dp)
 *   deprived_24ths     int|null
 *   score              float|null
 *   status             'poor'|'not_poor'|'incomplete'
 */
function mpi_compute_indicators(?array $profile): array {
  $dimensions = mpi_dimension_map();

  $indicators = [];
  foreach ($dimensions as $indicatorWeights) {
    foreach ($indicatorWeights as $indicatorName => $_) {
      $indicators[$indicatorName] = mpi_indicator_value($indicatorName, $profile);
    }
  }

  $dimensionScores = [];
  $deprived24ths = 0;
  $anyNull = false;

  foreach ($dimensions as $dimName => $indicatorWeights) {
    $raw = 0;
    $missing = false;
    foreach ($indicatorWeights as $indicatorName => $weight) {
      $val = $indicators[$indicatorName];
      if ($val === null) {
        $missing = true;
        break;
      }
      $raw += $weight * $val;
    }
    if ($missing) {
      $dimensionScores[$dimName] = null;
      $anyNull = true;
    } else {
      $dimensionScores[$dimName] = round($raw / 24, 4);
      $deprived24ths += $raw;
    }
  }

  if ($anyNull) {
    return [
      'indicators' => $indicators,
      'dimension_scores' => $dimensionScores,
      'deprived_24ths' => null,
      'score' => null,
      'status' => 'incomplete',
    ];
  }

  $score = round($deprived24ths / 24, 4);
  $status = ($score > MPI_CUTOFF) ? 'poor' : 'not_poor';

  return [
    'indicators' => $indicators,
    'dimension_scores' => $dimensionScores,
    'deprived_24ths' => $deprived24ths,
    'score' => $score,
    'status' => $status,
  ];
}

/**
 * Compute annual + per-capita income and the income-poverty status.
 *
 * annual_household_income  = 12 * SUM(monthly_*)
 * annual_per_capita_income = annual / household_size
 * income_poverty_status    = 'below'        when per-capita <  threshold
 *                            'at_or_above'  when per-capita >= threshold
 *                            'cannot_determine' when all six monthly_* are NULL
 *                                              or no threshold is available
 */
function mpi_compute_income(?array $profile, int $householdSize, ?array $threshold): array {
  $fieldIds = [
    'monthly_wages', 'monthly_business', 'monthly_remittances',
    'monthly_pension', 'monthly_govt_assistance', 'monthly_other',
  ];

  $allNull = true;
  $monthlySum = 0.0;
  if ($profile !== null) {
    foreach ($fieldIds as $key) {
      if (mpi_present($profile[$key] ?? null)) {
        $allNull = false;
        $monthlySum += (float)$profile[$key];
      }
    }
  }

  if ($allNull) {
    return [
      'annual_household_income' => null,
      'annual_per_capita_income' => null,
      'income_poverty_status' => 'cannot_determine',
      'applied_threshold_id' => null,
    ];
  }

  $annual = round($monthlySum * 12, 2);
  $size = max(1, $householdSize);
  $perCapita = round($annual / $size, 2);

  $thresholdAmount = null;
  $thresholdId = null;
  if ($threshold !== null) {
    $thresholdId = isset($threshold['id']) ? (int)$threshold['id'] : null;
    $amount = $threshold['annual_per_capita_threshold'] ?? null;
    if (mpi_present($amount)) {
      $thresholdAmount = (float)$amount;
    }
  }

  if ($thresholdAmount === null) {
    return [
      'annual_household_income' => $annual,
      'annual_per_capita_income' => $perCapita,
      'income_poverty_status' => 'cannot_determine',
      'applied_threshold_id' => null,
    ];
  }

  $status = ($perCapita < $thresholdAmount) ? 'below' : 'at_or_above';

  return [
    'annual_household_income' => $annual,
    'annual_per_capita_income' => $perCapita,
    'income_poverty_status' => $status,
    'applied_threshold_id' => $thresholdId,
  ];
}

/**
 * Produce the full assessment row for UPDATE on `households`.
 *
 * @param array       $household  Row from `households` (uses household_size).
 * @param array|null  $profile    Row from `household_profiles` or null.
 * @param array|null  $threshold  Row from `poverty_thresholds` or null.
 * @return array<string,mixed> Column → value pairs ready to persist.
 */
function assess_household(array $household, ?array $profile, ?array $threshold): array {
  $householdSize = max(1, (int)($household['household_size'] ?? 1));

  $mpi = mpi_compute_indicators($profile);
  $income = mpi_compute_income($profile, $householdSize, $threshold);

  $completeness = mpi_profile_completeness($profile);
  $profileStatus = $completeness >= 100 ? 'complete'
    : ($completeness > 0 ? 'in_progress' : 'unprofiled');

  $assessmentComplete = (
    $profileStatus === 'complete'
    && $mpi['status'] !== 'incomplete'
    && $income['income_poverty_status'] !== 'cannot_determine'
  ) ? 1 : 0;

  $indicatorsJson = json_encode([
    'version' => MPI_METHOD_VERSION,
    'cutoff' => MPI_CUTOFF,
    'weights_24ths' => [
      'education' => 3,
      'health_nutrition' => 2,
      'housing_water_sanitation' => 1,
      'employment' => 3,
    ],
    'indicators' => $mpi['indicators'],
    'dimension_scores' => $mpi['dimension_scores'],
    'deprived_24ths' => $mpi['deprived_24ths'],
    'score' => $mpi['score'],
    'status' => $mpi['status'],
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

  return [
    'assessment_method_version' => MPI_METHOD_VERSION,
    'mpi_score' => $mpi['score'],
    'mpi_status' => $mpi['status'],
    'mpi_indicators_json' => $indicatorsJson,
    'annual_household_income' => $income['annual_household_income'],
    'annual_per_capita_income' => $income['annual_per_capita_income'],
    'income_poverty_status' => $income['income_poverty_status'],
    'applied_threshold_id' => $income['applied_threshold_id'],
    'profile_completeness' => $completeness,
    'profile_status' => $profileStatus,
    'assessment_complete' => $assessmentComplete,
  ];
}
