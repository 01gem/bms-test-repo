'use strict';

// Regression coverage for escaped complaint rendering and the editing workspace.

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..', '..');
const probe = '<em data-review-probe>text</em>';
const note = 'He said "no" ' + probe;
let passed = 0;
let failed = 0;

function check(name, ok) {
  if (ok) {
    passed += 1;
    console.log('PASS ' + name);
  } else {
    failed += 1;
    console.log('FAIL ' + name);
  }
}

function element(tag) {
  const el = {
    tag: tag || 'div',
    attrs: new Map(),
    children: [],
    parent: null,
    dataset: {},
    classList: null,
    style: {},
    textContent: '',
    value: '',
    disabled: false,
    hidden: false,
    checked: false,
    isConnected: true,
    listeners: {},
    innerHTML: '',
  };
  el.classList = {
    classes: new Set(),
    add(...names) { names.forEach(name => this.classes.add(name)); },
    remove(...names) { names.forEach(name => this.classes.delete(name)); },
    contains(name) { return this.classes.has(name); },
  };
  el.setAttribute = (name, value) => {
    el.attrs.set(name, String(value));
    if (name === 'class') {
      el.classList.classes = new Set(String(value).split(/\s+/).filter(Boolean));
    }
    if (name.startsWith('data-')) el.dataset[name.slice(5)] = String(value);
  };
  el.getAttribute = (name) => (el.attrs.has(name) ? el.attrs.get(name) : null);
  el.removeAttribute = name => el.attrs.delete(name);
  el.focus = () => {};
  el.addEventListener = (type, fn) => {
    (el.listeners[type] || (el.listeners[type] = [])).push(fn);
  };
  el.querySelector = (selector) => find(el, selector);
  el.querySelectorAll = (selector) => findAll(el, selector);
  el.closest = (selector) => {
    let current = el;
    while (current) {
      if (matches(current, selector)) return current;
      current = current.parent;
    }
    return null;
  };
  Object.defineProperty(el, 'innerHTML', {
    configurable: true,
    get() { return el._html || ''; },
    set(value) {
      el._html = String(value);
      el.children = parseChildren(String(value), el);
    },
  });
  el.elements = {
    namedItem(name) {
      return find(el, `[name="${name}"]`) || find(el, 'textarea');
    },
  };
  return el;
}

function matches(el, selector) {
  if (!selector) return false;
  if (selector.includes(',')) return selector.split(',').some(part => matches(el, part.trim()));
  if (selector.startsWith('[') && selector.endsWith(']')) {
    const body = selector.slice(1, -1);
    const eq = body.indexOf('=');
    if (eq === -1) return el.attrs.has(body);
    const name = body.slice(0, eq);
    const raw = body.slice(eq + 1).replace(/^"|"$/g, '');
    return el.attrs.get(name) === raw;
  }
  if (selector.startsWith('.')) return el.classList.contains(selector.slice(1));
  if (selector.startsWith('#')) return el.attrs.get('id') === selector.slice(1);
  const tagMatch = selector.match(/^([a-z0-9]+)/i);
  if (tagMatch && el.tag !== tagMatch[1]) return false;
  const attr = selector.match(/\[([^\]]+)\]/);
  if (attr) return matches(el, `[${attr[1]}]`);
  return el.tag === selector;
}

function find(el, selector) {
  for (const child of el.children) {
    if (matches(child, selector)) return child;
    const nested = find(child, selector);
    if (nested) return nested;
  }
  return null;
}

function findAll(el, selector) {
  const out = [];
  (function walk(node) {
    for (const child of node.children) {
      if (matches(child, selector)) out.push(child);
      walk(child);
    }
  })(el);
  return out;
}

function parseChildren(html, parent) {
  const root = element('fragment');
  root.parent = parent;
  const stack = [root];
  const re = /<\/?([a-zA-Z0-9:-]+)([^>]*)\/?>/g;
  const voidTags = new Set(['input', 'img', 'br', 'hr', 'meta', 'link', 'area', 'base', 'col', 'embed', 'source', 'track', 'wbr']);
  let match;
  while ((match = re.exec(html))) {
    const closing = match[0].startsWith('</');
    const tag = match[1].toLowerCase();
    const rawAttrs = match[2] || '';
    const selfClose = match[0].endsWith('/>') || voidTags.has(tag);
    if (closing) {
      if (stack.length > 1) stack.pop();
      continue;
    }
    const node = element(tag);
    node.parent = stack[stack.length - 1];
    const attrRe = /([:@a-zA-Z0-9_-]+)(?:\s*=\s*("([^"]*)"|'([^']*)'|([^\s"'>]+)))?/g;
    let attr;
    while ((attr = attrRe.exec(rawAttrs))) {
      const value = attr[3] ?? attr[4] ?? attr[5] ?? '';
      node.setAttribute(attr[1], value);
    }
    if (tag === 'input' || tag === 'textarea') node.value = node.getAttribute('value') || '';
    stack[stack.length - 1].children.push(node);
    if (!selfClose) stack.push(node);
  }
  return root.children;
}

function makeDocument() {
  const nodes = new Map();
  return {
    body: element('body'),
    activeElement: element('button'),
    addEventListener() {},
    removeEventListener() {},
    getElementById(id) {
      if (!nodes.has(id)) nodes.set(id, element('div'));
      const node = nodes.get(id);
      node.setAttribute('id', id);
      return node;
    },
  };
}

function loadScript(file, exportName, extras) {
  const filename = path.join(root, 'js', file);
  const source = fs.readFileSync(filename, 'utf8') + '\n' + extras;
  const document = makeDocument();
  const context = {
    console,
    document,
    window: null,
    fetch: async () => ({ ok: true, status: 200, text: async () => '{"officials":[],"complaints":[]}' }),
    setTimeout,
    clearTimeout,
    requestAnimationFrame(fn) { return fn(); },
    localStorage: { getItem() { return null; }, setItem() {} },
    sessionStorage: { getItem() { return null; }, setItem() {} },
    addEventListener() {},
    removeEventListener() {},
    confirm() { return true; },
  };
  context.window = context;
  context.globalThis = context;
  vm.createContext(context);
  vm.runInContext(source, context, { filename });
  return { context, api: context[exportName], document };
}

function noProbe(html, label) {
  check(label + ' has no probe element', !html.includes(probe) && html.includes('&lt;em data-review-probe&gt;'));
  check(label + ' is not double escaped', !html.includes('&amp;amp;') && html.includes('&amp;'));
}

const officialExport = `
globalThis.__official = {
  renderDashOpenComplaints,
  renderComplaintsAdmin,
  viewComplaint,
  closeModal,
  updateComplaint,
  complaintDetailDirty,
  complaintManagementValues,
  complaintCommentsSectionHtml,
  initComplaintComments,
  state() { return complaintDetailState; },
  configure(callback) {
    fetchJson = callback;
    loadAuditLog = async () => {};
    refreshDashboard = async () => {};
    loadComplaints = async () => {};
    refreshComplaintTimeline = async () => {};
    syncComplaintWorkspace = () => {};
    refreshComplaintsAdmin = () => {};
  },
  setRows(rows) { COMPLAINTS_ADMIN = rows; },
};
globalThis.__calls = { assignment: 0, sharing: 0 };
const __realAssign = initComplaintAssignment;
const __realShare = initComplaintSharing;
initComplaintAssignment = function(box, complaint, seq) {
  globalThis.__calls.assignment += 1;
  return __realAssign(box, complaint, seq);
};
initComplaintSharing = function(box, complaint, seq) {
  globalThis.__calls.sharing += 1;
  return __realShare(box, complaint, seq);
};
`;

const residentExport = `
globalThis.__resident = {
  renderRecentComplaints,
  renderComplaintsList,
  viewComplaint,
  setRows(rows) { COMPLAINTS = rows; },
};
`;

const official = loadScript('official.js', '__official', officialExport);
const resident = loadScript('resident.js', '__resident', residentExport);
const row = {
  id: 7,
  ref: '#<em data-review-probe>text</em>',
  filer: 'Ada & ' + probe,
  cat: 'Noise & ' + probe,
  loc: 'Purok "1" ' + probe,
  date: 'Oct 24, 2026 ' + probe,
  status: 'open',
  severity: 'low',
  note,
  description: probe + ' & more',
  progress: 50,
  residentConfirmation: null,
  isPublic: false,
  isDuplicate: true,
  duplicateOfId: 99999,
  duplicateReferenceNo: 'PARENT-REF-XYZ',
  duplicateChildrenCount: 0,
  assignedOfficialId: null,
  slaDueAt: null,
  escalatedAt: null,
  isOverdue: false,
  extraFieldsDisplay: [],
};

official.api.setRows([row]);
official.document.getElementById('dashOpenComplaints');
official.api.renderDashOpenComplaints();
const dash = official.document.getElementById('dashOpenComplaints').innerHTML;
noProbe(dash, 'official dashboard');
check('official dashboard keeps the open status token', dash.includes('status-open') && !dash.includes('onclick='));

const hostile = Object.assign({}, row, { status: 'open" onclick="alert(1)' });
official.document.getElementById('complaintsAdminList');
official.api.renderComplaintsAdmin([hostile]);
const list = official.document.getElementById('complaintsAdminList').innerHTML;
noProbe(list, 'official list');
check('official list status class is allowlisted', list.includes('class="status-badge status-unknown"') && !list.includes('status-open&quot;'));
check('official note renders as escaped text', list.includes('He said &quot;no&quot; &lt;em data-review-probe&gt;text&lt;/em&gt;'));
check('official card has one details action and no mutation fields', (list.match(/<button/g) || []).length === 1 && list.includes('View details') && !list.includes('<select') && !list.includes('updateComplaint('));
check('official note creates no extra attribute', !/value="[^"]*"\s+data-review-probe=/.test(list));
check('official article id is escaped', list.includes('id="comp-&lt;em data-review-probe&gt;text&lt;/em&gt;"'));

row.extraFieldsDisplay = [{ label: 'Landmark', value: 'Gate ' + probe }];
row.residentConfirmation = 'reopened';
row.reopenedAt = 'Oct 24, 2026 ' + probe;
official.api.setRows([row]);

function openOfficial(isAdmin) {
  official.context.IS_OFFICIAL_ADMIN = isAdmin;
  official.context.window.IS_OFFICIAL_ADMIN = isAdmin;
  official.api.viewComplaint(7);
  return official.document.getElementById('actionModalBox');
}

function absent(box, selector) {
  return box.querySelector(selector) === null && !box.innerHTML.includes(selector.slice(1, -1));
}

function assertOfficialCompact(box, label) {
  const html = box.innerHTML;
  check(label + ' omits assignment section and save',
    absent(box, '[data-complaint-triage]')
    && absent(box, '[data-assignment-controls]')
    && absent(box, '[data-save-assignment]')
    && !html.includes('Save assignment')
    && !html.includes('>Assignment</h4>'));
  check(label + ' omits sharing section and save',
    absent(box, '[data-complaint-sharing]')
    && absent(box, '[data-sharing-controls]')
    && absent(box, '[data-save-sharing]')
    && absent(box, '[data-save-link]')
    && !html.includes('Save sharing')
    && !html.includes('Sharing and related reports'));
  check(label + ' omits related-report navigation',
    absent(box, '[data-open-related-report]')
    && !html.includes('View related report'));
  check(label + ' leaves assignment and sharing initializers idle',
    official.context.__calls.assignment === 0 && official.context.__calls.sharing === 0);
  noProbe(html, label);
  check(label + ' shows facts, description, and official note',
    html.includes('Filed by <strong>')
    && html.includes('Ada &amp; ')
    && html.includes('Noise &amp; ')
    && html.includes('Purok &quot;1&quot;')
    && html.includes('class="status-badge status-open"')
    && html.includes('data-complaint-status')
    && html.includes('>Low</span>')
    && html.includes('>Description</h4>')
    && html.includes('&amp; more')
    && html.includes('>Official note</h4>')
    && html.includes('He said &quot;no&quot;'));
  check(label + ' shows additional details and resident response',
    html.includes('data-complaint-extra-details')
    && html.includes('>Additional details</h4>')
    && html.includes('>Landmark</dt>')
    && html.includes('Gate &lt;em data-review-probe&gt;text&lt;/em&gt;')
    && html.includes('data-resident-response')
    && html.includes('Reopened by resident')
    && html.includes('data-response-times'));
  check(label + ' shows timeline, comments, and photos',
    html.includes('>Timeline</h4>')
    && html.includes('id="complaintTimeline"')
    && html.includes('data-complaint-comments')
    && html.includes('data-comment-form')
    && html.includes('>Photos <span')
    && html.includes('id="complaintAttachments"'));
  check(label + ' has three accessible tabs and management form',
    box.querySelectorAll('[role="tab"]').length === 3
    && box.getAttribute('role') === 'dialog'
    && html.includes('aria-controls="complaintPanelTimeline"')
    && html.includes('name="official_note"')
    && html.includes('Save updates'));
}

assertOfficialCompact(openOfficial(false), 'staff open');
assertOfficialCompact(openOfficial(false), 'staff repeat');
assertOfficialCompact(openOfficial(true), 'admin open');
assertOfficialCompact(openOfficial(true), 'admin repeat');
check('modal is shown before use', official.document.getElementById('actionModal').classList.contains('show'));

resident.api.setRows([Object.assign({}, row, { status: 'open" onclick="alert(1)' })]);
resident.document.getElementById('recentComplaintsList');
resident.api.renderRecentComplaints();
noProbe(resident.document.getElementById('recentComplaintsList').innerHTML, 'resident dashboard');
resident.document.getElementById('complaintsList');
resident.api.renderComplaintsList();
const residentList = resident.document.getElementById('complaintsList').innerHTML;
noProbe(residentList, 'resident list');
check('resident list status class is allowlisted', residentList.includes('class="status-badge status-unknown"') && !residentList.includes('status-open&quot;'));
check('resident progress is numeric', residentList.includes('width:50%') && !residentList.includes('width:50% resolved'));
resident.api.viewComplaint(7);
const residentDetail = resident.document.getElementById('requestModalBox').innerHTML;
check('resident detail has triage and sharing summaries', residentDetail.includes('data-complaint-triage') && residentDetail.includes('data-complaint-own-sharing'));
check('resident detail hides parent identity', !residentDetail.includes('PARENT-REF-XYZ') && !residentDetail.includes('99999'));
check('resident detail has no mutation controls', !residentDetail.includes('data-save-sharing') && !residentDetail.includes('data-save-assignment') && !residentDetail.includes('data-open-related-report'));

async function behaviorChecks() {
  // Let the initial asynchronous detail reads settle before stubbing writes.
  await new Promise(resolve => setImmediate(resolve));
  const state = official.api.state();
  const status = state.form.elements.namedItem('status');
  const severity = state.form.elements.namedItem('severity');
  const noteField = state.form.elements.namedItem('official_note');
  status.value = 'open';
  severity.value = 'low';
  state.saved = JSON.stringify(official.api.complaintManagementValues(state.form));
  noteField.value = 'Draft note\nSecond line';
  const tabs = state.box.querySelectorAll('[role="tab"]');
  tabs[1].listeners.click[0]();
  tabs[2].listeners.click[0]();
  check('switching tabs retains unsaved note', noteField.value === 'Draft note\nSecond line' && official.api.complaintDetailDirty());
  check('tab selection exposes only the selected panel', state.box.querySelector('#complaintPanelOverview').hidden && state.box.querySelector('#complaintPanelTimeline').hidden && !state.box.querySelector('#complaintPanelComments').hidden);

  let requests = [];
  let finish;
  official.api.configure(async (url, options) => {
    requests.push({ url, body: JSON.parse(options.body) });
    return new Promise(resolve => { finish = resolve; });
  });
  status.value = 'resolved';
  severity.value = 'critical';
  const pending = official.api.updateComplaint(7);
  await official.api.updateComplaint(7);
  check('repeated save cannot submit a duplicate request', requests.length === 1 && state.busy && state.form.querySelector('button').disabled);
  official.api.closeModal();
  check('close is blocked during a save', official.api.state() === state && state.feedback.textContent.includes('wait'));
  check('resolution uses the existing endpoint with note and no assignment', requests[0].url.endsWith('/notify_resolved.php') && requests[0].body.official_note === noteField.value && !('assigned_official_id' in requests[0].body));
  finish({ success: true, changed: true, sms_status: 'skipped' });
  await pending;
  check('successful save retains modal and resets dirty state', official.api.state() === state && !official.api.complaintDetailDirty() && state.feedback.textContent === 'Updates saved.' && !state.busy);
  check('successful save re-enables controls', !status.disabled && !severity.disabled && !noteField.disabled && !state.form.querySelector('button').disabled);

  official.api.configure(async () => { throw new Error('HTTP 500'); });
  noteField.value = 'Keep this after a failure';
  await official.api.updateComplaint(7);
  check('failed save retains entered values and shows no false success', noteField.value === 'Keep this after a failure' && state.feedback.dataset.kind === 'error' && state.feedback.textContent.includes('HTTP 500') && official.api.complaintDetailDirty());

  official.api.configure(async () => ({ success: true, changed: false }));
  noteField.value = 'Draft note\nSecond line';
  await official.api.updateComplaint(7);
  check('unchanged save reports no changes', state.feedback.textContent === 'No changes to save.');

  let calls = 0;
  official.api.configure(async () => { calls++; return { success: true }; });
  noteField.value = 'x'.repeat(256);
  await official.api.updateComplaint(7);
  check('overlong notes are rejected before sending', calls === 0 && state.feedback.textContent.includes('255'));
  noteField.value = 'Unsaved';
  official.api.closeModal();
  check('unsaved close exposes a discard choice', !state.box.querySelector('[data-unsaved-prompt]').hidden);
  state.box.querySelector('[data-keep-editing]').listeners.click[0]();
  check('cancel discard keeps the draft and dialog', official.api.state() === state && noteField.value === 'Unsaved');
  state.box.querySelector('[data-discard-edits]').listeners.click[0]();
  check('confirmed discard releases modal state', official.api.state() === null && !official.document.getElementById('actionModal').classList.contains('show'));

  let commentWrites = 0;
  let commentFinish;
  let comments = [];
  official.api.configure(async (url) => {
    if (url.includes('list_comments')) return { success: true, comments };
    commentWrites++;
    return new Promise(resolve => { commentFinish = resolve; });
  });
  const commentRoot = element('div');
  commentRoot.innerHTML = official.api.complaintCommentsSectionHtml();
  await official.api.initComplaintComments(commentRoot, 7);
  const commentForm = commentRoot.querySelector('[data-comment-form]');
  const commentInput = commentForm.elements.namedItem('body');
  commentInput.value = 'One comment only';
  const commentPending = commentForm.listeners.submit[0]({ preventDefault() {} });
  await commentForm.listeners.submit[0]({ preventDefault() {} });
  check('repeated comment submit posts once', commentWrites === 1 && commentForm.querySelector('button').disabled);
  comments = [{ body: 'One comment only', actor_name: 'Tester', actor_type: 'official' }];
  commentFinish({ success: true });
  await commentPending;
  check('comment success clears draft and updates count', commentInput.value === '' && commentRoot.querySelector('[data-comment-count]').textContent === '(1)');
  commentInput.value = 'x'.repeat(2001);
  await commentForm.listeners.submit[0]({ preventDefault() {} });
  check('overlong comments never submit', commentWrites === 1 && commentRoot.querySelector('[data-comment-status]').textContent.includes('2,000'));
  official.api.configure(async () => { throw new Error('Connection failed'); });
  commentInput.value = 'Keep failed comment';
  await commentForm.listeners.submit[0]({ preventDefault() {} });
  check('failed comment keeps draft and enables retry', commentInput.value === 'Keep failed comment' && !commentForm.querySelector('button').disabled && commentRoot.querySelector('[data-comment-status]').textContent.includes('Connection failed'));
}

behaviorChecks().then(() => {
  console.log(`RENDER + BEHAVIOR ${passed} passed, ${failed} failed`);
  process.exit(failed === 0 ? 0 : 1);
}).catch(error => { console.error(error); process.exit(1); });
