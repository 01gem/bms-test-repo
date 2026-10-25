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
    if (name === 'hidden') el.hidden = true;
    if (name === 'class') {
      el.classList.classes = new Set(String(value).split(/\s+/).filter(Boolean));
    }
    if (name.startsWith('data-')) el.dataset[name.slice(5)] = String(value);
  };
  el.getAttribute = (name) => (el.attrs.has(name) ? el.attrs.get(name) : null);
  el.hasAttribute = name => el.attrs.has(name);
  el.removeAttribute = name => { el.attrs.delete(name); };
  Object.defineProperty(el, 'hidden', {
    get() { return el.attrs.has('hidden'); },
    set(value) { if (value) el.attrs.set('hidden', ''); else el.attrs.delete('hidden'); },
  });
  Object.defineProperty(el, 'parentElement', { get() { return el.parent; } });
  el.contains = node => { while (node) { if (node === el) return true; node = node.parent; } return false; };
  el.getClientRects = () => el.isConnected && !el.closest('[hidden]') ? [{}] : [];
  el.focus = () => {
    if (!el.disabled && el.ownerDocument) el.ownerDocument.activeElement = el;
  };
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
      el.children.forEach(detach);
      el._html = String(value);
      el.children = parseChildren(String(value), el);
    },
  });
  el.remove = () => {
    const siblings = el.parent?.children;
    if (siblings) { const index = siblings.indexOf(el); if (index >= 0) siblings.splice(index, 1); }
    detach(el);
  };
  Object.defineProperty(el, 'outerHTML', { set(value) {
    const siblings = el.parent.children;
    const index = siblings.indexOf(el);
    const replacements = parseChildren(String(value), el.parent);
    replacements.forEach(node => { node.parent = el.parent; });
    siblings.splice(index, 1, ...replacements);
    detach(el);
  } });
  el.insertAdjacentHTML = (position, html) => {
    const container = position === 'beforebegin' || position === 'afterend' ? el.parent : el;
    const nodes = parseChildren(html, container);
    nodes.forEach(node => { node.parent = container; });
    const index = position === 'beforebegin' ? container.children.indexOf(el)
      : position === 'afterend' ? container.children.indexOf(el) + 1
      : position === 'afterbegin' ? 0 : container.children.length;
    container.children.splice(index, 0, ...nodes);
  };
  el.before = node => { node.parent = el.parent; el.parent.children.splice(el.parent.children.indexOf(el), 0, node); };
  el.append = node => { node.parent = el; el.children.push(node); };
  el.elements = {
    namedItem(name) {
      return find(el, `[name="${name}"]`) || find(el, 'textarea');
    },
  };
  return el;
}

function detach(node) { node.isConnected = false; node.children.forEach(detach); }

function matches(el, selector) {
  if (!selector) return false;
  if (selector.includes(',')) return selector.split(',').some(part => matches(el, part.trim()));
  if (selector.includes(' ')) {
    const parts = selector.split(/\s+/);
    if (!matches(el, parts.pop())) return false;
    let ancestor = el.parent;
    while (parts.length && ancestor) {
      if (matches(ancestor, parts[parts.length - 1])) parts.pop();
      ancestor = ancestor.parent;
    }
    return !parts.length;
  }
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
    node.ownerDocument = parent.ownerDocument;
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
  const document = {
    body: element('body'),
    activeElement: element('button'),
    addEventListener() {},
    removeEventListener() {},
    createElement(tag) { const node = element(tag); node.ownerDocument = document; return node; },
    querySelector(selector) {
      for (const node of nodes.values()) { if (matches(node, selector)) return node; const found = find(node, selector); if (found) return found; }
      return null;
    },
    getElementById(id) {
      if (!nodes.has(id)) nodes.set(id, element('div'));
      const node = nodes.get(id);
      node.ownerDocument = document;
      node.setAttribute('id', id);
      return node;
    },
  };
  document.body.ownerDocument = document;
  document.activeElement.ownerDocument = document;
  return document;
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
  closeRequestModal,
  renderAttachmentsHtml,
  renderTimelineHtml,
  renderComplaintCommentsHtml,
  complaintResolutionActionsHtml,
  state() { return residentComplaintDialog; },
  holdDrafts(id, draft) { complaintResolutionHold.set(id, draft); },
  heldDrafts(id) { return complaintResolutionHold.get(id); },
  configure(callback) { fetchJson = callback; },
  configureResolution(send, refreshRows) {
    globalThis.fetch = send;
    loadComplaints = refreshRows;
    renderComplaintsList = () => {};
  },
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
check('resident detail removes assignment sharing and response targets', !residentDetail.includes('data-complaint-triage') && !residentDetail.includes('data-complaint-own-sharing') && !residentDetail.includes('Response target') && !residentDetail.includes('Unassigned'));
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

async function discardSaveChecks() {
  const modal = official.document.getElementById('actionModal');
  const open = () => modal.classList.contains('show');
  const click = node => node.listeners.click[0](); // Deliberately bypass native disabled-click filtering.
  const escape = state => state.keyHandler({ key: 'Escape', preventDefault() {}, stopPropagation() {} });
  const fresh = async () => {
    official.api.configure(async () => ({ success: true, comments: [], attachments: [], updates: [] }));
    official.api.setRows([Object.assign({}, row, { status: 'open', severity: 'low', note: 'Saved baseline' })]);
    official.api.viewComplaint(7);
    await new Promise(resolve => setImmediate(resolve));
    const state = official.api.state();
    state.form.elements.namedItem('status').value = 'open';
    state.form.elements.namedItem('severity').value = 'low';
    state.saved = JSON.stringify(official.api.complaintManagementValues(state.form));
    return {
      state,
      note: state.form.elements.namedItem('official_note'),
      comment: state.box.querySelector('[name="body"]'),
      prompt: state.box.querySelector('[data-unsaved-prompt]'),
      discard: state.box.querySelector('[data-discard-edits]'),
      keep: state.box.querySelector('[data-keep-editing]'),
    };
  };
  const pendingSave = () => {
    let resolve, reject, requests = 0;
    const response = new Promise((ok, fail) => { resolve = ok; reject = fail; });
    official.api.configure(async () => { requests++; return response; });
    return { promise: official.api.updateComplaint(7), resolve, reject, requests: () => requests };
  };
  const dispose = dialog => {
    if (official.api.state() === dialog.state) click(dialog.discard);
  };

  let dialog = await fresh();
  dialog.note.value = 'First draft';
  const noteLeave = { prevented: false, preventDefault() { this.prevented = true; } };
  dialog.state.unloadHandler(noteLeave);
  check('discard regression: unsaved note requests page-leave protection', noteLeave.prevented && noteLeave.returnValue === '');
  official.api.closeModal();
  check('discard regression: editing opens a visible confirmation', open() && !dialog.prompt.hidden);
  let save = pendingSave();
  check('discard regression: pending save disables discard', dialog.discard.disabled);
  click(dialog.discard);
  check('discard regression: programmatic discard during save stays open without approval', open() && !dialog.state.discardApproved);
  for (let attempt = 0; attempt < 3; attempt++) {
    await official.api.updateComplaint(7);
    click(dialog.discard);
    escape(dialog.state);
    official.api.closeModal();
  }
  check('discard regression: duplicate save Escape and close stay blocked', open() && !dialog.state.discardApproved && save.requests() === 1);
  save.resolve({ success: true, changed: true });
  await save.promise;
  check('discard regression: success clears approval and hides stale prompt', open() && !dialog.state.discardApproved && dialog.prompt.hidden && !dialog.discard.disabled);
  const savedLeave = { prevented: false, preventDefault() { this.prevented = true; } };
  dialog.state.unloadHandler(savedLeave);
  check('discard regression: fully saved management form no longer requests page-leave warning', !savedLeave.prevented && savedLeave.returnValue === undefined);
  dialog.note.value = 'A later edit must be protected';
  official.api.closeModal();
  check('discard regression: later edits get a fresh confirmation', open() && !dialog.prompt.hidden && dialog.note.value === 'A later edit must be protected');
  dispose(dialog);

  dialog = await fresh();
  dialog.note.value = 'Keep this failed note';
  dialog.comment.value = 'Keep this unsent comment after failure';
  dialog.state.form.elements.namedItem('status').value = 'investigating';
  dialog.state.form.elements.namedItem('severity').value = 'high';
  official.api.closeModal();
  save = pendingSave();
  click(dialog.discard);
  escape(dialog.state);
  save.reject(new Error('Controlled save failure'));
  await save.promise;
  const fields = Array.from(dialog.state.form.querySelectorAll('button, select, textarea'));
  check('discard regression: failure retains all values and restores controls', open() && dialog.note.value === 'Keep this failed note' && dialog.comment.value === 'Keep this unsent comment after failure' && dialog.state.form.elements.namedItem('status').value === 'investigating' && dialog.state.form.elements.namedItem('severity').value === 'high' && fields.every(field => !field.disabled) && !dialog.discard.disabled && dialog.state.feedback.dataset.kind === 'error');
  official.api.closeModal();
  check('discard regression: failed save still requires confirmation', open() && !dialog.prompt.hidden && !dialog.state.discardApproved);
  const failedLeave = { prevented: false, preventDefault() { this.prevented = true; } };
  dialog.state.unloadHandler(failedLeave);
  check('discard regression: failed save keeps page-leave protection', failedLeave.prevented && failedLeave.returnValue === '');
  dispose(dialog);

  dialog = await fresh();
  dialog.note.value = 'Keep editing this draft';
  dialog.note.focus();
  official.api.closeModal();
  check('discard regression: warning focuses keep editing', official.document.activeElement === dialog.keep);
  click(dialog.keep);
  check('discard regression: keep editing hides prompt and restores field focus', open() && dialog.prompt.hidden && dialog.note.value === 'Keep editing this draft' && official.document.activeElement === dialog.note);
  escape(dialog.state);
  click(dialog.discard);
  check('discard regression: idle confirmed discard closes and consumes approval', !open() && official.api.state() === null && !dialog.state.discardApproved);

  dialog = await fresh();
  check('discard regression: reopening starts with a hidden prompt and no approval', open() && dialog.prompt.hidden && !dialog.state.discardApproved);
  dialog.note.value = 'Reopened dialog edit';
  escape(dialog.state);
  check('discard regression: reopened dialog starts with fresh confirmation state', open() && !dialog.prompt.hidden && !dialog.state.discardApproved);
  dispose(dialog);

  dialog = await fresh();
  dialog.note.value = 'Management edit';
  dialog.comment.value = 'Separate unsent comment';
  official.api.closeModal();
  save = pendingSave();
  click(dialog.discard);
  save.resolve({ success: true, changed: true });
  await save.promise;
  check('discard regression: management success preserves unsent comment and hides old prompt', open() && dialog.prompt.hidden && dialog.comment.value === 'Separate unsent comment' && !dialog.state.discardApproved);
  const leave = { prevented: false, preventDefault() { this.prevented = true; } };
  dialog.state.unloadHandler(leave);
  check('discard regression: unsent comment still requests page-leave protection', leave.prevented && leave.returnValue === '');
  escape(dialog.state);
  check('discard regression: unsent comment gets a fresh close warning after save', open() && !dialog.prompt.hidden && dialog.comment.value === 'Separate unsent comment');
  dispose(dialog);
}

async function residentChecks() {
  const api = resident.api;
  const doc = resident.document;
  const modal = doc.getElementById('requestModal');
  const tick = () => new Promise(resolve => setImmediate(resolve));
  const click = node => node.listeners.click[0]();
  const key = (node, value) => node.listeners.keydown[0]({ key: value, preventDefault() {} });
  const closeKey = state => state.keyHandler({ key: 'Escape', preventDefault() {}, stopPropagation() {} });
  const cleanClose = () => {
    const state = api.state();
    if (!state) return;
    const comment = state.box.querySelector('[name="body"]');
    const reason = state.box.querySelector('[data-reopen-reason]');
    if (comment) comment.value = '';
    if (reason) reason.value = '';
    api.closeRequestModal();
  };
  const base = Object.assign({}, row, { status: 'open', note: 'Line one\n' + note, severity: 'high', residentConfirmation: null, resolutionUpdateId: 42, assignedOfficialName: 'PRIVATE ASSIGNEE', slaDueAt: 'PRIVATE TARGET', isPublic: true });
  let requests = [];
  let comments = [];
  const reader = async (url, options) => {
    requests.push({ url, options });
    if (url.includes('list_comments')) return { success: true, comments };
    if (url.includes('timeline')) return { success: true, updates: [{ actor_name: 'Actor ' + probe, actor_type: 'resident', to_status: 'open', note: 'Filed ' + probe, created_at: '2026-10-25 08:00:00' }] };
    if (url.includes('list_attachments')) return { success: true, attachments: [{ id: 51, url: '../api/complaints/view_attachment.php?id=51' }] };
    return { success: true };
  };
  const open = async (overrides = {}, read = reader) => {
    cleanClose();
    api.configure(read);
    api.setRows([Object.assign({}, base, overrides)]);
    await api.viewComplaint(7);
    await tick();
    return api.state();
  };
  let state = await open();
  const box = state.box;
  const html = box.innerHTML;
  check('resident: actual reference category location date and escaped description', html.includes(escapeForTest(base.ref)) && html.includes(escapeForTest(base.cat)) && html.includes(escapeForTest(base.loc)) && html.includes(escapeForTest(base.date)) && html.includes(escapeForTest(base.description)));
  check('resident: exactly three named tabs and one visible panel', box.querySelectorAll('[role="tab"]').length === 3 && box.querySelector('[role="tablist"]').getAttribute('aria-label') === 'Complaint details' && box.querySelectorAll('[role="tabpanel"]').filter(panel => !panel.hidden).length === 1 && box.getAttribute('role') === 'dialog' && box.getAttribute('aria-modal') === 'true');
  check('resident: filing-only information and no administrative inputs', box.querySelector('.rc-information').querySelectorAll('dt').length === 1 && html.includes('>Date filed</dt>') && !box.querySelector('select') && !box.querySelector('[name="official_note"]') && !html.includes('Save updates'));
  check('resident: all tabs omit assignment sharing targets and parent identities', !html.includes('data-complaint-triage') && !html.includes('data-complaint-own-sharing') && !html.includes('PRIVATE ASSIGNEE') && !html.includes('PRIVATE TARGET') && !html.includes('Unassigned') && !html.includes('Sharing') && !html.includes('PARENT-REF-XYZ'));
  check('resident: opening only reads existing authorized detail endpoints', requests.length === 3 && requests.every(request => !request.options?.method) && requests.some(request => request.url.includes('list_attachments.php?complaint_id=7')));
  const tabs = box.querySelectorAll('[role="tab"]');
  key(tabs[0], 'ArrowRight');
  check('resident: arrow key selects Timeline and moves focus', tabs[1].getAttribute('aria-selected') === 'true' && doc.activeElement === tabs[1] && !box.querySelector('#residentPanelTimeline').hidden);
  key(tabs[1], 'End');
  check('resident: End selects Comments and Home returns Overview', tabs[2].getAttribute('aria-selected') === 'true' && (key(tabs[2], 'Home'), tabs[0].getAttribute('aria-selected') === 'true'));
  const input = box.querySelector('[name="body"]');
  input.value = 'Unsent ' + probe;
  click(tabs[2]); click(tabs[1]); click(tabs[0]);
  check('resident: tab changes preserve unsent comment draft', input.value === 'Unsent ' + probe);
  input.focus();
  closeKey(state);
  check('resident: Escape warns and focuses Keep editing without losing draft', modal.classList.contains('show') && !box.querySelector('[data-resident-unsaved]').hidden && doc.activeElement === box.querySelector('[data-resident-keep]') && input.value === 'Unsent ' + probe);
  click(box.querySelector('[data-resident-keep]'));
  check('resident: Keep editing hides warning and restores focus', box.querySelector('[data-resident-unsaved]').hidden && doc.activeElement === input && input.value === 'Unsent ' + probe);
  const leave = { prevented: false, preventDefault() { this.prevented = true; } };
  state.unloadHandler(leave);
  check('resident: unsent comment requests page-leave protection', leave.prevented && leave.returnValue === '');
  box.querySelector('.rc-close').focus();
  let prevented = false;
  state.keyHandler({ key: 'Tab', shiftKey: true, preventDefault() { prevented = true; } });
  const footerClose = box.querySelector('.rc-footer').querySelectorAll('button').at(-1);
  check('resident: Shift Tab wraps to footer Close', prevented && doc.activeElement === footerClose);
  prevented = false;
  state.keyHandler({ key: 'Tab', shiftKey: false, preventDefault() { prevented = true; } });
  check('resident: forward Tab wraps to header Close', prevented && doc.activeElement === box.querySelector('.rc-close'));
  const form = box.querySelector('[data-comment-form]');
  const status = box.querySelector('[data-comment-status]');
  let finish, reject, writes = 0;
  api.configure(async (url) => {
    if (url.includes('list_comments')) return { success: true, comments };
    writes++;
    return new Promise((resolve, fail) => { finish = resolve; reject = fail; });
  });
  const posting = form.listeners.submit[0]({ preventDefault() {} });
  await form.listeners.submit[0]({ preventDefault() {} });
  closeKey(state);
  click(box.querySelector('[data-resident-discard]'));
  check('resident: pending post blocks duplicate posts and all discard attempts', writes === 1 && modal.classList.contains('show') && form.querySelector('button').disabled && box.querySelector('[data-resident-discard]').disabled && input.value === 'Unsent ' + probe);
  reject(new Error('Controlled comment failure'));
  await posting;
  check('resident: failed post preserves draft and recovers controls', input.value === 'Unsent ' + probe && !input.disabled && !form.querySelector('button').disabled && !box.querySelector('[data-resident-discard]').disabled && status.textContent.includes('Controlled comment failure'));
  closeKey(state);
  check('resident: failed post still requires close warning', modal.classList.contains('show') && !box.querySelector('[data-resident-unsaved]').hidden);
  api.holdDrafts(7, { commentDraft: input.value, reopenReason: 'Separate held reason' });
  const retry = form.listeners.submit[0]({ preventDefault() {} });
  comments = [{ actor_type: 'resident', actor_name: 'Test & name', body: input.value, created_at: '2026-10-25 08:30:00' }];
  finish({ success: true, id: 101 });
  await retry;
  check('resident: successful retry posts once updates list/count and clears draft', writes === 2 && input.value === '' && box.querySelector('[data-comment-count]').textContent === '(1)' && box.querySelector('[data-comment-tab-count]').textContent === '(1)' && box.querySelector('[data-comment-list]').innerHTML.includes('&lt;em data-review-probe&gt;') && box.querySelector('[data-resident-unsaved]').hidden);
  check('resident: accepted comments clear only their held copy', api.heldDrafts(7).commentDraft === '' && api.heldDrafts(7).reopenReason === 'Separate held reason');
  input.value = 'x'.repeat(2001);
  await form.listeners.submit[0]({ preventDefault() {} });
  check('resident: 2000 character limit rejects before a request', writes === 2 && input.value.length === 2001 && status.textContent.includes('2,000'));
  const image = box.querySelector('#complaintAttachments').querySelector('img');
  const photoLink = image.parentElement;
  check('resident: evidence thumbnail and original link use authorized route', image.getAttribute('src') === '../api/complaints/view_attachment.php?id=51' && photoLink.getAttribute('href') === image.getAttribute('src') && image.getAttribute('alt').includes('Photo 1'));
  image.listeners.error[0]();
  check('resident: failed image offers accessible original link fallback', image.hidden && photoLink.querySelector('span').textContent.includes('Photo unavailable') && photoLink.querySelector('span').textContent.includes('Open original') && photoLink.getAttribute('href').includes('view_attachment.php?id=51'));
  check('resident: empty photos timeline and comments have explicit states', api.renderAttachmentsHtml([]).includes('No photos attached.') && api.renderTimelineHtml([]).includes('No timeline entries yet.') && api.renderComplaintCommentsHtml([]).includes('No comments yet.'));
  check('resident: timeline escapes actor status and note text', box.querySelector('#complaintTimeline').innerHTML.includes('Actor &lt;em') && !box.querySelector('#complaintTimeline').innerHTML.includes(probe));
  api.holdDrafts(7, { commentDraft: 'Older held comment', reopenReason: 'Older held reason' });
  closeKey(state);
  click(box.querySelector('[data-resident-discard]'));
  check('resident: idle discard closes and restores opener focus', !modal.classList.contains('show') && api.state() === null && doc.activeElement === state.opener);
  check('resident: explicit discard clears recovery drafts while preserving recovery lock', !!api.heldDrafts(7) && api.heldDrafts(7).commentDraft === '' && api.heldDrafts(7).reopenReason === '');

  api.configureResolution(async () => {}, async () => {});
  state = await open();
  check('resident: discarded recovery drafts do not return on reopen', state.box.querySelector('[name="body"]').value === '');
  cleanClose();

  state = await open({}, async () => { throw new Error('Read failed'); });
  check('resident: failed reads have actionable timeline photo and comment states', state.box.querySelector('#complaintTimeline').textContent.includes('reopen') && state.box.querySelector('#complaintAttachments').textContent.includes('reopen') && state.box.querySelector('[data-comment-list]').textContent.includes('retry'));
  cleanClose();

  comments = [];
  let current = Object.assign({}, base, { status: 'resolved', residentConfirmation: 'pending', resolutionUpdateId: 42 });
  state = await open(current);
  let section = state.box.querySelector('[data-resident-response]');
  check('resident: resolved complaint retains confirm and reopen actions', !!section.querySelector('[data-confirm-resolution]') && !!section.querySelector('[data-reopen-toggle]') && !state.box.querySelector('select'));
  let sent = [];
  api.configure(reader);
  api.configureResolution(async (url, options) => {
    sent.push({ url, body: JSON.parse(options.body) });
    current = Object.assign({}, current, { resolutionUpdateId: 43 });
    return { ok: false, status: 409, text: async () => JSON.stringify({ success: false, message: 'Resolution changed.' }) };
  }, async () => api.setRows([current]));
  state.box.querySelector('[name="body"]').value = 'Keep separate discussion draft';
  click(section.querySelector('[data-confirm-resolution]'));
  await tick();
  check('resident: stale confirmation sends the expected resolution token without auto retry', sent.length === 1 && sent[0].url.endsWith('/confirm_resolution.php') && sent[0].body.expected_resolution_id === 42 && section.dataset.resolutionToken === '43' && section.querySelector('[data-response-status]').textContent.includes('Review the current status'));
  check('resident: stale resolution recovery preserves unsent comment', state.box.querySelector('[name="body"]').value === 'Keep separate discussion draft');
  api.configureResolution(async (url, options) => {
    sent.push({ url, body: JSON.parse(options.body) });
    current = Object.assign({}, current, { residentConfirmation: 'confirmed' });
    return { ok: true, status: 200, text: async () => JSON.stringify({ success: true, message: 'Confirmed.' }) };
  }, async () => api.setRows([current]));
  click(section.querySelector('[data-confirm-resolution]'));
  await tick();
  check('resident: fresh confirmation uses refreshed token and hides confirm when confirmed', sent[1].body.expected_resolution_id === 43 && !section.querySelector('[data-confirm-resolution]') && !!section.querySelector('[data-reopen-toggle]'));
  const toggle = section.querySelector('[data-reopen-toggle]');
  click(toggle);
  const reason = section.querySelector('[data-reopen-reason]');
  check('resident: reopen toggle exposes reason and moves focus', !section.querySelector('[data-reopen-panel]').hidden && doc.activeElement === reason);
  reason.value = 'Still broken ' + probe;
  api.configureResolution(async (url, options) => {
    sent.push({ url, body: JSON.parse(options.body) });
    current = Object.assign({}, current, { status: 'open', residentConfirmation: 'reopened' });
    return { ok: true, status: 200, text: async () => JSON.stringify({ success: true, message: 'Reopened.' }) };
  }, async () => api.setRows([current]));
  click(section.querySelector('[data-reopen-submit]'));
  await tick();
  check('resident: reopening preserves expected token reason and updated status', sent[2].url.endsWith('/reopen_complaint.php') && sent[2].body.expected_resolution_id === 43 && sent[2].body.reason === 'Still broken ' + probe && state.box.querySelector('[data-complaint-status]').textContent === 'open' && !section.querySelector('[data-response-actions]'));
  check('resident: resolution refresh updates comment count without erasing draft', state.box.querySelector('[data-comment-tab-count]').textContent === '(0)' && state.box.querySelector('[name="body"]').value === 'Keep separate discussion draft');
  check('resident: no administrative or sharing assignment mutation requests', sent.every(request => /\/(confirm_resolution|reopen_complaint)\.php$/.test(request.url)) && !api.complaintResolutionActionsHtml(Object.assign({}, current, { status: 'open' })));
  cleanClose();
}

function escapeForTest(value) {
  return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

behaviorChecks().then(discardSaveChecks).then(residentChecks).then(() => {
  console.log(`RENDER + BEHAVIOR ${passed} passed, ${failed} failed`);
  process.exit(failed === 0 ? 0 : 1);
}).catch(error => { console.error(error); process.exit(1); });
