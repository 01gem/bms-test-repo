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

// ── RETRY STATE ───────────────────────────
// Keyed by the form element. One entry per active retry session, scoped to the
// lifetime of the form. WeakMap so a detached form (e.g. a modal-based composer
// in a later phase) doesn't leak its state. Generic — reusable by any future
// resource that has a "parent created, children failed" split.
const RETRY_STATES = new WeakMap();

function setRetryState(formEl, state) {
  RETRY_STATES.set(formEl, state);
}

function getRetryState(formEl) {
  return RETRY_STATES.get(formEl) || null;
}

function clearRetryState(formEl) {
  RETRY_STATES.delete(formEl);
}

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
const complaintResolutionHold = new Map();
let complaintDetailSeq = 0;
let complaintOpenGeneration = 0;
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
  // Phones: default to collapsed so the fixed 260px sidebar doesn't eat
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
  box.classList.remove('complaint-detail-box');
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
  const box = document.getElementById('requestModalBox');
  document.getElementById('requestModal')?.classList.remove('show');
  box?.classList.remove('complaint-detail-box');
  complaintOpenGeneration += 1;
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

  const requestClose = () => {
    setFormModalOpen(form, false);
    openBtn?.focus();
  };

  openBtn?.addEventListener('click', () => {
    setFormModalOpen(form, true);
    formEl?.querySelector('select, input, textarea')?.focus();
  });
  closeBtn?.addEventListener('click', requestClose);
  cancelBtn?.addEventListener('click', requestClose);
  formEl?.addEventListener('submit', e => {
    e.preventDefault();
    submitRequest(formEl, form);
  });
}

// ── COMPLAINTS LIST ───────────────────────
// Keep in sync with the identical severityChip() in js/official.js.
function severityChip(severity) {
  const map = {
    low:      { bg: '#DCFCE7', color: '#15803D', label: 'Low' },
    medium:   { bg: '#FEF9C3', color: '#A16207', label: 'Medium' },
    high:     { bg: '#FFEDD5', color: '#C2410C', label: 'High' },
    critical: { bg: '#FEE2E2', color: '#B91C1C', label: 'Critical' }
  };
  const s = map[String(severity).toLowerCase()] || map.medium;
  return `<span style="display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;background:${s.bg};color:${s.color}">${s.label}</span>`;
}

async function loadComplaintTimeline(complaintId) {
  const data = await fetchJson(`${API_BASE}/complaints/timeline.php?complaint_id=${encodeURIComponent(complaintId)}`);
  return data.updates || [];
}

function renderTimelineHtml(updates) {
  if (!updates.length) {
    return '<p class="complaint-meta">No timeline entries yet.</p>';
  }
  return '<ol style="list-style:none;padding:0;margin:0">' + updates.map(u => {
    const when = u.created_at ? escapeHtml(String(u.created_at)) : '';
    const who = escapeHtml(u.actor_name || (u.actor_type === 'system' ? 'System' : u.actor_type));
    const from = u.from_status ? `${escapeHtml(u.from_status)} → ` : '';
    const to = escapeHtml(u.to_status || '');
    const note = u.note ? `<div class="complaint-timeline-note">${escapeHtml(u.note)}</div>` : '';
    return `<li class="complaint-timeline-item">
      <div class="complaint-timeline-when">${when}</div>
      <div class="complaint-timeline-who">${who} — ${from}${to}</div>
      ${note}
    </li>`;
  }).join('') + '</ol>';
}

async function loadComplaintAttachments(complaintId) {
  const data = await fetchJson(`${API_BASE}/complaints/list_attachments.php?complaint_id=${encodeURIComponent(complaintId)}`);
  return data.attachments || [];
}

function renderAttachmentsHtml(attachments) {
  if (!attachments.length) {
    return '<p class="complaint-meta">No photos attached.</p>';
  }
  return '<div class="complaint-photos">' + attachments.map(a => {
    const href = escapeHtml(a.url);
    return `<a href="${href}" target="_blank" rel="noopener" title="Open full size">
      <img src="${href}" alt="Complaint photo" />
    </a>`;
  }).join('') + '</div>';
}

// Keep in sync with the identical complaint comment helpers in js/official.js.
function complaintCommentsSectionHtml() {
  return `<section data-complaint-comments class="complaint-section">
    <h4>Comments</h4>
    <div data-comment-list aria-live="polite" class="complaint-scroll-well">Loading comments…</div>
    <form data-comment-form class="complaint-controls">
      <label class="complaint-field">Add a comment (up to 2,000 characters)
        <textarea name="body" rows="3" required></textarea>
      </label>
      <button type="submit" disabled class="complaint-btn">Post comment</button>
      <p data-comment-status role="status" class="complaint-meta"></p>
    </form>
  </section>`;
}

async function loadComplaintComments(complaintId) {
  const data = await fetchJson(`${API_BASE}/complaints/list_comments.php?complaint_id=${encodeURIComponent(complaintId)}`);
  return data.comments || [];
}

function renderComplaintCommentsHtml(comments) {
  if (!comments.length) return '<p class="complaint-meta">No comments yet.</p>';
  return comments.map(comment => {
    const name = escapeHtml(comment.actor_name || (comment.actor_type === 'official' ? 'Official' : 'Resident'));
    const role = comment.actor_type === 'official' ? 'Official' : 'Resident';
    const when = escapeHtml(String(comment.created_at || ''));
    const body = escapeHtml(String(comment.body || ''));
    return `<article class="complaint-comment">
      <div class="complaint-comment-name">${name} · ${role}</div>
      <div class="complaint-comment-when">${when}</div>
      <div class="complaint-comment-body">${body}</div>
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

// Keep in sync with the identical complaintResidentResponseText() in js/official.js.
function complaintResidentResponseText(c) {
  if (c.status === 'resolved') {
    return c.residentConfirmation === 'confirmed'
      ? 'Resident confirmed the resolution'
      : 'Awaiting resident confirmation';
  }
  return c.residentConfirmation === 'reopened' ? 'Reopened by resident' : '';
}

// Keep in sync with the identical complaintResidentResponseTimesHtml() in js/official.js.
function complaintResidentResponseTimesHtml(c) {
  const parts = [];
  if (c.residentConfirmation === 'confirmed' && c.confirmedAt) {
    parts.push(`Confirmed ${escapeHtml(String(c.confirmedAt))}`);
  }
  if (c.residentConfirmation === 'reopened' && c.reopenedAt) {
    parts.push(`Reopened ${escapeHtml(String(c.reopenedAt))}`);
  }
  if (!parts.length) return '';
  return `<p data-response-times class="complaint-meta">${parts.join(' · ')}</p>`;
}

// Keep in sync with the identical complaintIdOrNull() in js/official.js.
function complaintIdOrNull(value) {
  if (value === null || value === undefined || value === '') return null;
  const parsed = Number(value);
  if (!Number.isInteger(parsed) || parsed <= 0) return null;
  return parsed;
}

// Keep in sync with the identical complaintAssigneeLabel() in js/official.js.
function complaintAssigneeLabel(c) {
  if (c.assignedOfficialId === null || c.assignedOfficialId === undefined) return 'Unassigned';
  const name = String(c.assignedOfficialName || '').trim();
  return name !== '' ? name : 'Assigned official';
}

// Keep in sync with the identical complaintTriageStateText() in js/official.js.
function complaintTriageStateText(c) {
  if (c.status === 'resolved') {
    return c.slaDueAt
      ? 'Resolved. The response target is kept for history.'
      : 'Resolved. No response target was recorded.';
  }
  if (c.isOverdue) return 'Overdue';
  if (c.slaDueAt) return 'Within the response target';
  return 'No response target is set';
}

// Keep in sync with the identical complaintTriageSummaryHtml() in js/official.js.
function complaintTriageSummaryHtml(c) {
  const assignee = escapeHtml(complaintAssigneeLabel(c));
  const state = escapeHtml(complaintTriageStateText(c));
  const target = c.slaDueAt
    ? `<p data-triage-target class="complaint-meta">Response target ${escapeHtml(String(c.slaDueAt))}</p>`
    : '';
  let flagged = '';
  if (c.escalatedAt && c.status === 'resolved') {
    flagged = `<p data-triage-flagged class="complaint-meta">Previously flagged ${escapeHtml(String(c.escalatedAt))}</p>`;
  } else if (c.escalatedAt) {
    flagged = `<p data-triage-flagged class="complaint-meta">Flagged ${escapeHtml(String(c.escalatedAt))}</p>`;
  }
  return `<p data-triage-assignee class="complaint-strong">${assignee}</p>${target}<p data-triage-state class="complaint-meta">${state}</p>${flagged}`;
}

function complaintTriageSectionHtml(c) {
  return `<section data-complaint-triage data-complaint-id="${Number(c.id)}" class="complaint-section complaint-section-block">
    <h4>Assignment</h4>
    <div data-triage-summary>${complaintTriageSummaryHtml(c)}</div>
  </section>`;
}

function paintComplaintTriageSummary(root, complaint) {
  if (!root || !complaint) return;
  const summary = root.querySelector('[data-triage-summary]');
  if (summary) summary.innerHTML = complaintTriageSummaryHtml(complaint);
}

function complaintResolutionActionsHtml(c) {
  if (!c || c.status !== 'resolved') return '';
  const confirm = c.residentConfirmation === 'confirmed'
    ? ''
    : '<button type="button" data-confirm-resolution class="complaint-btn">Confirm resolution</button>';
  return `<div data-response-actions class="complaint-control-row">
      ${confirm}
      <button type="button" data-reopen-toggle aria-expanded="false" class="complaint-btn complaint-btn-ghost">Still broken? Reopen complaint</button>
      <div data-reopen-panel hidden class="complaint-reopen">
        <label class="complaint-field">What is still broken? This reason is posted as a comment.
          <textarea data-reopen-reason rows="3"></textarea>
        </label>
        <button type="button" data-reopen-submit class="complaint-btn">Reopen complaint</button>
      </div>
    </div>`;
}

function complaintResidentResponseSectionHtml(c) {
  const text = complaintResidentResponseText(c);
  if (!text) return '';
  return `<section data-resident-response data-complaint-id="${Number(c.id)}" class="complaint-section complaint-section-block">
    <h4>Resident response</h4>
    <p data-response-summary class="complaint-strong">${escapeHtml(text)}</p>
    ${complaintResidentResponseTimesHtml(c)}
    ${complaintResolutionActionsHtml(c)}
    <p data-response-status role="status" class="complaint-meta"></p>
  </section>`;
}

function initResidentResolutionActions(section, complaint, box, options = {}) {
  if (!section) return;
  const confirmBtn = section.querySelector('[data-confirm-resolution]');
  const toggleBtn = section.querySelector('[data-reopen-toggle]');
  const statusEl = section.querySelector('[data-response-status]');
  if (!confirmBtn && !toggleBtn) return;

  const complaintId = Number(complaint.id);
  let expectedResolutionId = Number(complaint.resolutionUpdateId || 0);
  const modal = document.getElementById('requestModal');
  let busy = false;
  let recovering = false;
  section.dataset.resolutionToken = String(expectedResolutionId);

  const liveControls = () => ({
    confirmBtn: section.querySelector('[data-confirm-resolution]'),
    toggleBtn: section.querySelector('[data-reopen-toggle]'),
    reasonEl: section.querySelector('[data-reopen-reason]'),
    submitBtn: section.querySelector('[data-reopen-submit]')
  });

  const setBusy = (on) => {
    busy = on;
    const controls = liveControls();
    [controls.confirmBtn, controls.toggleBtn, controls.submitBtn, controls.reasonEl].forEach(control => {
      if (control && control.isConnected) control.disabled = on;
    });
  };

  const stillThisComplaint = () => section.isConnected
    && modal
    && modal.classList.contains('show')
    && Number(box.dataset.complaintId) === complaintId;

  const markHold = (fallbackReason = '') => {
    const existing = complaintResolutionHold.get(complaintId) || {};
    let reopenReason = existing.reopenReason || fallbackReason || '';
    let commentDraft = existing.commentDraft || '';
    if (stillThisComplaint()) {
      const liveReason = section.querySelector('[data-reopen-reason]');
      if (liveReason) reopenReason = liveReason.value;
      const commentEl = box.querySelector('[data-comment-form] textarea');
      if (commentEl) commentDraft = commentEl.value;
    }
    complaintResolutionHold.set(complaintId, { reopenReason, commentDraft });
  };

  const hideRefresh = () => {
    const button = section.querySelector('[data-refresh-resolution]');
    if (button) button.hidden = true;
  };

  const ensureRefreshButton = () => {
    let button = section.querySelector('[data-refresh-resolution]');
    if (button) return button;
    button = document.createElement('button');
    button.type = 'button';
    button.dataset.refreshResolution = '';
    button.textContent = 'Refresh status';
    button.className = 'complaint-btn complaint-btn-ghost';
    if (statusEl) statusEl.before(button);
    else section.append(button);
    button.addEventListener('click', () => {
      recoverResolutionState().catch(() => {
        if (!stillThisComplaint()) return;
        button.disabled = false;
        if (statusEl && statusEl.isConnected) {
          statusEl.textContent = 'Status could not be refreshed. Use Refresh status to try again, or reload the page.';
        }
      });
    });
    return button;
  };

  const showRecovery = (message, remember = true) => {
    if (remember) markHold();
    setBusy(true);
    if (!stillThisComplaint()) return;
    const button = ensureRefreshButton();
    button.hidden = false;
    button.disabled = false;
    if (statusEl && statusEl.isConnected) statusEl.textContent = message;
  };

  const paintResolution = (updated) => {
    paintComplaintTriageSummary(box, updated);
    const statusNode = box.querySelector('[data-complaint-status]');
    if (statusNode && statusNode.isConnected) {
      statusNode.textContent = updated.status;
      statusNode.className = `status-badge status-${updated.status}`;
    }
    const summary = section.querySelector('[data-response-summary]');
    if (summary) summary.textContent = complaintResidentResponseText(updated);
    section.querySelector('[data-response-times]')?.remove();
    const times = complaintResidentResponseTimesHtml(updated);
    if (times && summary) summary.insertAdjacentHTML('afterend', times);
    const previousReason = section.querySelector('[data-reopen-reason]')?.value || '';
    const panelOpen = !!section.querySelector('[data-reopen-panel]')
      && !section.querySelector('[data-reopen-panel]').hasAttribute('hidden');
    const existing = section.querySelector('[data-response-actions]');
    const html = complaintResolutionActionsHtml(updated);
    if (!html) {
      existing?.remove();
    } else if (existing) {
      existing.outerHTML = html;
    } else {
      const anchor = section.querySelector('[data-response-status]') || section.querySelector('[data-refresh-resolution]');
      if (anchor) anchor.insertAdjacentHTML('beforebegin', html);
      else section.insertAdjacentHTML('beforeend', html);
    }
    if (updated.status === 'resolved' && (previousReason || panelOpen)) {
      const reasonInput = section.querySelector('[data-reopen-reason]');
      if (reasonInput && previousReason) reasonInput.value = previousReason;
      if (panelOpen || previousReason) {
        section.querySelector('[data-reopen-panel]')?.removeAttribute('hidden');
        section.querySelector('[data-reopen-toggle]')?.setAttribute('aria-expanded', 'true');
      }
    }
    bindResolutionControls(section);
    expectedResolutionId = Number(updated.resolutionUpdateId || 0);
    section.dataset.resolutionToken = String(expectedResolutionId);
  };

  const enableFor = (updated) => {
    hideRefresh();
    complaintResolutionHold.delete(complaintId);
    if (!updated || updated.status !== 'resolved') {
      setBusy(true);
      return;
    }
    setBusy(false);
  };

  async function refreshTimelineAndComments() {
    const timeline = box.querySelector('#complaintTimeline');
    if (timeline && timeline.isConnected) {
      const timelineToken = `${Date.now()}-${Math.random()}`;
      box.dataset.timelineToken = timelineToken;
      const updates = await loadComplaintTimeline(complaintId);
      if (!stillThisComplaint() || box.dataset.timelineToken !== timelineToken) return;
      if (timeline.isConnected) timeline.innerHTML = renderTimelineHtml(updates);
    }
    const commentRoot = box.querySelector('[data-complaint-comments]');
    if (!commentRoot || !commentRoot.isConnected || !stillThisComplaint()) return;
    const textarea = commentRoot.querySelector('textarea[name="body"]');
    const started = Date.now();
    while (textarea && textarea.isConnected && textarea.disabled && Date.now() - started < 20000) {
      await new Promise(resolve => setTimeout(resolve, 40));
    }
    if (!stillThisComplaint()) return;
    const list = commentRoot.querySelector('[data-comment-list]');
    if (list && list.isConnected) {
      const comments = await loadComplaintComments(complaintId);
      if (list.isConnected && (!textarea || textarea.isConnected) && stillThisComplaint()) {
        list.innerHTML = renderComplaintCommentsHtml(comments);
      }
    }
  }

  async function recoverResolutionState() {
    if (recovering) return;
    recovering = true;
    const button = ensureRefreshButton();
    button.disabled = true;
    if (stillThisComplaint() && statusEl) statusEl.textContent = 'Refreshing status…';
    try {
      await loadComplaints();
      renderComplaintsList();
    } catch {
      recovering = false;
      if (!stillThisComplaint()) return;
      button.disabled = false;
      if (statusEl && statusEl.isConnected) {
        statusEl.textContent = 'Status could not be refreshed. Use Refresh status to try again, or reload the page.';
      }
      return;
    }
    if (!stillThisComplaint()) {
      complaintResolutionHold.delete(complaintId);
      recovering = false;
      return;
    }
    const updated = COMPLAINTS.find(item => Number(item.id) === complaintId);
    if (!updated) {
      recovering = false;
      button.disabled = false;
      if (statusEl && statusEl.isConnected) {
        statusEl.textContent = 'Status could not be refreshed. Use Refresh status to try again, or reload the page.';
      }
      return;
    }
    paintResolution(updated);
    enableFor(updated);
    if (statusEl && statusEl.isConnected) statusEl.textContent = 'Status refreshed.';
    try {
      await refreshTimelineAndComments();
    } catch {
      if (stillThisComplaint() && statusEl && statusEl.isConnected) {
        statusEl.textContent = 'Status refreshed. Some details could not be loaded. Use Refresh status to try again, or reload the page.';
        const retry = section.querySelector('[data-refresh-resolution]');
        if (retry) {
          retry.hidden = false;
          retry.disabled = false;
        }
      }
    }
    recovering = false;
  }

  async function adoptFreshComplaint(message) {
    await loadComplaints();
    renderComplaintsList();
    if (!stillThisComplaint()) {
      complaintResolutionHold.delete(complaintId);
      return;
    }
    const updated = COMPLAINTS.find(item => Number(item.id) === complaintId);
    if (!updated) throw new Error('Complaint missing after refresh.');
    paintResolution(updated);
    enableFor(updated);
    if (statusEl && statusEl.isConnected) statusEl.textContent = message;
    try {
      await refreshTimelineAndComments();
    } catch {
      if (stillThisComplaint() && statusEl && statusEl.isConnected) {
        statusEl.textContent = `${message} Some details could not be loaded. Use Refresh status to try again.`;
        const retry = ensureRefreshButton();
        retry.hidden = false;
        retry.disabled = false;
      }
    }
  }

  const runResolution = (kind) => {
    sendResolution(kind).catch(() => {
      showRecovery('The result could not be verified. Refresh status before trying again.');
    });
  };

  function bindResolutionControls(root) {
    const confirmControl = root.querySelector('[data-confirm-resolution]');
    const toggleControl = root.querySelector('[data-reopen-toggle]');
    const submitControl = root.querySelector('[data-reopen-submit]');
    if (confirmControl && confirmControl.dataset.resolutionBound !== '1') {
      confirmControl.dataset.resolutionBound = '1';
      confirmControl.addEventListener('click', () => runResolution('confirm'));
    }
    if (toggleControl && toggleControl.dataset.resolutionBound !== '1') {
      toggleControl.dataset.resolutionBound = '1';
      toggleControl.addEventListener('click', () => {
        if (busy) return;
        const livePanel = section.querySelector('[data-reopen-panel]');
        const liveToggle = section.querySelector('[data-reopen-toggle]');
        const liveReason = section.querySelector('[data-reopen-reason]');
        if (!livePanel || !liveToggle) return;
        const willOpen = livePanel.hasAttribute('hidden');
        if (willOpen) {
          livePanel.removeAttribute('hidden');
          liveToggle.setAttribute('aria-expanded', 'true');
          liveReason?.focus();
        } else {
          livePanel.setAttribute('hidden', '');
          liveToggle.setAttribute('aria-expanded', 'false');
        }
      });
    }
    if (submitControl && submitControl.dataset.resolutionBound !== '1') {
      submitControl.dataset.resolutionBound = '1';
      submitControl.addEventListener('click', () => runResolution('reopen'));
    }
  }

  async function sendResolution(kind) {
    if (busy || !section.isConnected) return;
    const liveReason = section.querySelector('[data-reopen-reason]');
    let reason = '';
    if (kind === 'reopen') {
      const trimmed = String(liveReason?.value ?? '').trim();
      if (!trimmed || Array.from(trimmed).length > 2000) {
        if (statusEl) statusEl.textContent = 'Enter a reason of 1–2,000 characters. It is posted as a comment.';
        return;
      }
      reason = trimmed;
    }
    setBusy(true);
    if (statusEl) statusEl.textContent = kind === 'confirm' ? 'Saving confirmation…' : 'Reopening complaint…';

    let response;
    try {
      const payload = { complaint_id: complaintId, expected_resolution_id: expectedResolutionId };
      if (kind === 'reopen') payload.reason = reason;
      response = await fetch(`${API_BASE}/complaints/${kind === 'confirm' ? 'confirm_resolution' : 'reopen_complaint'}.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
    } catch {
      if (stillThisComplaint() && statusEl) statusEl.textContent = 'Could not reach the server.';
      setBusy(false);
      return;
    }

    let data = null;
    try {
      const raw = await response.text();
      if (raw.trim() !== '') data = JSON.parse(raw);
    } catch {
      showRecovery('The result could not be verified. Refresh status before trying again.');
      return;
    }
    if (!data || typeof data !== 'object') {
      showRecovery('The result could not be verified. Refresh status before trying again.');
      return;
    }
    if (response.status === 409) {
      try {
        await adoptFreshComplaint(`${data.message || 'This complaint changed.'} Review the current status before responding.`);
      } catch {
        showRecovery('This complaint changed. Refresh status to load the current resolution before responding again.');
      }
      return;
    }
    if (!response.ok || !data.success) {
      complaintResolutionHold.delete(complaintId);
      hideRefresh();
      if (stillThisComplaint() && statusEl) statusEl.textContent = data.message || 'Could not save the complaint.';
      setBusy(false);
      return;
    }

    const savedMessage = data.message || 'Saved.';
    if (stillThisComplaint() && statusEl) statusEl.textContent = savedMessage;
    try {
      await adoptFreshComplaint(savedMessage);
    } catch {
      showRecovery('Saved. Refresh status to load the latest details.');
    }
  }

  bindResolutionControls(section);
  if (options.locked) {
    showRecovery('Current status could not be loaded. Use Refresh status to try again, or reload the page.', false);
  }
}

function restoreComplaintDrafts(box, draft) {
  if (!draft) return;
  const reasonInput = box.querySelector('[data-reopen-reason]');
  if (reasonInput && draft.reopenReason) {
    reasonInput.value = draft.reopenReason;
    box.querySelector('[data-reopen-panel]')?.removeAttribute('hidden');
    box.querySelector('[data-reopen-toggle]')?.setAttribute('aria-expanded', 'true');
  }
  const commentInput = box.querySelector('[data-comment-form] textarea');
  if (commentInput && draft.commentDraft) commentInput.value = draft.commentDraft;
}

function complaintOwnSharingHtml(c) {
  const sharing = c.isPublic
    ? 'Sharing enabled for the resident community'
    : 'Private';
  const link = c.isDuplicate
    ? '<p class="complaint-meta">Linked to a related report; each complaint remains tracked separately.</p>'
    : '';
  return `<section data-complaint-own-sharing class="complaint-section complaint-section-block">
    <h4>Sharing</h4>
    <p class="complaint-strong">${escapeHtml(sharing)}</p>
    ${link}
  </section>`;
}

// Keep in sync with js/official.js renderComplaintExtraFieldsHtml.
function renderComplaintExtraFieldsHtml(fields) {
  if (!Array.isArray(fields)) return '';
  const rows = fields.filter(field => field
    && typeof field.label === 'string' && typeof field.value === 'string'
    && field.label !== '' && field.value !== '');
  if (!rows.length) return '';
  return `<section data-complaint-extra-details class="complaint-section complaint-section-block">
    <h4>Additional details</h4>
    <dl class="complaint-extra-list">${rows.map(field => `
      <div>
        <dt>${escapeHtml(field.label)}</dt>
        <dd>${escapeHtml(field.value)}</dd>
      </div>`).join('')}
    </dl>
  </section>`;
}

async function viewComplaint(id) {
  const seq = ++complaintDetailSeq;
  const openGeneration = complaintOpenGeneration;
  const numericId = Number(id);
  let lockActions = false;
  let stagedDraft = null;
  if (complaintResolutionHold.has(numericId)) {
    stagedDraft = complaintResolutionHold.get(numericId);
    try {
      await loadComplaints();
      if (seq !== complaintDetailSeq || openGeneration !== complaintOpenGeneration) return;
      renderComplaintsList();
      complaintResolutionHold.delete(numericId);
    } catch {
      if (seq !== complaintDetailSeq || openGeneration !== complaintOpenGeneration) return;
      lockActions = true;
    }
  }
  if (seq !== complaintDetailSeq || openGeneration !== complaintOpenGeneration) return;
  const c = COMPLAINTS.find(x => Number(x.id) === numericId);
  if (!c) return;
  const box = document.getElementById('requestModalBox');
  if (!box) return;
  box.dataset.complaintId = String(c.id);
  const timelineToken = `${Date.now()}-${Math.random()}`;
  const attachmentToken = `${Date.now()}-${Math.random()}`;
  box.dataset.timelineToken = timelineToken;
  box.dataset.attachmentToken = attachmentToken;
  const modal = document.getElementById('requestModal');

  const descriptionHtml = c.description
    ? escapeHtml(c.description).replace(/\r?\n/g, '<br>')
    : '<span class="complaint-muted">No description provided.</span>';
  const noteHtml = c.note
    ? escapeHtml(c.note)
    : '<span class="complaint-muted">No official note yet.</span>';
  const fact = (label, html) => `<div class="complaint-fact"><dt>${escapeHtml(label)}</dt><dd>${html}</dd></div>`;

  box.classList.add('complaint-detail-box');
  box.innerHTML = `
    <div class="complaint-detail-head">
      <div>
        <p class="complaint-detail-kicker">Complaint</p>
        <h3>${escapeHtml(c.ref)}</h3>
      </div>
      <button type="button" class="complaint-detail-x" onclick="closeRequestModal()" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="complaint-detail-scroll">
      <div class="complaint-detail-facts">
        ${fact('Category', escapeHtml(c.cat || 'Not provided'))}
        ${fact('Location', escapeHtml(c.loc || 'Not provided'))}
        ${fact('Date Filed', escapeHtml(c.date || 'Not provided'))}
        ${fact('Status', `<span class="status-badge status-${escapeHtml(c.status)}" data-complaint-status>${escapeHtml(c.status || 'Not provided')}</span>`)}
        ${fact('Severity', severityChip(c.severity))}
      </div>
      <div class="complaint-detail-copy">
        <section class="complaint-panel">
          <h4>Description</h4>
          <div class="complaint-panel-body">${descriptionHtml}</div>
        </section>
        <section class="complaint-panel complaint-panel-note">
          <h4>Official note</h4>
          <div class="complaint-panel-body">${noteHtml}</div>
        </section>
      </div>
      ${renderComplaintExtraFieldsHtml(c.extraFieldsDisplay)}
      ${complaintResidentResponseSectionHtml(c)}
      <div class="complaint-detail-split">
        <section class="complaint-section">
          <h4>Timeline</h4>
          <div id="complaintTimeline" class="complaint-scroll-well"></div>
        </section>
        ${complaintCommentsSectionHtml()}
      </div>
      <section class="complaint-section complaint-section-block">
        <h4>Photos</h4>
        <div id="complaintAttachments" class="complaint-photo-well"></div>
      </section>
    </div>
    <button type="button" class="complaint-detail-close" onclick="closeRequestModal()">Close</button>
  `;
  if (seq !== complaintDetailSeq || openGeneration !== complaintOpenGeneration) return;
  document.getElementById('requestModal').classList.add('show');
  initComplaintComments(box.querySelector('[data-complaint-comments]'), c.id);
  initResidentResolutionActions(box.querySelector('[data-resident-response]'), c, box, { locked: lockActions });
  restoreComplaintDrafts(box, stagedDraft);
  const sameResidentDetail = () => modal
    && modal.classList.contains('show')
    && Number(box.dataset.complaintId) === Number(c.id);
  loadComplaintTimeline(c.id)
    .then(updates => {
      if (!sameResidentDetail() || box.dataset.timelineToken !== timelineToken) return;
      const el = box.querySelector('#complaintTimeline');
      if (el) el.innerHTML = renderTimelineHtml(updates);
    })
    .catch(() => {
      if (!sameResidentDetail() || box.dataset.timelineToken !== timelineToken) return;
      const el = box.querySelector('#complaintTimeline');
      if (el) el.textContent = 'Timeline unavailable.';
    });
  loadComplaintAttachments(c.id)
    .then(atts => {
      if (!sameResidentDetail() || box.dataset.attachmentToken !== attachmentToken) return;
      const el = box.querySelector('#complaintAttachments');
      if (el) el.innerHTML = renderAttachmentsHtml(atts);
    })
    .catch(() => {
      if (!sameResidentDetail() || box.dataset.attachmentToken !== attachmentToken) return;
      const el = box.querySelector('#complaintAttachments');
      if (el) el.textContent = 'Photos unavailable.';
    });
}

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
        <div class="ci-badges">
          ${severityChip(c.severity)}
          <span class="status-badge status-${c.status}">${c.status}</span>
        </div>
      </div>
      <div class="ci-meta">
        <span><i class="fa-regular fa-calendar"></i> Filed: ${c.date}</span>
        <span><i class="fa-solid fa-comment-dots"></i> ${escapeHtml(c.note)}</span>
      </div>
      <div class="ci-progress">
        <div class="progress-track"><div class="progress-fill" style="width:${c.progress}%"></div></div>
        <span class="progress-label">${c.progress}% resolved</span>
      </div>
      <div class="ci-actions">
        <button type="button" class="ci-view-btn" onclick="viewComplaint(${c.id})">View Details</button>
      </div>
    </div>
  `).join('');
}

function syncComplaintExtraFields(formEl) {
  if (!formEl) return;
  const category = formEl.querySelector('#reqCategory')?.value || '';
  const locked = !!getRetryState(formEl);
  formEl.querySelectorAll('[data-complaint-extra-category]').forEach(group => {
    const active = group.dataset.complaintExtraCategory === category;
    group.hidden = !active;
    group.disabled = locked || !active;
    group.querySelectorAll('[data-complaint-extra-key]').forEach(control => {
      if (!locked && !active) control.value = '';
      control.disabled = locked || !active;
    });
  });
}

function collectComplaintExtraFields(formEl) {
  const values = {};
  if (!formEl || getRetryState(formEl)) return values;
  const category = formEl.querySelector('#reqCategory')?.value || '';
  formEl.querySelectorAll('[data-complaint-extra-category]').forEach(group => {
    if (group.hidden || group.disabled || group.dataset.complaintExtraCategory !== category) return;
    group.querySelectorAll('[data-complaint-extra-key]').forEach(control => {
      if (!control.disabled && control.value !== '') values[control.dataset.complaintExtraKey] = control.value;
    });
  });
  return values;
}

function initComplaintExtraFields() {
  const formEl = document.getElementById('complaintFormEl');
  if (!formEl) return;
  formEl.querySelector('#reqCategory')?.addEventListener('change', () => syncComplaintExtraFields(formEl));
  formEl.addEventListener('reset', () => {
    queueMicrotask(() => syncComplaintExtraFields(formEl));
  });
  syncComplaintExtraFields(formEl);
}

function setFormModalOpen(form, open) {
  if (!form) return;
  form.classList.toggle('show', open);
  form.style.display = '';
  form.setAttribute('aria-hidden', open ? 'false' : 'true');
}

function initComplaintFormToggle() {
  const openBtn  = document.getElementById('newComplaintBtn');
  const closeBtn = document.getElementById('closeComplaintForm');
  const cancelBtn= document.getElementById('cancelComplaintForm');
  const form     = document.getElementById('newComplaintForm');
  const formEl   = document.getElementById('complaintFormEl');

  const requestClose = () => {
    if (getRetryState(formEl)) { skipPendingComplaintUploads(formEl); return; }
    setFormModalOpen(form, false);
    openBtn?.focus();
  };

  openBtn?.addEventListener('click', () => {
    setFormModalOpen(form, true);
    formEl?.querySelector('select, input, textarea')?.focus();
  });
  closeBtn?.addEventListener('click', requestClose);
  cancelBtn?.addEventListener('click', requestClose);
  formEl?.addEventListener('submit', e => {
    e.preventDefault();
    submitComplaint(formEl, form);
  });
  document.getElementById('complaintRetryBtn')?.addEventListener('click', () => retryPendingComplaintUploads(formEl));
  document.getElementById('complaintSkipBtn')?.addEventListener('click', () => skipPendingComplaintUploads(formEl));
}

function initComplaintPhotoInput() {
  const input = document.getElementById('reqPhotos');
  const preview = document.getElementById('reqPhotosPreview');
  const error = document.getElementById('reqPhotosError');
  if (!input || !preview) return;

  const MAX_FILES = 3;
  const MAX_BYTES = 5 * 1024 * 1024;
  const ALLOWED = ['image/jpeg', 'image/png'];

  const showError = (msg) => {
    if (!error) return;
    error.textContent = msg;
    error.style.display = msg ? 'block' : 'none';
  };

  input.addEventListener('change', () => {
    showError('');
    preview.innerHTML = '';
    const files = Array.from(input.files || []);
    if (files.length > MAX_FILES) {
      showError(`Maximum ${MAX_FILES} photos per complaint.`);
      input.value = '';
      return;
    }
    for (const file of files) {
      if (!ALLOWED.includes(file.type)) {
        showError(`Unsupported file: ${file.name}. Use JPEG or PNG.`);
        input.value = '';
        return;
      }
      if (file.size > MAX_BYTES) {
        showError(`Photo too large: ${file.name}. Max 5MB.`);
        input.value = '';
        return;
      }
    }
    files.forEach(file => {
      const url = URL.createObjectURL(file);
      const img = document.createElement('img');
      img.src = url;
      img.alt = file.name;
      img.style.cssText = 'width:64px;height:64px;object-fit:cover;border-radius:6px;border:1px solid var(--border);';
      img.addEventListener('load', () => URL.revokeObjectURL(url));
      preview.appendChild(img);
    });
  });

  // Reset preview whenever the form is reset (close form, successful submit).
  const formEl = document.getElementById('complaintFormEl');
  formEl?.addEventListener('reset', () => {
    preview.innerHTML = '';
    showError('');
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
    id: Number(row.id),
    ref: row.reference_no,
    cat: row.category,
    severity: (row.severity || 'medium').toLowerCase(),
    loc: row.location_text,
    description: row.description || '',
    date: row.date_filed,
    status: row.status,
    progress: statusToProgress[row.status] ?? 0,
    note: row.official_note || 'Received. Awaiting assignment.',
    residentConfirmation: row.resident_confirmation || null,
    confirmedAt: row.confirmed_at || null,
    reopenedAt: row.reopened_at || null,
    resolutionUpdateId: Number(row.resolution_update_id || 0),
    assignedOfficialId: complaintIdOrNull(row.assigned_official_id),
    assignedOfficialName: row.assigned_official_name == null || String(row.assigned_official_name).trim() === ''
      ? null
      : String(row.assigned_official_name).trim(),
    assignedOfficialStatus: row.assigned_official_status === 'active' || row.assigned_official_status === 'inactive'
      ? row.assigned_official_status
      : null,
    slaDueAt: row.sla_due_at ? String(row.sla_due_at) : null,
    escalatedAt: row.escalated_at ? String(row.escalated_at) : null,
    isOverdue: Number(row.is_overdue) === 1,
    extraFields: row.extra_fields && typeof row.extra_fields === 'object' && !Array.isArray(row.extra_fields)
      ? row.extra_fields : {},
    extraFieldsDisplay: Array.isArray(row.extra_fields_display) ? row.extra_fields_display : [],
    isPublic: row.is_public === true || row.is_public === 1,
    isDuplicate: row.is_duplicate === true || row.is_duplicate === 1,
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
  const wbPending = document.getElementById('wbPendingRequests');
  const wbActive  = document.getElementById('wbActiveComplaints');
  if (wbPending) wbPending.textContent = String(
    REQUESTS.filter(r => r.status === 'pending').length
  );
  if (wbActive) wbActive.textContent = String(
    COMPLAINTS.filter(c => c.status !== 'resolved').length
  );
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
    setFormModalOpen(form, false);
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
  const severity = document.getElementById('reqSeverity')?.value || 'medium';

  if (!category || !location || !description) {
    showToast('Missing Fields', 'Please complete all complaint details.');
    return;
  }

  const fileInput = document.getElementById('reqPhotos');
  const files = fileInput ? Array.from(fileInput.files || []) : [];
  const submitBtn = formEl.querySelector('button[type="submit"]');
  const originalBtnText = submitBtn ? submitBtn.textContent : '';
  if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Submitting...'; }

  let complaintId = 0;
  let created;
  try {
    created = await fetchJson(`${API_BASE}/complaints/create.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ category, location_text: location, description, severity, extra_fields: collectComplaintExtraFields(formEl) })
    });
    complaintId = Number(created.id) || 0;
  } catch (err) {
    if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = originalBtnText; }
    showToast('Submit Failed', err.message);
    return;
  }

  // Complaint is now committed. Attachments are best-effort; failures route to retry mode.
  let uploaded = 0;
  const failedFiles = [];
  for (let i = 0; i < files.length; i++) {
    if (submitBtn) submitBtn.textContent = `Uploading ${i + 1}/${files.length}...`;
    try {
      const body = new FormData();
      body.append('complaint_id', String(complaintId));
      body.append('photo', files[i]);
      const res = await fetch(`${API_BASE}/complaints/upload_attachment.php`, { method: 'POST', body });
      const data = await res.json().catch(() => null);
      if (!res.ok || !data || !data.success) throw new Error((data && data.message) || `HTTP ${res.status}`);
      uploaded++;
    } catch (err) {
      failedFiles.push(files[i]);
    }
  }

  if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = originalBtnText; }
  await loadComplaints();
  await refreshResidentView();

  if (failedFiles.length === 0) {
    setFormModalOpen(form, false);
    formEl.reset();
    formEl.querySelectorAll('input[type="file"]').forEach(el => { el.value = ''; });
    if (files.length === 0) {
      showToast('Complaint Submitted!', 'Your complaint has been submitted for review.');
    } else {
      showToast('Complaint Submitted!', `${uploaded} photo${uploaded === 1 ? '' : 's'} attached.`);
    }
  } else {
    enterComplaintRetryMode(formEl, form, complaintId, String(created.reference_no || ''), failedFiles);
  }
}

function enterComplaintRetryMode(formEl, form, complaintId, referenceNo, pendingFiles) {
  // Snapshot which controls were enabled before we lock everything, so teardown
  // restores the original state rather than blanket-enabling every node.
  const originallyEnabled = new Set(
    Array.from(formEl.querySelectorAll('input, select, textarea'))
      .filter(el => !el.disabled)
  );
  setRetryState(formEl, {
    formEl,
    form,
    complaintId,
    referenceNo,
    pendingFiles,
    originallyEnabled,
  });
  // Complaint is filed — lock every field so resubmission can't duplicate it.
  formEl.querySelectorAll('input, select, textarea').forEach(el => { el.disabled = true; });
  const actions = formEl.querySelector('.form-actions');
  if (actions) actions.style.display = 'none';
  const banner = document.getElementById('complaintRetryBanner');
  const message = document.getElementById('complaintRetryMessage');
  const retryBtn = document.getElementById('complaintRetryBtn');
  if (!banner || !message || !retryBtn) return;
  message.textContent = `Complaint ${referenceNo} was filed, but ${pendingFiles.length} photo${pendingFiles.length === 1 ? '' : 's'} failed to upload.`;
  retryBtn.textContent = `Retry Upload (${pendingFiles.length})`;
  retryBtn.disabled = false;
  banner.style.display = '';
}

async function retryPendingComplaintUploads(formEl) {
  const state = getRetryState(formEl);
  if (!state) return;
  const { complaintId, referenceNo } = state;
  const pendingFiles = state.pendingFiles;
  const retryBtn = document.getElementById('complaintRetryBtn');
  if (!retryBtn) return;
  retryBtn.disabled = true;
  const stillFailed = [];
  for (let i = 0; i < pendingFiles.length; i++) {
    retryBtn.textContent = `Uploading ${i + 1}/${pendingFiles.length}...`;
    try {
      const body = new FormData();
      body.append('complaint_id', String(complaintId));
      body.append('photo', pendingFiles[i]);
      const res = await fetch(`${API_BASE}/complaints/upload_attachment.php`, { method: 'POST', body });
      const data = await res.json().catch(() => null);
      if (!res.ok || !data || !data.success) throw new Error((data && data.message) || `HTTP ${res.status}`);
    } catch (err) {
      stillFailed.push(pendingFiles[i]);
    }
  }
  if (stillFailed.length === 0) {
    closeComplaintRetryMode(formEl);
    await loadComplaints();
    await refreshResidentView();
    showToast('Photos Uploaded', `All remaining photos attached to ${referenceNo}.`);
  } else {
    state.pendingFiles = stillFailed;
    retryBtn.disabled = false;
    retryBtn.textContent = `Retry Upload (${stillFailed.length})`;
    const message = document.getElementById('complaintRetryMessage');
    if (message) {
      message.textContent = `${stillFailed.length} photo${stillFailed.length === 1 ? '' : 's'} still failing to upload.`;
    }
  }
}

function closeComplaintRetryMode(formEl) {
  const banner = document.getElementById('complaintRetryBanner');
  if (banner) banner.style.display = 'none';
  const state = getRetryState(formEl);
  if (!state) return;
  // Restore the exact enabled/disabled state from before the lock. Any
  // conditionally-disabled field (present or future) stays that way.
  state.formEl.querySelectorAll('input, select, textarea').forEach(el => {
    el.disabled = !state.originallyEnabled.has(el);
  });
  const actions = state.formEl.querySelector('.form-actions');
  if (actions) actions.style.display = '';
  setFormModalOpen(state.form, false);
  state.formEl.reset();
  // form.reset() is unreliable for FileList across engines — force-clear.
  state.formEl.querySelectorAll('input[type="file"]').forEach(el => { el.value = ''; });
  clearRetryState(formEl);
  syncComplaintExtraFields(formEl);
}

function skipPendingComplaintUploads(formEl) {
  const state = getRetryState(formEl);
  if (!state) return;
  const referenceNo = state.referenceNo;
  const remaining = state.pendingFiles.length;
  closeComplaintRetryMode(formEl);
  showToast('Complaint Filed', `${referenceNo} was filed without ${remaining} photo${remaining === 1 ? '' : 's'}.`);
}

// ── TOAST ─────────────────────────────────
let toastTimeout;
async function initSmsPreferences() {
  const form = document.getElementById('smsPrefForm');
  if (!form) return;
  try {
    const data = await fetchJson(`${API_BASE}/residents/sms_preferences.php`);
    form.complaints_enabled.checked = Number(data.complaints_enabled) === 1;
    form.requests_enabled.checked   = Number(data.requests_enabled)   === 1;
    form.announcements_enabled.checked = Number(data.announcements_enabled) === 1;
  } catch { /* non-fatal */ }
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      await fetchJson(`${API_BASE}/residents/sms_preferences.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          complaints_enabled: form.complaints_enabled.checked ? 1 : 0,
          requests_enabled: form.requests_enabled.checked ? 1 : 0,
          announcements_enabled: form.announcements_enabled.checked ? 1 : 0,
        }),
      });
      showToast('SMS Preferences', 'Your notification preferences were saved.');
    } catch (err) {
      showToast('Save Failed', err.message);
    }
  });
}

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
  initComplaintExtraFields();
  initComplaintPhotoInput();
  initNotifDrawer();
  initMarkAllRead();
  initProfilePhotoUpload();
  initSmsPreferences();
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
