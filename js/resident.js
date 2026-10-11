/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

/* ─────────────────────────────────────────
   BARANGAYLINK — RESIDENT.JS
───────────────────────────────────────── */

const API_BASE = '../api';
const SIDEBAR_STATE_KEY = 'barangalink.sidebarCollapsed.resident';
const ACTIVE_SECTION_KEY = 'resident_active_section';
let DOC_PRICES = {};
function pesoR(n) { return '₱' + Number(n || 0).toFixed(2); }

async function loadDocumentPrices() {
  try {
    const data = await fetchJson(`${API_BASE}/document_prices/list.php`);
    const rows = data.prices || [];
    DOC_PRICES = {};
    (Array.isArray(rows) ? rows : []).forEach(p => {
      if (p && p.document_type) DOC_PRICES[p.document_type] = Number(p.unit_price || 0);
    });
  } catch { DOC_PRICES = {}; }
}

function hasPostedPrice(type) {
  return Object.prototype.hasOwnProperty.call(DOC_PRICES, type);
}

function updateRequestTotal() {
  const typeEl   = document.getElementById('reqDocType');
  const copiesEl = document.getElementById('reqCopies');
  const submitEl = document.getElementById('reqSubmitBtn');
  if (!typeEl || !copiesEl) return;
  const copies = Math.max(1, Math.min(5, parseInt(copiesEl.value, 10) || 1));
  document.getElementById('reqCopiesOut').textContent = String(copies);
  if (!hasPostedPrice(typeEl.value)) {
    document.getElementById('reqUnitPrice').textContent   = '—';
    document.getElementById('reqTotalAmount').textContent = '—';
    if (submitEl) submitEl.disabled = true;
    return;
  }
  const unit = Number(DOC_PRICES[typeEl.value]);
  document.getElementById('reqUnitPrice').textContent   = pesoR(unit);
  document.getElementById('reqTotalAmount').textContent = pesoR(unit * copies);
  if (submitEl) submitEl.disabled = false;
}

const DOC_REQUEST_EXTRA_FIELDS = {
  'Barangay Clearance': [
    { name: 'age', label: 'Age', type: 'number', min: 0, max: 120, required: true, prefill: 'age' },
    { name: 'civil_status', label: 'Civil Status', type: 'select', required: true, prefill: 'civil_status',
      options: ['Single', 'Married', 'Widowed', 'Separated'] },
  ],
  'Certificate of Residency': [
    { name: 'years_of_residency', label: 'Years of Residency', type: 'number', min: 0, required: true },
  ],
  'Business Permit Endorsement': [
    { name: 'business_name', label: 'Business Name', type: 'text', required: true },
    { name: 'business_type', label: 'Business Type', type: 'text', required: true,
      placeholder: 'e.g. Sari-sari store, Carinderia, Repair shop' },
  ],
};

// Flat key → label index, built once at module load. Keeps
// requestFieldLabel O(1) as the whitelist grows. Null prototype so a
// key like 'constructor' or '__proto__' can't collide with Object's
// own properties.
const REQUEST_FIELD_LABELS = Object.values(DOC_REQUEST_EXTRA_FIELDS)
  .flat()
  .reduce((map, def) => {
    if (!(def.name in map)) map[def.name] = def.label;
    return map;
  }, Object.create(null));

// Resolve a stored request_fields_json key to the same label the
// resident saw on the submit form. Falls back to Title Case for any
// key that isn't currently in DOC_REQUEST_EXTRA_FIELDS (e.g. a field
// that was whitelisted in the past but removed since).
function requestFieldLabel(key) {
  if (key in REQUEST_FIELD_LABELS) return REQUEST_FIELD_LABELS[key];
  return String(key)
    .split('_')
    .filter(Boolean)
    .map(part => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ');
}

function computeAgeFromBirthdate(birthdate) {
  if (!birthdate) return '';
  const b = new Date(`${birthdate}T00:00:00`);
  if (Number.isNaN(b.getTime())) return '';
  const now = new Date();
  let age = now.getFullYear() - b.getFullYear();
  const m = now.getMonth() - b.getMonth();
  if (m < 0 || (m === 0 && now.getDate() < b.getDate())) age--;
  return age >= 0 ? String(age) : '';
}

function renderExtraRequestFields() {
  const typeEl = document.getElementById('reqDocType');
  const wrap   = document.getElementById('reqExtraFields');
  if (!typeEl || !wrap) return;
  const defs = DOC_REQUEST_EXTRA_FIELDS[typeEl.value] || [];
  if (!defs.length) { wrap.innerHTML = ''; wrap.style.display = 'none'; return; }
  wrap.style.display = 'grid';
  const profile = window.RESIDENT_PROFILE || {};
  wrap.innerHTML = defs.map(def => {
    const id = `req-extra-${def.name}`;
    let value = '';
    if (def.prefill === 'age') value = computeAgeFromBirthdate(profile.birthdate);
    if (def.prefill === 'civil_status') value = profile.civil_status || '';
    const req = def.required ? ' required' : '';
    let inner;
    if (def.type === 'select') {
      inner = `<select class="form-input" id="${id}" data-extra-field="${def.name}"${req}>
        <option value="">— Select —</option>
        ${def.options.map(o => `<option value="${escapeHtml(o)}"${value === o ? ' selected' : ''}>${escapeHtml(o)}</option>`).join('')}
      </select>`;
    } else {
      const min = def.min !== undefined ? ` min="${def.min}"` : '';
      const max = def.max !== undefined ? ` max="${def.max}"` : '';
      const ph  = def.placeholder ? ` placeholder="${escapeHtml(def.placeholder)}"` : '';
      inner = `<input type="${def.type}" class="form-input" id="${id}" data-extra-field="${def.name}"
        value="${escapeHtml(value)}"${min}${max}${ph}${req} />`;
    }
    return `<div class="field"><label>${escapeHtml(def.label)}</label>${inner}</div>`;
  }).join('');
}

function collectExtraRequestFields() {
  const out = {};
  document.querySelectorAll('#reqExtraFields [data-extra-field]').forEach(el => {
    const key = el.getAttribute('data-extra-field');
    const val = (el.value || '').trim();
    if (key && val !== '') out[key] = val;
  });
  return out;
}

function initRequestPricing() {
  document.getElementById('reqDocType')?.addEventListener('change', () => {
    renderExtraRequestFields();
    updateRequestTotal();
  });
  document.getElementById('reqCopies')?.addEventListener('input', updateRequestTotal);
  renderExtraRequestFields();
  updateRequestTotal();
}

let REQUESTS = [];
let COMPLAINTS = [];
let NOTIFICATIONS = [];
let ANNOUNCEMENTS = [];
let SERVICES = [];
let announcementsLoaded = false;
let servicesLoaded = false;

// Get all notifications
function getAllNotifications() {
  return NOTIFICATIONS;
}

const SECTION_PLAQUES = {
  announcements: { icon: 'fa-bullhorn', label: 'Community Announcements' },
  services: { icon: 'fa-store', label: 'Local Services Directory' },
  dashboard: { icon: 'fa-house', label: 'Resident Dashboard' },
  requests: { icon: 'fa-file-lines', label: 'My Document Requests' },
  complaints: { icon: 'fa-triangle-exclamation', label: 'My Complaints & Concerns' },
  notifications: { icon: 'fa-bell', label: 'Notifications' },
  profile: { icon: 'fa-user', label: 'My Profile' }
};

// ── TAB SWITCHING ─────────────────────────
function switchTab(name) {
  document.querySelectorAll('.snav-item').forEach(b => {
    b.classList.toggle('active', b.dataset.tab === name);
  });
  document.querySelectorAll('.tab-panel').forEach(p => {
    p.classList.toggle('active', p.id === `tab-${name}`);
  });
  const plaque = SECTION_PLAQUES[name];
  const plaqueIcon = document.getElementById('sectionPlaqueIcon');
  const plaqueText = document.getElementById('sectionPlaque');
  if (plaque && plaqueIcon && plaqueText) {
    plaqueText.parentElement.className = `door-plaque door-plaque-${name}`;
    plaqueIcon.className = `fa-solid ${plaque.icon}`;
    plaqueText.textContent = plaque.label;
  }
  if (name === 'announcements' && !announcementsLoaded) {
    loadAnnouncements().catch(err => showToast('Announcements Load Failed', err.message));
  }
  if (name === 'services' && !servicesLoaded) {
    loadServices().catch(err => showToast('Services Load Failed', err.message));
  }
  try {
    sessionStorage.setItem(ACTIVE_SECTION_KEY, name);
  } catch (err) {
    /* ignore storage failures */
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
  applySidebarState(collapsed);
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

// ── DASHBOARD STATS ───────────────────────
function renderDashStats() {
  const el = document.getElementById('dashStats');
  if (!el) return;
  const totalRequests = REQUESTS.length;
  const pendingPickup = REQUESTS.filter(r => r.status === 'ready').length;
  const activeComplaints = COMPLAINTS.filter(c => c.status !== 'resolved').length;
  const unreadNotifications = getAllNotifications().filter(n => n.unread).length;
  const stats = [
    { val: String(totalRequests), lbl: 'Total Requests', ico: 'fa-file-lines', bg: '#DBEAFE', color: '#1976D2' },
    { val: String(pendingPickup), lbl: 'Pending Pickup', ico: 'fa-bell', bg: '#FEF9C3', color: '#D97706' },
    { val: String(activeComplaints), lbl: 'Active Complaints', ico: 'fa-triangle-exclamation', bg: '#FFF7ED', color: '#EA580C' },
    { val: String(unreadNotifications), lbl: 'Unread Notifications', ico: 'fa-envelope', bg: '#DCFCE7', color: '#059669' },
  ];
  el.innerHTML = stats.map(s => `
    <div class="stat-card">
      <div class="sc-ico" style="background:${s.bg}; color:${s.color}">
        <i class="fa-solid ${s.ico}"></i>
      </div>
      <div class="sc-data">
        <div class="sc-val">${s.val}</div>
        <div class="sc-lbl">${s.lbl}</div>
      </div>
    </div>
  `).join('');
}

// ── RECENT ITEMS (DASHBOARD) ──────────────
function renderRecentRequests() {
  const el = document.getElementById('recentRequestsList');
  if (!el) return;
  el.innerHTML = REQUESTS.slice(0,3).map(r => `
    <div class="mini-row">
      <div class="mr-icon" style="background:#DBEAFE; color:#1976D2"><i class="fa-solid fa-file-lines"></i></div>
      <div class="mr-text">
        <div class="mr-title">${r.type}</div>
        <div class="mr-sub">${r.ref} · ${r.date}</div>
      </div>
      <span class="status-badge status-${r.status}">${r.status}</span>
    </div>
  `).join('');
}

function renderRecentComplaints() {
  const el = document.getElementById('recentComplaintsList');
  if (!el) return;
  el.innerHTML = COMPLAINTS.slice(0,2).map(c => `
    <div class="mini-row">
      <div class="mr-icon" style="background:#FFF7ED; color:#EA580C"><i class="fa-solid fa-triangle-exclamation"></i></div>
      <div class="mr-text">
        <div class="mr-title">${c.cat}</div>
        <div class="mr-sub">${c.ref} · ${c.date}</div>
      </div>
      <span class="status-badge status-${c.status}">${c.status}</span>
    </div>
  `).join('');
}

// ── REQUESTS TABLE ────────────────────────
function renderRequestsTable(data) {
  const tbody = document.getElementById('reqTableBody');
  if (!tbody) return;
  if (!data.length) {
    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;color:var(--text-3);padding:32px;">No requests found.</td></tr>`;
    return;
  }
  tbody.innerHTML = data.map(r => `
    <tr>
      <td><code style="font-size:12px;color:var(--blue-mid)">${r.ref}</code></td>
      <td style="font-weight:600;color:var(--text)">${r.type}</td>
      <td>${r.purpose}</td>
      <td>${r.date}</td>
      <td>
        <div style="font-weight:600">${pesoR(r.totalAmount)}</div>
        <span class="status-badge status-${r.paymentStatus || 'unpaid'}">${r.paymentStatus || 'unpaid'}</span>
      </td>
      <td><span class="status-badge status-${r.status}">${r.status}</span></td>
      <td><button onclick="viewRequest(${r.id})" style="font-size:12px;padding:5px 12px;background:var(--surface);border:1.5px solid var(--border);border-radius:6px;cursor:pointer;font-weight:600;color:var(--text-2);">View</button></td>
    </tr>
  `).join('');
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function viewRequest(id) {
  const req = REQUESTS.find(r => Number(r.id) === Number(id));
  if (!req) return;
  const copies = Number(req.copies || 1);
  const copiesLabel = copies === 1 ? '1 copy' : `${copies} copies`;
  const extraRows = Object.entries(req.requestFields || {})
    .filter(([, v]) => v !== null && v !== undefined && v !== '')
    .map(([k, v]) => [requestFieldLabel(k), String(v)]);
  const fields = [
    ['Reference Number', req.ref],
    ['Document Type', req.type],
    ['Purpose', req.purpose],
    ...extraRows,
    ['Quantity', copiesLabel],
    ['Total Amount', pesoR(req.totalAmount)],
    ['Payment Status', req.paymentStatus || 'unpaid'],
    ['Status', req.status],
    ['Date Requested', req.date],
  ];
  const box = document.getElementById('requestModalBox');
  if (!box) return;
  box.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid var(--border)">
      <h3 style="font-size:1.2rem;font-weight:600;color:var(--text)">Request Details</h3>
      <button type="button" onclick="closeRequestModal()" style="background:none;border:none;font-size:18px;color:var(--text-3);cursor:pointer"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <dl style="display:grid;gap:8px;margin:0 0 20px">
      ${fields.map(([label, value]) => `
        <div style="display:flex;justify-content:space-between;gap:12px;padding:8px 10px;background:var(--surface-2);border:1px solid var(--border);border-radius:7px">
          <dt style="color:var(--text-3);font-size:11px;text-transform:uppercase;letter-spacing:.04em">${escapeHtml(label)}</dt>
          <dd style="margin:0;color:var(--text);font-size:13px;font-weight:600;text-align:right;overflow-wrap:anywhere">${escapeHtml(value)}</dd>
        </div>`).join('')}
    </dl>
    <button type="button" onclick="closeRequestModal()" style="padding:9px 24px;background:var(--blue-mid);color:white;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer">Close</button>
  `;
  document.getElementById('requestModal').classList.add('show');
}

function closeRequestModal() {
  document.getElementById('requestModal')?.classList.remove('show');
}

function initRequestsTable() {
  renderRequestsTable(REQUESTS);
  document.getElementById('reqSearch')?.addEventListener('input', filterRequests);
  document.getElementById('reqFilter')?.addEventListener('change', filterRequests);
}

function filterRequests() {
  const q = document.getElementById('reqSearch').value.toLowerCase();
  const status = document.getElementById('reqFilter').value;
  const filtered = REQUESTS.filter(r => {
    const matchQ = r.type.toLowerCase().includes(q) || r.ref.toLowerCase().includes(q) || r.purpose.toLowerCase().includes(q);
    const matchS = status === 'all' || r.status === status;
    return matchQ && matchS;
  });
  renderRequestsTable(filtered);
}

// ── REQUEST FORM TOGGLE ───────────────────
function initReqFormToggle() {
  const openBtn  = document.getElementById('newReqBtn');
  const closeBtn = document.getElementById('closeReqForm');
  const cancelBtn= document.getElementById('cancelReqForm');
  const form     = document.getElementById('newReqForm');
  const formEl   = document.getElementById('reqFormEl');

  openBtn?.addEventListener('click',   () => { form.style.display = 'block'; form.scrollIntoView({ behavior:'smooth', block:'start' }); });
  closeBtn?.addEventListener('click',  () => { form.style.display = 'none'; });
  cancelBtn?.addEventListener('click', () => { form.style.display = 'none'; });
  formEl?.addEventListener('submit', e => {
    e.preventDefault();
    submitRequest(formEl, form);
  });
}

// ── COMPLAINTS LIST ───────────────────────
function renderComplaintsList() {
  const el = document.getElementById('complaintsList');
  if (!el) return;
  el.innerHTML = COMPLAINTS.map(c => `
    <div class="complaint-item">
      <div class="ci-head">
        <div>
          <div class="ci-ref">${c.ref} · ${c.cat}</div>
          <div class="ci-title">${c.loc}</div>
        </div>
        <span class="status-badge status-${c.status}">${c.status}</span>
      </div>
      <div class="ci-meta">
        <span><i class="fa-regular fa-calendar"></i> Filed: ${c.date}</span>
        <span><i class="fa-solid fa-comment-dots"></i> ${c.note}</span>
      </div>
      <div class="ci-progress">
        <div class="progress-track"><div class="progress-fill" style="width:${c.progress}%"></div></div>
        <span class="progress-label">${c.progress}% resolved</span>
      </div>
    </div>
  `).join('');
}

function initComplaintFormToggle() {
  const openBtn  = document.getElementById('newComplaintBtn');
  const closeBtn = document.getElementById('closeComplaintForm');
  const cancelBtn= document.getElementById('cancelComplaintForm');
  const form     = document.getElementById('newComplaintForm');
  const formEl   = document.getElementById('complaintFormEl');

  openBtn?.addEventListener('click',   () => { form.style.display = 'block'; form.scrollIntoView({ behavior:'smooth' }); });
  closeBtn?.addEventListener('click',  () => { form.style.display = 'none'; });
  cancelBtn?.addEventListener('click', () => { form.style.display = 'none'; });
  formEl?.addEventListener('submit', e => {
    e.preventDefault();
    submitComplaint(formEl, form);
  });
}

// ── NOTIFICATIONS ─────────────────────────
function renderNotifications() {
  const list = document.getElementById('notifList');
  const drawerList = document.getElementById('notifDrawerList');
  const allNotifs = getAllNotifications();  // get notifications
  const html = allNotifs.map(n => `
    <div class="notif-item ${n.unread ? 'unread' : ''}" onclick="markRead(${n.id})">
      <div class="ni-icon" style="background:${n.iconBg}; color:${n.iconColor}">
        <i class="fa-solid ${n.icon}"></i>
      </div>
      <div>
        <div class="ni-title">${n.title}</div>
        <div class="ni-body">${n.body}</div>
        <div class="ni-time"><i class="fa-regular fa-clock"></i> ${n.time}</div>
      </div>
    </div>
  `).join('');
  if (list) list.innerHTML = html;
  if (drawerList) drawerList.innerHTML = html;
}

async function markAllNotificationsRead() {
  const button = document.getElementById('markAllReadBtn');
  if (button) button.disabled = true;
  try {
    await fetchJson(`${API_BASE}/notifications/mark_all_read.php`, { method: 'POST' });
    await loadNotifications();
    await refreshResidentView();
    showToast('Notifications Updated', 'All notifications are marked as read.');
  } catch (err) {
    showToast('Update Failed', err.message);
  } finally {
    if (button) button.disabled = false;
  }
}

function markRead(id) {
  const allNotifs = getAllNotifications();
  const n = allNotifs.find(x => x.id === id);
  if (n) n.unread = false;
  renderNotifications();
}

// Notification badge/count logic removed — notifications render without numeric badge.

// ── NOTIFICATION DRAWER ───────────────────
function initNotifDrawer() {
  const drawer  = document.getElementById('notifDrawer');
  const overlay = document.getElementById('drawerOverlay');
  const toggle  = document.getElementById('notifToggle');
  const close   = document.getElementById('closeNotifDrawer');

  toggle?.addEventListener('click', () => { drawer.classList.toggle('open'); overlay.classList.toggle('show'); });
  close?.addEventListener('click',  () => { drawer.classList.remove('open'); overlay.classList.remove('show'); });
  overlay?.addEventListener('click',() => { drawer.classList.remove('open'); overlay.classList.remove('show'); });
}

function initMarkAllRead() {
  document.getElementById('markAllReadBtn')?.addEventListener('click', markAllNotificationsRead);
}

function mapRequestRow(row) {
  let requestFields = {};
  try {
    requestFields = row.request_fields_json ? JSON.parse(row.request_fields_json) : {};
  } catch { requestFields = {}; }
  return {
    id: Number(row.id),
    ref: row.reference_no,
    type: row.document_type,
    purpose: row.purpose,
    date: row.date_requested,
    status: row.status,
    copies: Number(row.copies || 1),
    totalAmount: Number(row.total_amount || 0),
    paymentStatus: row.payment_status || 'unpaid',
    requestFields,
  };
}

function mapComplaintRow(row) {
  const statusToProgress = { open: 0, investigating: 50, resolved: 100 };
  return {
    ref: row.reference_no,
    cat: row.category,
    loc: row.location_text,
    date: row.date_filed,
    status: row.status,
    progress: statusToProgress[row.status] ?? 0,
    note: row.official_note || 'Received. Awaiting assignment.'
  };
}

function mapNotificationRow(row) {
  return {
    id: Number(row.id),
    unread: Number(row.is_read) === 0,
    iconBg: '#DBEAFE',
    iconColor: '#1976D2',
    icon: 'fa-bell',
    title: row.title,
    body: row.body,
    time: row.created_at
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

async function loadRequests() {
  // Resident identity is provided by the server-side PHP session.
  const data = await fetchJson(`${API_BASE}/requests/list.php`);
  REQUESTS = (data.requests || []).map(mapRequestRow);
}

async function loadComplaints() {
  const data = await fetchJson(`${API_BASE}/complaints/list.php`);
  COMPLAINTS = (data.complaints || []).map(mapComplaintRow);
}

async function loadNotifications() {
  const data = await fetchJson(`${API_BASE}/notifications/list.php`);
  NOTIFICATIONS = (data.notifications || []).map(mapNotificationRow);
}

function renderAnnouncements() {
  const grid = document.getElementById('residentAnnouncementsGrid');
  if (!grid) return;
  if (!ANNOUNCEMENTS.length) {
    grid.innerHTML = '<p class="community-empty">No announcements have been posted yet.</p>';
    return;
  }
  grid.innerHTML = ANNOUNCEMENTS.slice(0, 6).map(a => `
    <article class="community-card announcement-card">
      <div class="community-meta">
        ${Number(a.is_pinned) === 1 ? '<span class="community-badge">Pinned</span>' : ''}
        <span><i class="fa-regular fa-calendar"></i> ${formatCommunityDate(a.created_at)}</span>
      </div>
      <h3>${a.title}</h3>
      <p>${a.content}</p>
    </article>
  `).join('');
}

async function loadAnnouncements() {
  const data = await fetchJson(`${API_BASE}/announcements/list.php`);
  ANNOUNCEMENTS = data.announcements || [];
  announcementsLoaded = true;
  renderAnnouncements();
}

function renderServices(filter = 'all') {
  const grid = document.getElementById('residentServicesGrid');
  if (!grid) return;
  const filtered = filter === 'all'
    ? SERVICES
    : SERVICES.filter(service => String(service.category || '').toLowerCase() === filter);
  if (!filtered.length) {
    grid.innerHTML = '<p class="community-empty">No local services have been added yet.</p>';
    return;
  }
  grid.innerHTML = filtered.map(service => `
    <article class="community-card service-card">
      <div class="service-card-head">
        <div class="service-icon">🏪</div>
        <div>
          <h3>${service.service_name}</h3>
          <span>${service.category}</span>
        </div>
      </div>
      <div class="service-verified"><i class="fa-solid fa-circle-check"></i> Barangay Verified</div>
      <p>${service.description || 'No description provided.'}</p>
      <div class="service-detail"><i class="fa-solid fa-location-dot"></i> ${service.address || 'Address not provided'}</div>
      <div class="service-detail"><i class="fa-solid fa-phone"></i> ${service.contact_number || 'Contact not provided'}</div>
      ${service.operating_hours ? `<div class="service-detail"><i class="fa-regular fa-clock"></i> ${service.operating_hours}</div>` : ''}
    </article>
  `).join('');
}

function renderServiceFilters() {
  const row = document.getElementById('residentServiceFilters');
  if (!row) return;
  const filters = ['All', ...new Set(SERVICES.map(service => service.category).filter(Boolean))];
  row.innerHTML = filters.map((filter, index) =>
    `<button class="filter-btn ${index === 0 ? 'active' : ''}" data-filter="${filter.toLowerCase()}">${filter}</button>`
  ).join('');
  row.querySelectorAll('.filter-btn').forEach(button => {
    button.addEventListener('click', () => {
      row.querySelectorAll('.filter-btn').forEach(item => item.classList.remove('active'));
      button.classList.add('active');
      renderServices(button.dataset.filter);
    });
  });
}

async function loadServices() {
  const data = await fetchJson(`${API_BASE}/services/list.php`);
  SERVICES = data.services || [];
  servicesLoaded = true;
  renderServiceFilters();
  renderServices();
}

function formatCommunityDate(value) {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString(undefined, {
    month: 'short', day: 'numeric', year: 'numeric'
  });
}

async function refreshResidentView() {
  renderDashStats();
  renderRecentRequests();
  renderRecentComplaints();
  renderRequestsTable(REQUESTS);
  renderComplaintsList();
  renderNotifications();
}

async function submitRequest(formEl, form) {
  const type   = document.getElementById('reqDocType')?.value || '';
  const purpose = document.getElementById('reqPurpose')?.value || '';
  const copies = Math.min(5, Math.max(1, parseInt(document.getElementById('reqCopies')?.value || '1', 10) || 1));
  if (!type || !purpose) {
    showToast('Missing Fields', 'Please select a document type and purpose.');
    return;
  }
  if (!hasPostedPrice(type)) {
    showToast('Price Unavailable', 'This document type has no posted price. Choose another type or try again later.');
    return;
  }

  // Enforce required extras client-side to give a clean error before the request.
  const extras = collectExtraRequestFields();
  const required = (DOC_REQUEST_EXTRA_FIELDS[type] || []).filter(d => d.required);
  const missing = required.filter(d => !extras[d.name]);
  if (missing.length) {
    showToast('Missing Fields', `Please fill in: ${missing.map(d => d.label).join(', ')}.`);
    return;
  }

  try {
    await fetchJson(`${API_BASE}/requests/create.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ document_type: type, purpose, copies, fields: extras })
    });
    form.style.display = 'none';
    formEl.reset();
    renderExtraRequestFields();
    updateRequestTotal();
    await loadRequests();
    await refreshResidentView();
    showToast('Request Submitted!', 'Your document request has been submitted.');
  } catch (err) {
    showToast('Submit Failed', err.message);
  }
}

async function submitComplaint(formEl, form) {
  const category = document.getElementById('reqCategory')?.value || '';
  const location = document.getElementById('reqLocation')?.value || '';
  const description = document.getElementById('reqDescription')?.value || '';
  if (!category || !location || !description) {
    showToast('Missing Fields', 'Please complete all complaint details.');
    return;
  }
  try {
    await fetchJson(`${API_BASE}/complaints/create.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ category, location_text: location, description })
    });
    form.style.display = 'none';
    formEl.reset();
    await loadComplaints();
    await refreshResidentView();
    showToast('Complaint Submitted!', 'Your complaint has been submitted for review.');
  } catch (err) {
    showToast('Submit Failed', err.message);
  }
}

// ── TOAST ─────────────────────────────────
let toastTimeout;
function initProfilePhotoUpload() {
  const form = document.getElementById('profilePhotoForm');
  const input = document.getElementById('profilePhotoInput');
  if (!form || !input) return;
  input.addEventListener('change', () => {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    const modal = document.createElement('div');
    modal.className = 'photo-confirm-overlay';
    modal.innerHTML = `
      <div class="photo-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="photoConfirmTitle">
        <h3 id="photoConfirmTitle">Change your profile photo to this?</h3>
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
        const response = await fetch('../api/residents/upload_photo.php', { method: 'POST', body });
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.message || 'Photo upload failed.');
        close();
        showToast('Profile Photo Updated', 'Your profile photo was updated.');
        window.location.reload();
      } catch (error) {
        close();
        showToast('Upload Failed', error.message);
      }
    });
  });
}

function showToast(title, body) {
  let toast = document.getElementById('residentToast');
  if (!toast) {
    toast = document.createElement('div');
    toast.id = 'residentToast';
    toast.style.cssText = `
      position:fixed; bottom:24px; right:24px; background:white;
      border-radius:12px; padding:16px 20px; box-shadow:0 8px 32px rgba(11,30,69,0.18);
      border:1px solid #C5CBD6; border-left:4px solid #059669; display:flex; align-items:flex-start; gap:12px;
      max-width:380px; z-index:9999; transform:translateY(120px); opacity:0;
      transition:all 0.4s cubic-bezier(0.34,1.56,0.64,1);
    `;
    document.body.appendChild(toast);
  }

  toast.innerHTML = `
    <i class="fa-solid fa-circle-check" style="color:#059669; font-size:20px; margin-top:2px; flex-shrink:0;"></i>
    <div><div style="font-size:14px;font-weight:700;color:#0B1E45;margin-bottom:3px">${title}</div><div style="font-size:13px;color:#475569">${body}</div></div>
  `;
  clearTimeout(toastTimeout);
  requestAnimationFrame(() => {
    toast.style.transform = 'translateY(0)';
    toast.style.opacity = '1';
  });
  toastTimeout = setTimeout(() => {
    toast.style.transform = 'translateY(120px)';
    toast.style.opacity = '0';
  }, 5000);
}

const POLL_INTERVAL_MS = 20000;
const POLL_BACKOFF_MAX_MS = 120000;

let pollTimer = null;
let pollBackoff = POLL_INTERVAL_MS;
let pollInflight = false;

async function pollResidentUpdates() {
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
    fetches.push(fetchJson(`${API_BASE}/requests/list.php`)
      .then(d => { REQUESTS = (d.requests || []).map(mapRequestRow); }));
  }
  if (activeSection === 'tab-complaints' || activeSection === 'tab-dashboard') {
    fetches.push(fetchJson(`${API_BASE}/complaints/list.php`)
      .then(d => { COMPLAINTS = (d.complaints || []).map(mapComplaintRow); }));
  }
  if (activeSection === 'tab-notifications' || activeSection === 'tab-dashboard') {
    fetches.push(fetchJson(`${API_BASE}/notifications/list.php`)
      .then(d => { NOTIFICATIONS = (d.notifications || []).map(mapNotificationRow); }));
  }
  if (!fetches.length) { scheduleNextPoll(); return; }

  pollInflight = true;
  try {
    await Promise.all(fetches);
    await refreshResidentView();
    filterRequests();
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
  pollTimer = setTimeout(pollResidentUpdates, pollBackoff);
}

function startResidentPolling() {
  if (pollTimer) return;
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') pollResidentUpdates();
  });
  pollResidentUpdates();
}

// ── INIT ──────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  initNav();
  restoreActiveSection();
  initSidebarToggle();
  initLogoutConfirmation();
  initRequestsTable();
  initReqFormToggle();
  initComplaintFormToggle();
  initNotifDrawer();
  initMarkAllRead();
  initProfilePhotoUpload();
  startResidentPolling();
  (async () => {
    try {
      await loadDocumentPrices();
      initRequestPricing();
      await Promise.all([loadRequests(), loadComplaints(), loadNotifications()]);
      await refreshResidentView();
    } catch (err) {
      showToast('Data Load Failed', err.message);
    }
  })();
});
