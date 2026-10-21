<?php
/** Elapsed-hour SLA defaults selected for this project. */
function complaint_sla_hours(string $severity): int {
  return match (strtolower(trim($severity))) {
    'critical' => 24,
    'high' => 48,
    'low' => 168,
    default => 72,
  };
}
