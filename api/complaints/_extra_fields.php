<?php
/** One schema for complaint validation, form choices, and display labels. */
function complaint_extra_fields_schema(): array {
  return [
    'Road / Infrastructure' => [
      'road_issue' => ['label' => 'Infrastructure issue', 'options' => [
        'pothole' => 'Pothole', 'damaged_road' => 'Damaged road',
        'streetlight' => 'Streetlight', 'sidewalk' => 'Sidewalk', 'other' => 'Other',
      ]],
      'access_impact' => ['label' => 'Access impact', 'options' => [
        'passable' => 'Passable', 'partly_blocked' => 'Partly blocked', 'blocked' => 'Blocked',
      ]],
    ],
    'Noise / Public Disturbance' => [
      'noise_source' => ['label' => 'Noise source', 'options' => [
        'music' => 'Music', 'construction' => 'Construction', 'vehicles' => 'Vehicles',
        'gathering' => 'Gathering', 'other' => 'Other',
      ]],
      'usual_time' => ['label' => 'Usual time', 'options' => [
        'daytime' => 'Daytime', 'evening' => 'Evening', 'overnight' => 'Overnight', 'varies' => 'Varies',
      ]],
    ],
    'Flooding / Drainage' => [
      'drainage_issue' => ['label' => 'Drainage issue', 'options' => [
        'blocked_drain' => 'Blocked drain', 'flooding' => 'Flooding',
        'standing_water' => 'Standing water', 'other' => 'Other',
      ]],
      'affected_area' => ['label' => 'Affected area', 'options' => [
        'one_property' => 'One property', 'street' => 'Street', 'several_streets' => 'Several streets',
      ]],
    ],
    'Public Safety' => [
      'safety_hazard' => ['label' => 'Safety concern', 'options' => [
        'poor_lighting' => 'Poor lighting', 'unsafe_structure' => 'Unsafe structure',
        'blocked_path' => 'Blocked path', 'other' => 'Other',
      ]],
      'ongoing' => ['label' => 'Is the concern still present?', 'options' => [
        'yes' => 'Yes', 'no' => 'No', 'unsure' => 'Unsure',
      ]],
    ],
    'Other' => [],
  ];
}

/** Strict writes. Raw nested JSON must retain stdClass vs array identity. */
function complaint_extra_fields_validate(string $category, mixed $raw): array {
  if ($raw === null) return [];
  if (!$raw instanceof stdClass) {
    throw new InvalidArgumentException('Additional details must be a JSON object.');
  }
  $schema = complaint_extra_fields_schema()[$category] ?? [];
  $clean = [];
  foreach (get_object_vars($raw) as $key => $value) {
    if (!array_key_exists($key, $schema)) {
      throw new InvalidArgumentException('An additional field is not valid for this category.');
    }
    if (!is_string($value)) {
      throw new InvalidArgumentException('Additional detail values must be text options.');
    }
    $value = trim($value);
    if ($value === '') continue;
    if (!array_key_exists($value, $schema[$key]['options'])) {
      throw new InvalidArgumentException('An additional detail option is not valid.');
    }
    $clean[$key] = $value;
  }
  return $clean;
}

/** Tolerant reads: never expose unknown stored keys or invalid enum values. */
function complaint_extra_fields_read(string $category, ?string $json): array {
  if ($json === null || $json === '') return [];
  $raw = json_decode($json);
  if (!$raw instanceof stdClass) return [];
  $schema = complaint_extra_fields_schema()[$category] ?? [];
  $clean = [];
  foreach ($schema as $key => $field) {
    if (!property_exists($raw, $key) || !is_string($raw->{$key})) continue;
    $value = trim($raw->{$key});
    if (array_key_exists($value, $field['options'])) $clean[$key] = $value;
  }
  return $clean;
}

/** Human-readable projection in schema order, shared by both portals. */
function complaint_extra_fields_display(string $category, array $values): array {
  $display = [];
  foreach (complaint_extra_fields_schema()[$category] ?? [] as $key => $field) {
    $value = $values[$key] ?? null;
    if (!is_string($value) || !array_key_exists($value, $field['options'])) continue;
    $display[] = ['label' => $field['label'], 'value' => $field['options'][$value]];
  }
  return $display;
}
