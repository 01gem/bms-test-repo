<?php
require_once __DIR__ . '/_generation.php';
function xml_safe_text(string $value): string {
  return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function remove_dir_tree(string $path): void {
  if (!is_dir($path)) return;
  $entries = scandir($path);
  if ($entries === false) return;
  foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $full = $path . DIRECTORY_SEPARATOR . $entry;
    if (is_dir($full)) {
      remove_dir_tree($full);
    } elseif (file_exists($full)) {
      @unlink($full);
    }
  }
  @rmdir($path);
}


function request_render_error(string $message, int $status = 500): void { throw new RequestProblem($message, $status, 'conversion_failed'); }
function request_render_document(array $request, array $submittedFields, string $tempDir, string $operation): array {
$fixedOfficialName = 'Hon. Erwin Astronomo';
$fixedOfficialPosition = 'Barangay Captain';
$referenceNo = $operation;
$templateMap = [
  'Barangay Clearance' => [
    'file' => 'barangay_clearance.docx',
    'placeholders' => ['full_name', 'age', 'civil_status', 'address', 'purpose', 'date_issued', 'reference_no', 'official_name', 'official_position'],
  ],
  'Certificate of Residency' => [
    'file' => 'certificate_of_residency.docx',
    'placeholders' => ['full_name', 'address', 'years_of_residency', 'purpose', 'date_issued', 'reference_no', 'official_name', 'official_position'],
  ],
  'Certificate of Indigency' => [
    'file' => 'certificate_of_indigency.docx',
    'placeholders' => ['full_name', 'address', 'purpose', 'date_issued', 'reference_no', 'official_name', 'official_position'],
  ],
  'Certificate of Good Moral Character' => [
    'file' => 'certificate_of_good_moral_character.docx',
    'placeholders' => ['full_name', 'address', 'purpose', 'date_issued', 'reference_no', 'official_name', 'official_position'],
  ],
  'Business Permit Endorsement' => [
    'file' => 'business_permit_endorsement.docx',
    'placeholders' => ['owner_name', 'business_name', 'business_type', 'business_address', 'purpose', 'date_issued', 'reference_no', 'official_name', 'official_position'],
  ],
];

$documentType = (string)($request['document_type'] ?? '');
if (!isset($templateMap[$documentType])) {
  request_render_error("No Word template mapping found for document type: {$documentType}", 400);
}

$templateFile = $templateMap[$documentType]['file'];
$placeholderKeys = $templateMap[$documentType]['placeholders'];
$templatePath = __DIR__ . '/../../document_templates/' . $templateFile;
if (!file_exists($templatePath)) {
  request_render_error("Template file not found: {$templateFile}", 500);
}

$generatedFields = [];
foreach ($submittedFields as $key => $value) {
  if (!is_string($key)) continue;
  if (is_scalar($value) || $value === null) {
    $generatedFields[$key] = trim((string)$value);
  }
}

$residentFullName = trim((string)($request['resident_name'] ?? ''));
if ($residentFullName === '') {
  $residentFullName = trim(((string)($request['fname'] ?? '')) . ' ' . ((string)($request['lname'] ?? '')));
}
$residentAddress = trim((string)($request['address'] ?? ''));

if (($generatedFields['full_name'] ?? '') === '' && $residentFullName !== '') {
  $generatedFields['full_name'] = $residentFullName;
}
if (($generatedFields['owner_name'] ?? '') === '' && $residentFullName !== '') {
  $generatedFields['owner_name'] = $residentFullName;
}
if (($generatedFields['address'] ?? '') === '' && $residentAddress !== '') {
  $generatedFields['address'] = $residentAddress;
}
if (($generatedFields['business_address'] ?? '') === '' && $residentAddress !== '') {
  $generatedFields['business_address'] = $residentAddress;
}
if (($generatedFields['purpose'] ?? '') === '') {
  $generatedFields['purpose'] = trim((string)($request['purpose'] ?? ''));
}
$generatedFields['reference_no'] = (string)($request['reference_no'] ?? '');
if (($generatedFields['date_issued'] ?? '') === '') {
  $generatedFields['date_issued'] = date('F d, Y');
}
$generatedFields['official_name'] = $fixedOfficialName;
$generatedFields['official_position'] = $fixedOfficialPosition;

// Resident-supplied fields from the request form fill any placeholder the
// official left blank. Explicit values typed in the modal still win.
$storedFields = json_decode((string)($request['request_fields_json'] ?? ''), true);
if (is_array($storedFields)) {
  foreach ($storedFields as $k => $v) {
    if (!isset($generatedFields[$k]) || $generatedFields[$k] === '') {
      $generatedFields[$k] = is_scalar($v) ? (string)$v : '';
    }
  }
}

// Derive age from the resident's birthdate when neither the modal nor the
// stored fields supplied one. Barangay Clearance needs this placeholder.
if (($generatedFields['age'] ?? '') === '' && !empty($request['birthdate'])) {
  try {
    $birth = new DateTime((string)$request['birthdate']);
    $generatedFields['age'] = (string)$birth->diff(new DateTime('now'))->y;
  } catch (Throwable $e) {
    // Leave blank; the placeholder resolves to empty in the DOCX.
  }
}

$workingDocx = $tempDir . DIRECTORY_SEPARATOR . $referenceNo . '.docx';
if (!copy($templatePath, $workingDocx)) {
  request_render_error('Failed to create working document from template.', 500);
}

if (!class_exists('ZipArchive')) {
  request_render_error('PHP ZipArchive extension is not enabled.', 500);
}

$zip = new ZipArchive();
if ($zip->open($workingDocx) !== true) {
  request_render_error('Failed to open template document archive.', 500);
}

$documentXml = $zip->getFromName('word/document.xml');
if ($documentXml === false) {
  $zip->close();
  request_render_error('Template is missing word/document.xml.', 500);
}

foreach ($placeholderKeys as $key) {
  $value = $generatedFields[$key] ?? '';
  $documentXml = str_replace('{{' . $key . '}}', xml_safe_text((string)$value), $documentXml);
}

if (!$zip->addFromString('word/document.xml', $documentXml)) {
  $zip->close();
  request_render_error('Failed to write updated document XML.', 500);
}
$zip->close();


$soffice = 'C:/Program Files/LibreOffice/program/soffice.exe';
if (!is_file($soffice)) throw new RuntimeException('LibreOffice executable is unavailable.');
$profilePath = str_replace('\\', '/', $tempDir . '/profile');
$profile = 'file:///' . implode('/', array_map('rawurlencode', explode('/', $profilePath)));
// Preserve the drive colon in a Windows file URI.
$profile = str_replace('%3A', ':', $profile);
request_convert([$soffice, '-env:UserInstallation=' . $profile, '--headless', '--convert-to', 'pdf', '--outdir', $tempDir, $workingDocx], $tempDir);
$pdf = $tempDir . '/' . $operation . '.pdf';
if (!request_valid_pdf($pdf)) throw new RuntimeException('This operation did not produce a valid PDF.');
return ['pdf' => $pdf, 'docx' => $workingDocx];
}
