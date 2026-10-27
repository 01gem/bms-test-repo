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
$officialAccess = session_revalidate_active();
if (($officialAccess['error'] ?? null) === 'server') {
  http_response_code(500);
  header('Content-Type: text/html; charset=utf-8');
  echo session_access_failure_html();
  exit;
}
if (empty($officialAccess['ok']) || empty($_SESSION['official_id'])) {
  header('Location: login.php');
  exit;
}
function e($value) {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
$isOfficialAdmin = ($_SESSION['official_role'] ?? '') === 'admin';
$officialName = trim((string)($_SESSION['official_name'] ?? ''));
$officialRole = trim((string)($_SESSION['official_role'] ?? ''));
$officialPosition = trim((string)($_SESSION['official_position'] ?? ''));
$officialUsername = '';
$officialAddress = '';
$officialContact = '';
$officialStatus = '';
$officialCreatedAt = '';
$officialProfilePhoto = '';
$officialDb = get_db();
$officialPhotoStmt = $officialDb->prepare('SELECT username, address, contact, status, created_at, profile_photo FROM barangay_officials WHERE id = ? LIMIT 1');
if ($officialPhotoStmt) {
  $officialId = (int)$_SESSION['official_id'];
  $officialPhotoStmt->bind_param('i', $officialId);
  $officialPhotoStmt->execute();
  $officialPhotoResult = $officialPhotoStmt->get_result();
  if ($officialPhotoResult && ($officialPhotoRow = $officialPhotoResult->fetch_assoc())) {
    $officialUsername = (string)($officialPhotoRow['username'] ?? '');
    $officialAddress = (string)($officialPhotoRow['address'] ?? '');
    $officialContact = (string)($officialPhotoRow['contact'] ?? '');
    $officialStatus = (string)($officialPhotoRow['status'] ?? '');
    $officialCreatedAt = (string)($officialPhotoRow['created_at'] ?? '');
    $officialProfilePhoto = (string)($officialPhotoRow['profile_photo'] ?? '');
  }
  $officialPhotoStmt->close();
}
$officialPhotoUrl = $officialProfilePhoto !== '' ? '../' . ltrim($officialProfilePhoto, '/') : '';
$officialRoleLabel = $officialRole === 'admin' ? 'Administrator' : ($officialRole === 'staff' ? 'Staff' : ucwords(str_replace(['_', '-'], ' ', $officialRole)));
$officialSubtitle = $officialPosition !== '' ? $officialPosition : ($officialRoleLabel !== '' ? $officialRoleLabel : 'Official');
$officialInitials = 'O';
$officialNameParts = preg_split('/\s+/', $officialName, -1, PREG_SPLIT_NO_EMPTY);
if (count($officialNameParts) >= 2) {
  $officialInitials = strtoupper(substr($officialNameParts[0], 0, 1) . substr($officialNameParts[count($officialNameParts) - 1], 0, 1));
} elseif (count($officialNameParts) === 1) {
  $officialInitials = strtoupper(substr($officialNameParts[0], 0, 2));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>BarangayLink — Official Dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/official.css?v=<?= filemtime(__DIR__ . '/../css/official.css') ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
</head>
<body>

  <!-- ═══ SIDEBAR ═══ -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <div class="brand-icon"><i class="fa-solid fa-seedling"></i></div>
      <div class="sidebar-brand-text">
        <span class="brand-name">BarangayLink</span>
        <span class="brand-loc">Admin / Official Panel</span>
      </div>
      <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Hide sidebar" aria-expanded="true">
        <i class="fa-solid fa-angles-left"></i>
      </button>
    </div>
    <div class="sidebar-user">
      <?php if ($officialPhotoUrl !== ''): ?>
        <img class="user-avatar official-header-photo" src="<?= e($officialPhotoUrl) ?>" alt="Profile photo" />
      <?php else: ?>
        <div class="user-avatar"><?= e($officialInitials) ?></div>
      <?php endif; ?>
      <div class="user-info">
        <span class="user-name"><?= e($officialName !== '' ? $officialName : 'Official') ?></span>
        <span class="user-role official"><i class="fa-solid fa-shield-halved"></i> <?= e($officialSubtitle) ?></span>
      </div>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-group-label">Main</div>
      <button class="snav-item active" data-tab="dashboard"><i class="fa-solid fa-gauge-high"></i> <span class="nav-text">Dashboard</span></button>
      <div class="nav-group-label">Management</div>
      <button class="snav-item" data-tab="residents"><i class="fa-solid fa-users"></i> <span class="nav-text">Residents</span></button>
      <button class="snav-item" data-tab="requests"><i class="fa-solid fa-file-lines"></i> <span class="nav-text">Document Requests</span></button>
      <button class="snav-item" data-tab="complaints"><i class="fa-solid fa-triangle-exclamation"></i> <span class="nav-text">Complaints</span></button>
      <div class="nav-group-label">Configuration</div>
      <button class="snav-item" data-tab="documentprices"><i class="fa-solid fa-tags"></i> <span class="nav-text">Document Prices</span></button>
      <div class="nav-group-label">Community</div>
      <button class="snav-item" data-tab="announcements"><i class="fa-solid fa-bullhorn"></i> <span class="nav-text">Announcements</span></button>
      <button class="snav-item" data-tab="services"><i class="fa-solid fa-store"></i> <span class="nav-text">Local Services</span></button>
      <div class="nav-group-label">Intelligence</div>
      <button class="snav-item" data-tab="aianalyst"><i class="fa-solid fa-brain"></i> <span class="nav-text">AI Analytics</span></button>
      <div class="nav-group-label">System</div>
      <?php if ($isOfficialAdmin): ?>
      <button class="snav-item" data-tab="officials"><i class="fa-solid fa-user-shield"></i> <span class="nav-text">Manage Officials</span></button>
      <?php endif; ?>
      <button class="snav-item" data-tab="auditlog"><i class="fa-solid fa-scroll"></i> <span class="nav-text">Audit Log</span></button>
      <div class="nav-group-label">Account</div>
      <button class="snav-item" data-tab="profile"><i class="fa-solid fa-user"></i> <span class="nav-text">My Profile</span></button>
    </nav>
    <div class="sidebar-footer">
      <a href="../logout.php" class="snav-item logout-item"><i class="fa-solid fa-arrow-left-from-bracket"></i> <span class="nav-text">Back to Home</span></a>
    </div>
  </aside>

  <!-- ═══ MAIN ═══ -->
  <main class="main-area">
    <header class="topbar">
      <div class="topbar-left">
        <div class="door-plaque door-plaque-dashboard" aria-live="polite">
          <i class="fa-solid fa-house" id="sectionPlaqueIcon" aria-hidden="true"></i>
          <span id="sectionPlaque">Office of the Barangay Captain</span>
        </div>
      </div>
      <div class="topbar-right">
        <div class="official-chip"><i class="fa-solid fa-shield-halved"></i> Official Access</div>
        <div class="topbar-user">
          <?php if ($officialPhotoUrl !== ''): ?>
            <img class="tu-avatar official-header-photo" src="<?= e($officialPhotoUrl) ?>" alt="Profile photo" />
          <?php else: ?>
            <div class="tu-avatar"><?= e($officialInitials) ?></div>
          <?php endif; ?>
          <span><?= e($officialName !== '' ? $officialName : 'Official') ?></span>
          <a href="../logout.php" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i></a>
        </div>
      </div>
    </header>

    <div class="content-area">

      <!-- ─── DASHBOARD ─── -->
      <div class="tab-panel active" id="tab-dashboard">
        <div class="stat-cards" id="dashStats"></div>
        <div class="dash-row">
          <div class="card dash-card">
            <div class="dc-head">
              <h3><i class="fa-solid fa-file-clock"></i> Pending Document Requests</h3>
              <button class="dc-more-btn" onclick="switchTab('requests')">View all →</button>
            </div>
            <div id="dashPendingReqs"></div>
          </div>
          <div class="card dash-card">
            <div class="dc-head">
              <h3><i class="fa-solid fa-triangle-exclamation"></i> Open Complaints</h3>
              <button class="dc-more-btn" onclick="switchTab('complaints')">View all →</button>
            </div>
            <div id="dashOpenComplaints"></div>
          </div>
        </div>
        <div class="card recent-activity-card">
          <div class="dc-head"><h3><i class="fa-solid fa-clock-rotate-left"></i> Recent System Activity</h3></div>
          <div id="dashActivity"></div>
        </div>
      </div>

      <!-- ─── DOCUMENT PRICES ─── -->
      <div class="tab-panel" id="tab-documentprices">
        <div class="tab-header"><h2>Document Prices</h2></div>
        <div class="card">
          <div class="registry-header">
            <div>
              <h3><i class="fa-solid fa-tags"></i> Document Prices</h3>
              <p>Set the unit price charged per print. Default is ₱20.00.</p>
            </div>
          </div>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Document</th><th>Price</th><th>Last Updated</th><th class="action-column">Action</th></tr></thead>
              <tbody id="docPricesBody"></tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ─── RESIDENTS ─── -->
      <div class="tab-panel" id="tab-residents">
        <div class="tab-header">
          <h2>Resident</h2>
          <button class="btn-primary-action" id="addResidentBtn"><i class="fa-solid fa-plus"></i> Add Resident</button>
        </div>
        <div class="modal-overlay form-modal-overlay" id="residentFormCard">
          <div class="modal-box form-card">
            <div class="form-card-header">
              <h3 id="residentFormTitle"><i class="fa-solid fa-user-plus"></i> Add New Resident</h3>
              <button class="close-card-btn" id="closeResidentForm"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <!-- Wizard progress -->
            <div class="wizard-progress" id="residentWizardProgress">
              <div class="wz-step" data-step="1"><span class="wz-dot">1</span><span class="wz-label">Account</span></div>
              <div class="wz-step" data-step="2"><span class="wz-dot">2</span><span class="wz-label">Household</span></div>
              <div class="wz-step" data-step="3"><span class="wz-dot">3</span><span class="wz-label">Profiling</span></div>
            </div>

            <form id="residentFormEl" data-mode="create">
            <input type="hidden" name="resident_id" />

            <!-- ── Step 1: Account ── -->
            <div class="wizard-step" data-step="1">
            <div class="form-2col">
              <div class="field"><label>First Name</label><input type="text" name="fname" class="form-input" required /></div>
              <div class="field"><label>Last Name</label><input type="text" name="lname" class="form-input" required /></div>
            </div>
            <div class="form-2col">
              <div class="field"><label>Contact</label><input type="text" name="contact" class="form-input" required placeholder="+639171234567" /><small style="color:var(--text-3);font-size:11px">Philippine mobile number, format +639XXXXXXXXX</small></div>
              <div class="field"><label>Username</label><input type="text" name="username" class="form-input" required /></div>
            </div>
            <div class="field"><label>Address</label><input type="text" name="address" class="form-input" required /></div>
            <div class="field" style="margin-top:12px;"><label id="residentPasswordLabel">Temporary Password</label><input type="password" name="password" class="form-input" required /></div>
            </div>

            <!-- ── Step 2: Household ── -->
            <div class="wizard-step" data-step="2" hidden>
              <p class="wz-help">How should this resident be linked to a household?</p>
              <div class="wz-household-choice">
                <label class="wz-choice">
                  <input type="radio" name="household_mode" value="new" checked />
                  <span><strong>Create a new household</strong><small>This resident becomes the household head.</small></span>
                </label>
                <label class="wz-choice">
                  <input type="radio" name="household_mode" value="link" />
                  <span><strong>Link to an existing household</strong><small>Choose a household from the list below.</small></span>
                </label>
              </div>

              <div class="wz-household-pane" data-pane="new">
                <div class="form-2col">
                  <div class="field"><label>Household Purok / Zone</label>
                    <select name="household_purok_zone" class="form-input">
                      <option value="">— Select —</option>
                      <option value="Purok 1">Purok 1</option>
                      <option value="Purok 2">Purok 2</option>
                      <option value="Purok 3">Purok 3</option>
                      <option value="Purok 4">Purok 4</option>
                      <option value="Purok 5">Purok 5</option>
                    </select>
                  </div>
                  <div class="field" id="residentHouseholdSizeField"><label>Household Size</label><input type="number" name="household_size" class="form-input" min="1" value="1" /></div>
                </div>
                <div class="field" style="margin-top:8px;"><label>Household Address <span class="opt-label">(defaults to resident address)</span></label><input type="text" name="household_address" class="form-input" /></div>
              </div>

              <div class="wz-household-pane" data-pane="link" hidden>
                <div class="field"><label>Existing Household</label>
                  <select name="household_id" class="form-input" id="residentHouseholdSelect">
                    <option value="">— Select household —</option>
                  </select>
                </div>
                <div class="field" style="margin-top:8px;"><label>Relationship to Head</label><input type="text" name="relationship_to_household_head" class="form-input" placeholder="e.g. Spouse, Son, Daughter" /></div>
              </div>
            </div>

            <!-- ── Step 3: Profiling ── -->
            <div class="wizard-step" data-step="3" hidden>
              <div class="wz-profiling-choice" id="residentProfilingChoice">
                <p class="wz-help">Complete the resident's profiling information now, or defer it.</p>
                <div class="wz-choice-row">
                  <label class="wz-choice">
                    <input type="radio" name="profile_now" value="1" checked />
                    <span><strong>Complete Now</strong><small>Fill in demographics and livelihood details.</small></span>
                  </label>
                  <label class="wz-choice">
                    <input type="radio" name="profile_now" value="0" />
                    <span><strong>Later</strong><small>Keep the resident unprofiled for now.</small></span>
                  </label>
                </div>
              </div>

              <fieldset class="profiling-group" data-profile-fields>
                <legend>Demographics</legend>
                <div class="form-2col">
                  <div class="field"><label>Birthdate</label><input type="date" name="birthdate" class="form-input" /></div>
                  <div class="field"><label>Civil Status</label>
                    <select name="civil_status" class="form-input">
                      <option value="">— Select —</option>
                      <option value="Single">Single</option>
                      <option value="Married">Married</option>
                      <option value="Widowed">Widowed</option>
                      <option value="Separated">Separated</option>
                    </select>
                  </div>
                </div>
                <div class="field" style="margin-top:8px;"><label>Purok / Zone</label>
                  <select name="purok_zone" class="form-input">
                    <option value="">— Select —</option>
                    <option value="Purok 1">Purok 1</option>
                    <option value="Purok 2">Purok 2</option>
                    <option value="Purok 3">Purok 3</option>
                    <option value="Purok 4">Purok 4</option>
                    <option value="Purok 5">Purok 5</option>
                  </select>
                </div>
                <div id="residentHouseholdSizeSlot"></div>
                <div class="form-2col profiling-checkboxes">
                  <label class="checkbox-label"><input type="checkbox" name="is_solo_parent" value="1" /> Solo Parent</label>
                  <label class="checkbox-label"><input type="checkbox" name="is_pwd" value="1" /> Person with Disability (PWD)</label>
                  <label class="checkbox-label"><input type="checkbox" name="is_4ps_member" value="1" /> 4Ps Member</label>
                </div>
              </fieldset>

              <fieldset class="profiling-group" data-profile-fields>
                <legend>Livelihood &amp; Skills</legend>
                <div class="form-2col">
                  <div class="field"><label>Educational Attainment</label>
                    <select name="educational_attainment" class="form-input">
                      <option value="">— Select —</option>
                      <option value="Elementary">Elementary</option>
                      <option value="High School">High School</option>
                      <option value="Vocational">Vocational</option>
                      <option value="College">College</option>
                      <option value="Postgraduate">Postgraduate</option>
                    </select>
                  </div>
                  <div class="field"><label>Employment Status</label>
                    <select name="employment_status" class="form-input">
                      <option value="">— Select —</option>
                      <option value="Employed">Employed</option>
                      <option value="Unemployed">Unemployed</option>
                      <option value="Self-Employed">Self-Employed</option>
                      <option value="Student">Student</option>
                      <option value="Retired">Retired</option>
                    </select>
                  </div>
                </div>
                <div class="field" style="margin-top:8px;"><label>Occupation</label><input type="text" name="occupation" class="form-input" placeholder="e.g. Farmer, Teacher" /></div>
                <div class="field" style="margin-top:8px;"><label>Work Availability</label>
                  <select name="work_availability" class="form-input">
                    <option value="">— Select —</option>
                    <option value="Full-time">Full-time</option>
                    <option value="Part-time">Part-time</option>
                    <option value="Seasonal">Seasonal</option>
                    <option value="Not looking">Not looking</option>
                  </select>
                </div>
                <div class="field" style="margin-top:8px;"><label>Training / Certifications</label><textarea name="training_certifications" class="form-input" rows="2" placeholder="e.g. TESDA NC II - Welding"></textarea></div>
              </fieldset>
            </div>

            <div class="form-actions wizard-actions">
              <button type="button" class="btn-cancel-form" id="cancelResidentForm">Cancel</button>
              <span class="wizard-spacer"></span>
              <button type="button" class="btn-cancel-form" id="residentWizardBack" hidden><i class="fa-solid fa-arrow-left"></i> Back</button>
              <button type="button" class="btn-submit-form" id="residentWizardNext">Next <i class="fa-solid fa-arrow-right"></i></button>
              <button type="submit" class="btn-submit-form" id="residentFormSubmitBtn" hidden>Save Resident</button>
            </div>
            </form>
          </div>
        </div>
        <div class="card">
          <div class="table-toolbar">
            <input type="text" class="table-search" id="residentSearch" placeholder="Search residents…" />
            <select class="table-filter" id="residentStatusFilter">
              <option value="all">All Status</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
          <div class="table-wrap">
            <table class="data-table" id="residentsTable">
              <thead><tr><th>Resident ID</th><th>Full Name</th><th>Address</th><th>Contact</th><th>Status</th><th class="action-column">Actions</th></tr></thead>
              <tbody id="residentsBody"></tbody>
            </table>
          </div>
          <div class="registry-pagination" id="residentPager">
            <button type="button" class="btn-action view" id="residentPrevBtn"><i class="fa-solid fa-chevron-left"></i> Previous</button>
            <span id="residentPageLabel">Page 1 of 1</span>
            <button type="button" class="btn-action view" id="residentNextBtn">Next <i class="fa-solid fa-chevron-right"></i></button>
          </div>
        </div>
      </div>

      <!-- ─── DOCUMENT REQUESTS ─── -->
      <div class="tab-panel" id="tab-requests">
        <div class="tab-header"><h2>Document Requests</h2></div>
        <div class="card">
          <div class="table-toolbar">
            <input type="text" class="table-search" id="reqSearch" placeholder="Search by name, ref #…" />
            <select class="table-filter" id="reqStatusFilter">
              <option value="all">All Status</option>
              <option value="pending">Pending</option>
              <option value="processing">Processing</option>
              <option value="ready">Ready</option>
              <option value="completed">Completed</option>
            </select>
          </div>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Ref #</th><th>Resident</th><th>Document Type</th><th>Purpose</th><th>Date</th><th>Status</th><th class="action-column">Action</th></tr></thead>
              <tbody id="reqsBody"></tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ─── COMPLAINTS ─── -->
      <div class="tab-panel" id="tab-complaints">
        <div class="tab-header"><h2>Complaints &amp; Concerns</h2></div>
        <div class="card">
          <div class="table-toolbar">
            <input type="text" class="table-search" id="compSearch" placeholder="Search complaints…" />
            <select class="table-filter" id="compStatusFilter">
              <option value="all">All</option>
              <option value="open">Open</option>
              <option value="investigating">Investigating</option>
              <option value="resolved">Resolved</option>
            </select>
          </div>
          <div id="complaintsAdminList"></div>
        </div>
      </div>

      <!-- ─── ANNOUNCEMENTS ─── -->
      <div class="tab-panel" id="tab-announcements">
        <div class="tab-header">
          <h2>Announcements</h2>
          <button class="btn-primary-action" id="newAnnBtn"><i class="fa-solid fa-plus"></i> Post Announcement</button>
        </div>
        <div class="modal-overlay form-modal-overlay" id="annForm">
          <div class="modal-box form-card">
            <div class="form-card-header">
              <h3><i class="fa-solid fa-bullhorn"></i> New Announcement</h3>
              <button class="close-card-btn" id="closeAnnForm"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="annFormEl">
            <div class="form-2col">
              <div class="field"><label>Title</label><input type="text" class="form-input" placeholder="Announcement title" required /></div>
              <div class="field"><label>Category</label>
                <select class="form-input">
                  <option>General</option><option>Health</option><option>Environment</option><option>Safety</option><option>Event</option>
                </select>
              </div>
            </div>
            <div class="field" style="margin-top:12px;"><label>Content</label><textarea class="form-input" rows="5" placeholder="Write the announcement content here…" required></textarea></div>
            <div class="field" style="margin-top:12px;"><label>Expires On <span class="opt-label">(optional — leave blank for a permanent announcement)</span></label><input id="announcementExpiresAt" type="datetime-local" class="form-input" /></div>
            <?php if (getenv('HTTPSMS_API_KEY') && getenv('HTTPSMS_FROM_NUMBER')): ?>
            <div class="field" style="margin-top:8px;">
              <label>Send SMS Notification to Residents? <span class="opt-label">(via httpSMS)</span></label>
              <label class="toggle-label"><input type="checkbox" id="smsToggle" /> <span class="toggle-switch"></span> <span>Yes, send SMS to all registered residents</span></label>
            </div>
            <?php else: ?>
            <div class="field" style="margin-top:8px;">
              <small style="color:var(--text-3);font-size:12px">SMS notifications are disabled in this build.</small>
            </div>
            <?php endif; ?>
            <div class="form-actions"><button type="submit" class="btn-submit-form">Post Announcement</button><button type="button" class="btn-cancel-form" id="cancelAnnForm">Cancel</button></div>
            </form>
          </div>
        </div>
        <div class="card">
          <div id="announcementsAdminList"></div>
        </div>
      </div>

      

      <!-- ─── LOCAL SERVICES ─── -->
      <div class="tab-panel" id="tab-services">
        <div class="tab-header">
          <h2>Local Services Directory</h2>
          <button class="btn-primary-action" id="newServiceBtn"><i class="fa-solid fa-plus"></i> Add Service</button>
        </div>
        <div class="modal-overlay form-modal-overlay" id="serviceForm">
          <div class="modal-box form-card">
            <div class="form-card-header">
              <h3><i class="fa-solid fa-store"></i> Local Service</h3>
              <button class="close-card-btn" id="closeServiceForm"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="serviceFormEl">
            <input type="hidden" name="id" />
            <div class="form-2col">
              <div class="field"><label>Service Name</label><input name="service_name" type="text" class="form-input" required /></div>
              <div class="field"><label>Category</label><input name="category" type="text" class="form-input" placeholder="e.g. Food, Retail, Services" required /></div>
            </div>
            <div class="form-2col" style="margin-top:12px;">
              <div class="field"><label>Contact Number</label><input name="contact_number" type="text" class="form-input" /></div>
              <div class="field"><label>Address</label><input name="address" type="text" class="form-input" /></div>
            </div>
            <div class="field" style="margin-top:12px;"><label>Operating Hours</label><input name="operating_hours" type="text" class="form-input" /></div>
            <div class="field" style="margin-top:12px;"><label>Description</label><textarea name="description" class="form-input" rows="4"></textarea></div>
            <div class="form-actions"><button type="submit" class="btn-submit-form">Save Service</button><button type="button" class="btn-cancel-form" id="cancelServiceForm">Cancel</button></div>
            </form>
          </div>
        </div>
        <div class="services-admin-grid" id="servicesAdminGrid"></div>
      </div>

      <?php if ($isOfficialAdmin): ?>
      <!-- ─── MANAGE OFFICIALS ─── -->
      <div class="tab-panel" id="tab-officials">
        <div class="tab-header">
          <h2>Manage Officials</h2>
          <button class="btn-primary-action" id="addOfficialBtn"><i class="fa-solid fa-plus"></i> Add Official</button>
        </div>
        <div class="modal-overlay form-modal-overlay" id="officialFormCard">
          <div class="modal-box form-card">
            <div class="form-card-header">
              <h3 id="officialFormTitle"><i class="fa-solid fa-user-plus"></i> Add New Official</h3>
              <button class="close-card-btn" id="closeOfficialForm"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="officialFormEl">
            <input type="hidden" name="official_id" />
            <div class="form-2col">
              <div class="field"><label>First Name</label><input type="text" name="fname" class="form-input" required /></div>
              <div class="field"><label>Last Name</label><input type="text" name="lname" class="form-input" required /></div>
            </div>
            <div class="form-2col">
              <div class="field"><label>Contact</label><input type="text" name="contact" class="form-input" required placeholder="+639171234567" /><small style="color:var(--text-3);font-size:11px">Philippine mobile number, format +639XXXXXXXXX</small></div>
              <div class="field"><label>Username</label><input type="text" name="username" class="form-input" required /></div>
            </div>
            <div class="field"><label>Address</label><input type="text" name="address" class="form-input" required /></div>
            <div class="form-2col" style="margin-top:12px;">
              <div class="field"><label>Position</label><input type="text" name="position" class="form-input" required /></div>
              <div class="field"><label>Role</label>
                <select name="role" class="form-input" required>
                  <option value="staff">Staff</option>
                  <option value="admin">Admin</option>
                </select>
              </div>
            </div>
            <div class="field" style="margin-top:12px;"><label id="officialPasswordLabel">Temporary Password</label><input type="password" name="password" class="form-input" required /></div>
            <div class="form-actions">
              <button type="submit" class="btn-submit-form" id="officialFormSubmitBtn">Save Official</button>
              <button type="button" class="btn-cancel-form" id="cancelOfficialForm">Cancel</button>
            </div>
            </form>
          </div>
        </div>
        <div class="card">
          <div class="table-toolbar">
            <input type="text" class="table-search" id="officialSearch" placeholder="Search by name, username, position…" />
            <select class="table-filter" id="officialStatusFilter">
              <option value="all">All Status</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
          <div class="table-wrap">
            <table class="data-table" id="officialsTable">
              <thead><tr><th>ID</th><th>Full Name</th><th>Username</th><th>Contact</th><th>Address</th><th>Position</th><th>Role</th><th>Status</th><th class="action-column">Actions</th></tr></thead>
              <tbody id="officialsBody"></tbody>
            </table>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- ─── AUDIT LOG ─── -->
      <div class="tab-panel" id="tab-auditlog">
        <div class="tab-header"><h2>Audit Log</h2></div>
        <div class="card">
          <div class="table-toolbar">
            <input type="text" class="table-search" id="auditSearch" placeholder="Search by user, action, or module…" />
            <select class="table-filter" id="auditActionFilter">
              <option value="all">All Actions</option>
            </select>
            <?php if ($isOfficialAdmin): ?>
            <select class="table-filter" id="auditOfficialFilter">
              <option value="all">All Staff/Admins</option>
            </select>
            <?php endif; ?>
          </div>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Timestamp</th><th>User</th><th>Role</th><th>Action</th><th>Module</th><th>IP Address</th></tr></thead>
              <tbody id="auditBody"></tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ─── AI ANALYTICS ─── -->
      <div class="tab-panel" id="tab-aianalyst">
        <div class="tab-header"><h2><i class="fa-solid fa-brain"></i> AI Analytics</h2></div>
        <div class="ai-hub" id="aiHub">
          <button type="button" class="ai-tool-tile" data-ai-view="registry">
            <span class="ai-tile-ico" style="background:#DBEAFE;color:#1976D2"><i class="fa-solid fa-shield-halved"></i></span>
            <span class="ai-tile-text"><span class="ai-tile-title">Vulnerability Registry</span><span class="ai-tile-desc">Deterministic MPI ranking with AI commentary</span></span>
          </button>
          <button type="button" class="ai-tool-tile" data-ai-view="workforce">
            <span class="ai-tile-ico" style="background:#EDE9FE;color:#7C3AED"><i class="fa-solid fa-chart-column"></i></span>
            <span class="ai-tile-text"><span class="ai-tile-title">Workforce Insights</span><span class="ai-tile-desc">Employment and skill breakdowns</span></span>
          </button>
          <button type="button" class="ai-tool-tile" data-ai-view="docreports">
            <span class="ai-tile-ico" style="background:#DBEAFE;color:#1976D2"><i class="fa-solid fa-file-invoice-dollar"></i></span>
            <span class="ai-tile-text"><span class="ai-tile-title">Document Requests Report</span><span class="ai-tile-desc">Monthly volume, revenue &amp; AI commentary</span></span>
          </button>
          <button type="button" class="ai-tool-tile" data-ai-view="complaintreports">
            <span class="ai-tile-ico" style="background:#FEE2E2;color:#DC2626"><i class="fa-solid fa-triangle-exclamation"></i></span>
            <span class="ai-tile-text"><span class="ai-tile-title">Complaints Report</span><span class="ai-tile-desc">Category, severity &amp; AI commentary</span></span>
          </button>
          <button type="button" class="ai-tool-tile is-secondary" data-ai-view="echo">
            <span class="ai-tile-ico"><i class="fa-solid fa-plug"></i></span>
            <span class="ai-tile-text"><span class="ai-tile-title">Connectivity Test</span><span class="ai-tile-desc">Verify AI service is responding</span></span>
          </button>
        </div>
        <div class="ai-subview" data-ai-subview="registry" hidden>
        <button type="button" class="ai-back-btn"><i class="fa-solid fa-arrow-left"></i> Back to AI Tools</button>

        <div class="registry-tabs" role="tablist" aria-label="Registry view">
          <button type="button" class="registry-tab active" data-registry-tab="vulnerability" role="tab" aria-selected="true">
            <i class="fa-solid fa-shield-halved"></i> Vulnerability Registry
          </button>
          <button type="button" class="registry-tab" data-registry-tab="household" role="tab" aria-selected="false">
            <i class="fa-solid fa-house-user"></i> Household Registry
          </button>
        </div>

        <div class="registry-pane" data-registry-pane="vulnerability">
        <div class="card vulnerability-registry-card">
          <div class="registry-header">
            <div>
              <h3><i class="fa-solid fa-shield-halved"></i> Vulnerability Registry</h3>
              <p>Household MPI status and deprivations from the household questionnaire. Gemini generates a plain-English commentary per household on demand; the deterministic MPI result is unchanged.</p>
            </div>
            <div class="registry-actions">
              <button id="aiAssessBtn" type="button" class="btn-primary-action"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate Reports</button>
              <button id="clearAllReportsBtn" type="button" class="btn-primary-action">
                <i class="fa-solid fa-eraser"></i> Clear Commentaries
              </button>
              <button id="registryExportBtn" type="button" class="btn-primary-action"><i class="fa-solid fa-file-csv"></i> Export as CSV</button>
            </div>
          </div>
          <p class="registry-note">Rate limit: 200 households per hour per official.</p>
          <div class="table-toolbar">
            <select id="vulnerabilityPurokFilter" class="table-filter" aria-label="Filter vulnerability registry by Purok">
              <option value="all">All Puroks</option>
            </select>
          </div>
          <div class="table-wrap">
            <table class="data-table vulnerability-registry-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Purok</th>
                  <th>Household</th>
                  <th>Priority Status</th>
                  <th>Priority Indicators</th>
                  <th>AI Commentary</th>
                </tr>
              </thead>
              <tbody id="vulnerabilityRegistryBody"></tbody>
            </table>
          </div>
          <div class="registry-pagination" id="vulnerabilityRegistryPager">
            <button type="button" class="btn-action view" id="vulnerabilityPrevBtn"><i class="fa-solid fa-chevron-left"></i> Prev</button>
            <span id="vulnerabilityPageLabel">Page 1 of 1</span>
            <button type="button" class="btn-action view" id="vulnerabilityNextBtn">Next <i class="fa-solid fa-chevron-right"></i></button>
          </div>
        </div>
        </div>

        <div class="registry-pane" data-registry-pane="household" hidden>
        <div class="card vulnerability-registry-card household-registry-card">
          <div class="registry-header">
            <div>
              <h3><i class="fa-solid fa-house-user"></i> Household Registry</h3>
              <p>One row per household. Households marked <strong>Unprofiled</strong> have no questionnaire data yet and cannot be assessed.</p>
            </div>
            <div class="registry-actions">
              <button id="householdReportsBtn" type="button" class="btn-primary-action"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate Reports</button>
              <button id="clearAllHouseholdReportsBtn" type="button" class="btn-primary-action">
                <i class="fa-solid fa-eraser"></i> Clear Commentaries
              </button>
              <button id="householdSortBtn" type="button" class="btn-primary-action"><i class="fa-solid fa-arrow-down-wide-short"></i> Organize by Poverty</button>
              <button id="householdExportBtn" type="button" class="btn-primary-action"><i class="fa-solid fa-file-csv"></i> Export as CSV</button>
            </div>
          </div>
          <p class="registry-note">Rate limit: 200 households per hour per official.</p>
          <div class="table-toolbar">
            <select id="householdPurokFilter" class="table-filter" aria-label="Filter household registry by Purok">
              <option value="all">All Puroks</option>
            </select>
          </div>
          <div class="table-wrap">
            <table class="data-table vulnerability-registry-table">
              <thead><tr><th>Household</th><th>Head</th><th>Purok</th><th>Size</th><th>Profile Status</th><th>MPI</th><th>Income Status</th><th>AI Commentary</th><th class="action-column">Actions</th></tr></thead>
              <tbody id="householdRegistryBody"></tbody>
            </table>
          </div>
          <div class="registry-pagination" id="householdRegistryPager">
            <button type="button" class="btn-action view" id="householdPrevBtn"><i class="fa-solid fa-chevron-left"></i> Prev</button>
            <span id="householdPageLabel">Page 1 of 1</span>
            <button type="button" class="btn-action view" id="householdNextBtn">Next <i class="fa-solid fa-chevron-right"></i></button>
          </div>
        </div>
        </div>
        </div>
        <!-- ── Document Requests Report ── -->
        <div class="ai-subview" data-ai-subview="docreports" hidden>
          <button type="button" class="ai-back-btn"><i class="fa-solid fa-arrow-left"></i> Back to AI Tools</button>

          <div class="card" id="docReportsAiCard" style="margin-bottom:1.5rem;border-left:4px solid #1976D2">
            <div class="registry-header">
              <div>
                <h3><i class="fa-solid fa-wand-magic-sparkles"></i> AI Commentary</h3>
                <p>Interpretation of this report. This section is visible on screen only — it is excluded from CSV exports and printed reports.</p>
              </div>
              <div class="registry-actions">
                <button id="docReportsAiBtn" type="button" class="btn-primary-action">
                  <i class="fa-solid fa-wand-magic-sparkles"></i> Generate AI Commentary
                </button>
              </div>
            </div>
            <div id="docReportsAiOutput" class="ai-echo-response" role="status" aria-live="polite">No commentary generated yet.</div>
          </div>

          <div class="card" style="margin-bottom:1.5rem">
            <div class="registry-header">
              <div>
                <h3><i class="fa-solid fa-file-invoice-dollar"></i> Document Requests — Monthly Summary</h3>
                <p>Requests issued per month with gross billed and total collected.</p>
              </div>
              <div class="registry-actions">
                <button id="docReportsRefreshBtn" type="button" class="btn-primary-action"><i class="fa-solid fa-rotate"></i> Refresh</button>
                <button id="docReportsExportBtn" type="button" class="btn-primary-action"><i class="fa-solid fa-file-csv"></i> Export as CSV</button>
              </div>
            </div>
            <div class="stat-cards is-compact" id="docRequestStats"></div>
            <div class="table-wrap" style="margin-top:12px">
              <table class="data-table">
                <thead><tr>
                  <th>Month</th><th>Requests</th><th>Copies</th>
                  <th>Gross</th><th>Collected</th><th>Pending</th><th>Waived</th>
                </tr></thead>
                <tbody id="docRequestMonthlyBody"></tbody>
              </table>
            </div>
            <h4 style="margin:18px 0 8px;color:var(--text-2);font-size:.95rem">By Document Type</h4>
            <div class="table-wrap">
              <table class="data-table">
                <thead><tr>
                  <th>Document</th><th>Requests</th><th>Copies</th><th>Gross</th><th>Collected</th>
                </tr></thead>
                <tbody id="docRequestTypeBody"></tbody>
              </table>
            </div>
          </div>

        </div>

        <!-- ── Complaints Report ── -->
        <div class="ai-subview" data-ai-subview="complaintreports" hidden>
          <button type="button" class="ai-back-btn"><i class="fa-solid fa-arrow-left"></i> Back to AI Tools</button>

          <div class="card" id="complaintReportsAiCard" style="margin-bottom:1.5rem;border-left:4px solid #DC2626">
            <div class="registry-header">
              <div>
                <h3><i class="fa-solid fa-wand-magic-sparkles"></i> AI Commentary</h3>
                <p>Interpretation of this report. This section is visible on screen only — it is excluded from CSV exports and printed reports.</p>
              </div>
              <div class="registry-actions">
                <button id="complaintReportsAiBtn" type="button" class="btn-primary-action">
                  <i class="fa-solid fa-wand-magic-sparkles"></i> Generate AI Commentary
                </button>
              </div>
            </div>
            <div id="complaintReportsAiOutput" class="ai-echo-response" role="status" aria-live="polite">No commentary generated yet.</div>
          </div>

          <div class="card">
            <div class="registry-header">
              <div>
                <h3><i class="fa-solid fa-triangle-exclamation"></i> Complaints — Summary &amp; Ranking</h3>
                <p>Counts by category, severity, and monthly ranking of the most common type.</p>
              </div>
              <div class="registry-actions">
                <button id="complaintReportsRefreshBtn" type="button" class="btn-primary-action"><i class="fa-solid fa-rotate"></i> Refresh</button>
                <button id="complaintReportsExportBtn" type="button" class="btn-primary-action"><i class="fa-solid fa-file-csv"></i> Export as CSV</button>
              </div>
            </div>
            <div class="stat-cards is-compact" id="complaintStats"></div>

            <div class="dash-row" style="margin-top:1rem">
              <div class="card dash-card">
                <div class="dc-head"><h3>By Category</h3></div>
                <div id="complaintByCategory"></div>
              </div>
              <div class="card dash-card">
                <div class="dc-head"><h3>By Severity</h3></div>
                <div id="complaintBySeverity"></div>
              </div>
            </div>

            <h4 style="margin:18px 0 8px;color:var(--text-2);font-size:.95rem">Category × Severity</h4>
            <div class="table-wrap">
              <table class="data-table">
                <thead><tr>
                  <th>Category</th><th>Low</th><th>Medium</th><th>High</th><th>Critical</th><th>Total</th>
                </tr></thead>
                <tbody id="complaintCrossBody"></tbody>
              </table>
            </div>

            <h4 style="margin:18px 0 8px;color:var(--text-2);font-size:.95rem">Top Complaint Types — Monthly Ranking</h4>
            <div class="table-wrap">
              <table class="data-table">
                <thead><tr><th>Month</th><th>Rank</th><th>Category</th><th>Count</th></tr></thead>
                <tbody id="complaintRankingBody"></tbody>
              </table>
            </div>
          </div>
        </div>
        <div class="ai-subview" data-ai-subview="workforce" hidden>
        <button type="button" class="ai-back-btn"><i class="fa-solid fa-arrow-left"></i> Back to AI Tools</button>
        <div class="card vulnerability-registry-card workforce-card">
          <div class="registry-header">
            <div>
              <h3><i class="fa-solid fa-chart-column"></i> Workforce Insights</h3>
              <p>Employment, education, availability and skill-category breakdowns of active residents. Gemini classifies each resident's occupation and skills into a fixed category once; edits to those fields reset the category.</p>
            </div>
            <div class="registry-actions">
              <button id="workforceClassifyBtn" type="button" class="btn-primary-action"><i class="fa-solid fa-tags"></i> Classify Workforce</button>
            </div>
          </div>
          <p class="registry-note">Rate limit: 200 households per hour per official.</p>
          <div class="table-toolbar">
            <select id="workforcePurokFilter" class="table-filter" aria-label="Filter workforce insights by Purok">
              <option value="all">All Puroks</option>
            </select>
          </div>
          <div id="workforceStatus" class="ai-echo-response" role="status" aria-live="polite" hidden></div>
          <div id="workforceStats" class="stat-cards workforce-stats"></div>
          <div id="workforceCharts" class="workforce-charts"></div>
        </div>
        </div>
        <div class="ai-subview" data-ai-subview="echo" hidden>
        <button type="button" class="ai-back-btn"><i class="fa-solid fa-arrow-left"></i> Back to AI Tools</button>
        <div class="card ai-echo-test-card">
          <p class="ai-echo-test-note">Temporary connectivity test. Full AI features are still in development.</p>
          <label for="aiEchoPrompt">Test prompt</label>
          <div class="ai-echo-test-controls">
            <input id="aiEchoPrompt" type="text" value="Say hello and confirm you are working correctly." maxlength="2000">
            <button id="aiEchoSendBtn" type="button" class="btn-primary-action"><i class="fa-solid fa-paper-plane"></i> Send Test Prompt</button>
          </div>
          <div id="aiEchoResponse" class="ai-echo-response" role="status" aria-live="polite">No response yet.</div>
        </div>
        </div>
      </div>

      <!-- ─── MY PROFILE ─── -->
      <div class="tab-panel" id="tab-profile">
        <div class="tab-header"><h2>My Profile</h2></div>
        <div class="profile-grid official-profile-grid">
          <div class="card profile-card">
            <?php if ($officialPhotoUrl !== ''): ?>
              <img class="profile-avatar-big profile-photo" src="<?= e($officialPhotoUrl) ?>" alt="Profile photo" />
            <?php else: ?>
              <div class="profile-avatar-big"><?= e($officialInitials) ?></div>
            <?php endif; ?>
            <div class="profile-name-big"><?= e($officialName !== '' ? $officialName : 'Official') ?></div>
            <div class="profile-role-badge official-profile-role"><i class="fa-solid fa-shield-halved"></i> <?= e($officialSubtitle) ?></div>
            <form id="officialProfilePhotoForm" class="profile-photo-form" enctype="multipart/form-data">
              <label for="officialProfilePhotoInput" class="btn-submit-form">Change Photo</label>
              <input id="officialProfilePhotoInput" type="file" name="photo" accept="image/jpeg,image/png" hidden />
              <small>JPEG or PNG, maximum 50MB</small>
            </form>
          </div>
          <div class="card">
            <h3 class="card-section-title">Official Information</h3>
            <div class="profile-form">
              <div class="field"><label>Name</label><div class="profile-readonly"><?= e($officialName) ?></div></div>
              <div class="field"><label>Position</label><div class="profile-readonly"><?= e($officialPosition !== '' ? $officialPosition : '—') ?></div></div>
              <div class="field"><label>Role</label><div class="profile-readonly"><?= e($officialRoleLabel) ?></div></div>
              <div class="field"><label>Username</label><div class="profile-readonly"><?= e($officialUsername !== '' ? $officialUsername : '—') ?></div></div>
              <div class="field"><label>Contact</label><div class="profile-readonly"><?= e($officialContact !== '' ? $officialContact : '—') ?></div></div>
              <div class="field"><label>Address</label><div class="profile-readonly"><?= e($officialAddress !== '' ? $officialAddress : '—') ?></div></div>
              <div class="field"><label>Account Status</label><div class="profile-readonly"><?= e($officialStatus !== '' ? ucfirst($officialStatus) : '—') ?></div></div>
              <div class="field"><label>Account ID</label><div class="profile-readonly"><?= e((string)$_SESSION['official_id']) ?></div></div>
              <div class="field"><label>Account Created</label><div class="profile-readonly"><?= e($officialCreatedAt !== '' ? date('F j, Y', strtotime($officialCreatedAt)) : '—') ?></div></div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </main>

  <!-- Action Modal -->
  <div class="modal-overlay" id="actionModal">
    <div class="modal-box" id="actionModalBox"></div>
  </div>

  <!-- Document Generation Modal -->
  <div class="modal-overlay" id="docGenModal">
    <div class="modal-box docgen-modal-box">
      <div class="docgen-modal-head">
        <h3 id="docGenTitle" class="docgen-modal-title">Generate Document</h3>
        <button type="button" class="close-card-btn" id="closeDocGenModal"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <div id="docGenMeta" class="docgen-modal-meta"></div>
      <div class="docgen-modal-grid">
        <div class="docgen-preview-col">
          <h4 class="docgen-preview-title">PDF Preview</h4>
          <div id="docGenPreviewEmpty" class="docgen-preview-empty">Generate a document to see its PDF preview here.</div>
          <div id="docGenPreviewWrap" class="docgen-preview-wrap" style="display:none;">
            <iframe id="docGenPreviewFrame" title="Generated document preview" class="docgen-preview-frame"></iframe>
          </div>
        </div>
        <div class="docgen-form-col">
          <div class="form-2col" id="docGenFields"></div>
          <div class="docgen-actions">
            <button type="button" class="btn-cancel-form" id="cancelDocGenModal">Close</button>
            <button type="button" class="btn-submit-form" id="generateDocBtn">Generate &amp; Preview</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Household Profiling Wizard Modal -->
  <div class="modal-overlay form-modal-overlay" id="householdWizardModal" aria-hidden="true">
    <div class="modal-box form-card household-wizard-box" role="dialog" aria-modal="true" aria-labelledby="householdWizardTitle">
      <div class="form-card-header">
        <h3 id="householdWizardTitle"><i class="fa-solid fa-house-user"></i> Profile Household</h3>
        <button class="close-card-btn" id="closeHouseholdWizard" type="button" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
      </div>

      <div class="household-wizard-meta" id="householdWizardMeta"></div>

      <div class="wizard-progress" id="householdWizardProgress">
        <div class="wz-step" data-step="1"><span class="wz-dot">1</span><span class="wz-label">Composition</span></div>
        <div class="wz-step" data-step="2"><span class="wz-dot">2</span><span class="wz-label">Education</span></div>
        <div class="wz-step" data-step="3"><span class="wz-dot">3</span><span class="wz-label">Health</span></div>
        <div class="wz-step" data-step="4"><span class="wz-dot">4</span><span class="wz-label">Housing</span></div>
        <div class="wz-step" data-step="5"><span class="wz-dot">5</span><span class="wz-label">Employment</span></div>
        <div class="wz-step" data-step="6"><span class="wz-dot">6</span><span class="wz-label">Income</span></div>
      </div>

      <form id="householdWizardForm" novalidate>
        <div id="householdWizardStepBody" class="household-wizard-step-body"></div>

        <div class="form-actions wizard-actions">
          <button type="button" class="btn-cancel-form" id="householdWizardCancel">Cancel</button>
          <span class="wizard-spacer"></span>
          <button type="button" class="btn-cancel-form" id="householdWizardBack" hidden><i class="fa-solid fa-arrow-left"></i> Back</button>
          <button type="button" class="btn-submit-form" id="householdWizardNext">Next <i class="fa-solid fa-arrow-right"></i></button>
          <button type="submit" class="btn-submit-form" id="householdWizardSave" hidden><i class="fa-solid fa-floppy-disk"></i> Save Step</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    window.IS_OFFICIAL_ADMIN = <?php echo $isOfficialAdmin ? 'true' : 'false'; ?>;
    window.OFFICIAL_ID = <?php echo (int)$_SESSION['official_id']; ?>;
    window.SMS_CONFIGURED = <?= (getenv('HTTPSMS_API_KEY') && getenv('HTTPSMS_FROM_NUMBER')) ? 'true' : 'false'; ?>;
    window.AI_CONFIGURED  = <?= getenv('GEMINI_API_KEY') ? 'true' : 'false'; ?>;
  </script>
  <script src="../js/official.js?v=<?= filemtime(__DIR__ . '/../js/official.js') ?>"></script>
</body>
</html>
