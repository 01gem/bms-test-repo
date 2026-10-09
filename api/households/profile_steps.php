<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

// Canonical definition of the 6-step household profiling wizard.
// Every field name here must exist in household_profiles and appear in
// mpi_questionnaire_field_names(). The 47 fields are partitioned with no
// overlap so a partial save from one step can never clobber another.

function profile_steps(): array {
  return [
    1 => [
      'slug'  => 'composition',
      'title' => 'Household Composition',
      'fields' => [
        'num_children', 'num_working_age', 'num_seniors',
        'has_4ps', 'has_pwd_member', 'has_senior_member', 'is_solo_parent_hh',
      ],
    ],
    2 => [
      'slug'  => 'education',
      'title' => 'Education',
      'fields' => [
        'children_5_17_total', 'children_5_17_not_in_school',
        'members_19_plus_total', 'has_19_plus_secondary_grad',
      ],
    ],
    3 => [
      'slug'  => 'health',
      'title' => 'Health & Nutrition',
      'fields' => [
        'medical_need_6mo', 'medical_treatment_obtained',
        'distance_health_center_km', 'distance_hospital_km',
        'fies_worried_food', 'fies_no_nutritious', 'fies_few_food_types',
        'fies_skipped_meals', 'fies_ate_less', 'fies_ran_out',
        'fies_hungry', 'fies_whole_day_no_food',
      ],
    ],
    4 => [
      'slug'  => 'housing',
      'title' => 'Housing & Utilities',
      'fields' => [
        'housing_tenure', 'roof_material', 'wall_material', 'floor_area_sqm',
        'main_water_source', 'toilet_facility', 'shared_toilet',
        'has_electricity', 'has_internet', 'public_transport_access',
        'assets_json',
      ],
    ],
    5 => [
      'slug'  => 'employment',
      'title' => 'Employment & Livelihood',
      'fields' => [
        'labor_force_total', 'employed_count',
        'unemployed_seeking_count', 'employed_wanting_more_hours',
        'working_children_5_17', 'working_children_not_in_school',
      ],
    ],
    6 => [
      'slug'  => 'income',
      'title' => 'Income & Context',
      'fields' => [
        'monthly_wages', 'monthly_business', 'monthly_remittances',
        'monthly_pension', 'monthly_govt_assistance', 'monthly_other',
        'contextual_flags_json',
      ],
    ],
  ];
}

/** Which step owns a given field, or 0 when unowned. */
function profile_step_for_field(string $field): int {
  foreach (profile_steps() as $n => $def) {
    if (in_array($field, $def['fields'], true)) return $n;
  }
  return 0;
}

function profile_step_fields(int $step): array {
  return profile_steps()[$step]['fields'] ?? [];
}

/** Column type map for value normalization on save. */
function profile_field_types(): array {
  $bool = ['has_4ps','has_pwd_member','has_senior_member','is_solo_parent_hh',
           'has_19_plus_secondary_grad','medical_need_6mo','medical_treatment_obtained',
           'fies_worried_food','fies_no_nutritious','fies_few_food_types','fies_skipped_meals',
           'fies_ate_less','fies_ran_out','fies_hungry','fies_whole_day_no_food',
           'shared_toilet','has_electricity','has_internet','public_transport_access'];
  $int = ['num_children','num_working_age','num_seniors',
          'children_5_17_total','children_5_17_not_in_school',
          'members_19_plus_total',
          'labor_force_total','employed_count',
          'unemployed_seeking_count','employed_wanting_more_hours',
          'working_children_5_17','working_children_not_in_school'];
  $dec = ['distance_health_center_km','distance_hospital_km','floor_area_sqm',
          'monthly_wages','monthly_business','monthly_remittances',
          'monthly_pension','monthly_govt_assistance','monthly_other'];
  $json = ['assets_json','contextual_flags_json'];
  $str  = ['housing_tenure','roof_material','wall_material',
           'main_water_source','toilet_facility'];

  $map = [];
  foreach ($bool as $f) $map[$f] = 'bool';
  foreach ($int as $f)  $map[$f] = 'int';
  foreach ($dec as $f)  $map[$f] = 'decimal';
  foreach ($json as $f) $map[$f] = 'json';
  foreach ($str as $f)  $map[$f] = 'string';
  return $map;
}

/** mysqli bind_param type character for a given column type. */
function profile_bind_char(string $type): string {
  return match ($type) {
    'int'     => 'i',
    'decimal' => 'd',
    default   => 's',
  };
}

/**
 * Convert an incoming JSON value into the PHP type that mysqli expects.
 * Empty string and explicit null both yield PHP null (SQL NULL).
 */
function profile_normalize_value(string $type, $raw) {
  if ($raw === null) return null;
  if (is_string($raw)) $raw = trim($raw);

  return match ($type) {
    'int'     => $raw === '' ? null : (int)$raw,
    'decimal' => $raw === '' ? null : (float)$raw,
    'bool'    => ($raw === '' || $raw === null) ? null
                  : ((!empty($raw) && $raw !== '0' && $raw !== 'false') ? 1 : 0),
    'json'    => $raw === '' ? null
                  : (is_array($raw) ? json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string)$raw),
    default   => $raw === '' ? null : (string)$raw,
  };
}

/**
 * First step that still has at least one NULL/empty field, or 7 if all
 * six steps are fully populated. Used to resume a wizard mid-flow.
 */
function profile_next_incomplete_step(?array $profile): int {
  foreach (profile_steps() as $n => $def) {
    foreach ($def['fields'] as $f) {
      if ($profile === null) return $n;
      $v = $profile[$f] ?? null;
      if ($v === null || $v === '') return $n;
    }
  }
  return 7; // all six steps complete
}
