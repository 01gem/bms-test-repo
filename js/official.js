/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

/* ─────────────────────────────────────────
   BARANGAYLINK — OFFICIAL.JS
───────────────────────────────────────── */

const API_BASE = '../api';
const SIDEBAR_STATE_KEY = 'barangalink.sidebarCollapsed.official';
const ACTIVE_SECTION_KEY = 'official_active_section';
const SECTION_PLAQUES = {
  dashboard: { icon: 'fa-house', label: 'Office of the Barangay Captain' },
  residents: { icon: 'fa-users', label: 'Population Management' },
  requests: { icon: 'fa-file-lines', label: 'Records & Processing' },
  complaints: { icon: 'fa-triangle-exclamation', label: 'Grievance Redressal' },
  announcements: { icon: 'fa-bullhorn', label: 'Public Information' },
  services: { icon: 'fa-hand-holding-heart', label: 'Welfare & Operations' },
  aianalyst: { icon: 'fa-brain', label: 'AI Analytics' },
  officials: { icon: 'fa-user-shield', label: 'Human Resources' },
  auditlog: { icon: 'fa-shield-halved', label: 'Security & Audit' },
  profile: { icon: 'fa-user', label: 'My Official Profile' }
};
let RESIDENTS = [];
let OFFICIALS = [];
let DOC_REQUESTS = [];
let COMPLAINTS_ADMIN = [];
let ANNOUNCEMENTS_DATA = [];
let SERVICES_DATA = [];
let AUDIT_LOG = [];
let ACTIVE_DOC_GEN_REQUEST = null;
const FIXED_OFFICIAL_NAME = 'Hon. Erwin Astronomo';
const FIXED_OFFICIAL_POSITION = 'Barangay Captain';
const PUROK_OPTIONS = ['Purok 1', 'Purok 2', 'Purok 3', 'Purok 4', 'Purok 5'];
function featureEnabled(name) {
  return name === 'sms' ? !!window.SMS_CONFIGURED : !!window.AI_CONFIGURED;
}
function peso(n) {
  return '₱' + Number(n || 0).toFixed(2);
}
let DOC_REQUEST_REPORT = null;
let COMPLAINT_REPORT = null;

const DOC_TEMPLATE_FIELDS = {
  'Barangay Clearance': ['full_name', 'age', 'civil_status', 'address', 'purpose', 'date_issued'],
  'Certificate of Residency': ['full_name', 'address', 'years_of_residency', 'purpose', 'date_issued'],
  'Certificate of Indigency': ['full_name', 'address', 'purpose', 'date_issued'],
  'Certificate of Good Moral Character': ['full_name', 'address', 'purpose', 'date_issued'],
  'Business Permit Endorsement': ['owner_name', 'business_name', 'business_type', 'business_address', 'purpose', 'date_issued']
};

// ── TAB SWITCHING ─────────────────────────
function switchTab(name) {
  document.querySelectorAll('.snav-item[data-tab]').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active', p.id === `tab-${name}`));
  const plaque = SECTION_PLAQUES[name];
  const plaqueIcon = document.getElementById('sectionPlaqueIcon');
  const plaqueText = document.getElementById('sectionPlaque');
  if (plaque && plaqueIcon && plaqueText) {
    plaqueText.parentElement.className = `door-plaque door-plaque-${name}`;
    plaqueIcon.className = `fa-solid ${plaque.icon}`;
    plaqueText.textContent = plaque.label;
  }

  try {
    sessionStorage.setItem(ACTIVE_SECTION_KEY, name);
  } catch (err) {
    /* ignore storage failures */
  }
  if (name === 'aianalyst') {
    showAiView(null);
  }
  if (name === 'auditlog') {
    loadAuditLog().catch(err => showToastAdmin('Audit Log Load Failed', err.message));
  }
  if (name === 'officials' && window.IS_OFFICIAL_ADMIN) {
    loadOfficials().catch(err => showToastAdmin('Officials Load Failed', err.message));
  }
}

function isValidSectionName(name) {
  if (!name) return false;
  return !!document.querySelector(`.snav-item[data-tab="${name}"]`) && !!document.getElementById(`tab-${name}`);
}

function restoreActiveSection() {
  let saved = '';
  try {
    saved = sessionStorage.getItem(ACTIVE_SECTION_KEY) || '';
  } catch (err) {
    saved = '';
  }
  if (isValidSectionName(saved)) {
    switchTab(saved);
  } else if (saved) {
    try {
      sessionStorage.removeItem(ACTIVE_SECTION_KEY);
    } catch (err) {
      /* ignore storage failures */
    }
  }
}
function initNav() {
  document.querySelectorAll('.snav-item[data-tab]').forEach(btn => {
    btn.addEventListener('click', () => switchTab(btn.dataset.tab));
  });
}

function applySidebarState(collapsed) {
  document.body.classList.toggle('sidebar-collapsed', collapsed);
  const toggle = document.getElementById('sidebarToggle');
  const icon = toggle?.querySelector('i');
  if (toggle) {
    toggle.setAttribute('aria-expanded', String(!collapsed));
    toggle.setAttribute('aria-label', collapsed ? 'Show sidebar' : 'Hide sidebar');
  }
  if (icon) {
    icon.className = collapsed ? 'fa-solid fa-angles-right' : 'fa-solid fa-angles-left';
  }
}

function initSidebarToggle() {
  const toggle = document.getElementById('sidebarToggle');
  if (!toggle) return;
  let collapsed = false;
  try {
    collapsed = localStorage.getItem(SIDEBAR_STATE_KEY) === '1';
  } catch (err) {
    collapsed = false;
  }
  // Phones: default to collapsed so the fixed 272px sidebar doesn't eat
  // the viewport. Don't persist this override — localStorage is for the
  // user's desktop preference.
  const phoneQuery = window.matchMedia('(max-width: 640px)');
  if (phoneQuery.matches) {
    collapsed = true;
  }
  applySidebarState(collapsed);
  phoneQuery.addEventListener('change', () => {
    if (phoneQuery.matches) {
      applySidebarState(true);
      return;
    }
    let stored = false;
    try {
      stored = localStorage.getItem(SIDEBAR_STATE_KEY) === '1';
    } catch (err) {
      stored = false;
    }
    applySidebarState(stored);
  });
  toggle.addEventListener('click', () => {
    const next = !document.body.classList.contains('sidebar-collapsed');
    applySidebarState(next);
    try {
      localStorage.setItem(SIDEBAR_STATE_KEY, next ? '1' : '0');
    } catch (err) {
      /* ignore storage failures */
    }
  });
}

function initLogoutConfirmation() {
  document.querySelectorAll('.logout-item, .logout-btn').forEach(link => {
    link.addEventListener('click', (event) => {
      const ok = window.confirm('Do you want to log out?');
      if (!ok) {
        event.preventDefault();
      } else {
        try {
          sessionStorage.removeItem(ACTIVE_SECTION_KEY);
        } catch (err) {
          /* ignore storage failures */
        }
      }
    });
  });
}

function initOfficialProfilePhotoUpload() {
  const form = document.getElementById('officialProfilePhotoForm');
  const input = document.getElementById('officialProfilePhotoInput');
  if (!form || !input) return;
  input.addEventListener('change', () => {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    const modal = document.createElement('div');
    modal.className = 'photo-confirm-overlay';
    modal.innerHTML = `
      <div class="photo-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="officialPhotoConfirmTitle">
        <h3 id="officialPhotoConfirmTitle">Change your profile photo to this?</h3>
        <img class="photo-confirm-preview" alt="Selected profile photo preview" />
        <div class="photo-confirm-actions">
          <button type="button" class="btn-submit-form photo-confirm-btn">Confirm</button>
          <button type="button" class="btn-cancel-form photo-cancel-btn">Cancel</button>
        </div>
      </div>`;
    const preview = modal.querySelector('.photo-confirm-preview');
    const objectUrl = URL.createObjectURL(file);
    preview.src = objectUrl;
    document.body.appendChild(modal);
    const close = () => {
      URL.revokeObjectURL(objectUrl);
      input.value = '';
      modal.remove();
    };
    modal.querySelector('.photo-cancel-btn').addEventListener('click', close);
    modal.querySelector('.photo-confirm-btn').addEventListener('click', async () => {
      const body = new FormData();
      body.append('photo', file);
      try {
        const response = await fetch('../api/officials/upload_photo.php', { method: 'POST', body });
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.message || 'Photo upload failed.');
        close();
        showToastAdmin('Profile Photo Updated', 'Your profile photo was updated.');
        window.location.reload();
      } catch (error) {
        close();
        showToastAdmin('Upload Failed', error.message);
      }
    });
  });
}

// ── DASHBOARD ─────────────────────────────
function renderDashStats() {
  const el = document.getElementById('dashStats');
  if (!el) return;
  const totalResidents = RESIDENTS.length;
  const pendingRequests = DOC_REQUESTS.filter(r => r.status === 'pending').length;
  const openComplaints = COMPLAINTS_ADMIN.filter(c => c.status !== 'resolved').length;
  const verifiedServices = SERVICES_DATA.length;
  const stats = [
    { val:String(totalResidents),  lbl:'Total Residents',   trend:'0 this month', ico:'fa-users',      bg:'#DBEAFE', color:'#1976D2' },
    { val:String(pendingRequests), lbl:'Pending Requests',  trend:'0 pending',    ico:'fa-file-clock', bg:'#FEF9C3', color:'#D97706' },
    { val:String(openComplaints),  lbl:'Open Complaints',   trend:'0 open',       ico:'fa-flag',       bg:'#FEE2E2', color:'#EF4444' },
    { val:String(verifiedServices),lbl:'Verified Services', trend:'0 verified',   ico:'fa-store',      bg:'#CCFBF1', color:'#0D9488' },
  ];
  el.innerHTML = stats.map(s => `
    <div class="stat-card">
      <div class="sc-ico" style="background:${s.bg};color:${s.color}"><i class="fa-solid ${s.ico}"></i></div>
      <div class="sc-data">
        <div class="sc-val">${s.val}</div>
        <div class="sc-lbl">${s.lbl}</div>
        <div class="sc-trend"><i class="fa-solid fa-arrow-trend-up"></i> ${s.trend}</div>
      </div>
    </div>
  `).join('');
}

function renderDashPendingReqs() {
  const el = document.getElementById('dashPendingReqs');
  if (!el) return;
  const pending = DOC_REQUESTS.filter(r => r.status === 'pending').slice(0,3);
  if (!pending.length) {
    el.innerHTML = '<p style="color:var(--text-3);font-size:13px;padding:12px 0">No pending document requests.</p>';
    return;
  }
  el.innerHTML = pending.map(r => `
    <div class="mini-row">
      <div class="mr-icon" style="background:#DBEAFE;color:#1976D2"><i class="fa-solid fa-file-lines"></i></div>
      <div class="mr-text">
        <div class="mr-title">${r.resident}</div>
        <div class="mr-sub">${r.type} · ${r.date}</div>
      </div>
      <span class="status-badge status-pending">Pending</span>
    </div>
  `).join('');
}

function renderDashOpenComplaints() {
  const el = document.getElementById('dashOpenComplaints');
  if (!el) return;
  const open = COMPLAINTS_ADMIN.filter(c => c.status === 'open' || c.status === 'investigating').slice(0,3);
  if (!open.length) {
    el.innerHTML = '<p style="color:var(--text-3);font-size:13px;padding:12px 0">No open complaints.</p>';
    return;
  }
  el.innerHTML = open.map(c => `
    <div class="mini-row">
      <div class="mr-icon" style="background:#FEE2E2;color:#EF4444"><i class="fa-solid fa-triangle-exclamation"></i></div>
      <div class="mr-text">
        <div class="mr-title">${c.cat}</div>
        <div class="mr-sub">${c.filer} · ${c.loc}</div>
      </div>
      <span class="status-badge status-${c.status}">${c.status}</span>
    </div>
  `).join('');
}

function renderDashActivity() {
  const el = document.getElementById('dashActivity');
  if (!el) return;
  const COLORS = { 'Request Approved':'#059669', 'Request Submitted':'#1976D2', 'Announcement Posted':'#D97706', 'Complaint Filed':'#EF4444', 'Resident Verified':'#0D9488', 'Login':'#94A3B8', 'Status Updated':'#0EA5E9' };
  const recent = AUDIT_LOG.slice(0,6);
  if (!recent.length) {
    el.innerHTML = '<p style="color:var(--text-3);font-size:13px;padding:12px 0">No recent activity.</p>';
    return;
  }
  el.innerHTML = recent.map(a => `
    <div class="activity-row">
      <div class="act-dot" style="background:${COLORS[a.action] || '#94A3B8'}"></div>
      <div class="act-text"><strong>${a.user}</strong> — ${a.action} in <strong>${a.module}</strong></div>
      <div class="act-time">${a.ts.split(' ')[1]}</div>
    </div>
  `).join('');
}

// ── RESIDENTS TABLE ───────────────────────
function renderResidents(data) {
  const tbody = document.getElementById('residentsBody');
  if (!tbody) return;
  tbody.innerHTML = data.map(r => {
    const toggleLabel = r.status === 'active' ? 'Deactivate' : 'Activate';
    return `
    <tr>
      <td><code style="font-size:12px;color:var(--blue-mid)">${r.id}</code></td>
      <td style="font-weight:600;color:var(--text)">
        ${r.profilePhoto ? `<img class="resident-photo-thumb" src="../${escapeAttribute(r.profilePhoto)}" alt="" />` : `<span class="resident-initials-thumb">${escapeAttribute(getInitials(r.name))}</span>`}
        ${escapeAttribute(r.name)}
      </td>
      <td>${escapeAttribute(r.addr)}</td>
      <td>${escapeAttribute(r.contact)}</td>
      <td><span class="status-badge status-${r.status === 'active' ? 'verified' : 'suspended'}">${escapeAttribute(r.status)}</span></td>
      <td class="action-column">
        <div class="action-btns">
          <button class="btn-action view" onclick="showResidentProfile(${r.dbId})">View</button>
          <button class="btn-action process" onclick="openEditResidentForm(${r.dbId})">Edit</button>
          <button class="btn-action ${r.status === 'active' ? 'reject' : 'approve'}" onclick="toggleResidentStatus(${r.dbId})">${toggleLabel}</button>
        </div>
      </td>
    </tr>
  `;
  }).join('');
}
function initResidentTable() {
  renderResidents(RESIDENTS);
  document.getElementById('residentSearch')?.addEventListener('input', filterResidents);
  document.getElementById('residentStatusFilter')?.addEventListener('change', filterResidents);
}
function filterResidents() {
  const q = document.getElementById('residentSearch').value.toLowerCase();
  const s = document.getElementById('residentStatusFilter').value;
  renderResidents(RESIDENTS.filter(r => {
    return (r.name.toLowerCase().includes(q) || r.addr.toLowerCase().includes(q)) &&
           (s === 'all' || r.status === s);
  }));
}

// ── DOC REQUESTS TABLE ────────────────────
function renderDocRequests(data) {
  const tbody = document.getElementById('reqsBody');
  if (!tbody) return;
  if (!data.length) {
    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;color:var(--text-3);padding:32px;">No document requests found.</td></tr>`;
    return;
  }
  tbody.innerHTML = data.map(r => `
    <tr>
      <td><code style="font-size:12px;color:var(--blue-mid)">${escapeAttribute(r.ref)}</code></td>
      <td style="font-weight:600">${escapeAttribute(r.resident)}</td>
      <td>${escapeAttribute(r.type)}</td>
      <td>${escapeAttribute(r.purpose)}</td>
      <td>${escapeAttribute(r.date)}</td>
      <td><span class="status-badge status-${escapeAttribute(r.status)}">${escapeAttribute(r.status)}</span></td>
      <td class="action-column">
        <div class="action-btns">
          <button class="btn-action view" onclick="showRequestDetails(${r.id})">View</button>
        </div>
      </td>
    </tr>
  `).join('');
}
async function updateReqStatus(ref, newStatus, officialNote = '') {
  try {
    const payload = { reference_no: ref, status: newStatus };
    if (officialNote) payload.official_note = officialNote;
    const data = await fetchJson(`${API_BASE}/requests/update_status.php`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Portal-Source': 'official'
      },
      body: JSON.stringify(payload)
    });
    await loadRequests();
    await loadAuditLog();
    await refreshDashboard();
    showToastAdmin('Status Updated', data.message || `${ref} status set to ${newStatus}.`);
    const modalEl = document.getElementById('actionModal');
    if (modalEl?.classList.contains('show')) {
      const fresh = DOC_REQUESTS.find(r => r.ref === ref);
      if (fresh) showRequestDetails(fresh.id);
    }
  } catch (err) {
    showToastAdmin('Update Failed', err.message);
  }
}
async function updateReqPayment(ref, paymentStatus) {
  try {
    const data = await fetchJson(`${API_BASE}/requests/update_payment.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ reference_no: ref, payment_status: paymentStatus })
    });
    await loadRequests();
    await loadAuditLog();
    showToastAdmin('Payment Updated', data.message || `${ref} payment set to ${paymentStatus}.`);
    const modalEl = document.getElementById('actionModal');
    if (modalEl?.classList.contains('show')) {
      const fresh = DOC_REQUESTS.find(r => r.ref === ref);
      if (fresh) showRequestDetails(fresh.id);
    }
  } catch (err) {
    showToastAdmin('Payment Update Failed', err.message);
  }
}

async function cancelReq(ref) {
  const reason = window.prompt('Brief reason for cancelling this request (required):');
  if (reason === null) return;
  const note = reason.trim();
  if (!note) {
    showToastAdmin('Cancellation Failed', 'A brief cancellation reason is required.');
    return;
  }
  await updateReqStatus(ref, 'cancelled', note);
}

function prettifyFieldName(fieldKey) {
  return fieldKey
    .split('_')
    .map(part => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ');
}

function escapeAttribute(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

function getResidentDefaultsForRequest(req) {
  if (!req) return { full_name: '', address: '' };
  const resident = RESIDENTS.find(r => Number(r.dbId) === Number(req.residentId));
  return {
    full_name: resident?.name || req.resident || '',
    address: resident && resident.addr !== '-' ? resident.addr : ''
  };
}

function closeDocumentGenerationModal() {
  const modal = document.getElementById('docGenModal');
  const fieldsWrap = document.getElementById('docGenFields');
  const previewWrap = document.getElementById('docGenPreviewWrap');
  const previewFrame = document.getElementById('docGenPreviewFrame');
  const previewEmpty = document.getElementById('docGenPreviewEmpty');
  if (modal) modal.classList.remove('show');
  if (fieldsWrap) fieldsWrap.innerHTML = '';
  if (previewWrap) previewWrap.style.display = 'none';
  if (previewEmpty) previewEmpty.style.display = 'flex';
  if (previewEmpty) previewEmpty.textContent = 'Generate a document to see its PDF preview here.';
  if (previewFrame) previewFrame.src = 'about:blank';
  ACTIVE_DOC_GEN_REQUEST = null;
}

function renderDocumentGenerationFields(req) {
  const fieldsWrap = document.getElementById('docGenFields');
  if (!fieldsWrap) return;
  const keys = DOC_TEMPLATE_FIELDS[req.type] || [];
  const residentDefaults = getResidentDefaultsForRequest(req);
  const stored = req.requestFields || {};
  const todayIso = new Date().toISOString().slice(0, 10);
  const purposeDefault = req.purpose || '';

  fieldsWrap.innerHTML = keys.map((key) => {
    let value = '';
    // Resident-supplied value wins over resident-record defaults.
    if (Object.prototype.hasOwnProperty.call(stored, key)) {
      value = String(stored[key]);
    } else {
      if (key === 'full_name' || key === 'owner_name') value = residentDefaults.full_name;
      if (key === 'address' || key === 'business_address') value = residentDefaults.address;
      if (key === 'purpose') value = purposeDefault;
      if (key === 'date_issued') value = todayIso;
    }
    const isDate = key === 'date_issued';
    return `
      <div class="field" style="margin-top:8px;">
        <label>${prettifyFieldName(key)}</label>
        <input type="${isDate ? 'date' : 'text'}" class="form-input"
          data-doc-field="${key}" value="${escapeAttribute(value)}" />
      </div>
    `;
  }).join('');
}

function openDocumentGenerationModal(ref) {
  const req = DOC_REQUESTS.find(r => r.ref === ref);
  if (!req) return;
  if (!DOC_TEMPLATE_FIELDS[req.type]) {
    showToastAdmin('Template Missing', `No template configuration found for "${req.type}".`);
    return;
  }
  ACTIVE_DOC_GEN_REQUEST = req;
  const modal = document.getElementById('docGenModal');
  const title = document.getElementById('docGenTitle');
  const meta = document.getElementById('docGenMeta');
  const previewWrap = document.getElementById('docGenPreviewWrap');
  const previewEmpty = document.getElementById('docGenPreviewEmpty');
  if (title) title.textContent = `Generate ${req.type}`;
  if (meta) meta.textContent =
    `Reference: ${req.ref} · Resident: ${req.resident} · Signed by ${FIXED_OFFICIAL_NAME} (${FIXED_OFFICIAL_POSITION}) — fixed for this build`;
  if (previewWrap) previewWrap.style.display = 'none';
  if (previewEmpty) previewEmpty.style.display = 'flex';
  renderDocumentGenerationFields(req);
  if (modal) modal.classList.add('show');
}

async function generateAndPreviewDocument() {
  if (!ACTIVE_DOC_GEN_REQUEST) return;
  const fields = {};
  document.querySelectorAll('#docGenFields [data-doc-field]').forEach((input) => {
    const key = input.getAttribute('data-doc-field');
    if (!key) return;
    fields[key] = (input.value || '').trim();
  });
  fields.official_name = FIXED_OFFICIAL_NAME;
  fields.official_position = FIXED_OFFICIAL_POSITION;

  const previewEmpty = document.getElementById('docGenPreviewEmpty');
  const previewWrap = document.getElementById('docGenPreviewWrap');
  const previewFrame = document.getElementById('docGenPreviewFrame');
  if (previewWrap) previewWrap.style.display = 'none';
  if (previewFrame) previewFrame.src = 'about:blank';
  if (previewEmpty) {
    previewEmpty.innerHTML = '<span class="docgen-loading"><i class="fa-solid fa-spinner fa-spin"></i> Generating document...</span>';
    previewEmpty.style.display = 'flex';
  }
  try {
    const data = await fetchJson(`${API_BASE}/requests/generate_document.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        id: ACTIVE_DOC_GEN_REQUEST.id,
        fields
      })
    });

    if (previewFrame && data.pdf_path) {
      const separator = data.pdf_path.includes('?') ? '&' : '?';
      previewFrame.src = `${data.pdf_path}${separator}t=${Date.now()}`;
    }
    if (previewWrap) previewWrap.style.display = 'block';
    if (previewEmpty) previewEmpty.style.display = 'none';

    await updateReqStatus(ACTIVE_DOC_GEN_REQUEST.ref, 'processing');
    showToastAdmin('Document Generated', `Generated PDF for ${ACTIVE_DOC_GEN_REQUEST.ref}.`);
  } catch (err) {
    if (previewEmpty) {
      previewEmpty.textContent = `Generation failed: ${err.message}`;
      previewEmpty.style.display = 'flex';
    }
    showToastAdmin('Generation Failed', err.message);
  }
}

function initDocumentGenerationModal() {
  document.getElementById('closeDocGenModal')?.addEventListener('click', closeDocumentGenerationModal);
  document.getElementById('cancelDocGenModal')?.addEventListener('click', closeDocumentGenerationModal);
  document.getElementById('generateDocBtn')?.addEventListener('click', generateAndPreviewDocument);
}

function showAiView(view) {
  const hub = document.getElementById('aiHub');
  if (!hub) return;
  hub.hidden = !!view;
  document.querySelectorAll('[data-ai-subview]').forEach(panel => {
    panel.hidden = panel.dataset.aiSubview !== view;
  });
  if (view === 'registry') {
    rerollRegistryOrder();
  }
  if (view === 'docreports') {
    loadReports().catch(err => showToastAdmin('Report Load Failed', err.message));
  }
  if (view === 'complaintreports') {
    loadComplaintReport().catch(err => showToastAdmin('Report Load Failed', err.message));
  }
}

function initAiHub() {
  document.querySelectorAll('button[data-ai-view]').forEach(tile => {
    tile.addEventListener('click', () => showAiView(tile.dataset.aiView));
  });
  document.querySelectorAll('.ai-back-btn').forEach(btn => {
    btn.addEventListener('click', () => showAiView(null));
  });
  if (!featureEnabled('ai')) {
    const hub = document.getElementById('aiHub');
    if (hub && !hub.querySelector('.ai-disabled-note')) {
      const note = document.createElement('p');
      note.className = 'ai-disabled-note';
      note.textContent = 'AI features are disabled in this build (GEMINI_API_KEY not configured).';
      hub.parentElement.insertBefore(note, hub);
    }
    if (hub) hub.querySelectorAll('button.ai-tool-tile').forEach(b => { b.disabled = true; });
  }
}

async function loadDocumentPrices() {
  const data = await fetchJson(`${API_BASE}/document_prices/list.php`);
  renderDocumentPrices(data.prices || []);
}

function renderDocumentPrices(prices) {
  const body = document.getElementById('docPricesBody');
  if (!body) return;
  body.innerHTML = (prices || []).map(p => `
    <tr>
      <td style="font-weight:600">${escapeHtml(p.document_type)}</td>
      <td>${peso(p.unit_price)}</td>
      <td>${escapeHtml(p.updated_at || '—')}</td>
      <td class="action-column">
        <button type="button" class="btn-action process" onclick="editDocumentPrice(${Number(p.id)}, '${escapeAttribute(p.document_type)}', ${Number(p.unit_price)})">Edit</button>
      </td>
    </tr>`).join('') || '<tr><td colspan="4" class="registry-empty">No prices configured.</td></tr>';
}

async function editDocumentPrice(id, type, current) {
  const raw = window.prompt(`New unit price for ${type} (₱):`, Number(current).toFixed(2));
  if (raw === null) return;
  const price = Number(raw);
  if (!Number.isFinite(price) || price < 0) {
    showToastAdmin('Invalid Price', 'Enter a non-negative amount.');
    return;
  }
  try {
    await fetchJson(`${API_BASE}/document_prices/update.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id, unit_price: price })
    });
    await loadDocumentPrices();
    showToastAdmin('Price Updated', `${type} is now ${peso(price)}.`);
  } catch (err) {
    showToastAdmin('Price Update Failed', err.message);
  }
}

async function loadRequestReport() {
  const data = await fetchJson(`${API_BASE}/stats/requests_report.php`);
  DOC_REQUEST_REPORT = data;
  const t = data.totals || {};
  const el = document.getElementById('docRequestStats');
  if (el) {
    el.innerHTML = [
      { lbl: 'Total Requests',  val: String(t.request_count || 0), ico: 'fa-file-lines',         bg: '#DBEAFE', color: '#1976D2' },
      { lbl: 'Total Copies',    val: String(t.total_copies || 0),  ico: 'fa-copy',               bg: '#E0E7FF', color: '#4338CA' },
      { lbl: 'Gross Billed',    val: peso(t.gross_amount),         ico: 'fa-receipt',            bg: '#FEF3C7', color: '#D97706' },
      { lbl: 'Total Collected', val: peso(t.collected_amount),     ico: 'fa-sack-dollar',        bg: '#DCFCE7', color: '#059669' },
      { lbl: 'Pending Payment', val: peso(t.pending_amount),       ico: 'fa-hourglass-half',     bg: '#FEF9C3', color: '#B45309' },
      { lbl: 'Waived',          val: peso(t.waived_amount),        ico: 'fa-hand-holding-heart', bg: '#F1F5F9', color: '#475569' },
    ].map(s => `
      <div class="stat-card">
        <div class="sc-ico" style="background:${s.bg};color:${s.color}"><i class="fa-solid ${s.ico}"></i></div>
        <div class="sc-data"><div class="sc-val">${s.val}</div><div class="sc-lbl">${s.lbl}</div></div>
      </div>`).join('');
  }

  const monthlyBody = document.getElementById('docRequestMonthlyBody');
  if (monthlyBody) {
    monthlyBody.innerHTML = (data.monthly || []).map(m => `
      <tr>
        <td>${escapeHtml(m.ym || '—')}</td>
        <td>${Number(m.request_count || 0)}</td>
        <td>${Number(m.total_copies || 0)}</td>
        <td>${peso(m.gross_amount)}</td>
        <td style="font-weight:600;color:var(--success)">${peso(m.collected_amount)}</td>
        <td>${peso(m.pending_amount)}</td>
        <td>${peso(m.waived_amount)}</td>
      </tr>`).join('') || '<tr><td colspan="7" class="registry-empty">No requests yet.</td></tr>';
  }

  const typeBody = document.getElementById('docRequestTypeBody');
  if (typeBody) {
    typeBody.innerHTML = (data.by_type || []).map(row => `
      <tr>
        <td style="font-weight:600">${escapeHtml(row.document_type)}</td>
        <td>${Number(row.request_count || 0)}</td>
        <td>${Number(row.total_copies || 0)}</td>
        <td>${peso(row.gross_amount)}</td>
        <td style="font-weight:600;color:var(--success)">${peso(row.collected_amount)}</td>
      </tr>`).join('') || '<tr><td colspan="5" class="registry-empty">No data yet.</td></tr>';
  }
}

async function loadComplaintReport() {
  const data = await fetchJson(`${API_BASE}/stats/complaints_report.php`);
  COMPLAINT_REPORT = data;
  const t = data.totals || {};
  const statsEl = document.getElementById('complaintStats');
  if (statsEl) {
    statsEl.innerHTML = [
      { lbl: 'Total Complaints', val: String(t.total || 0),          ico: 'fa-flag',             bg: '#FEE2E2', color: '#EF4444' },
      { lbl: 'Open',             val: String(t.open || 0),           ico: 'fa-door-open',        bg: '#FEF3C7', color: '#B45309' },
      { lbl: 'Investigating',    val: String(t.investigating || 0),  ico: 'fa-magnifying-glass', bg: '#E0F2FE', color: '#0284C7' },
      { lbl: 'Resolved',         val: String(t.resolved || 0),       ico: 'fa-circle-check',     bg: '#DCFCE7', color: '#059669' },
    ].map(s => `
      <div class="stat-card">
        <div class="sc-ico" style="background:${s.bg};color:${s.color}"><i class="fa-solid ${s.ico}"></i></div>
        <div class="sc-data"><div class="sc-val">${s.val}</div><div class="sc-lbl">${s.lbl}</div></div>
      </div>`).join('');
  }

  const renderBars = (id, rows, keyName) => {
    const el = document.getElementById(id);
    if (!el) return;
    const max = rows.reduce((m, r) => Math.max(m, Number(r.count || 0)), 0);
    el.innerHTML = rows.map(r => {
      const pct = max ? Math.round((Number(r.count) / max) * 100) : 0;
      return `<div class="workforce-bar-row">
        <span class="workforce-bar-label">${escapeHtml(r[keyName])}</span>
        <span class="workforce-bar-track"><span class="workforce-bar-fill" style="width:${pct}%"></span></span>
        <span class="workforce-bar-count">${Number(r.count || 0)}</span>
      </div>`;
    }).join('') || '<p class="workforce-empty">No data.</p>';
  };
  renderBars('complaintByCategory', data.by_category || [], 'category');
  renderBars('complaintBySeverity', data.by_severity || [], 'severity');

  const crossBody = document.getElementById('complaintCrossBody');
  if (crossBody) {
    const map = {};
    (data.cross || []).forEach(r => {
      map[r.category] ??= { low: 0, medium: 0, high: 0, critical: 0, total: 0 };
      map[r.category][r.severity] = Number(r.count);
      map[r.category].total += Number(r.count);
    });
    const rows = Object.entries(map).sort((a, b) => b[1].total - a[1].total);
    crossBody.innerHTML = rows.map(([cat, v]) => `
      <tr>
        <td style="font-weight:600">${escapeHtml(cat)}</td>
        <td>${v.low}</td><td>${v.medium}</td><td>${v.high}</td><td>${v.critical}</td>
        <td style="font-weight:700">${v.total}</td>
      </tr>`).join('') || '<tr><td colspan="6" class="registry-empty">No complaints yet.</td></tr>';
  }

  const rankBody = document.getElementById('complaintRankingBody');
  if (rankBody) {
    rankBody.innerHTML = (data.monthly_rank || []).map(r => `
      <tr>
        <td>${escapeHtml(r.ym || '—')}</td>
        <td>#${Number(r.rn || 0)}</td>
        <td style="font-weight:600">${escapeHtml(r.category || '')}</td>
        <td>${Number(r.count || 0)}</td>
      </tr>`).join('') || '<tr><td colspan="4" class="registry-empty">No complaints yet.</td></tr>';
  }
}

async function loadReports() {
  await Promise.all([
    loadRequestReport(),
    loadComplaintReport(),
    loadDocumentPrices(),
  ]);
}

function renderAiCommentaryBox(targetId, payload) {
  const el = document.getElementById(targetId);
  if (!el) return;
  if (!payload) {
    el.className = 'ai-echo-response';
    el.textContent = 'No commentary generated yet.';
    return;
  }
  const block = (title, items) => items && items.length
    ? `<div style="margin-top:10px"><div style="font-weight:700;color:var(--text);font-size:13px;margin-bottom:4px">${title}</div>
         <ul style="margin:0;padding-left:18px;color:var(--text-2);font-size:13px">${items.map(i => `<li>${escapeHtml(i)}</li>`).join('')}</ul></div>`
    : '';
  el.className = 'ai-echo-response is-success';
  el.innerHTML = `
    <div style="font-size:14px;color:var(--text);line-height:1.6">${escapeHtml(payload.summary || '')}</div>
    ${block('Most requested types', payload.most_requested_types)}
    ${block('Top issues', payload.top_issues)}
    ${block('Patterns', payload.patterns)}
    ${block('Recommendations', payload.recommendations)}
    ${payload.model ? `<div style="margin-top:10px;color:var(--text-3);font-size:11px">Model: ${escapeHtml(payload.model)}</div>` : ''}
  `;
}

async function runDocumentRequestAnalysis(button) {
  const out = document.getElementById('docReportsAiOutput');
  button.disabled = true;
  if (out) { out.className = 'ai-echo-response is-loading'; out.textContent = 'Analyzing document-request report…'; }
  try {
    const data = await fetchJson(`${API_BASE}/ai/analyze_document_requests.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({})
    });
    if (data.reason === 'no_data') {
      if (out) { out.className = 'ai-echo-response'; out.textContent = 'No document requests to analyze yet.'; }
      return;
    }
    renderAiCommentaryBox('docReportsAiOutput', { ...data.analysis, model: data.model });
  } catch (err) {
    if (out) { out.className = 'ai-echo-response is-error'; out.textContent = `Analysis failed: ${err.message}`; }
  } finally {
    button.disabled = false;
  }
}

async function runComplaintAnalysis(button) {
  const out = document.getElementById('complaintReportsAiOutput');
  button.disabled = true;
  if (out) { out.className = 'ai-echo-response is-loading'; out.textContent = 'Analyzing complaints report…'; }
  try {
    const data = await fetchJson(`${API_BASE}/ai/analyze_complaints.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({})
    });
    if (data.reason === 'no_data') {
      if (out) { out.className = 'ai-echo-response'; out.textContent = 'No complaints to analyze yet.'; }
      return;
    }
    renderAiCommentaryBox('complaintReportsAiOutput', { ...data.analysis, model: data.model });
  } catch (err) {
    if (out) { out.className = 'ai-echo-response is-error'; out.textContent = `Analysis failed: ${err.message}`; }
  } finally {
    button.disabled = false;
  }
}

function csvEscape(v) {
  let s = String(v ?? '');
  if (/^[=@\t\r]/.test(s) || (/^[+\-]/.test(s) && !/^[+\-]?[\d\s()-]+$/.test(s))) s = `'${s}`;
  return /[",\r\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

function downloadCsv(filename, rows) {
  const lines = rows.map(r => r.map(csvEscape).join(','));
  const blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 0);
}

function exportDocumentRequestCsv() {
  if (!DOC_REQUEST_REPORT) {
    showToastAdmin('Nothing to Export', 'Load the report first.');
    return;
  }
  const d = DOC_REQUEST_REPORT;
  const stamp = new Date().toISOString().slice(0, 10);
  const rows = [];

  rows.push(['Document Request Report']);
  rows.push(['Generated', new Date().toLocaleString()]);
  rows.push([]);
  rows.push(['TOTAL']);
  rows.push(['Metric', 'Value']);
  rows.push(['Total Requests',  d.totals?.request_count ?? 0]);
  rows.push(['Total Copies',    d.totals?.total_copies ?? 0]);
  rows.push(['Gross Billed',    Number(d.totals?.gross_amount || 0).toFixed(2)]);
  rows.push(['Collected',       Number(d.totals?.collected_amount || 0).toFixed(2)]);
  rows.push(['Pending',         Number(d.totals?.pending_amount || 0).toFixed(2)]);
  rows.push(['Waived',          Number(d.totals?.waived_amount || 0).toFixed(2)]);
  rows.push([]);

  rows.push(['MONTHLY SUMMARY']);
  rows.push(['Month', 'Requests', 'Copies', 'Gross', 'Collected', 'Pending', 'Waived']);
  (d.monthly || []).forEach(m => rows.push([
    m.ym || '',
    Number(m.request_count || 0),
    Number(m.total_copies || 0),
    Number(m.gross_amount || 0).toFixed(2),
    Number(m.collected_amount || 0).toFixed(2),
    Number(m.pending_amount || 0).toFixed(2),
    Number(m.waived_amount || 0).toFixed(2),
  ]));
  rows.push([]);

  rows.push(['BY DOCUMENT TYPE']);
  rows.push(['Document', 'Requests', 'Copies', 'Gross', 'Collected']);
  (d.by_type || []).forEach(row => rows.push([
    row.document_type || '',
    Number(row.request_count || 0),
    Number(row.total_copies || 0),
    Number(row.gross_amount || 0).toFixed(2),
    Number(row.collected_amount || 0).toFixed(2),
  ]));

  downloadCsv(`document-request-report-${stamp}.csv`, rows);
}

function exportComplaintCsv() {
  if (!COMPLAINT_REPORT) {
    showToastAdmin('Nothing to Export', 'Load the report first.');
    return;
  }
  const d = COMPLAINT_REPORT;
  const stamp = new Date().toISOString().slice(0, 10);
  const rows = [];

  rows.push(['Complaints Report']);
  rows.push(['Generated', new Date().toLocaleString()]);
  rows.push([]);
  rows.push(['TOTAL']);
  rows.push(['Metric', 'Value']);
  rows.push(['Total',         d.totals?.total ?? 0]);
  rows.push(['Open',          d.totals?.open ?? 0]);
  rows.push(['Investigating', d.totals?.investigating ?? 0]);
  rows.push(['Resolved',      d.totals?.resolved ?? 0]);
  rows.push([]);

  rows.push(['BY CATEGORY']);
  rows.push(['Category', 'Count']);
  (d.by_category || []).forEach(r => rows.push([r.category || '', Number(r.count || 0)]));
  rows.push([]);

  rows.push(['BY SEVERITY']);
  rows.push(['Severity', 'Count']);
  (d.by_severity || []).forEach(r => rows.push([r.severity || '', Number(r.count || 0)]));
  rows.push([]);

  rows.push(['CATEGORY × SEVERITY']);
  rows.push(['Category', 'Low', 'Medium', 'High', 'Critical', 'Total']);
  const map = {};
  (d.cross || []).forEach(r => {
    map[r.category] ??= { low: 0, medium: 0, high: 0, critical: 0, total: 0 };
    map[r.category][r.severity] = Number(r.count);
    map[r.category].total += Number(r.count);
  });
  Object.entries(map).forEach(([cat, v]) => rows.push([cat, v.low, v.medium, v.high, v.critical, v.total]));
  rows.push([]);

  rows.push(['MONTHLY RANKING (Top 5 per month)']);
  rows.push(['Month', 'Rank', 'Category', 'Count']);
  (d.monthly_rank || []).forEach(r => rows.push([r.ym || '', Number(r.rn || 0), r.category || '', Number(r.count || 0)]));

  downloadCsv(`complaints-report-${stamp}.csv`, rows);
}

function initReportTiles() {
  document.getElementById('docReportsAiBtn')?.addEventListener('click', e => runDocumentRequestAnalysis(e.currentTarget));
  document.getElementById('docReportsRefreshBtn')?.addEventListener('click', () => {
    loadReports().catch(err => showToastAdmin('Refresh Failed', err.message));
  });
  document.getElementById('docReportsExportBtn')?.addEventListener('click', exportDocumentRequestCsv);

  document.getElementById('complaintReportsAiBtn')?.addEventListener('click', e => runComplaintAnalysis(e.currentTarget));
  document.getElementById('complaintReportsRefreshBtn')?.addEventListener('click', () => {
    loadComplaintReport().catch(err => showToastAdmin('Refresh Failed', err.message));
  });
  document.getElementById('complaintReportsExportBtn')?.addEventListener('click', exportComplaintCsv);
}

function initAiEchoTest() {
  const promptInput = document.getElementById('aiEchoPrompt');
  const sendButton = document.getElementById('aiEchoSendBtn');
  const responseArea = document.getElementById('aiEchoResponse');
  if (!promptInput || !sendButton || !responseArea) return;

  sendButton.addEventListener('click', async () => {
    sendButton.disabled = true;
    responseArea.className = 'ai-echo-response is-loading';
    responseArea.textContent = 'Waiting for AI response...';

    try {
      const data = await fetchJson(`${API_BASE}/ai/echo_test.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ prompt: promptInput.value.trim() })
      });
      responseArea.className = 'ai-echo-response is-success';
      responseArea.textContent = data.reply || 'AI returned an empty reply.';
    } catch (err) {
      responseArea.className = 'ai-echo-response is-error';
      responseArea.textContent = `Connectivity test failed: ${err.message}`;
    } finally {
      sendButton.disabled = false;
    }
  });
}

// ── WORKFORCE INSIGHTS ────────────────────
const WORKFORCE_CATEGORIES = [
  'Skilled Trades', 'Unskilled Labor', 'Agriculture & Fishing', 'Office & Professional',
  'Sales & Services', 'Transport & Driving', 'Business Owner', 'Homemaker', 'Student',
  'Retired', 'Other', 'No Skills Listed'
];
const WORKFORCE_BATCH_SIZE = 25;
const WORKFORCE_UNCLASSIFIED = 'Unclassified';

// Values outside the fixed list (legacy imports) count as unclassified, matching the server.
function workforceCategory(resident) {
  const value = String(resident.skill_category || '');
  return WORKFORCE_CATEGORIES.includes(value) ? value : '';
}

function residentAgeYears(birthdate) {
  if (!birthdate) return null;
  const birth = new Date(`${birthdate}T00:00:00`);
  if (Number.isNaN(birth.getTime())) return null;
  const today = new Date();
  let age = today.getFullYear() - birth.getFullYear();
  const beforeBirthday = today.getMonth() < birth.getMonth()
    || (today.getMonth() === birth.getMonth() && today.getDate() < birth.getDate());
  if (beforeBirthday) age--;
  return age;
}

function workforceNeedsGemini(resident) {
  if (resident.employment_status === 'Student' || resident.employment_status === 'Retired') return false;
  return Boolean(String(resident.occupation || '').trim());
}

function workforceDistribution(residents, getValue, order = null, emptyLabel = 'Not provided') {
  const counts = new Map();
  residents.forEach(resident => {
    const key = getValue(resident) || emptyLabel;
    counts.set(key, (counts.get(key) || 0) + 1);
  });
  const keys = order
    ? [...order.filter(key => counts.has(key)), ...[...counts.keys()].filter(key => !order.includes(key))]
    : [...counts.keys()].sort((a, b) => counts.get(b) - counts.get(a) || a.localeCompare(b));
  return keys.map(label => ({ label, count: counts.get(label) }));
}

function workforceChartHtml(title, rows, total, mutedLabels = []) {
  const max = rows.reduce((peak, row) => Math.max(peak, row.count), 0);
  const bars = rows.map(row => {
    const width = max ? Math.round((row.count / max) * 100) : 0;
    const share = total ? Math.round((row.count / total) * 100) : 0;
    const muted = mutedLabels.includes(row.label) ? ' is-muted' : '';
    return `<div class="workforce-bar-row">
      <span class="workforce-bar-label" title="${escapeAttribute(row.label)}">${escapeHtml(row.label)}</span>
      <span class="workforce-bar-track"><span class="workforce-bar-fill${muted}" style="width:${width}%"></span></span>
      <span class="workforce-bar-count">${row.count} · ${share}%</span>
    </div>`;
  }).join('');
  return `<div class="workforce-chart"><h4>${escapeHtml(title)}</h4>${bars || '<p class="workforce-empty">No data.</p>'}</div>`;
}

function renderWorkforceInsights() {
  const filter = document.getElementById('workforcePurokFilter');
  const statsEl = document.getElementById('workforceStats');
  const chartsEl = document.getElementById('workforceCharts');
  if (!filter || !statsEl || !chartsEl) return;

  const active = activeResidents();
  const puroks = [...new Set(active.map(r => r.purok_zone).filter(Boolean))].sort();
  const selected = filter.value;
  filter.innerHTML = '<option value="all">All Puroks</option>'
    + puroks.map(purok => `<option value="${escapeAttribute(purok)}">${escapeHtml(purok)}</option>`).join('');
  filter.value = puroks.includes(selected) ? selected : 'all';

  const residents = active.filter(resident => filter.value === 'all' || resident.purok_zone === filter.value);
  const availableNow = resident => ['Unemployed', 'Self-Employed'].includes(resident.employment_status)
    && resident.work_availability !== 'Not looking';
  const stats = [
    {
      val: residents.filter(r => { const age = residentAgeYears(r.birthdate); return age !== null && age >= 18 && age <= 30 && r.employment_status === 'Unemployed'; }).length,
      lbl: 'Unemployed Youth (18–30)', ico: 'fa-user-clock', bg: '#FEE2E2', color: '#EF4444'
    },
    { val: residents.filter(r => r.educational_attainment === 'College').length, lbl: 'College Graduates', ico: 'fa-graduation-cap', bg: '#DBEAFE', color: '#1976D2' },
    { val: residents.filter(availableNow).length, lbl: 'Available Now', ico: 'fa-briefcase', bg: '#DCFCE7', color: '#16A34A' },
    {
      val: residents.filter(r => workforceCategory(r) === 'Skilled Trades' && r.work_availability !== 'Not looking').length,
      lbl: 'Skilled Trades Available', ico: 'fa-screwdriver-wrench', bg: '#FEF9C3', color: '#D97706'
    }
  ];
  statsEl.innerHTML = stats.map(s => `
    <div class="stat-card">
      <div class="sc-ico" style="background:${s.bg};color:${s.color}"><i class="fa-solid ${s.ico}"></i></div>
      <div class="sc-data"><div class="sc-val">${s.val}</div><div class="sc-lbl">${escapeHtml(s.lbl)}</div></div>
    </div>`).join('');

  const total = residents.length;
  chartsEl.innerHTML = [
    workforceChartHtml('Skill Category',
      workforceDistribution(residents, workforceCategory, [...WORKFORCE_CATEGORIES, WORKFORCE_UNCLASSIFIED], WORKFORCE_UNCLASSIFIED),
      total, [WORKFORCE_UNCLASSIFIED]),
    workforceChartHtml('Employment Status',
      workforceDistribution(residents, r => r.employment_status, ['Employed', 'Self-Employed', 'Unemployed', 'Student', 'Retired']), total, ['Not provided']),
    workforceChartHtml('Educational Attainment',
      workforceDistribution(residents, r => r.educational_attainment, ['Elementary', 'High School', 'Vocational', 'College', 'Postgraduate']), total, ['Not provided']),
    workforceChartHtml('Work Availability',
      workforceDistribution(residents, r => r.work_availability, ['Full-time', 'Part-time', 'Seasonal', 'Not looking']), total, ['Not provided'])
  ].join('');
}

async function runWorkforceClassification(button, status) {
  const pending = activeResidents().filter(resident => !workforceCategory(resident));
  const total = pending.length;
  if (!total) {
    showToastAdmin('Nothing to Classify', 'Every active resident already has a skill category.');
    return;
  }
  const geminiCalls = Math.ceil(pending.filter(workforceNeedsGemini).length / WORKFORCE_BATCH_SIZE);
  if (!window.confirm(`Classify ${total} residents? This makes ~${geminiCalls} Gemini calls.`)) return;

  const post = body => fetchJson(`${API_BASE}/ai/classify_workforce.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body)
  });

  button.disabled = true;
  status.hidden = true;
  let classified = 0;
  let batches = 0;
  let model = '';
  let failure = null;
  button.textContent = `0 of ${total} classified`;
  try {
    while (true) {
      if (batches > 0) await new Promise(r => setTimeout(r, 600));
      let data;
      try {
        data = await post({ final: false });
      } catch (err) {
        failure = /HTTP 429/.test(err.message)
          ? `Classified ${classified} of ${total} — Gemini rate-limited. Click again to continue.`
          : `Classified ${classified} of ${total} — ${err.message} Click again to continue.`;
        break;
      }
      batches++;
      const done = Number(data.classified) || 0;
      classified += done;
      if (data.model) model = data.model;
      button.textContent = `${classified} of ${total} classified`;
      if (!(Number(data.remaining) > 0)) break;
      if (!done) {
        failure = `Classified ${classified} of ${total} — AI skipped the remaining residents. Click again to retry.`;
        break;
      }
    }
    if (batches > 0 && classified > 0) {
      try { await post({ final: true, classified, batches, model }); } catch (err) { /* audit only */ }
    }
  } finally {
    button.disabled = false;
    button.innerHTML = '<i class="fa-solid fa-tags"></i> Classify Workforce';
  }

  if (failure) {
    status.hidden = false;
    status.className = 'ai-echo-response is-error';
    status.textContent = failure;
  } else {
    showToastAdmin('Workforce Classified', `${classified} of ${total} residents classified.`);
  }
  try {
    await loadResidents();
    await loadAuditLog();
  } catch (err) {
    showToastAdmin('Refresh Failed', err.message);
  }
}

function initWorkforceInsights() {
  const filter = document.getElementById('workforcePurokFilter');
  const button = document.getElementById('workforceClassifyBtn');
  const status = document.getElementById('workforceStatus');
  if (!filter || !button || !status) return;
  filter.addEventListener('change', renderWorkforceInsights);
  button.addEventListener('click', () => runWorkforceClassification(button, status));
  renderWorkforceInsights();
}

function findResidentContactForRequest(req) {
  if (!req) return '';
  if (req.residentId) {
    const byId = RESIDENTS.find(r => Number(r.dbId) === Number(req.residentId));
    if (byId && byId.contact && byId.contact !== '-') {
      return byId.contact;
    }
  }
  const residentName = (req.resident || '').trim().toLowerCase();
  const byName = RESIDENTS.find(r => (r.name || '').trim().toLowerCase() === residentName);
  return byName && byName.contact && byName.contact !== '' && byName.contact !== '-' ? byName.contact : '';
}

async function notifyPickupReady(ref) {
  const req = DOC_REQUESTS.find(r => r.ref === ref);
  if (!req) return;
  try {
    const payload = { reference_no: ref };
    let contact = findResidentContactForRequest(req);
    if (!contact) {
      const contactOverride = window.prompt(
        `Notify ${req.resident} via SMS that ${ref} is ready for pickup.\nEnter contact number, or leave blank to look up from records.`,
        ''
      );
      if (contactOverride && contactOverride.trim() !== '') {
        payload.contact = contactOverride.trim();
      }
    } else {
      payload.contact = contact;
    }
    const data = await fetchJson(`${API_BASE}/requests/notify_ready.php`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Portal-Source': 'official'
      },
      body: JSON.stringify(payload)
    });
    await loadRequests();
    await loadAuditLog();
    await refreshDashboard();
    showToastAdmin('Resident Notified', data.message || `${ref} SMS pickup notification sent.`);
  } catch (err) {
    showToastAdmin('Notify Failed', err.message);
  }
}
function refreshDocRequestsTable() {
  const q = (document.getElementById('reqSearch')?.value || '').toLowerCase();
  const s = document.getElementById('reqStatusFilter')?.value || 'all';
  renderDocRequests(DOC_REQUESTS.filter(r =>
    (r.resident.toLowerCase().includes(q) || r.ref.toLowerCase().includes(q)) &&
    (s === 'all' || r.status === s)
  ));
}

function initDocRequestsTable() {
  refreshDocRequestsTable();
  document.getElementById('reqSearch')?.addEventListener('input', refreshDocRequestsTable);
  document.getElementById('reqStatusFilter')?.addEventListener('change', refreshDocRequestsTable);
}

// ── COMPLAINTS ADMIN ──────────────────────
function renderComplaintsAdmin(data) {
  const el = document.getElementById('complaintsAdminList');
  if (!el) return;
  if (!data.length) {
    el.innerHTML = '<p style="color:var(--text-3);font-size:13px;padding:12px 0">No complaints found.</p>';
    return;
  }
  el.innerHTML = data.map(c => `
    <div class="complaint-admin-row" id="comp-${c.ref.replace('#','')}">
      <div class="ca-top">
        <div>
          <div class="ca-ref">${c.ref} · ${c.filer}</div>
          <div class="ca-title">${c.cat} — ${c.loc}</div>
        </div>
        <span class="status-badge status-${c.status}">${c.status}</span>
      </div>
      <div class="ca-meta">
        <span><i class="fa-regular fa-calendar"></i> Filed: ${escapeAttribute(c.date)}</span>
        ${severityChip(c.severity)}
        ${c.note ? `<span><i class="fa-solid fa-comment"></i> ${escapeAttribute(c.note)}</span>` : ''}
      </div>
      ${c.description ? `<div style="margin:8px 0 12px;font-size:12.5px;color:var(--text-2);line-height:1.5">${escapeAttribute(c.description.length > 160 ? c.description.slice(0, 160).trimEnd() + '…' : c.description)}</div>` : ''}
      <div class="ca-actions">
        <label>Update:</label>
        <select class="ca-status-select" id="compStatus-${c.id}">
          <option value="open"          ${c.status==='open'          ?'selected':''}>Open</option>
          <option value="investigating" ${c.status==='investigating' ?'selected':''}>Investigating</option>
          <option value="resolved"      ${c.status==='resolved'      ?'selected':''}>Resolved</option>
        </select>
        <select class="ca-status-select" id="compSeverity-${c.id}" aria-label="Severity">
          <option value="low"      ${c.severity==='low'      ?'selected':''}>Low</option>
          <option value="medium"   ${c.severity==='medium'   ?'selected':''}>Medium</option>
          <option value="high"     ${c.severity==='high'     ?'selected':''}>High</option>
          <option value="critical" ${c.severity==='critical' ?'selected':''}>Critical</option>
        </select>
        <input type="text" class="ca-note-input" id="compNote-${c.id}" placeholder="Add official note…" value="${c.note}" />
        <button class="btn-action resolve" onclick="updateComplaint(${c.id})">Save</button>
        <button class="btn-action view" onclick="viewComplaint(${c.id})">View</button>
      </div>
    </div>
  `).join('');
}

// Keep in sync with the identical severityChip() in js/resident.js.
function severityChip(severity) {
  const map = {
    low:      { bg: '#DCFCE7', color: '#15803D', label: 'Low' },
    medium:   { bg: '#FEF9C3', color: '#A16207', label: 'Medium' },
    high:     { bg: '#FFEDD5', color: '#C2410C', label: 'High' },
    critical: { bg: '#FEE2E2', color: '#B91C1C', label: 'Critical' }
  };
  const s = map[String(severity).toLowerCase()] || map.medium;
  return `<span style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:700;background:${s.bg};color:${s.color}">${s.label}</span>`;
}

async function loadComplaintTimeline(complaintId) {
  const data = await fetchJson(`${API_BASE}/complaints/timeline.php?complaint_id=${encodeURIComponent(complaintId)}`);
  return data.updates || [];
}

function renderTimelineHtml(updates) {
  if (!updates.length) {
    return '<p style="color:var(--text-3);font-size:12.5px">No timeline entries yet.</p>';
  }
  return '<ol style="list-style:none;padding:0;margin:0">' + updates.map(u => {
    const when = u.created_at ? escapeHtml(String(u.created_at)) : '';
    const who = escapeHtml(u.actor_name || (u.actor_type === 'system' ? 'System' : u.actor_type));
    const from = u.from_status ? `${escapeHtml(u.from_status)} → ` : '';
    const to = escapeHtml(u.to_status || '');
    const note = u.note ? `<div style="color:var(--text-2);margin-top:3px">${escapeHtml(u.note)}</div>` : '';
    return `<li style="padding:10px 0;border-bottom:1px solid var(--border)">
      <div style="font-size:11px;color:var(--text-3)">${when}</div>
      <div style="font-weight:600;color:var(--text);font-size:13px">${who} — ${from}${to}</div>
      ${note}
    </li>`;
  }).join('') + '</ol>';
}

// Keep in sync with the identical loadComplaintAttachments() / renderAttachmentsHtml()
// in js/resident.js.
async function loadComplaintAttachments(complaintId) {
  const data = await fetchJson(`${API_BASE}/complaints/list_attachments.php?complaint_id=${encodeURIComponent(complaintId)}`);
  return data.attachments || [];
}

function renderAttachmentsHtml(attachments) {
  if (!attachments.length) {
    return '<p style="color:var(--text-3);font-size:12.5px">No photos attached.</p>';
  }
  return '<div style="display:flex;gap:8px;flex-wrap:wrap">' + attachments.map(a => {
    const href = escapeHtml(a.url);
    return `<a href="${href}" target="_blank" rel="noopener" title="Open full size">
      <img src="${href}" alt="Complaint photo"
        style="width:80px;height:80px;object-fit:cover;border-radius:6px;border:1px solid var(--border)" />
    </a>`;
  }).join('') + '</div>';
}

// Keep in sync with the identical complaint comment helpers in js/resident.js.
function complaintCommentsSectionHtml() {
  return `<section data-complaint-comments style="margin:16px 0">
    <h4 style="margin:0 0 8px;font-size:13px;color:var(--text-2);text-transform:uppercase;letter-spacing:.05em">Comments</h4>
    <div data-comment-list aria-live="polite" style="max-height:240px;overflow-y:auto;border:1px solid var(--border);border-radius:7px;padding:0 12px">Loading comments…</div>
    <form data-comment-form style="margin-top:10px">
      <label style="display:block;font-size:12px;color:var(--text-2)">Add a comment (up to 2,000 characters)
        <textarea name="body" rows="3" required style="display:block;box-sizing:border-box;width:100%;margin:6px 0;padding:8px;border:1px solid var(--border);border-radius:7px;background:var(--surface);color:var(--text)"></textarea>
      </label>
      <button type="submit" disabled style="padding:8px 16px;background:var(--blue-mid);color:white;border:none;border-radius:7px;cursor:pointer">Post comment</button>
      <p data-comment-status role="status" style="font-size:12px;color:var(--text-2);margin:8px 0 0"></p>
    </form>
  </section>`;
}

async function loadComplaintComments(complaintId) {
  const data = await fetchJson(`${API_BASE}/complaints/list_comments.php?complaint_id=${encodeURIComponent(complaintId)}`);
  return data.comments || [];
}

function renderComplaintCommentsHtml(comments) {
  if (!comments.length) return '<p style="color:var(--text-3);font-size:12.5px">No comments yet.</p>';
  return comments.map(comment => {
    const name = escapeHtml(comment.actor_name || (comment.actor_type === 'official' ? 'Official' : 'Resident'));
    const role = comment.actor_type === 'official' ? 'Official' : 'Resident';
    const when = escapeHtml(String(comment.created_at || ''));
    const body = escapeHtml(String(comment.body || ''));
    return `<article style="padding:10px 0;border-bottom:1px solid var(--border);overflow-wrap:anywhere">
      <div style="font-size:12px;font-weight:600;color:var(--text)">${name} · ${role}</div>
      <div style="font-size:11px;color:var(--text-3)">${when}</div>
      <div style="white-space:pre-wrap;font-size:13px;line-height:1.5;color:var(--text-2);margin-top:4px">${body}</div>
    </article>`;
  }).join('');
}

async function initComplaintComments(root, complaintId) {
  if (!root) return;
  const list = root.querySelector('[data-comment-list]');
  const form = root.querySelector('[data-comment-form]');
  const textarea = form.elements.namedItem('body');
  const button = form.querySelector('button[type="submit"]');
  const status = root.querySelector('[data-comment-status]');
  let busy = true;
  const refresh = async () => {
    const comments = await loadComplaintComments(complaintId);
    if (root.isConnected) list.innerHTML = renderComplaintCommentsHtml(comments);
  };
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy || !root.isConnected) return;
    const body = textarea.value.trim();
    if (!body || Array.from(body).length > 2000) {
      status.textContent = 'Enter a comment of 1–2,000 characters.';
      return;
    }
    busy = true;
    button.disabled = true;
    textarea.disabled = true;
    status.textContent = 'Posting…';
    try {
      await fetchJson(`${API_BASE}/complaints/add_comment.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ complaint_id: complaintId, body })
      });
    } catch (error) {
      if (root.isConnected) {
        status.textContent = error.message || 'Could not post comment.';
        busy = false;
        button.disabled = false;
        textarea.disabled = false;
      }
      return;
    }
    if (!root.isConnected) return;
    textarea.value = '';
    status.textContent = 'Comment posted.';
    try {
      await refresh();
    } catch {
      if (root.isConnected) status.textContent = 'Comment saved. Reopen this complaint to refresh the discussion.';
    } finally {
      if (root.isConnected) {
        busy = false;
        button.disabled = false;
        textarea.disabled = false;
      }
    }
  });
  try {
    await refresh();
  } catch {
    if (root.isConnected) list.textContent = 'Comments unavailable. Reopen this complaint to retry.';
  } finally {
    if (root.isConnected) {
      busy = false;
      button.disabled = false;
    }
  }
}

// Keep in sync with the identical complaintResidentResponseText() in js/resident.js.
function complaintResidentResponseText(c) {
  if (c.status === 'resolved') {
    return c.residentConfirmation === 'confirmed'
      ? 'Resident confirmed the resolution'
      : 'Awaiting resident confirmation';
  }
  return c.residentConfirmation === 'reopened' ? 'Reopened by resident' : '';
}

// Keep in sync with the identical complaintResidentResponseTimesHtml() in js/resident.js.
function complaintResidentResponseTimesHtml(c) {
  const parts = [];
  if (c.residentConfirmation === 'confirmed' && c.confirmedAt) {
    parts.push(`Confirmed ${escapeHtml(String(c.confirmedAt))}`);
  }
  if (c.residentConfirmation === 'reopened' && c.reopenedAt) {
    parts.push(`Reopened ${escapeHtml(String(c.reopenedAt))}`);
  }
  if (!parts.length) return '';
  return `<p data-response-times style="margin:4px 0 0;font-size:12px;color:var(--text-3)">${parts.join(' · ')}</p>`;
}

function complaintResidentResponseSectionHtml(c) {
  const text = complaintResidentResponseText(c);
  if (!text) return '';
  return `<section data-resident-response data-complaint-id="${Number(c.id)}" style="margin:0 0 16px">
    <h4 style="margin:16px 0 8px;font-size:13px;color:var(--text-2);text-transform:uppercase;letter-spacing:.05em">Resident response</h4>
    <p data-response-summary style="margin:0;font-size:13px;font-weight:600;color:var(--text)">${escapeHtml(text)}</p>
    ${complaintResidentResponseTimesHtml(c)}
  </section>`;
}

function syncOpenComplaintResponse(id) {
  const modal = document.getElementById('actionModal');
  const box = document.getElementById('actionModalBox');
  if (!modal || !box || !modal.classList.contains('show')) return;
  if (Number(box.dataset.complaintId) !== Number(id)) return;
  const updated = COMPLAINTS_ADMIN.find(item => Number(item.id) === Number(id));
  if (!updated) return;
  const statusNode = box.querySelector('[data-complaint-status]');
  if (statusNode) statusNode.textContent = updated.status;
  const html = complaintResidentResponseSectionHtml(updated);
  const existing = box.querySelector('[data-resident-response]');
  if (!html) {
    if (existing) existing.remove();
    return;
  }
  if (existing) {
    existing.outerHTML = html;
    return;
  }
  const timeline = box.querySelector('#complaintTimeline');
  const heading = timeline?.previousElementSibling;
  if (heading) heading.insertAdjacentHTML('beforebegin', html);
}

function viewComplaint(id) {
  const c = COMPLAINTS_ADMIN.find(x => Number(x.id) === Number(id));
  if (!c) return;

  const box = document.getElementById('actionModalBox');
  if (!box) return;
  box.dataset.complaintId = String(c.id);
  const timelineToken = `${Date.now()}-${Math.random()}`;
  const attachmentToken = `${Date.now()}-${Math.random()}`;
  box.dataset.timelineToken = timelineToken;
  box.dataset.attachmentToken = attachmentToken;
  box.classList.add('detail-modal-box');

  const descriptionHtml = c.description
    ? escapeHtml(c.description).replace(/\r?\n/g, '<br>')
    : '<span style="color:var(--text-3)">No description provided.</span>';

  box.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid var(--border)">
      <h3 style="font-size:1.3rem;font-weight:600;color:var(--text)">Complaint ${escapeHtml(c.ref)}</h3>
      <button type="button" onclick="closeModal()" style="background:none;border:none;font-size:18px;color:var(--text-3);cursor:pointer"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:9px 14px;margin-bottom:16px">
      ${profileDetailField('Filed By', c.filer)}
      ${profileDetailField('Category', c.cat)}
      ${profileDetailField('Location', c.loc)}
      ${profileDetailField('Date Filed', c.date)}
      <div class="detail-field"><dt>Status</dt><dd data-complaint-status>${escapeHtml(c.status)}</dd></div>
      <div class="detail-field"><dt>Severity</dt><dd>${severityChip(c.severity)}</dd></div>
    </div>
    <div style="margin-bottom:16px;padding:12px 14px;background:var(--surface-2);border:1px solid var(--border);border-radius:8px">
      <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px">Description</div>
      <div style="font-size:13.5px;color:var(--text);line-height:1.6">${descriptionHtml}</div>
    </div>
    ${c.note ? `<div style="margin-bottom:16px;padding:10px 14px;background:#EFF6FF;border:1px solid #93C5FD;border-radius:8px">
      <div style="font-size:11px;color:var(--blue);text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px">Official Note</div>
      <div style="font-size:13px;color:var(--text-2);line-height:1.5">${escapeHtml(c.note)}</div>
    </div>` : ''}
    <p style="font-size:12px;color:var(--text-3);margin:0 0 16px">Status, severity, and notes can be updated using the controls on the complaint card. Close this view to make changes.</p>
    ${complaintResidentResponseSectionHtml(c)}
    <h4 style="margin:16px 0 8px;font-size:13px;color:var(--text-2);text-transform:uppercase;letter-spacing:.05em">Timeline</h4>
    <div id="complaintTimeline" style="max-height:220px;overflow-y:auto;border:1px solid var(--border);border-radius:7px;padding:0 12px"></div>
    ${complaintCommentsSectionHtml()}
    <h4 style="margin:16px 0 8px;font-size:13px;color:var(--text-2);text-transform:uppercase;letter-spacing:.05em">Photos</h4>
    <div id="complaintAttachments" style="border:1px solid var(--border);border-radius:7px;padding:12px"></div>
    <button type="button" onclick="closeModal()" style="padding:9px 24px;background:var(--blue-mid);color:white;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer">Close</button>
  `;
  initComplaintComments(box.querySelector('[data-complaint-comments]'), c.id);
  document.getElementById('actionModal').classList.add('show');
  const modal = document.getElementById('actionModal');
  const sameOfficialDetail = () => modal
    && modal.classList.contains('show')
    && Number(box.dataset.complaintId) === Number(c.id);
  loadComplaintTimeline(c.id)
    .then(updates => {
      if (!sameOfficialDetail() || box.dataset.timelineToken !== timelineToken) return;
      const el = box.querySelector('#complaintTimeline');
      if (el) el.innerHTML = renderTimelineHtml(updates);
    })
    .catch(() => {
      if (!sameOfficialDetail() || box.dataset.timelineToken !== timelineToken) return;
      const el = box.querySelector('#complaintTimeline');
      if (el) el.textContent = 'Timeline unavailable.';
    });
  loadComplaintAttachments(c.id)
    .then(atts => {
      if (!sameOfficialDetail() || box.dataset.attachmentToken !== attachmentToken) return;
      const el = box.querySelector('#complaintAttachments');
      if (el) el.innerHTML = renderAttachmentsHtml(atts);
    })
    .catch(() => {
      if (!sameOfficialDetail() || box.dataset.attachmentToken !== attachmentToken) return;
      const el = box.querySelector('#complaintAttachments');
      if (el) el.textContent = 'Photos unavailable.';
    });
}

async function updateComplaint(id) {
  const c = COMPLAINTS_ADMIN.find(x => Number(x.id) === Number(id));
  if (!c) return;
  const status = document.getElementById(`compStatus-${id}`).value;
  const officialNote = document.getElementById(`compNote-${id}`).value;
  const severity = document.getElementById(`compSeverity-${id}`)?.value || c.severity || 'medium';
  try {
    const resolving = status === 'resolved';
    const data = await fetchJson(`${API_BASE}/complaints/${resolving ? 'notify_resolved' : 'update_status'}.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id, status, official_note: officialNote, severity })
    });
    const savedMessage = data.message || `${c.ref} status updated to "${status}".`;
    try {
      await loadComplaints();
      await loadAuditLog();
      await refreshDashboard();
      syncOpenComplaintResponse(id);
      const title = data.changed ? (resolving ? 'Complaint Resolved' : 'Complaint Updated') : 'Complaint Unchanged';
      showToastAdmin(title, savedMessage);
    } catch (refreshError) {
      showToastAdmin('Complaint Saved', 'Saved. Refresh the page to see the latest details.');
    }
  } catch (err) {
    showToastAdmin('Update Failed', err.message);
  }
}

function mapResidentRow(row) {
  return {
    dbId: Number(row.id),
    id: `RS-${String(row.id).padStart(4, '0')}`,
    name: `${row.fname} ${row.lname}`.trim(),
    fname: row.fname || '',
    lname: row.lname || '',
    addr: row.address || '-',
    contact: row.contact || '-',
    username: row.username || '',
    profilePhoto: row.profile_photo || '',
    status: row.status || 'active',
    // Profiling: Household & Demographics
    birthdate: row.birthdate || '',
    civil_status: row.civil_status || '',
    purok_zone: row.purok_zone || '',
    household_size: row.household_size ?? 1,
    is_solo_parent: Number(row.is_solo_parent || 0),
    is_pwd: Number(row.is_pwd || 0),
    is_4ps_member: Number(row.is_4ps_member || 0),
    // Profiling: Livelihood & Skills
    educational_attainment: row.educational_attainment || '',
    employment_status: row.employment_status || '',
    occupation: row.occupation || '',
    training_certifications: row.training_certifications || '',
    work_availability: row.work_availability || '',
    skill_category: row.skill_category || '',
    household_code: row.household_code || '',
    household_profile_status: row.household_profile_status || '',
    household_profile_completeness: Number(row.household_profile_completeness || 0),
    mpi_score: row.household_mpi_score === null ? null : Number(row.household_mpi_score),
    mpi_status: row.household_mpi_status || '',
    income_poverty_status: row.household_income_poverty_status || '',
    mpi_deprivations: (() => {
      const raw = row.household_mpi_indicators_json;
      if (!raw) return [];
      try {
        const decoded = typeof raw === 'string' ? JSON.parse(raw) : raw;
        const inds = decoded && decoded.indicators;
        if (!inds) return [];
        return Object.entries(inds).filter(([, v]) => v === 1).map(([k]) => k);
      } catch { return []; }
    })(),
    // Household linkage (managed by household endpoints)
    household_id: row.household_id ? Number(row.household_id) : null,
    relationship_to_household_head: row.relationship_to_household_head || '',
    profile_status: row.profile_status || 'unprofiled'
  };
}

function getInitials(name) {
  const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
  if (parts.length >= 2) return `${parts[0][0]}${parts[parts.length - 1][0]}`.toUpperCase();
  return (parts[0] || 'R').slice(0, 2).toUpperCase();
}

function mapAuditRow(row) {
  return {
    ts: row.created_at || '',
    user: row.official_name || 'Unknown Official',
    role: 'Official',
    action: row.action || 'Action',
    module: row.target_type || '-',
    ip: row.target_reference || '-'
  };
}

function mapRequestRow(row) {
  let requestFields = {};
  try {
    requestFields = row.request_fields_json ? JSON.parse(row.request_fields_json) : {};
  } catch { requestFields = {}; }
  return {
    id: Number(row.id),
    ref: row.reference_no,
    residentId: row.resident_id ? Number(row.resident_id) : 0,
    resident: row.resident_name || 'Resident',
    type: row.document_type,
    purpose: row.purpose,
    date: row.date_requested,
    status: row.status,
    copies: Number(row.copies || 1),
    totalAmount: Number(row.total_amount || 0),
    paymentStatus: row.payment_status || 'unpaid',
    generatedDocumentPath: row.generated_document_path || '',
    dateCompleted: row.date_completed || row.date_processed || '',
    requestFields,
  };
}

function mapComplaintRow(row) {
  return {
    id: Number(row.id),
    ref: row.reference_no,
    filer: row.resident_name,
    cat: row.category,
    severity: (row.severity || 'medium').toLowerCase(),
    loc: row.location_text,
    description: row.description || '',
    date: row.date_filed,
    status: row.status,
    note: row.official_note || '',
    residentConfirmation: row.resident_confirmation || null,
    confirmedAt: row.confirmed_at || null,
    reopenedAt: row.reopened_at || null,
    resolutionUpdateId: Number(row.resolution_update_id || 0),
  };
}

async function fetchJson(url, options) {
  const res = await fetch(url, options);
  const raw = await res.text();
  let data = null;

  if (raw.trim() !== '') {
    try {
      data = JSON.parse(raw);
    } catch (err) {
      data = null;
    }
  }

  if (!res.ok) {
    if (data && data.message) {
      throw new Error(data.message);
    }
    throw new Error(`Server request failed (HTTP ${res.status}).`);
  }

  if (!data || typeof data !== 'object') {
    throw new Error('Server returned an invalid response format.');
  }

  if (!data.success) {
    throw new Error(data.message || 'Request failed.');
  }

  return data;
}

async function loadResidents() {
  const data = await fetchJson(`${API_BASE}/residents/list.php`);
  RESIDENTS = (data.residents || []).map(mapResidentRow);
  renderResidents(RESIDENTS);
  renderVulnerabilityRegistry();
  renderWorkforceInsights();
}

const VULNERABILITY_REPORTS = new Map(); // household_id -> report object

function reportCellHtml(report, householdId, householdUpdatedAt) {
  if (!report) return '<span class="ai-tier ai-tier-none">No opinion yet</span>';
  if (report.status === 'unprofiled') return '<span class="ai-tier ai-tier-moderate">Awaiting profile</span>';
  if (report.status === 'incomplete') return '<span class="ai-tier ai-tier-moderate">Profile incomplete</span>';
  if (report.status === 'failed') return '<span class="ai-tier ai-tier-none">Commentary unavailable</span>';

  const clearBtn = householdId
    ? `<button type="button" class="ai-report-clear" title="Clear commentary"
         onclick="event.stopPropagation(); clearVulnerabilityReport(${householdId})">&times;</button>`
    : '';

  const generatedAt = report.generated_at ? new Date(report.generated_at) : null;
  const householdAt = householdUpdatedAt ? new Date(householdUpdatedAt) : null;
  const stale = generatedAt && householdAt && generatedAt < householdAt;
  const ageLabel = generatedAt
    ? `<div class="ai-flags">Generated ${escapeHtml(generatedAt.toLocaleDateString())}${stale ? ' · stale' : ''}</div>`
    : '';

  const summary = escapeHtml(report.summary || '');
  const deprivations = (report.key_deprivations || []).join(', ');
  const gaps = (report.data_gaps || []).length
    ? `<div class="ai-flags">Data gaps: ${escapeHtml(report.data_gaps.join(', '))}</div>`
    : '';
  return `<div class="ai-reason">${summary}${clearBtn}</div>`
    + (deprivations ? `<div class="ai-flags">${escapeHtml(deprivations)}</div>` : '')
    + gaps
    + ageLabel;
}

function aiAssessmentCell(row) {
  if (!row.householdId) return '<span class="ai-tier ai-tier-none">No household profile</span>';
  return reportCellHtml(VULNERABILITY_REPORTS.get(row.householdId), row.householdId, row.householdUpdatedAt);
}

const REGISTRY_PAGE_SIZE = 20;
let REGISTRY_PAGE = 1;
let REGISTRY_ORDER = new Map();

function registryOrderKey(resident) {
  if (!REGISTRY_ORDER.has(resident.dbId)) REGISTRY_ORDER.set(resident.dbId, Math.random());
  return REGISTRY_ORDER.get(resident.dbId);
}

function rerollRegistryOrder() {
  REGISTRY_ORDER = new Map();
  REGISTRY_PAGE = 1;
  renderVulnerabilityRegistry();
}

// Registry and AI assessment only consider active residents; the Residents table still shows everyone.
function activeResidents() {
  return RESIDENTS.filter(resident => resident.status === 'active');
}

function parseDeprivationsFromJson(raw) {
  if (!raw) return [];
  try {
    const decoded = typeof raw === 'string' ? JSON.parse(raw) : raw;
    const inds = decoded && decoded.indicators;
    if (!inds) return [];
    return Object.entries(inds).filter(([, v]) => v === 1).map(([k]) => k);
  } catch { return []; }
}

// One row per active resident, resolved against its household so both registries agree.
function enrichedRegistryRows() {
  const householdsById = new Map(HOUSEHOLD_REGISTRY.map(h => [Number(h.id), h]));
  return activeResidents().map(r => {
    const h = r.household_id ? householdsById.get(Number(r.household_id)) : null;
    return {
      resident: r,
      householdId: h ? Number(h.id) : (r.household_id ? Number(r.household_id) : null),
      householdUpdatedAt: h ? h.updated_at : null,
      name: r.name,
      purok: r.purok_zone,
      code: h ? h.code : r.household_code,
      status: h ? h.mpi_status : r.mpi_status,
      score: h ? h.mpi_score : r.mpi_score,
      deprivations: h ? parseDeprivationsFromJson(h.mpi_indicators_json) : r.mpi_deprivations,
      income: h ? h.income_poverty_status : r.income_poverty_status,
    };
  });
}

// Keep keys in sync with api/ai/generate_vulnerability_report.php's MPI_INDICATOR_LABELS.
// The values may diverge intentionally, but the key set must match.
const MPI_INDICATOR_LABELS = {
  school_attendance: 'Out-of-school children',
  educational_attainment: 'Low education',
  hunger: 'Hunger',
  food_insecurity: 'Food insecurity',
  health_access: 'Poor health access',
  assets: 'Few assets',
  toilet: 'Poor sanitation',
  water: 'Unsafe water',
  tenure: 'Insecure tenure',
  materials: 'Weak housing',
  electricity: 'No electricity',
  underemployment: 'Underemployment',
  working_children: 'Child labor',
};

function priorityStatusBadge(row) {
  if (!row.householdId) return '<span class="ai-tier ai-tier-none">Unprofiled</span>';
  const status = row.status;
  const score = row.score === null || row.score === undefined ? '' : ` · ${Number(row.score).toFixed(4)}`;
  if (status === 'poor')      return `<span class="ai-tier ai-tier-critical">Poor${score}</span>`;
  if (status === 'not_poor')  return `<span class="ai-tier ai-tier-low">Not poor${score}</span>`;
  if (status === 'incomplete')return '<span class="ai-tier ai-tier-moderate">Incomplete</span>';
  return '<span class="ai-tier ai-tier-none">No MPI data</span>';
}

function priorityIndicatorsCell(row) {
  const list = row.deprivations || [];
  if (!list.length) {
    if (!row.householdId) return '<span style="color:var(--text-3)">No household profile</span>';
    if (row.status === 'not_poor') return '<span style="color:var(--text-3)">No deprivations</span>';
    if (row.status === 'incomplete') return '<span style="color:var(--text-3)">Assessment incomplete</span>';
    return '<span style="color:var(--text-3)">—</span>';
  }
  return escapeHtml(list.map(n => MPI_INDICATOR_LABELS[n] || n).join(', '));
}

function householdCell(row) {
  if (!row.code) return '<span style="color:var(--text-3)">—</span>';
  return `<code>${escapeHtml(row.code)}</code>`;
}

function renderVulnerabilityRegistry() {
  const tbody = document.getElementById('vulnerabilityRegistryBody');
  const filter = document.getElementById('vulnerabilityPurokFilter');
  if (!tbody || !filter) return;

  const allRows = enrichedRegistryRows();
  const puroks = [...new Set(allRows.map(r => r.purok).filter(Boolean))].sort();
  const selected = filter.value;
  filter.innerHTML = '<option value="all">All Puroks</option>'
    + puroks.map(p => `<option value="${escapeAttribute(p)}">${escapeHtml(p)}</option>`).join('');
  filter.value = puroks.includes(selected) ? selected : 'all';

  const statusRank = { poor: 0, incomplete: 1, not_poor: 2, '': 3 };
  const rows = allRows
    .filter(r => filter.value === 'all' || r.purok === filter.value)
    .sort((a, b) => {
      const rankA = statusRank[a.status] ?? 3;
      const rankB = statusRank[b.status] ?? 3;
      if (rankA !== rankB) return rankA - rankB;
      const scoreA = a.score === null || a.score === undefined ? -1 : a.score;
      const scoreB = b.score === null || b.score === undefined ? -1 : b.score;
      if (scoreA !== scoreB) return scoreB - scoreA;
      return registryOrderKey(a.resident) - registryOrderKey(b.resident);
    });

  const totalPages = Math.max(1, Math.ceil(rows.length / REGISTRY_PAGE_SIZE));
  REGISTRY_PAGE = Math.min(Math.max(REGISTRY_PAGE, 1), totalPages);
  const pageRows = rows.slice((REGISTRY_PAGE - 1) * REGISTRY_PAGE_SIZE, REGISTRY_PAGE * REGISTRY_PAGE_SIZE);
  const pageLabel = document.getElementById('vulnerabilityPageLabel');
  const prevBtn = document.getElementById('vulnerabilityPrevBtn');
  const nextBtn = document.getElementById('vulnerabilityNextBtn');
  if (pageLabel) pageLabel.textContent = `Page ${REGISTRY_PAGE} of ${totalPages}`;
  if (prevBtn) prevBtn.disabled = REGISTRY_PAGE <= 1;
  if (nextBtn) nextBtn.disabled = REGISTRY_PAGE >= totalPages;

  tbody.innerHTML = pageRows.length ? pageRows.map(r => `
    <tr class="vulnerability-registry-entry">
      <td style="font-weight:600">${escapeHtml(r.name)}</td>
      <td>${escapeHtml(r.purok || 'Not provided')}</td>
      <td>${householdCell(r)}</td>
      <td>${priorityStatusBadge(r)}</td>
      <td>${priorityIndicatorsCell(r)}</td>
      <td class="ai-assessment-cell">${aiAssessmentCell(r)}</td>
    </tr>`).join('')
    : '<tr><td colspan="6" class="registry-empty">No residents match this Purok.</td></tr>';
}

const AI_BATCH_SIZE = 10;
const AI_MAX_PASSES = 4;
const AI_BACKOFF_MS = [0, 2000, 8000, 20000]; // wait before pass N

async function generateHouseholdCommentaries(householdIds, onProgress) {
  const stats = { successCount: 0, unprofiledCount: 0, incompleteCount: 0, failedCount: 0, lastModel: null };
  const total = householdIds.length;
  let done = 0;

  const commitResult = (hid, r, batchModel) => {
    if (r.reason === 'unprofiled') {
      VULNERABILITY_REPORTS.set(hid, { status: 'unprofiled' });
      stats.unprofiledCount++;
      return true;
    }
    if (r.reason === 'incomplete') {
      VULNERABILITY_REPORTS.set(hid, { status: 'incomplete' });
      stats.incompleteCount++;
      return true;
    }
    if (r.report) {
      VULNERABILITY_REPORTS.set(hid, { status: 'ok', ...r.report, model: r.model || batchModel });
      stats.lastModel = r.model || batchModel || stats.lastModel;
      stats.successCount++;
      return true;
    }
    return false; // 'failed' | 'not_found' | 'ai_skipped' — caller decides
  };

  const runPass = async (ids) => {
    const retryIds = [];
    for (let i = 0; i < ids.length; i += AI_BATCH_SIZE) {
      const batch = ids.slice(i, i + AI_BATCH_SIZE);
      let data = null;
      try {
        data = await fetchJson(`${API_BASE}/ai/generate_vulnerability_reports_batch.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ household_ids: batch }),
        });
      } catch (err) {
        if (String(err && err.message || '').includes('Hourly AI budget reached')) throw err;
        batch.forEach(id => retryIds.push(Number(id)));
        continue;
      }
      if (data.model) stats.lastModel = data.model;
      const byId = new Map();
      (data.results || []).forEach(r => byId.set(Number(r.household_id), r));

      batch.forEach(id => {
        const hid = Number(id);
        const r = byId.get(hid);
        if (!r) { retryIds.push(hid); return; }
        if (commitResult(hid, r, data.model)) done++;
        else retryIds.push(hid);
      });
      onProgress?.(done, total);
      renderHouseholdRegistry();
      renderVulnerabilityRegistry();
    }
    return retryIds;
  };

  // Bounded backoff: up to 4 passes, waits [0, 2s, 8s, 20s]. Total wall time
  // worst case ~30s per Generate Reports click.
  let pending = householdIds;
  for (let pass = 0; pass < AI_MAX_PASSES && pending.length; pass++) {
    if (AI_BACKOFF_MS[pass] > 0) {
      await new Promise(r => setTimeout(r, AI_BACKOFF_MS[pass]));
    }
    pending = await runPass(pending);
  }

  pending.forEach(hid => {
    const existing = VULNERABILITY_REPORTS.get(hid);
    if (!existing || existing.status !== 'ok') {
      VULNERABILITY_REPORTS.set(hid, { status: 'failed' });
      stats.failedCount++;
    }
    done++;
  });
  onProgress?.(done, total);
  renderHouseholdRegistry();
  renderVulnerabilityRegistry();

  return stats;
}

async function loadSavedVulnerabilityReports() {
  try {
    const data = await fetchJson(`${API_BASE}/ai/list_vulnerability_reports.php`);
    (data.reports || []).forEach(r => {
      VULNERABILITY_REPORTS.set(Number(r.household_id), {
        status: 'ok',
        summary: r.summary,
        key_deprivations: r.key_deprivations,
        data_gaps: r.data_gaps,
        suggested_followups: r.suggested_followups,
        model: r.model,
        generated_at: r.generated_at,
      });
    });
  } catch (err) {
    console.warn('Saved AI reports unavailable:', err.message);
  }
}

async function clearVulnerabilityReport(householdId) {
  try {
    await fetchJson(`${API_BASE}/ai/clear_vulnerability_reports.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ household_id: Number(householdId) }),
    });
    VULNERABILITY_REPORTS.delete(Number(householdId));
    renderHouseholdRegistry();
    renderVulnerabilityRegistry();
  } catch (err) {
    showToastAdmin('Clear Failed', err.message);
  }
}

async function clearAllVulnerabilityReports() {
  if (!window.confirm('Clear all saved AI commentaries? They will need to be regenerated.')) return;
  try {
    const data = await fetchJson(`${API_BASE}/ai/clear_vulnerability_reports.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({}),
    });
    VULNERABILITY_REPORTS.clear();
    renderHouseholdRegistry();
    renderVulnerabilityRegistry();
    showToastAdmin('Commentaries Cleared', `${data.deleted || 0} saved commentaries removed.`);
  } catch (err) {
    showToastAdmin('Clear Failed', err.message);
  }
}

async function runRegistryAiAssessment(button) {
  const filter = document.getElementById('vulnerabilityPurokFilter');
  const purok = filter ? filter.value : 'all';
  const statusRank = { poor: 0, incomplete: 1, not_poor: 2, '': 3 };
  const rows = enrichedRegistryRows()
    .filter(row => purok === 'all' || row.purok === purok)
    .sort((a, b) => {
      const rankA = statusRank[a.status] ?? 3;
      const rankB = statusRank[b.status] ?? 3;
      if (rankA !== rankB) return rankA - rankB;
      const scoreA = a.score === null || a.score === undefined ? -1 : a.score;
      const scoreB = b.score === null || b.score === undefined ? -1 : b.score;
      if (scoreA !== scoreB) return scoreB - scoreA;
      return registryOrderKey(a.resident) - registryOrderKey(b.resident);
    });

  const totalPages = Math.max(1, Math.ceil(rows.length / REGISTRY_PAGE_SIZE));
  REGISTRY_PAGE = Math.min(Math.max(REGISTRY_PAGE, 1), totalPages);
  const start = (REGISTRY_PAGE - 1) * REGISTRY_PAGE_SIZE;
  const pageRows = rows.slice(start, start + REGISTRY_PAGE_SIZE);
  if (!pageRows.length) { showToastAdmin('Nothing to Assess', 'No households match this Purok.'); return; }

  const householdIds = [...new Set(pageRows.filter(row => row.householdId).map(row => Number(row.householdId)))];
  if (!householdIds.length) { showToastAdmin('Nothing to Assess', 'No households match this Purok.'); return; }
  if (!window.confirm(`Generate AI commentary for ${householdIds.length} household${householdIds.length === 1 ? '' : 's'} on this page?`)) return;

  button.disabled = true;
  button.textContent = `Generating 0/${householdIds.length}...`;
  try {
    const r = await generateHouseholdCommentaries(householdIds, (done, total) => {
      button.textContent = `Generating ${done}/${total}...`;
    });

    await fetchJson(`${API_BASE}/ai/generate_vulnerability_report.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        final: true,
        count: r.successCount, unprofiled: r.unprofiledCount,
        incomplete: r.incompleteCount, failed: r.failedCount,
        scope: (purok === 'all' ? 'All Puroks' : purok) + ' (vulnerability registry)',
        model: r.lastModel,
      }),
    }).catch(() => {});

    const parts = [`${r.successCount} ${r.successCount === 1 ? 'commentary' : 'commentaries'} generated`];
    if (r.unprofiledCount) parts.push(`${r.unprofiledCount} awaiting profile`);
    if (r.incompleteCount) parts.push(`${r.incompleteCount} profile incomplete`);
    if (r.failedCount) parts.push(`${r.failedCount} unavailable`);
    showToastAdmin('AI Commentary Generated', parts.join('; ') + '.');
  } catch (err) {
    showToastAdmin('AI Commentary Failed', err.message);
  } finally {
    button.disabled = false;
    button.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Generate Reports';
    loadAuditLog().catch(() => {});
  }
}

async function runHouseholdAiReports(button) {
  const filter = document.getElementById('householdPurokFilter');
  const purok = filter ? filter.value : 'all';
  let rows = HOUSEHOLD_REGISTRY.filter(h => purok === 'all' || h.purok_zone === purok);
  if (HOUSEHOLD_SORT_BY_POVERTY) rows = sortHouseholdsByPoverty(rows);

  const totalPages = Math.max(1, Math.ceil(rows.length / HOUSEHOLD_REGISTRY_PAGE_SIZE));
  HOUSEHOLD_REGISTRY_PAGE = Math.min(Math.max(HOUSEHOLD_REGISTRY_PAGE, 1), totalPages);
  const start = (HOUSEHOLD_REGISTRY_PAGE - 1) * HOUSEHOLD_REGISTRY_PAGE_SIZE;
  const pageRows = rows.slice(start, start + HOUSEHOLD_REGISTRY_PAGE_SIZE);
  if (!pageRows.length) { showToastAdmin('Nothing to Assess', 'No households on this page.'); return; }

  const householdIds = pageRows.map(h => Number(h.id));
  if (!window.confirm(`Generate AI commentary for ${householdIds.length} household${householdIds.length === 1 ? '' : 's'} on this page?`)) return;

  button.disabled = true;
  button.textContent = `Generating 0/${householdIds.length}...`;
  try {
    const r = await generateHouseholdCommentaries(householdIds, (done, total) => {
      button.textContent = `Generating ${done}/${total}...`;
    });

    // One audit entry per Generate Reports click — the batch endpoint itself
    // does not call log_audit per household (N inserts per click is wrong).
    await fetchJson(`${API_BASE}/ai/generate_vulnerability_report.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        final: true,
        count: r.successCount, unprofiled: r.unprofiledCount,
        incomplete: r.incompleteCount, failed: r.failedCount,
        scope: (purok === 'all' ? 'All Puroks' : purok) + ' (household registry)',
        model: r.lastModel,
      }),
    }).catch(() => {});

    const parts = [`${r.successCount} ${r.successCount === 1 ? 'commentary' : 'commentaries'} generated`];
    if (r.unprofiledCount) parts.push(`${r.unprofiledCount} awaiting profile`);
    if (r.incompleteCount) parts.push(`${r.incompleteCount} profile incomplete`);
    if (r.failedCount) parts.push(`${r.failedCount} unavailable`);
    showToastAdmin('AI Commentary Generated', parts.join('; ') + '.');
  } catch (err) {
    showToastAdmin('AI Commentary Failed', err.message);
  } finally {
    button.disabled = false;
    button.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Generate Reports';
    loadAuditLog().catch(() => {});
  }
}

function initRegistryTabs() {
  const tabs = document.querySelectorAll('[data-registry-tab]');
  const panes = document.querySelectorAll('[data-registry-pane]');
  if (!tabs.length || !panes.length) return;

  const activate = (name) => {
    tabs.forEach(t => {
      const on = t.dataset.registryTab === name;
      t.classList.toggle('active', on);
      t.setAttribute('aria-selected', String(on));
    });
    panes.forEach(p => { p.hidden = p.dataset.registryPane !== name; });
    // Lazy-load the household table only when the user opens its tab.
    if (name === 'household') {
      loadHouseholdRegistry().catch(err => console.warn('Household registry unavailable:', err.message));
    }
  };

  tabs.forEach(t => t.addEventListener('click', () => activate(t.dataset.registryTab)));
}

function initVulnerabilityRegistry() {
  const filter = document.getElementById('vulnerabilityPurokFilter');
  filter?.addEventListener('change', () => {
    REGISTRY_PAGE = 1;
    renderVulnerabilityRegistry();
  });
  document.getElementById('vulnerabilityPrevBtn')?.addEventListener('click', () => {
    REGISTRY_PAGE--;
    renderVulnerabilityRegistry();
  });
  document.getElementById('vulnerabilityNextBtn')?.addEventListener('click', () => {
    REGISTRY_PAGE++;
    renderVulnerabilityRegistry();
  });
  const aiAssessBtn = document.getElementById('aiAssessBtn');
  aiAssessBtn?.addEventListener('click', () => runRegistryAiAssessment(aiAssessBtn));
  document.getElementById('clearAllReportsBtn')?.addEventListener('click', clearAllVulnerabilityReports);
  document.getElementById('registryExportBtn')?.addEventListener('click', exportRegistryCsv);
  renderVulnerabilityRegistry();
}

function exportRegistryCsv() {
  const filter = document.getElementById('vulnerabilityPurokFilter');
  const purok = filter ? filter.value : 'all';
  const statusRank = { poor: 0, incomplete: 1, not_poor: 2, '': 3 };
  const rows = enrichedRegistryRows()
    .filter(r => purok === 'all' || r.purok === purok)
    .sort((a, b) => {
      const rankA = statusRank[a.status] ?? 3;
      const rankB = statusRank[b.status] ?? 3;
      if (rankA !== rankB) return rankA - rankB;
      const scoreA = a.score === null || a.score === undefined ? -1 : a.score;
      const scoreB = b.score === null || b.score === undefined ? -1 : b.score;
      return scoreB - scoreA;
    });
  if (!rows.length) { showToastAdmin('Nothing to Export', 'No residents match this Purok.'); return; }
  const header = ['Name', 'Purok', 'Household', 'Priority Status', 'MPI Score', 'Income Poverty Status', 'Priority Indicators', 'AI Summary', 'Key Deprivations', 'Data Gaps', 'Suggested Follow-ups'];
  const lines = [header, ...rows.map(r => {
    const report = (r.householdId && VULNERABILITY_REPORTS.get(r.householdId)) || {};
    const indicators = (r.deprivations || []).map(n => MPI_INDICATOR_LABELS[n] || n).join('; ');
    return [
      r.name,
      r.purok || 'Not provided',
      r.code || '',
      r.status ? String(r.status).replace('_', ' ') : (r.householdId ? 'No MPI data' : 'Unprofiled'),
      r.score === null || r.score === undefined ? '' : r.score,
      r.income || '',
      indicators || 'No deprivations',
      report.summary || '',
      (report.key_deprivations || []).join('; '),
      (report.data_gaps || []).join('; '),
      (report.suggested_followups || []).join('; ')
    ];
  })].map(cells => cells.map(csvCell).join(','));
  const blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
  const now = new Date();
  const date = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
  const suffix = purok === 'all' ? '' : `-${purok.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = `vulnerability-registry${suffix}-${date}.csv`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(link.href), 0);
}

function csvCell(value) {
  let text = String(value ?? '');
  const isPhoneLike = /^[+\-]?[\d\s()-]+$/.test(text);
  if (/^[=@\t\r]/.test(text) || (/^[+\-]/.test(text) && !isPhoneLike)) text = `'${text}`;
  return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

async function loadRequests() {
  const data = await fetchJson(`${API_BASE}/requests/list.php`, {
    headers: { 'X-Portal-Source': 'official' }
  });
  DOC_REQUESTS = (data.requests || []).map(mapRequestRow);
  refreshDocRequestsTable();
}

async function loadComplaints() {
  const data = await fetchJson(`${API_BASE}/complaints/list.php`);
  COMPLAINTS_ADMIN = (data.complaints || []).map(mapComplaintRow);
  refreshComplaintsAdmin();
}

async function refreshDashboard() {
  renderDashStats();
  renderDashPendingReqs();
  renderDashOpenComplaints();
  renderDashActivity();
}

// ── HOUSEHOLDS ────────────────────────────
let HOUSEHOLDS = [];
let HOUSEHOLD_REGISTRY = [];
let HOUSEHOLD_SORT_BY_POVERTY = false;
const HOUSEHOLD_REGISTRY_PAGE_SIZE = 20;
let HOUSEHOLD_REGISTRY_PAGE = 1;
const PROFILE_FIELD_KEYS = [
  'birthdate', 'civil_status', 'purok_zone',
  'is_solo_parent', 'is_pwd', 'is_4ps_member',
  'educational_attainment', 'employment_status',
  'occupation', 'work_availability', 'training_certifications'
];

async function loadHouseholds() {
  const data = await fetchJson(`${API_BASE}/households/list.php`);
  HOUSEHOLDS = data.households || [];
  const select = document.getElementById('residentHouseholdSelect');
  if (!select) return;
  const current = select.value;
  select.innerHTML = '<option value="">— Select household —</option>'
    + HOUSEHOLDS.map(h => {
      const label = `${h.code} — ${h.head_name || 'No head yet'}${h.purok_zone ? ` (${h.purok_zone})` : ''}`;
      return `<option value="${h.id}">${escapeHtml(label)}</option>`;
    }).join('');
  if (current && HOUSEHOLDS.some(h => String(h.id) === current)) select.value = current;
}

async function loadHouseholdRegistry() {
  if (!document.getElementById('householdRegistryBody')) return;
  const data = await fetchJson(`${API_BASE}/households/registry.php`);
  HOUSEHOLD_REGISTRY = data.households || [];
  renderHouseholdRegistry();
  renderVulnerabilityRegistry();
}

function householdProfileBadge(h) {
  if (h.profile_status === 'complete') return '<span class="status-badge status-verified">Complete</span>';
  if (h.profile_status === 'in_progress') return `<span class="status-badge status-processing">In progress · ${Number(h.profile_completeness) || 0}%</span>`;
  return '<span class="status-badge status-pending">Unprofiled</span>';
}

const MPI_DIMENSION_LABELS = {
  education: 'Education',
  health_nutrition: 'Health',
  housing_water_sanitation: 'Housing',
  employment: 'Employment',
};

// Returns the human labels of any MPI dimension whose score is null.
// Used only when mpi_status === 'incomplete'; empty array otherwise.
function householdMpiMissingDimensions(h) {
  if (h.mpi_status !== 'incomplete') return [];
  const raw = h.mpi_indicators_json;
  if (!raw) return [];
  try {
    const decoded = typeof raw === 'string' ? JSON.parse(raw) : raw;
    const dims = decoded && decoded.dimension_scores;
    if (!dims) return [];
    return Object.entries(dims)
      .filter(([, v]) => v === null)
      .map(([k]) => MPI_DIMENSION_LABELS[k] || k);
  } catch {
    return [];
  }
}

function householdMpiBadge(h) {
  if (h.mpi_score === null || h.mpi_score === undefined) {
    if (h.mpi_status === 'incomplete') {
      const missing = householdMpiMissingDimensions(h);
      const tip = missing.length
        ? ` title="Missing questionnaire data in: ${escapeAttribute(missing.join(', '))}"`
        : '';
      const suffix = missing.length ? ` · ${escapeHtml(missing.join(', '))}` : '';
      return `<span class="ai-tier ai-tier-moderate"${tip}>Incomplete${suffix}</span>`;
    }
    return '<span class="ai-tier ai-tier-none">Not assessed</span>';
  }
  const tier = h.mpi_status === 'poor' ? 'critical' : (h.mpi_status === 'not_poor' ? 'low' : 'moderate');
  const label = h.mpi_status === 'poor' ? 'Poor' : (h.mpi_status === 'not_poor' ? 'Not poor' : 'Incomplete');
  const version = h.assessment_method_version
    ? ` title="Method: ${escapeAttribute(h.assessment_method_version)}"`
    : '';
  return `<span class="ai-tier ai-tier-${tier}"${version}>${label} (<span class="mpi-score">${Number(h.mpi_score).toFixed(4)}</span>)</span>`;
}

function householdIncomeBadge(h) {
  if (h.income_poverty_status === 'below') return '<span class="status-badge status-open">Below threshold</span>';
  if (h.income_poverty_status === 'at_or_above') return '<span class="status-badge status-verified">At / above</span>';
  return '<span class="status-badge status-completed">Cannot determine</span>';
}

function renderHouseholdRegistry() {
  const tbody = document.getElementById('householdRegistryBody');
  const filter = document.getElementById('householdPurokFilter');
  if (!tbody || !filter) return;

  const puroks = [...new Set(HOUSEHOLD_REGISTRY.map(h => h.purok_zone).filter(Boolean))].sort();
  const selected = filter.value;
  filter.innerHTML = '<option value="all">All Puroks</option>'
    + puroks.map(purok => `<option value="${escapeAttribute(purok)}">${escapeHtml(purok)}</option>`).join('');
  filter.value = puroks.includes(selected) ? selected : 'all';

  let rows = HOUSEHOLD_REGISTRY.filter(h => filter.value === 'all' || h.purok_zone === filter.value);
  if (HOUSEHOLD_SORT_BY_POVERTY) rows = sortHouseholdsByPoverty(rows);

  const totalPages = Math.max(1, Math.ceil(rows.length / HOUSEHOLD_REGISTRY_PAGE_SIZE));
  HOUSEHOLD_REGISTRY_PAGE = Math.min(Math.max(HOUSEHOLD_REGISTRY_PAGE, 1), totalPages);
  const start = (HOUSEHOLD_REGISTRY_PAGE - 1) * HOUSEHOLD_REGISTRY_PAGE_SIZE;
  const pageRows = rows.slice(start, start + HOUSEHOLD_REGISTRY_PAGE_SIZE);
  const pageLabel = document.getElementById('householdPageLabel');
  const prevBtn = document.getElementById('householdPrevBtn');
  const nextBtn = document.getElementById('householdNextBtn');
  if (pageLabel) pageLabel.textContent = `Page ${HOUSEHOLD_REGISTRY_PAGE} of ${totalPages}`;
  if (prevBtn) prevBtn.disabled = HOUSEHOLD_REGISTRY_PAGE <= 1;
  if (nextBtn) nextBtn.disabled = HOUSEHOLD_REGISTRY_PAGE >= totalPages;

  tbody.innerHTML = pageRows.length ? pageRows.map(h => `<tr class="vulnerability-registry-entry">
      <td><code>${escapeHtml(h.code)}</code></td>
      <td style="font-weight:600">${escapeHtml(h.head_name || '—')}</td>
      <td>${escapeHtml(h.purok_zone || 'Not provided')}</td>
      <td>${Number(h.household_size) || 0}</td>
      <td>${householdProfileBadge(h)}</td>
      <td>${householdMpiBadge(h)}</td>
      <td>${householdIncomeBadge(h)}</td>
      <td class="ai-assessment-cell">${reportCellHtml(VULNERABILITY_REPORTS.get(Number(h.id)), Number(h.id), h.updated_at)}</td>
      <td class="action-column">
        <div class="action-btns">
          <button type="button" class="btn-action process" onclick="openHouseholdProfileWizard(${Number(h.id)})">
            <i class="fa-solid fa-clipboard-list"></i> ${h.profile_status === 'unprofiled' ? 'Profile' : 'Continue'}
          </button>
        </div>
      </td>
    </tr>`).join('')
    : `<tr><td colspan="9" class="registry-empty">${HOUSEHOLD_REGISTRY.length ? 'No households match this Purok.' : 'No households registered yet.'}</td></tr>`;
}

function initHouseholdRegistry() {
  document.getElementById('householdPurokFilter')?.addEventListener('change', () => {
    HOUSEHOLD_REGISTRY_PAGE = 1;
    renderHouseholdRegistry();
  });
  document.getElementById('householdSortBtn')?.addEventListener('click', toggleHouseholdSort);
  document.getElementById('householdExportBtn')?.addEventListener('click', exportHouseholdCsv);
  document.getElementById('householdReportsBtn')?.addEventListener('click', e => runHouseholdAiReports(e.currentTarget));
  document.getElementById('clearAllHouseholdReportsBtn')?.addEventListener('click', clearAllVulnerabilityReports);
  document.getElementById('householdPrevBtn')?.addEventListener('click', () => {
    HOUSEHOLD_REGISTRY_PAGE--;
    renderHouseholdRegistry();
  });
  document.getElementById('householdNextBtn')?.addEventListener('click', () => {
    HOUSEHOLD_REGISTRY_PAGE++;
    renderHouseholdRegistry();
  });
}

// Poor first (highest MPI score first), then incomplete, then not_poor, then unassessed.
// Ties break on household code so the order is stable.
function sortHouseholdsByPoverty(rows) {
  const rank = { poor: 0, incomplete: 1, not_poor: 2 };
  return [...rows].sort((a, b) => {
    const rankA = Object.prototype.hasOwnProperty.call(rank, a.mpi_status) ? rank[a.mpi_status] : 3;
    const rankB = Object.prototype.hasOwnProperty.call(rank, b.mpi_status) ? rank[b.mpi_status] : 3;
    if (rankA !== rankB) return rankA - rankB;
    const scoreA = a.mpi_score === null || a.mpi_score === undefined ? -1 : Number(a.mpi_score);
    const scoreB = b.mpi_score === null || b.mpi_score === undefined ? -1 : Number(b.mpi_score);
    if (scoreA !== scoreB) return scoreB - scoreA;
    return String(a.code || '').localeCompare(String(b.code || ''));
  });
}

function toggleHouseholdSort() {
  HOUSEHOLD_SORT_BY_POVERTY = !HOUSEHOLD_SORT_BY_POVERTY;
  const btn = document.getElementById('householdSortBtn');
  if (btn) {
    btn.innerHTML = HOUSEHOLD_SORT_BY_POVERTY
      ? '<i class="fa-solid fa-arrow-down-wide-short"></i> Reset Order'
      : '<i class="fa-solid fa-arrow-down-wide-short"></i> Organize by Poverty';
  }
  HOUSEHOLD_REGISTRY_PAGE = 1;
  renderHouseholdRegistry();
}

function exportHouseholdCsv() {
  const filter = document.getElementById('householdPurokFilter');
  const purok = filter ? filter.value : 'all';
  const rows = sortHouseholdsByPoverty(
    HOUSEHOLD_REGISTRY.filter(h => purok === 'all' || h.purok_zone === purok)
  );
  if (!rows.length) {
    showToastAdmin('Nothing to Export', 'No households match this Purok.');
    return;
  }
  const header = [
    'Household Code', 'Head', 'Purok', 'Size',
    'Profile Status', 'Profile Completeness (%)',
    'MPI Status', 'MPI Score', 'MPI Method',
    'Missing MPI Dimensions',
    'Income Status', 'AI Commentary', 'Annual Per Capita Income',
    'Assessment Complete', 'Profiled At', 'Suggested Follow-ups'
  ];
  const lines = [header, ...rows.map(h => {
    const report = VULNERABILITY_REPORTS.get(Number(h.id)) || {};
    return [
      h.code || '',
      h.head_name || '',
      h.purok_zone || '',
      h.household_size ?? '',
      h.profile_status || '',
      h.profile_completeness ?? '',
      h.mpi_status ? String(h.mpi_status).replace(/_/g, ' ') : '',
      h.mpi_score === null || h.mpi_score === undefined ? '' : h.mpi_score,
      h.assessment_method_version || '',
      householdMpiMissingDimensions(h).join('; '),
      h.income_poverty_status ? String(h.income_poverty_status).replace(/_/g, ' ') : '',
      report.summary || '',
      h.annual_per_capita_income === null || h.annual_per_capita_income === undefined ? '' : h.annual_per_capita_income,
      Number(h.assessment_complete) === 1 ? 'Yes' : 'No',
      h.profiled_at || '',
      (report.suggested_followups || []).join('; ')
    ];
  })].map(cells => cells.map(csvCell).join(','));
  const blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
  const now = new Date();
  const date = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
  const suffix = purok === 'all' ? '' : `-${purok.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = `household-registry${suffix}-${date}.csv`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(link.href), 0);
}

// ── HOUSEHOLD PROFILING WIZARD ─────────────
const HOUSEHOLD_WIZARD_FIELD_LABELS = {
  num_children: 'Number of children (under 18)',
  num_working_age: 'Working-age members (18–64)',
  num_seniors: 'Seniors (65+)',
  has_4ps: 'Household is a 4Ps beneficiary',
  has_pwd_member: 'Has a member with disability (PWD)',
  has_senior_member: 'Has a senior citizen member',
  is_solo_parent_hh: 'Solo-parent household',
  children_5_17_total: 'Children aged 5–17',
  children_5_17_not_in_school: 'Of those, not currently in school',
  members_19_plus_total: 'Household members aged 19+',
  has_19_plus_secondary_grad: 'Has a 19+ member who finished high school',
  medical_need_6mo: 'Any member needed medical care in the last 6 months',
  medical_treatment_obtained: 'Medical treatment was obtained',
  distance_health_center_km: 'Distance to nearest health center (km)',
  distance_hospital_km: 'Distance to nearest hospital (km)',
  fies_worried_food: 'Worried about not having enough food',
  fies_no_nutritious: 'Unable to eat healthy or nutritious food',
  fies_few_food_types: 'Ate only a few kinds of food',
  fies_skipped_meals: 'Skipped a meal',
  fies_ate_less: 'Ate less than they should',
  fies_ran_out: 'Ran out of food',
  fies_hungry: 'Were hungry but did not eat',
  fies_whole_day_no_food: 'Went a whole day without eating',
  housing_tenure: 'Housing tenure',
  roof_material: 'Roof material',
  wall_material: 'Wall material',
  floor_area_sqm: 'Floor area (sqm)',
  main_water_source: 'Main water source',
  toilet_facility: 'Toilet facility',
  shared_toilet: 'Toilet is shared with other households',
  has_electricity: 'Household has electricity',
  has_internet: 'Household has internet access',
  public_transport_access: 'Public transport is accessible',
  assets_json: 'Household assets (JSON array)',
  labor_force_total: 'Members in the labor force',
  employed_count: 'Currently employed',
  unemployed_seeking_count: 'Unemployed and seeking work',
  employed_wanting_more_hours: 'Employed but want more hours',
  working_children_5_17: 'Working children aged 5–17',
  working_children_not_in_school: 'Working children not in school',
  monthly_wages: 'Monthly wages / salaries',
  monthly_business: 'Monthly business income',
  monthly_remittances: 'Monthly remittances',
  monthly_pension: 'Monthly pension',
  monthly_govt_assistance: 'Monthly government assistance',
  monthly_other: 'Monthly other income',
  contextual_flags_json: 'Contextual flags (JSON object)',
};

const HOUSEHOLD_WIZARD_SELECT_OPTIONS = {
  housing_tenure: ['Owned', 'Rented', 'Rent-free with consent', 'Rent-free without consent', 'Informal settler'],
  roof_material: ['Strong', 'Mixed', 'Light', 'Salvaged', 'Makeshift'],
  wall_material: ['Strong', 'Mixed', 'Light', 'Salvaged', 'Makeshift'],
  main_water_source: ['Piped into dwelling', 'Piped to yard', 'Community faucet', 'Protected well', 'Unprotected well', 'Protected spring', 'Unprotected spring', 'River/stream', 'Rainwater', 'Peddler', 'Bottled water'],
  toilet_facility: ['Water-sealed flush', 'Water-sealed pour', 'Closed pit', 'Open pit', 'None'],
};

const HOUSEHOLD_WIZARD = {
  household: null,
  steps: [],
  fieldTypes: {},
  profile: {},
  currentStep: 1,
  loading: false,
};

async function openHouseholdProfileWizard(householdId) {
  if (HOUSEHOLD_WIZARD.loading) return;
  HOUSEHOLD_WIZARD.loading = true;
  try {
    const data = await fetchJson(`${API_BASE}/households/profile_get.php?household_id=${encodeURIComponent(householdId)}`);
    HOUSEHOLD_WIZARD.household = data.household;
    HOUSEHOLD_WIZARD.steps = data.steps || [];
    HOUSEHOLD_WIZARD.fieldTypes = data.field_types || {};
    HOUSEHOLD_WIZARD.profile = data.profile || {};
    const suggested = Number(data.suggested_start_step) || 1;
    HOUSEHOLD_WIZARD.currentStep = Math.min(Math.max(suggested, 1), HOUSEHOLD_WIZARD.steps.length || 1);
    renderHouseholdWizardHeader();
    renderHouseholdWizardStep(HOUSEHOLD_WIZARD.currentStep);
    const modal = document.getElementById('householdWizardModal');
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
  } catch (err) {
    showToastAdmin('Wizard Load Failed', err.message);
  } finally {
    HOUSEHOLD_WIZARD.loading = false;
  }
}

function closeHouseholdWizard() {
  const modal = document.getElementById('householdWizardModal');
  if (modal) {
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
  }
  document.getElementById('householdWizardStepBody').innerHTML = '';
  HOUSEHOLD_WIZARD.household = null;
  HOUSEHOLD_WIZARD.steps = [];
  HOUSEHOLD_WIZARD.profile = {};
  HOUSEHOLD_WIZARD.currentStep = 1;
}

function renderHouseholdWizardHeader() {
  const h = HOUSEHOLD_WIZARD.household;
  if (!h) return;
  const title = document.getElementById('householdWizardTitle');
  if (title) title.innerHTML = `<i class="fa-solid fa-house-user"></i> Profile ${escapeHtml(h.code)}`;
  const meta = document.getElementById('householdWizardMeta');
  if (meta) {
    const completeness = Number(h.profile_completeness) || 0;
    const status = h.profile_status || 'unprofiled';
    const statusLabel = status === 'complete' ? 'Complete' : (status === 'in_progress' ? 'In progress' : 'Unprofiled');
    const mpi = h.mpi_score === null || h.mpi_score === undefined ? '—' : Number(h.mpi_score).toFixed(4);
    const income = h.income_poverty_status || '—';
    meta.innerHTML = `
      <span><i class="fa-solid fa-user"></i> ${escapeHtml(h.head_name || 'No head yet')}</span>
      <span><i class="fa-solid fa-location-dot"></i> ${escapeHtml(h.purok_zone || 'No purok')}</span>
      <span><i class="fa-solid fa-users"></i> Size ${Number(h.household_size) || 0}</span>
      <span><i class="fa-solid fa-chart-simple"></i> ${statusLabel} · ${completeness}%</span>
      <span><i class="fa-solid fa-shield-halved"></i> MPI ${escapeHtml(mpi)}</span>
      <span><i class="fa-solid fa-coins"></i> Income ${escapeHtml(income)}</span>`;
  }
  document.querySelectorAll('#householdWizardProgress .wz-step').forEach(el => {
    const step = Number(el.dataset.step);
    el.classList.toggle('active', step === HOUSEHOLD_WIZARD.currentStep);
    el.classList.toggle('done', step < HOUSEHOLD_WIZARD.currentStep);
  });
}

function renderHouseholdWizardStep(stepNumber) {
  const stepDef = HOUSEHOLD_WIZARD.steps.find(s => Number(s.step) === Number(stepNumber));
  const body = document.getElementById('householdWizardStepBody');
  if (!stepDef || !body) return;

  body.innerHTML = stepDef.fields.map(fieldName => {
    const label = HOUSEHOLD_WIZARD_FIELD_LABELS[fieldName] || prettifyFieldName(fieldName);
    const type = HOUSEHOLD_WIZARD.fieldTypes[fieldName] || 'string';
    const value = Object.prototype.hasOwnProperty.call(HOUSEHOLD_WIZARD.profile, fieldName)
      ? HOUSEHOLD_WIZARD.profile[fieldName]
      : null;
    return renderHouseholdWizardField(fieldName, label, type, value);
  }).join('');

  const steps = HOUSEHOLD_WIZARD.steps;
  const idx = steps.findIndex(s => Number(s.step) === Number(stepNumber));
  const backBtn = document.getElementById('householdWizardBack');
  const nextBtn = document.getElementById('householdWizardNext');
  const saveBtn = document.getElementById('householdWizardSave');
  if (backBtn) backBtn.hidden = idx <= 0;
  if (nextBtn) nextBtn.hidden = idx < 0 || idx >= steps.length - 1;
  if (saveBtn) saveBtn.hidden = idx !== steps.length - 1;

  applyHouseholdWizardConditionalLogic();
}

function renderHouseholdWizardField(fieldName, label, type, value) {
  const id = `hwf-${fieldName}`;
  const commonAttrs = `data-field="${escapeAttribute(fieldName)}" data-type="${escapeAttribute(type)}"`;
  const valueStr = value === null || value === undefined ? '' : String(value);

  if (type === 'bool') {
    const selected = value === null || value === undefined || value === '' ? '' : String(Number(value));
    return `<div class="field">
      <label for="${id}">${escapeHtml(label)}</label>
      <select id="${id}" class="form-input" ${commonAttrs}>
        <option value="" ${selected === '' ? 'selected' : ''}>— Select —</option>
        <option value="1" ${selected === '1' ? 'selected' : ''}>Yes</option>
        <option value="0" ${selected === '0' ? 'selected' : ''}>No</option>
      </select>
    </div>`;
  }
  if (type === 'int') {
    return `<div class="field">
      <label for="${id}">${escapeHtml(label)}</label>
      <input id="${id}" type="number" min="0" step="1" class="form-input" value="${escapeAttribute(valueStr)}" ${commonAttrs} />
    </div>`;
  }
  if (type === 'decimal') {
    return `<div class="field">
      <label for="${id}">${escapeHtml(label)}</label>
      <input id="${id}" type="number" min="0" step="0.01" class="form-input" value="${escapeAttribute(valueStr)}" ${commonAttrs} />
    </div>`;
  }
  if (type === 'json') {
    let jsonText = '';
    if (value !== null && value !== undefined) {
      jsonText = typeof value === 'string' ? value : JSON.stringify(value);
    }
    return `<div class="field hw-field-full">
      <label for="${id}">${escapeHtml(label)} <span class="opt-label">(valid JSON)</span></label>
      <textarea id="${id}" rows="3" class="form-input" ${commonAttrs} placeholder='["mobile phone","radio"]'>${escapeHtml(jsonText)}</textarea>
    </div>`;
  }
  if (HOUSEHOLD_WIZARD_SELECT_OPTIONS[fieldName]) {
    const options = HOUSEHOLD_WIZARD_SELECT_OPTIONS[fieldName];
    return `<div class="field">
      <label for="${id}">${escapeHtml(label)}</label>
      <select id="${id}" class="form-input" ${commonAttrs}>
        <option value="" ${valueStr === '' ? 'selected' : ''}>— Select —</option>
        ${options.map(o => `<option value="${escapeAttribute(o)}" ${valueStr === o ? 'selected' : ''}>${escapeHtml(o)}</option>`).join('')}
      </select>
    </div>`;
  }
  return `<div class="field">
    <label for="${id}">${escapeHtml(label)}</label>
    <input id="${id}" type="text" class="form-input" value="${escapeAttribute(valueStr)}" ${commonAttrs} />
  </div>`;
}

/**
 * Conditional logic that keeps the MPI engine's non-NULL invariant happy:
 *   - Step 2: children_5_17_not_in_school must be 0 when total is 0.
 *   - Step 3: medical_treatment_obtained must be 0 when there was no medical need
 *             (the engine requires both to be non-NULL to score health_access).
 *   - Step 5: working_children_not_in_school must be 0 when working_children_5_17 is 0.
 * When the gate is 0, we lock the dependent field to 0 so the save sends a
 * concrete value instead of NULL.
 */
function applyHouseholdWizardConditionalLogic() {
  const body = document.getElementById('householdWizardStepBody');
  if (!body) return;
  const get = (name) => body.querySelector(`[data-field="${name}"]`);

  const totalChildren = get('children_5_17_total');
  const notInSchool = get('children_5_17_not_in_school');
  if (totalChildren && notInSchool) {
    const sync = () => {
      const total = totalChildren.value;
      if (total !== '' && Number(total) === 0) {
        notInSchool.value = '0';
        notInSchool.disabled = true;
      } else {
        notInSchool.disabled = false;
      }
    };
    totalChildren.addEventListener('input', sync);
    sync();
  }

  const needMed = get('medical_need_6mo');
  const gotMed = get('medical_treatment_obtained');
  if (needMed && gotMed) {
    const sync = () => {
      if (needMed.value === '0') {
        gotMed.value = '0';
        gotMed.disabled = true;
      } else {
        gotMed.disabled = false;
      }
    };
    needMed.addEventListener('change', sync);
    sync();
  }

  const workingKids = get('working_children_5_17');
  const workingKidsNotSchool = get('working_children_not_in_school');
  if (workingKids && workingKidsNotSchool) {
    const sync = () => {
      const v = workingKids.value;
      if (v !== '' && Number(v) === 0) {
        workingKidsNotSchool.value = '0';
        workingKidsNotSchool.disabled = true;
      } else {
        workingKidsNotSchool.disabled = false;
      }
    };
    workingKids.addEventListener('input', sync);
    sync();
  }
}

function collectHouseholdWizardStepFields() {
  const body = document.getElementById('householdWizardStepBody');
  if (!body) return {};
  const fields = {};
  body.querySelectorAll('[data-field]').forEach(input => {
    const name = input.dataset.field;
    const type = input.dataset.type;
    const raw = input.value;
    if (raw === '' || raw === null || raw === undefined) {
      fields[name] = null;
    } else if (type === 'bool') {
      fields[name] = raw === '1' ? 1 : 0;
    } else if (type === 'int' || type === 'decimal') {
      const n = Number(raw);
      fields[name] = Number.isFinite(n) ? n : null;
    } else {
      fields[name] = raw;
    }
  });
  return fields;
}

async function saveHouseholdWizardStep(stepNumber, fields, { showSuccessToast = true } = {}) {
  const payload = {
    household_id: HOUSEHOLD_WIZARD.household.id,
    step: stepNumber,
    fields,
  };
  const data = await fetchJson(`${API_BASE}/households/profile_save_step.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });

  // Merge saved values back into local state so Back→Next round-trips work.
  Object.assign(HOUSEHOLD_WIZARD.profile, fields);

  // Refresh the header from the server's derived state.
  const h = HOUSEHOLD_WIZARD.household;
  if (h) {
    h.profile_completeness = data.profile_completeness;
    h.profile_status = data.profile_status;
    h.mpi_score = data.mpi_score;
    h.mpi_status = data.mpi_status;
    h.annual_per_capita_income = data.annual_per_capita_income;
    h.income_poverty_status = data.income_poverty_status;
    h.assessment_complete = data.assessment_complete;
  }
  renderHouseholdWizardHeader();

  // Keep the registry in sync so badges update without a page reload.
  await loadHouseholdRegistry().catch(() => {});

  if (showSuccessToast) {
    if (Number(data.assessment_complete) === 1) {
      showToastAdmin(
        'Household Profile Complete',
        `MPI: ${data.mpi_status || '—'} (${data.mpi_score ?? '—'}), Income: ${data.income_poverty_status || '—'}`
      );
    } else {
      showToastAdmin('Step Saved', `Completeness: ${data.profile_completeness}%`);
    }
  }
  return data;
}

async function advanceHouseholdWizard() {
  const stepNumber = HOUSEHOLD_WIZARD.currentStep;
  const nextBtn = document.getElementById('householdWizardNext');
  if (nextBtn) nextBtn.disabled = true;
  try {
    const fields = collectHouseholdWizardStepFields();
    await saveHouseholdWizardStep(stepNumber, fields);
    const steps = HOUSEHOLD_WIZARD.steps;
    const idx = steps.findIndex(s => Number(s.step) === Number(stepNumber));
    if (idx >= 0 && idx < steps.length - 1) {
      HOUSEHOLD_WIZARD.currentStep = Number(steps[idx + 1].step);
      renderHouseholdWizardHeader();
      renderHouseholdWizardStep(HOUSEHOLD_WIZARD.currentStep);
    }
  } catch (err) {
    showToastAdmin('Save Failed', err.message);
  } finally {
    if (nextBtn) nextBtn.disabled = false;
  }
}

function goBackHouseholdWizard() {
  const steps = HOUSEHOLD_WIZARD.steps;
  const idx = steps.findIndex(s => Number(s.step) === Number(HOUSEHOLD_WIZARD.currentStep));
  if (idx > 0) {
    HOUSEHOLD_WIZARD.currentStep = Number(steps[idx - 1].step);
    renderHouseholdWizardHeader();
    renderHouseholdWizardStep(HOUSEHOLD_WIZARD.currentStep);
  }
}

function initHouseholdWizard() {
  const modal = document.getElementById('householdWizardModal');
  const form = document.getElementById('householdWizardForm');
  if (!modal || !form) return;

  document.getElementById('closeHouseholdWizard')?.addEventListener('click', closeHouseholdWizard);
  document.getElementById('householdWizardCancel')?.addEventListener('click', closeHouseholdWizard);
  document.getElementById('householdWizardBack')?.addEventListener('click', goBackHouseholdWizard);
  document.getElementById('householdWizardNext')?.addEventListener('click', advanceHouseholdWizard);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const saveBtn = document.getElementById('householdWizardSave');
    if (saveBtn) saveBtn.disabled = true;
    try {
      const fields = collectHouseholdWizardStepFields();
      await saveHouseholdWizardStep(HOUSEHOLD_WIZARD.currentStep, fields);
      // Only close when the assessment is complete; otherwise stay open so the
      // official can keep working through remaining steps.
      const h = HOUSEHOLD_WIZARD.household;
      if (h && h.assessment_complete === 1) {
        closeHouseholdWizard();
      } else {
        // If there's a later incomplete step, jump to it; otherwise stay put.
        const nextIncomplete = HOUSEHOLD_WIZARD.steps
          .map(s => Number(s.step))
          .find(n => n > HOUSEHOLD_WIZARD.currentStep
            && HOUSEHOLD_WIZARD.steps.some(s => Number(s.step) === n));
        if (nextIncomplete) {
          HOUSEHOLD_WIZARD.currentStep = nextIncomplete;
          renderHouseholdWizardHeader();
          renderHouseholdWizardStep(nextIncomplete);
        }
      }
    } catch (err) {
      showToastAdmin('Save Failed', err.message);
    } finally {
      if (saveBtn) saveBtn.disabled = false;
    }
  });
}

// ── RESIDENT WIZARD ───────────────────────
// Shared controller so openEditResidentForm() can drive the same 3-step form.
let RESIDENT_WIZARD = null;

function initResidentCreateForm() {
  const openBtn = document.getElementById('addResidentBtn');
  const closeBtn = document.getElementById('closeResidentForm');
  const cancelBtn = document.getElementById('cancelResidentForm');
  const card = document.getElementById('residentFormCard');
  const form = document.getElementById('residentFormEl');
  const title = document.getElementById('residentFormTitle');
  const submitBtn = document.getElementById('residentFormSubmitBtn');
  const backBtn = document.getElementById('residentWizardBack');
  const nextBtn = document.getElementById('residentWizardNext');
  const passwordLabel = document.getElementById('residentPasswordLabel');
  const passwordInput = form?.querySelector('input[name="password"]');
  const profilingChoice = document.getElementById('residentProfilingChoice');
  // Household Size lives in step 2 for new residents. Edit mode skips step 2, so the
  // field is moved into the step 3 demographics grid while editing and moved back on reset.
  const sizeField = document.getElementById('residentHouseholdSizeField');
  const sizeSlot = document.getElementById('residentHouseholdSizeSlot');
  const sizeHome = sizeField?.parentElement || null;
  if (!card || !form || !submitBtn || !backBtn || !nextBtn) return;

  // Edit mode skips the Household step: update.php never touches household linkage.
  const stepsFor = mode => (mode === 'edit' ? [1, 3] : [1, 2, 3]);
  let step = 1;

  const currentMode = () => form.dataset.mode || 'create';
  const householdMode = () => form.querySelector('input[name="household_mode"]:checked')?.value || 'new';
  const profileNow = () => currentMode() === 'edit' || form.querySelector('input[name="profile_now"]:checked')?.value !== '0';

  const setStep = (n) => {
    const steps = stepsFor(currentMode());
    step = steps.includes(n) ? n : steps[0];
    const idx = steps.indexOf(step);
    form.querySelectorAll('.wizard-step').forEach(el => { el.hidden = Number(el.dataset.step) !== step; });
    document.querySelectorAll('#residentWizardProgress .wz-step').forEach(el => {
      const s = Number(el.dataset.step);
      const skipped = !steps.includes(s);
      el.classList.toggle('skipped', skipped);
      el.classList.toggle('active', s === step);
      el.classList.toggle('done', !skipped && steps.indexOf(s) < idx);
    });
    backBtn.hidden = idx === 0;
    nextBtn.hidden = idx === steps.length - 1;
    submitBtn.hidden = idx !== steps.length - 1;
  };
  const stepOffset = delta => {
    const steps = stepsFor(currentMode());
    const next = steps[steps.indexOf(step) + delta];
    if (next) setStep(next);
  };

  const syncHouseholdPanes = () => {
    const mode = householdMode();
    form.querySelectorAll('.wz-household-pane').forEach(pane => {
      const active = pane.dataset.pane === mode;
      pane.hidden = !active;
      // Disabled inputs are left out of FormData, so stale values from an unselected pane are never sent.
      pane.querySelectorAll('input,select,textarea').forEach(input => { input.disabled = !active; });
    });
  };
  const syncProfileFields = () => {
    const enabled = profileNow();
    form.querySelectorAll('[data-profile-fields]').forEach(fs => {
      fs.classList.toggle('is-deferred', !enabled);
      fs.querySelectorAll('input,select,textarea').forEach(input => { input.disabled = !enabled; });
    });
  };

  form.querySelectorAll('input[name="household_mode"]').forEach(r => r.addEventListener('change', syncHouseholdPanes));
  form.querySelectorAll('input[name="profile_now"]').forEach(r => r.addEventListener('change', syncProfileFields));

  const resetToCreateMode = () => {
    form.reset();
    form.dataset.mode = 'create';
    form.resident_id.value = '';
    title.innerHTML = '<i class="fa-solid fa-user-plus"></i> Add New Resident';
    submitBtn.textContent = 'Save Resident';
    passwordLabel.textContent = 'Temporary Password';
    passwordInput.required = true;
    passwordInput.placeholder = '';
    if (profilingChoice) profilingChoice.hidden = false;
    if (sizeField && sizeHome && sizeField.parentElement !== sizeHome) sizeHome.appendChild(sizeField);
    form.querySelector('input[name="household_mode"][value="new"]').checked = true;
    form.querySelector('input[name="profile_now"][value="1"]').checked = true;
    syncHouseholdPanes();
    syncProfileFields();
    setStep(1);
  };
  const enterEditMode = () => {
    form.dataset.mode = 'edit';
    if (profilingChoice) profilingChoice.hidden = true;
    if (sizeField && sizeSlot) {
      sizeSlot.appendChild(sizeField);
      sizeField.querySelector('input').disabled = false;
    }
    syncProfileFields();
    setStep(1);
  };
  const closeForm = () => {
    card.classList.remove('show');
    resetToCreateMode();
  };

  RESIDENT_WIZARD = { resetToCreateMode, enterEditMode, setStep, closeForm };

  openBtn?.addEventListener('click', () => {
    resetToCreateMode();
    card.classList.add('show');
    loadHouseholds().catch(() => { /* dropdown stays empty; "Skip" and "Create" still work */ });
  });
  closeBtn?.addEventListener('click', closeForm);
  cancelBtn?.addEventListener('click', closeForm);
  backBtn.addEventListener('click', () => stepOffset(-1));
  nextBtn.addEventListener('click', () => {
    const visible = form.querySelector(`.wizard-step[data-step="${step}"]`);
    const invalid = [...visible.querySelectorAll('input,select,textarea')].find(input => !input.disabled && !input.checkValidity());
    if (invalid) { invalid.reportValidity(); return; }
    if (step === 2 && householdMode() === 'link' && !form.household_id.value) {
      showToastAdmin('Select a Household', 'Choose an existing household, or pick another option.');
      form.household_id.focus();
      return;
    }
    stepOffset(1);
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const isEdit = currentMode() === 'edit';
    const fd = new FormData(form);
    const payload = Object.fromEntries(fd.entries());
    const includeProfile = profileNow();
    // Checkboxes not in FormData when unchecked — default to 0
    ['is_solo_parent', 'is_pwd', 'is_4ps_member'].forEach(k => {
      payload[k] = fd.has(k) ? 1 : 0;
    });
    if (isEdit) {
      // update.php owns no household linkage; never send wizard-only keys from edit mode.
      ['household_mode', 'household_id', 'household_address', 'household_purok_zone', 'relationship_to_household_head', 'profile_now'].forEach(k => delete payload[k]);
    } else {
      payload.profile_now = includeProfile ? 1 : 0;
      // [Later]: strip profiling fields; household_size stays because step 2 owns it.
      if (!includeProfile) PROFILE_FIELD_KEYS.forEach(k => delete payload[k]);
    }
    const url = isEdit ? `${API_BASE}/residents/update.php` : `${API_BASE}/residents/create.php`;
    submitBtn.disabled = true;
    try {
      await fetchJson(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Portal-Source': 'official'
        },
        body: JSON.stringify(payload)
      });
      closeForm();
      await Promise.all([
        loadResidents(),
        loadAuditLog(),
        loadHouseholdRegistry().catch(() => {})
      ]);
      await refreshDashboard();
      showToastAdmin(
        isEdit ? 'Resident Updated' : 'Resident Added',
        isEdit ? 'Resident record was updated successfully.'
          : (includeProfile ? 'New resident account was created.' : 'New resident account was created. Profiling was deferred.')
      );
    } catch (err) {
      showToastAdmin(isEdit ? 'Update Failed' : 'Create Failed', err.message);
    } finally {
      submitBtn.disabled = false;
    }
  });

  resetToCreateMode();
}

function openEditResidentForm(dbId) {
  const resident = RESIDENTS.find(r => r.dbId === Number(dbId));
  const card = document.getElementById('residentFormCard');
  const form = document.getElementById('residentFormEl');
  const title = document.getElementById('residentFormTitle');
  const submitBtn = document.getElementById('residentFormSubmitBtn');
  const passwordLabel = document.getElementById('residentPasswordLabel');
  const passwordInput = form?.querySelector('input[name="password"]');
  if (!resident || !card || !form || !title || !submitBtn || !passwordLabel || !passwordInput || !RESIDENT_WIZARD) return;

  RESIDENT_WIZARD.resetToCreateMode();
  RESIDENT_WIZARD.enterEditMode();
  form.resident_id.value = String(resident.dbId);
  form.fname.value = resident.fname;
  form.lname.value = resident.lname;
  form.contact.value = resident.contact === '-' ? '' : resident.contact;
  form.username.value = resident.username;
  form.address.value = resident.addr === '-' ? '' : resident.addr;
  form.password.value = '';
  passwordInput.required = false;
  passwordInput.placeholder = 'Leave blank to keep current password';
  title.innerHTML = '<i class="fa-solid fa-pen"></i> Edit Resident';
  submitBtn.textContent = 'Update Resident';
  passwordLabel.textContent = 'New Password (optional)';

  // ── Populate profiling fields ──
  if (form.birthdate) form.birthdate.value = resident.birthdate || '';
  if (form.civil_status) form.civil_status.value = resident.civil_status || '';
  if (form.purok_zone) {
    const stored = String(resident.purok_zone || '');
    const match = /^Purok\s*([1-5])$/i.exec(stored);
    const canonical = match ? `Purok ${match[1]}` : '';
    form.purok_zone.value = PUROK_OPTIONS.includes(canonical) ? canonical : '';
  }
  if (form.household_size) form.household_size.value = resident.household_size ?? 1;
  if (form.is_solo_parent) form.is_solo_parent.checked = !!resident.is_solo_parent;
  if (form.is_pwd) form.is_pwd.checked = !!resident.is_pwd;
  if (form.is_4ps_member) form.is_4ps_member.checked = !!resident.is_4ps_member;
  if (form.educational_attainment) form.educational_attainment.value = resident.educational_attainment || '';
  if (form.employment_status) form.employment_status.value = resident.employment_status || '';
  if (form.occupation) form.occupation.value = resident.occupation || '';
  if (form.training_certifications) form.training_certifications.value = resident.training_certifications || '';
  if (form.work_availability) form.work_availability.value = resident.work_availability || '';

  card.classList.add('show');
}

async function toggleResidentStatus(dbId) {
  const resident = RESIDENTS.find(r => r.dbId === Number(dbId));
  if (!resident) return;
  const next = resident.status === 'active' ? 'deactivate' : 'activate';
  const ok = window.confirm(`${next === 'deactivate' ? 'Deactivate' : 'Activate'} resident record for ${resident.name}?`);
  if (!ok) return;
  try {
    const data = await fetchJson(`${API_BASE}/residents/deactivate.php`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Portal-Source': 'official'
      },
      body: JSON.stringify({ resident_id: resident.dbId })
    });
    await loadResidents();
    await loadAuditLog();
    await refreshDashboard();
    showToastAdmin(next === 'deactivate' ? 'Resident Deactivated' : 'Resident Activated', data.message || `${resident.name} is now ${next === 'deactivate' ? 'inactive' : 'active'}.`);
  } catch (err) {
    showToastAdmin('Update Failed', err.message);
  }
}

function mapOfficialRow(row) {
  return {
    dbId: Number(row.id),
    fname: row.fname || '',
    lname: row.lname || '',
    name: `${row.fname || ''} ${row.lname || ''}`.trim(),
    username: row.username || '',
    contact: row.contact || '-',
    address: row.address || '',
    position: row.position || '-',
    role: row.role || 'staff',
    status: row.status || 'active'
  };
}

function renderOfficials(data) {
  const tbody = document.getElementById('officialsBody');
  if (!tbody) return;
  tbody.innerHTML = data.map(o => {
    const isSelf = Number(o.dbId) === Number(window.OFFICIAL_ID);
    const toggleLabel = o.status === 'active' ? 'Deactivate' : 'Activate';
    return `
    <tr>
      <td><code style="font-size:12px;color:var(--blue-mid)">${o.dbId}</code></td>
      <td style="font-weight:600;color:var(--text)">${o.name}</td>
      <td>${o.username}</td>
      <td>${o.contact}</td>
      <td>${o.position}</td>
      <td><span class="status-badge" style="background:var(--surface-2);color:var(--text-2)">${o.role}</span></td>
      <td><span class="status-badge status-${o.status === 'active' ? 'verified' : 'suspended'}">${o.status}</span></td>
      <td class="action-column">
        <div class="action-btns">
          <button class="btn-action process" onclick="openEditOfficialForm(${o.dbId})">Edit</button>
          ${isSelf ? '' : `<button class="btn-action ${o.status === 'active' ? 'reject' : 'approve'}" onclick="toggleOfficialStatus(${o.dbId})">${toggleLabel}</button>`}
        </div>
      </td>
    </tr>
  `;
  }).join('');
}

function filterOfficials() {
  const searchEl = document.getElementById('officialSearch');
  const statusEl = document.getElementById('officialStatusFilter');
  if (!searchEl || !statusEl) return;
  const q = searchEl.value.toLowerCase();
  const s = statusEl.value;
  renderOfficials(OFFICIALS.filter(o => {
    const hay = `${o.name} ${o.username} ${o.position}`.toLowerCase();
    return hay.includes(q) && (s === 'all' || o.status === s);
  }));
}

async function loadOfficials() {
  if (!window.IS_OFFICIAL_ADMIN) return;
  const data = await fetchJson(`${API_BASE}/officials/list.php`);
  OFFICIALS = (data.officials || []).map(mapOfficialRow);
  renderOfficials(OFFICIALS);
}

function initOfficialsSection() {
  if (!window.IS_OFFICIAL_ADMIN) return;
  const openBtn = document.getElementById('addOfficialBtn');
  const closeBtn = document.getElementById('closeOfficialForm');
  const cancelBtn = document.getElementById('cancelOfficialForm');
  const card = document.getElementById('officialFormCard');
  const form = document.getElementById('officialFormEl');
  const title = document.getElementById('officialFormTitle');
  const submitBtn = document.getElementById('officialFormSubmitBtn');
  const passwordLabel = document.getElementById('officialPasswordLabel');
  const passwordInput = form?.querySelector('input[name="password"]');
  if (!card || !form) return;

  const resetToCreateMode = () => {
    form.reset();
    form.official_id.value = '';
    title.innerHTML = '<i class="fa-solid fa-user-plus"></i> Add New Official';
    submitBtn.textContent = 'Save Official';
    passwordLabel.textContent = 'Temporary Password';
    passwordInput.required = true;
    passwordInput.placeholder = '';
  };
  const closeForm = () => {
    card.classList.remove('show');
    resetToCreateMode();
  };

  openBtn?.addEventListener('click', () => {
    resetToCreateMode();
    card.classList.add('show');
  });
  closeBtn?.addEventListener('click', closeForm);
  cancelBtn?.addEventListener('click', closeForm);
  document.getElementById('officialSearch')?.addEventListener('input', filterOfficials);
  document.getElementById('officialStatusFilter')?.addEventListener('change', filterOfficials);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const payload = Object.fromEntries(new FormData(form).entries());
    const isEdit = payload.official_id && String(payload.official_id).trim() !== '';
    const url = isEdit ? `${API_BASE}/officials/update.php` : `${API_BASE}/officials/create.php`;
    try {
      await fetchJson(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      closeForm();
      await loadOfficials();
      await loadAuditLog();
      showToastAdmin(isEdit ? 'Official Updated' : 'Official Added', isEdit ? 'Official account was updated successfully.' : 'New official account was created successfully.');
    } catch (err) {
      showToastAdmin(isEdit ? 'Update Failed' : 'Create Failed', err.message);
    }
  });
}

function openEditOfficialForm(dbId) {
  const official = OFFICIALS.find(o => o.dbId === Number(dbId));
  const card = document.getElementById('officialFormCard');
  const form = document.getElementById('officialFormEl');
  const title = document.getElementById('officialFormTitle');
  const submitBtn = document.getElementById('officialFormSubmitBtn');
  const passwordLabel = document.getElementById('officialPasswordLabel');
  const passwordInput = form?.querySelector('input[name="password"]');
  if (!official || !card || !form || !title || !submitBtn || !passwordLabel || !passwordInput) return;

  form.official_id.value = String(official.dbId);
  form.fname.value = official.fname;
  form.lname.value = official.lname;
  form.contact.value = official.contact === '-' ? '' : official.contact;
  form.username.value = official.username;
  form.address.value = official.address;
  form.position.value = official.position === '-' ? '' : official.position;
  form.role.value = official.role;
  form.password.value = '';
  passwordInput.required = false;
  passwordInput.placeholder = 'Leave blank to keep current password';
  title.innerHTML = '<i class="fa-solid fa-pen"></i> Edit Official';
  submitBtn.textContent = 'Update Official';
  passwordLabel.textContent = 'New Password (optional)';
  card.classList.add('show');
}

async function toggleOfficialStatus(dbId) {
  const official = OFFICIALS.find(o => o.dbId === Number(dbId));
  if (!official) return;
  const next = official.status === 'active' ? 'deactivate' : 'activate';
  const ok = window.confirm(`${next === 'deactivate' ? 'Deactivate' : 'Activate'} official account for ${official.name}?`);
  if (!ok) return;
  try {
    await fetchJson(`${API_BASE}/officials/deactivate.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ official_id: official.dbId })
    });
    await loadOfficials();
    await loadAuditLog();
    showToastAdmin(next === 'deactivate' ? 'Official Deactivated' : 'Official Activated', `${official.name} is now ${next === 'deactivate' ? 'inactive' : 'active'}.`);
  } catch (err) {
    showToastAdmin('Update Failed', err.message);
  }
}

function refreshComplaintsAdmin() {
  const q = (document.getElementById('compSearch')?.value || '').toLowerCase();
  const s = (document.getElementById('compStatusFilter')?.value) || 'all';
  renderComplaintsAdmin(COMPLAINTS_ADMIN.filter(c =>
    (c.cat.toLowerCase().includes(q) || c.filer.toLowerCase().includes(q) || c.ref.toLowerCase().includes(q)) &&
    (s === 'all' || c.status === s)
  ));
}

function initComplaintsAdmin() {
  refreshComplaintsAdmin();
  document.getElementById('compSearch')?.addEventListener('input', refreshComplaintsAdmin);
  document.getElementById('compStatusFilter')?.addEventListener('change', refreshComplaintsAdmin);
}

const POLL_INTERVAL_MS = 20000;
const POLL_BACKOFF_MAX_MS = 120000;

let pollTimer = null;
let pollBackoff = POLL_INTERVAL_MS;
let pollInflight = false;

async function pollOfficialUpdates() {
  if (document.visibilityState !== 'visible') {
    scheduleNextPoll();
    return;
  }
  if (document.querySelector('.modal-overlay.show')) {
    scheduleNextPoll();
    return;
  }
  if (pollInflight) return;

  const activeSection = document.querySelector('.tab-panel.active')?.id || '';
  const fetches = [];
  if (activeSection === 'tab-requests' || activeSection === 'tab-dashboard') {
    fetches.push(
      fetchJson(`${API_BASE}/requests/list.php`, { headers: { 'X-Portal-Source': 'official' } })
        .then(d => { DOC_REQUESTS = (d.requests || []).map(mapRequestRow); })
    );
  }
  if (activeSection === 'tab-complaints' || activeSection === 'tab-dashboard') {
    fetches.push(
      fetchJson(`${API_BASE}/complaints/list.php`)
        .then(d => { COMPLAINTS_ADMIN = (d.complaints || []).map(mapComplaintRow); })
    );
  }
  if (!fetches.length) { scheduleNextPoll(); return; }

  pollInflight = true;
  try {
    await Promise.all(fetches);
    refreshDocRequestsTable();
    refreshComplaintsAdmin();
    await refreshDashboard();
    pollBackoff = POLL_INTERVAL_MS;
  } catch {
    pollBackoff = Math.min(pollBackoff * 2, POLL_BACKOFF_MAX_MS);
  } finally {
    pollInflight = false;
    scheduleNextPoll();
  }
}

function scheduleNextPoll() {
  clearTimeout(pollTimer);
  pollTimer = setTimeout(pollOfficialUpdates, pollBackoff);
}

function startOfficialPolling() {
  if (pollTimer) return;
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') pollOfficialUpdates();
  });
  pollOfficialUpdates();
}

// ── ANNOUNCEMENTS ─────────────────────────
function renderAnnouncementsAdmin() {
  const el = document.getElementById('announcementsAdminList');
  if (!el) return;
  if (!ANNOUNCEMENTS_DATA.length) {
    el.innerHTML = '<p style="padding:20px;color:var(--text-3)">No announcements have been posted yet.</p>';
    return;
  }
  el.innerHTML = ANNOUNCEMENTS_DATA.map(a => `
    <div class="ann-admin-item">
      <div class="aai-cat" style="background:#DBEAFE">${Number(a.is_pinned) === 1 ? '📌' : '📢'}</div>
      <div class="aai-body">
        <div class="aai-title">${a.title}</div>
        <div class="aai-meta">
          <span><i class="fa-regular fa-calendar"></i> ${formatAnnouncementDate(a.created_at)}</span>
          ${Number(a.is_pinned) === 1 ? '<span style="color:#1976D2"><i class="fa-solid fa-thumbtack"></i> Pinned</span>' : ''}
          ${Number(a.is_expired) === 1 ? '<span class="aai-expired">Expired</span>' : ''}
        </div>
        <div class="aai-content">${truncateAnnouncementContent(a.content)}</div>
      </div>
      <div class="aai-actions">
        <button class="btn-action view" onclick="viewAnnouncement(${a.id})">View</button>
        <button class="btn-action process" onclick="editAnnouncement(${a.id})">Edit</button>
        <button class="btn-action approve" onclick="toggleAnnouncementPin(${a.id})">${Number(a.is_pinned) === 1 ? 'Unpin' : 'Pin'}</button>
        <button class="btn-action reject" onclick="deleteAnnouncement(${a.id})">Delete</button>
      </div>
    </div>
  `).join('');
}
function truncateAnnouncementContent(content) {
  const text = String(content || '').trim();
  return text.length > 180 ? `${text.slice(0, 180).trimEnd()}…` : text;
}
function formatAnnouncementDate(value) {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString(undefined, {
    month: 'short', day: 'numeric', year: 'numeric'
  });
}
async function loadAnnouncements() {
  const data = await fetchJson(`${API_BASE}/announcements/list.php`, { headers: { 'X-Portal-Source': 'official' } });
  ANNOUNCEMENTS_DATA = data.announcements || [];
  renderAnnouncementsAdmin();
}
function initAnnouncementForm() {
  const modal = document.getElementById('annForm');
  const closeForm = () => { modal?.classList.remove('show'); };
  document.getElementById('newAnnBtn')?.addEventListener('click', () => modal?.classList.add('show'));
  document.getElementById('closeAnnForm')?.addEventListener('click', closeForm);
  document.getElementById('cancelAnnForm')?.addEventListener('click', closeForm);
  document.getElementById('annFormEl')?.addEventListener('submit', async e => {
    e.preventDefault();
    const title = e.target.querySelector('input[type=text]').value.trim();
    const content = e.target.querySelector('textarea').value.trim();
    const expiresAt = document.getElementById('announcementExpiresAt')?.value || '';
    try {
      const created = await fetchJson(`${API_BASE}/announcements/create.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ title, content, expires_at: expiresAt, is_pinned: 0 })
      });
      const shouldNotify = document.getElementById('smsToggle')?.checked;
      let broadcastResult = null;
      if (shouldNotify) {
        const submitButton = e.target.querySelector('button[type="submit"]');
        if (submitButton) {
          submitButton.disabled = true;
          submitButton.textContent = 'Sending SMS...';
        }
        try {
          broadcastResult = await fetchJson(`${API_BASE}/announcements/notify_all.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: created.id })
          });
        } finally {
          if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = 'Post Announcement';
          }
        }
      }
      await loadAnnouncements();
      await loadAuditLog();
      closeForm();
      e.target.reset();
      if (broadcastResult) {
        showToastAdmin(
          'Announcement Posted',
          `SMS sent: ${broadcastResult.sent}; skipped: ${broadcastResult.skipped}; failed: ${broadcastResult.failed}.`
        );
      } else {
        showToastAdmin('Announcement Posted', 'Announcement posted successfully.');
      }
    } catch (err) {
      showToastAdmin('Post Failed', err.message);
    }
  });
}
async function editAnnouncement(id) {
  const announcement = ANNOUNCEMENTS_DATA.find(a => Number(a.id) === Number(id));
  if (!announcement) return;
  const title = window.prompt('Announcement title:', announcement.title);
  if (title === null) return;
  const content = window.prompt('Announcement content:', announcement.content);
  if (content === null) return;
  const expiresAt = window.prompt('Expiration date/time (YYYY-MM-DD HH:MM), or leave blank for permanent:', announcement.expires_at || '');
  if (expiresAt === null) return;
  try {
    await fetchJson(`${API_BASE}/announcements/update.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id, title, content, expires_at: expiresAt, is_pinned: Number(announcement.is_pinned) === 1 })
    });
    await loadAnnouncements();
    await loadAuditLog();
    showToastAdmin('Announcement Updated', 'Announcement updated successfully.');
  } catch (err) {
    showToastAdmin('Update Failed', err.message);
  }
}
function viewAnnouncement(id) {
  const announcement = ANNOUNCEMENTS_DATA.find(a => Number(a.id) === Number(id));
  if (announcement) {
    showAnnouncementModal(announcement);
  }
}

function showAnnouncementModal(announcement) {
  const isPinned = Number(announcement.is_pinned) === 1;
  const isExpired = Number(announcement.is_expired) === 1;

  const statusBadge = isExpired
    ? '<span class="status-badge status-cancelled">Expired</span>'
    : '<span class="status-badge status-active">Active</span>';

  const pinBadge = isPinned
    ? '<span class="status-badge status-processing" style="margin-right:6px"><i class="fa-solid fa-thumbtack"></i> Pinned</span>'
    : '';

  const bodyHtml = `
    <div class="detail-grid" style="margin-bottom: 20px; grid-template-columns: 1fr 1fr;">
      <div class="detail-field">
        <dt>Status</dt>
        <dd>${pinBadge} ${statusBadge}</dd>
      </div>
      <div class="detail-field">
        <dt>Date Posted</dt>
        <dd>${formatAnnouncementDate(announcement.created_at)}</dd>
      </div>
      <div class="detail-field" style="grid-column: 1 / -1;">
        <dt>Expiration</dt>
        <dd>${announcement.expires_at ? formatAnnouncementDate(announcement.expires_at) : 'No expiration set'}</dd>
      </div>
    </div>
    <div style="font-size:14px;color:var(--text-2);line-height:1.7;white-space:pre-wrap; background: var(--surface-2); padding: 16px; border-radius: 8px; border: 1px solid var(--border);">${escapeHtml(announcement.content)}</div>
  `;

  const box = document.getElementById('actionModalBox');
  box.classList.remove('detail-modal-box');
  box.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid var(--border)">
      <h3 style="font-size:1.3rem;font-weight:600;color:var(--text)">${escapeHtml(announcement.title)}</h3>
      <button onclick="closeModal()" style="background:none;border:none;font-size:18px;color:var(--text-3);cursor:pointer"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div style="margin-bottom:20px">
      ${bodyHtml}
    </div>
    <button onclick="closeModal()" style="padding:9px 24px;background:var(--blue-mid);color:white;border:none;border-radius:7px;font-family:'Inter',sans-serif;font-size:13px;font-weight:700;cursor:pointer">Close</button>
  `;
  document.getElementById('actionModal').classList.add('show');
}
async function toggleAnnouncementPin(id) {
  const announcement = ANNOUNCEMENTS_DATA.find(a => Number(a.id) === Number(id));
  if (!announcement) return;
  try {
    await fetchJson(`${API_BASE}/announcements/update.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        id,
        title: announcement.title,
        content: announcement.content,
        expires_at: announcement.expires_at || '',
        is_pinned: Number(announcement.is_pinned) !== 1
      })
    });
    await loadAnnouncements();
    await loadAuditLog();
    showToastAdmin('Announcement Updated', Number(announcement.is_pinned) === 1 ? 'Announcement unpinned.' : 'Announcement pinned.');
  } catch (err) {
    showToastAdmin('Update Failed', err.message);
  }
}
async function deleteAnnouncement(id) {
  if (!window.confirm('Delete this announcement?')) return;
  try {
    await fetchJson(`${API_BASE}/announcements/delete.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id })
    });
    await loadAnnouncements();
    await loadAuditLog();
    showToastAdmin('Announcement Deleted', 'Announcement deleted successfully.');
  } catch (err) {
    showToastAdmin('Delete Failed', err.message);
  }
}

// ── SERVICES ──────────────────────────────
function renderServicesAdmin() {
  const el = document.getElementById('servicesAdminGrid');
  if (!el) return;
  if (!SERVICES_DATA.length) {
    el.innerHTML = '<p style="color:var(--text-3)">No local services have been added yet.</p>';
    return;
  }
  el.innerHTML = SERVICES_DATA.map(s => `
    <div class="svc-admin-card">
      <div class="sac-head">
        <span class="sac-emoji">🏪</span>
        <div>
          <div class="sac-title">${s.service_name}</div>
          <div class="sac-cat">${s.category}</div>
        </div>
      </div>
      <div style="font-size:12px;color:var(--text-3)"><i class="fa-solid fa-location-dot"></i> ${s.address || 'Address not provided'}</div>
      <div style="font-size:12px;color:var(--text-3);margin-top:5px;"><i class="fa-solid fa-phone"></i> ${s.contact_number || 'Contact not provided'}</div>
      <div class="sac-actions">
        <button class="btn-action view" onclick="viewService(${s.id})">View</button>
        <button class="btn-action process" onclick="editService(${s.id})">Edit</button>
        <button class="btn-action reject" onclick="deleteService(${s.id})">Remove</button>
      </div>
    </div>
  `).join('');
}
async function loadServices() {
  const data = await fetchJson(`${API_BASE}/services/list.php`);
  SERVICES_DATA = data.services || [];
  renderServicesAdmin();
}

async function loadAuditLog() {
  const data = await fetchJson(`${API_BASE}/audit/list.php`);
  AUDIT_LOG = (data.logs || []).map(mapAuditRow);
  renderAuditLog();
}
function resetServiceForm() {
  const form = document.getElementById('serviceFormEl');
  form?.reset();
  if (form) form.elements.id.value = '';
}
function showServiceForm(service = null) {
  const form = document.getElementById('serviceFormEl');
  if (!form) return;
  resetServiceForm();
  if (service) {
    ['id', 'service_name', 'category', 'contact_number', 'address', 'operating_hours', 'description'].forEach(field => {
      form.elements[field].value = service[field] || '';
    });
  }
  document.getElementById('serviceForm').classList.add('show');
}
function initServiceForm() {
  document.getElementById('newServiceBtn')?.addEventListener('click', () => showServiceForm());
  const modal = document.getElementById('serviceForm');
  const closeForm = () => modal?.classList.remove('show');
  document.getElementById('closeServiceForm')?.addEventListener('click', closeForm);
  document.getElementById('cancelServiceForm')?.addEventListener('click', closeForm);
  document.getElementById('serviceFormEl')?.addEventListener('submit', async event => {
    event.preventDefault();
    const payload = Object.fromEntries(new FormData(event.target).entries());
    const isEdit = payload.id && String(payload.id).trim() !== '';
    try {
      await fetchJson(`${API_BASE}/services/${isEdit ? 'update' : 'create'}.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      await loadServices();
      await loadAuditLog();
      closeForm();
      resetServiceForm();
      showToastAdmin(isEdit ? 'Service Updated' : 'Service Added', 'Local service saved successfully.');
    } catch (err) {
      showToastAdmin(isEdit ? 'Update Failed' : 'Create Failed', err.message);
    }
  });
}
function viewService(id) {
  const service = SERVICES_DATA.find(s => Number(s.id) === Number(id));
  if (!service) return;
  const details = [service.category, service.description, service.address, service.contact_number, service.operating_hours]
    .filter(Boolean).join('<br>');
  document.getElementById('actionModalBox')?.classList.remove('detail-modal-box');
  showModal(service.service_name, details || 'No additional details provided.');
}
function editService(id) {
  const service = SERVICES_DATA.find(s => Number(s.id) === Number(id));
  if (service) showServiceForm(service);
}
async function deleteService(id) {
  if (!window.confirm('Remove this local service?')) return;
  try {
    await fetchJson(`${API_BASE}/services/delete.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id })
    });
    await loadServices();
    await loadAuditLog();
    await refreshDashboard();
    showToastAdmin('Service Removed', 'Local service removed successfully.');
  } catch (err) {
    showToastAdmin('Delete Failed', err.message);
  }
}

// ── AUDIT LOG ─────────────────────────────
const AUDIT_TAG_COLORS = {
  'Request Approved':    '#059669', 'Request Submitted': '#1976D2',
  'Announcement Posted': '#D97706', 'Complaint Filed':   '#EF4444',
  'Resident Verified':   '#0D9488', 'Login':             '#94A3B8',
  'Status Updated':      '#0EA5E9',
  'Created resident': '#0D9488',
  'Updated resident': '#0284C7',
  'Deleted resident': '#DC2626',
  'Updated document request status': '#0EA5E9',
  'Sent pickup notification': '#7C3AED',
  'Updated complaint status': '#D97706',
  'Resolved complaint': '#16A34A',
  'Created announcement': '#D97706',
  'Updated announcement': '#2563EB',
  'Deleted announcement': '#DC2626',
  'Created service': '#0D9488',
  'Updated service': '#0284C7',
  'Created official': '#0D9488',
  'Updated official': '#0284C7',
  'Deactivated official': '#DC2626',
  'Activated official': '#16A34A',
  'Cleared AI commentary':       '#DC2626',
  'Cleared all AI commentaries': '#DC2626',
};
function populateAuditActionFilter() {
  const select = document.getElementById('auditActionFilter');
  if (!select) return;
  const current = select.value;
  const actions = [...new Set(AUDIT_LOG.map(a => a.action).filter(Boolean))].sort();
  select.innerHTML = '<option value="all">All Actions</option>'
    + actions.map(a => `<option value="${escapeAttribute(a)}">${escapeHtml(a)}</option>`).join('');
  if (actions.includes(current)) select.value = current;
}

function renderAuditLogFiltered() {
  const searchEl = document.getElementById('auditSearch');
  const filterEl = document.getElementById('auditActionFilter');
  const q = searchEl ? searchEl.value.trim().toLowerCase() : '';
  const action = filterEl ? filterEl.value : 'all';

  const filtered = AUDIT_LOG.filter(a => {
    const hay = `${a.user} ${a.action} ${a.module} ${a.ip}`.toLowerCase();
    const matchQ = q === '' || hay.includes(q);
    const matchA = action === 'all' || a.action === action;
    return matchQ && matchA;
  });

  const tbody = document.getElementById('auditBody');
  if (!tbody) return;
  tbody.innerHTML = filtered.length ? filtered.map(a => `
    <tr>
      <td style="font-size:12px;white-space:nowrap">${a.ts}</td>
      <td style="font-weight:600">${a.user}</td>
      <td><span class="status-badge" style="background:var(--surface-2);color:var(--text-2)">${a.role}</span></td>
      <td><span class="audit-action-tag" style="background:${AUDIT_TAG_COLORS[a.action]||'#94A3B8'}20;color:${AUDIT_TAG_COLORS[a.action]||'#94A3B8'}">${a.action}</span></td>
      <td>${a.module}</td>
      <td style="font-size:12px;color:var(--text-3)">${a.ip}</td>
    </tr>
  `).join('') : '<tr><td colspan="6" class="registry-empty">No entries match this filter.</td></tr>';
}

function renderAuditLog() {
  const tbody = document.getElementById('auditBody');
  if (!tbody) return;
  populateAuditActionFilter();
  renderAuditLogFiltered();
}

function initAuditLog() {
  document.getElementById('auditSearch')?.addEventListener('input', renderAuditLogFiltered);
  document.getElementById('auditActionFilter')?.addEventListener('change', renderAuditLogFiltered);
}

 

// ── MODAL ─────────────────────────────────
function showModal(title, body) {
  document.getElementById('actionModalBox').innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid var(--border)">
      <h3 style="font-size:1.3rem;font-weight:600">${title}</h3>
      <button onclick="closeModal()" style="background:none;border:none;font-size:18px;color:var(--text-3);cursor:pointer"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div style="font-size:14px;color:var(--text-2);line-height:1.7;margin-bottom:20px">${body}</div>
    <button onclick="closeModal()" style="padding:9px 24px;background:var(--blue-mid);color:white;border:none;border-radius:7px;font-family:'Jost',sans-serif;font-size:13px;font-weight:700;cursor:pointer">Close</button>
  `;
  document.getElementById('actionModal').classList.add('show');
}
function closeModal() { document.getElementById('actionModal').classList.remove('show'); }

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function displayValue(value) {
  return value === null || value === undefined || value === '' ? 'Not provided' : escapeHtml(value);
}

function profileDetailField(label, value) {
  return `<div class="detail-field"><dt>${escapeHtml(label)}</dt><dd>${displayValue(value)}</dd></div>`;
}

function showResidentProfile(dbId) {
  const resident = RESIDENTS.find(item => Number(item.dbId) === Number(dbId));
  if (!resident) return;
  const yesNo = value => value === null || value === undefined || value === '' ? 'Not provided' : (Number(value) === 1 ? 'Yes' : 'No');
  const photo = resident.profilePhoto
    ? `<img class="resident-detail-photo" src="../${escapeAttribute(resident.profilePhoto)}" alt="Profile photo" />`
    : `<span class="resident-detail-initials">${escapeHtml(getInitials(resident.name))}</span>`;
  document.getElementById('actionModalBox')?.classList.add('detail-modal-box');
  showModal('Resident Profile', `
    <div class="resident-detail-head">${photo}<div><h4>${displayValue(resident.name)}</h4><span>${displayValue(resident.status)}</span></div></div>
    <div class="detail-columns">
      <section class="detail-column"><h4 class="detail-group-title">Identity</h4><dl class="detail-grid">
        ${profileDetailField('Address', resident.addr)}${profileDetailField('Contact', resident.contact)}${profileDetailField('Username', resident.username)}${profileDetailField('Status', resident.status)}
      </dl></section>
      <section class="detail-column"><h4 class="detail-group-title">Household &amp; Demographics</h4><dl class="detail-grid">
        ${profileDetailField('Birthdate', resident.birthdate)}${profileDetailField('Civil Status', resident.civil_status)}${profileDetailField('Purok / Zone', resident.purok_zone)}${profileDetailField('Household Size', resident.household_size)}${profileDetailField('Solo Parent', yesNo(resident.is_solo_parent))}${profileDetailField('PWD', yesNo(resident.is_pwd))}${profileDetailField('4Ps Member', yesNo(resident.is_4ps_member))}
      </dl></section>
      <section class="detail-column"><h4 class="detail-group-title">Livelihood &amp; Skills</h4><dl class="detail-grid">
        ${profileDetailField('Educational Attainment', resident.educational_attainment)}${profileDetailField('Employment Status', resident.employment_status)}${profileDetailField('Occupation', resident.occupation)}${profileDetailField('Certifications', resident.training_certifications)}${profileDetailField('Work Availability', resident.work_availability)}
      </dl></section>
    </div>`);
}

function requestActionButtonsHtml(req) {
  const isTerminal = req.status === 'completed' || req.status === 'cancelled';
  const ref = escapeAttribute(req.ref);

  const specs = [
    ['Process',         'process', `openDocumentGenerationModal('${ref}')`, req.status === 'pending'],
    ['Mark Ready',      'ready',   `updateReqStatus('${ref}','ready')`,     req.status === 'processing'],
    ['Complete',        'approve', `updateReqStatus('${ref}','completed')`, req.status === 'ready'],
    ['Notify via SMS',  'notify',  `notifyPickupReady('${ref}')`,           featureEnabled('sms') && req.status === 'ready'],
    ['Cancel Request',  'reject',  `cancelReq('${ref}')`,                   ['pending','processing'].includes(req.status)],
    ['Mark Paid',       'approve', `updateReqPayment('${ref}','paid')`,     req.paymentStatus === 'unpaid' && !isTerminal],
    ['Waive',           'reject',  `updateReqPayment('${ref}','waived')`,   req.paymentStatus === 'unpaid' && !isTerminal],
  ];

  const buttons = specs.map(([label, cls, handler, enabled]) => {
    if (label === 'Notify via SMS' && !featureEnabled('sms')) {
      return `<button type="button" class="btn-action ${cls}" disabled title="SMS is not configured in this build">${label}</button>`;
    }
    return enabled
      ? `<button type="button" class="btn-action ${cls}" onclick="${handler}">${label}</button>`
      : `<button type="button" class="btn-action ${cls}" disabled>${label}</button>`;
  }).join('');

  return `<div class="request-modal-actions">${buttons}</div>`;
}

function showRequestDetails(requestId) {
  const request = DOC_REQUESTS.find(item => Number(item.id) === Number(requestId));
  if (!request) return;
  const canViewGenerated = request.generatedDocumentPath && request.status !== 'completed';
  const generatedUrl = canViewGenerated ? `../${request.generatedDocumentPath.replace(/^\.?\//, '')}` : '';
  const copies = Number(request.copies || 1);
  const copiesLabel = copies === 1 ? '1 copy' : `${copies} copies`;
  const extraFieldsHtml = Object.entries(request.requestFields || {})
    .map(([k, v]) => profileDetailField(prettifyFieldName(k), String(v)))
    .join('');

  document.getElementById('actionModalBox')?.classList.add('detail-modal-box');
  showModal('Request Details', `
    <dl class="detail-grid request-detail-grid">
      ${profileDetailField('Reference Number', request.ref)}
      ${profileDetailField('Resident', request.resident)}
      ${profileDetailField('Document Type', request.type)}
      ${profileDetailField('Purpose', request.purpose)}
      ${extraFieldsHtml}
      ${profileDetailField('Status', request.status)}
      ${profileDetailField('Quantity', copiesLabel)}
      ${profileDetailField('Total Amount', peso(request.totalAmount))}
      ${profileDetailField('Payment Status', request.paymentStatus || 'unpaid')}
      ${profileDetailField('Date Requested', request.date)}
      ${profileDetailField('Date Completed / Processed', request.dateCompleted)}
    </dl>
    ${requestActionButtonsHtml(request)}
    ${canViewGenerated ? `<div class="generated-document-preview"><a class="btn-action approve" href="${escapeAttribute(generatedUrl)}" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf"></i> View Generated Document</a><iframe title="Generated document preview" src="${escapeAttribute(generatedUrl)}"></iframe></div>` : ''}`);
}

// ── TOAST ─────────────────────────────────
let toastTimeout;
function showToastAdmin(title, body) {
  let toast = document.getElementById('officialToast');
  if (!toast) {
    toast = document.createElement('div');
    toast.id = 'officialToast';
    toast.style.cssText = `position:fixed;bottom:24px;right:24px;background:white;border-radius:12px;padding:16px 20px;box-shadow:0 8px 32px rgba(11,30,69,0.18);border:1px solid #C5CBD6;border-left:4px solid #059669;display:flex;align-items:flex-start;gap:12px;max-width:380px;z-index:9999;transform:translateY(120px);opacity:0;transition:all 0.4s cubic-bezier(0.34,1.56,0.64,1);`;
    document.body.appendChild(toast);
  }
  toast.innerHTML = `<i class="fa-solid fa-circle-check" style="color:#059669;font-size:20px;margin-top:2px;flex-shrink:0"></i><div><div style="font-size:14px;font-weight:700;color:#0B1E45;margin-bottom:3px">${title}</div><div style="font-size:13px;color:#475569">${body}</div></div>`;
  clearTimeout(toastTimeout);
  requestAnimationFrame(() => { toast.style.transform='translateY(0)'; toast.style.opacity='1'; });
  toastTimeout = setTimeout(() => { toast.style.transform='translateY(120px)'; toast.style.opacity='0'; }, 5000);
}

// ── INIT ──────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  initNav();
  restoreActiveSection();
  initSidebarToggle();
  initLogoutConfirmation();
  initOfficialProfilePhotoUpload();
  initResidentTable();
  initDocRequestsTable();
  initComplaintsAdmin();
  initResidentCreateForm();
  initOfficialsSection();
  initAnnouncementForm();
  initServiceForm();
  initDocumentGenerationModal();
  initAiHub();
  initAiEchoTest();
  initReportTiles();
  initVulnerabilityRegistry();
  initHouseholdRegistry();
  initHouseholdWizard();
  initRegistryTabs();
  initWorkforceInsights();
  initAuditLog();
  renderAuditLog();
  startOfficialPolling();
  (async () => {
    try {
      await loadSavedVulnerabilityReports();
      await Promise.all([
        loadResidents(), loadRequests(), loadComplaints(), loadAnnouncements(), loadServices(), loadAuditLog(),
        // Household data is non-fatal: the rest of the dashboard must still load if these fail.
        loadHouseholds().catch(err => console.warn('Households unavailable:', err.message)),
        loadHouseholdRegistry().catch(err => console.warn('Household registry unavailable:', err.message)),
        ...(window.IS_OFFICIAL_ADMIN ? [loadOfficials()] : [])
      ]);
      renderVulnerabilityRegistry();
      renderHouseholdRegistry();
      await refreshDashboard();
    } catch (err) {
      showToastAdmin('Data Load Failed', err.message);
    }
  })();
});
