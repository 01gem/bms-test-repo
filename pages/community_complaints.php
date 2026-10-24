<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../api/_session.php';
session_start();
header('Cache-Control: private, no-store');
$communityAccess = session_revalidate_active();
if (($communityAccess['error'] ?? null) === 'server') {
  http_response_code(500);
  header('Content-Type: text/html; charset=utf-8');
  echo session_access_failure_html();
  exit;
}
if (empty($communityAccess['ok'])) {
  header('Location: login.php');
  exit;
}

function community_access_page(): void {
  echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Resident access required — BarangayLink</title></head><body><main><h1>Resident access required</h1><p>Sign in with a resident account to view community concerns.</p><p><a href="login.php">Resident login</a></p><p><a href="resident.php">Back</a></p></main></body></html>';
}

$isOfficial = !empty($_SESSION['official_id']);
$residentId = (int)($_SESSION['resident_id'] ?? 0);
if ($isOfficial) {
  http_response_code(403);
  community_access_page();
  exit;
}
if ($residentId <= 0) {
  header('Location: login.php');
  exit;
}
session_write_close();
$residentCssVersion = filemtime(__DIR__ . '/../css/resident.css');
$communityJsVersion = filemtime(__DIR__ . '/../js/community-complaints.js');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Community concerns — BarangayLink</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/resident.css?v=<?= (int)$residentCssVersion ?>">
  <style>
    body.community-board { display: block; min-height: 100vh; background: var(--surface-2); color: var(--text); }
    .community-wrap { width: min(960px, calc(100% - 32px)); margin: 0 auto; padding: 8px 0 40px; }
    .community-header, .community-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 16px; }
    .community-header { padding: 20px 0; border-bottom: 1px solid var(--border); margin-bottom: 20px; }
    .community-brand { display: flex; align-items: center; gap: 10px; }
    .community-mark { width: 34px; height: 34px; border-radius: 8px; background: linear-gradient(135deg, var(--amber), var(--gold)); }
    .community-brand-name { font-size: 17px; font-weight: 700; }
    .community-brand-loc { font-size: 12px; color: var(--text-3); }
    .community-back { color: var(--blue-mid); font-size: 13px; font-weight: 700; }
    .community-board h1 { font-size: 1.6rem; margin: 0 0 8px; }
    .community-lead { margin: 0 0 16px; color: var(--text-2); font-size: 14px; line-height: 1.5; max-width: 68ch; }
    .community-toolbar { margin-bottom: 16px; flex-wrap: wrap; }
    .community-toolbar label { display: flex; flex-direction: column; gap: 6px; font-size: 12px; font-weight: 600; color: var(--text-2); }
    .community-toolbar select, .community-toolbar button, .community-more, .community-retry {
      font: inherit; border-radius: 8px; border: 1px solid var(--border); background: var(--surface); color: var(--text);
    }
    .community-toolbar select { min-width: 180px; padding: 8px 10px; }
    .community-toolbar button, .community-more, .community-retry { padding: 8px 16px; cursor: pointer; font-weight: 700; }
    .community-toolbar button { background: var(--blue-mid); color: white; border-color: var(--blue-mid); }
    .community-board-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; }
    .community-card h2 { font-size: 1.05rem; margin: 0 0 8px; }
    .community-card p { margin: 0 0 6px; }
    .community-status { min-height: 1.4em; margin: 0 0 12px; color: var(--text-2); font-size: 14px; }
    .community-actions { margin-top: 16px; display: flex; gap: 8px; flex-wrap: wrap; }
    .community-login { color: var(--blue-mid); font-weight: 700; }
    .community-board a:focus-visible, .community-board button:focus-visible, .community-board select:focus-visible {
      outline: 2px solid var(--blue-mid); outline-offset: 2px;
    }
    @media (max-width: 640px) {
      .community-header, .community-toolbar { flex-direction: column; align-items: stretch; }
      .community-board-grid { grid-template-columns: 1fr; }
      .community-toolbar select, .community-toolbar button, .community-more, .community-retry { width: 100%; }
    }
  </style>
</head>
<body class="community-board">
  <div class="community-wrap">
    <header class="community-header">
      <div class="community-brand">
        <span class="community-mark" aria-hidden="true"></span>
        <div>
          <div class="community-brand-name">BarangayLink</div>
          <div class="community-brand-loc">Brgy. Sampaguita</div>
        </div>
      </div>
      <a class="community-back" href="resident.php">Back to my complaints</a>
    </header>
    <main class="community-board">
      <h1>Community concerns</h1>
      <p class="community-lead">Only summaries an administrator chose to share with residents appear here. A card shows the concern number, category, severity, status, filing date, and how many shared reports are grouped on that primary report. Private details stay off this board.</p>
      <div class="community-toolbar">
        <label>Category
          <select id="communityCategory"></select>
        </label>
        <label>Status
          <select id="communityStatus"></select>
        </label>
        <button type="button" id="communityRefresh">Refresh</button>
      </div>
      <div id="communityResults" aria-live="polite">
        <p id="communityStatusLine" class="community-status"></p>
        <div id="communityCards" class="community-board-grid"></div>
      </div>
      <div class="community-actions">
        <button type="button" id="communityMore" class="community-more" hidden>Load more</button>
        <button type="button" id="communityRetry" class="community-retry" hidden>Try again</button>
      </div>
    </main>
  </div>
  <script src="../js/community-complaints.js?v=<?= (int)$communityJsVersion ?>"></script>
</body>
</html>
