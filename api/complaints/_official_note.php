<?php
// Match the existing VARCHAR(255) column; omitted notes remain untouched.
function complaint_read_official_note(array $input): ?string {
  if (!array_key_exists('official_note', $input)) return null;
  if (!is_string($input['official_note'])) json_error('Official note must be text.');
  $note = trim($input['official_note']);
  if (preg_match('//u', $note) !== 1 || preg_match_all('/./us', $note) > 255) {
    json_error('Official note must contain at most 255 characters.');
  }
  return $note;
}
