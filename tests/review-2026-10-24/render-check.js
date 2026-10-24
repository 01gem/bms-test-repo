'use strict';

// The official complaint modal is intentionally compact.
// It omits Assignment and Sharing/related reports, including their Save controls
// and related-report navigation. Opening it must not call those initializers.

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
    add(name) { this.classes.add(name); },
    remove(name) { this.classes.delete(name); },
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
    addEventListener() {},
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
check('official note stays inside the value', list.includes('value="He said &quot;no&quot; &lt;em data-review-probe&gt;text&lt;/em&gt;"'));
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
    html.includes('>Filed By</dt>')
    && html.includes('Ada &amp; ')
    && html.includes('>Category</dt>')
    && html.includes('>Location</dt>')
    && html.includes('Purok &quot;1&quot;')
    && html.includes('>Date Filed</dt>')
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
    && html.includes('>Photos</h4>')
    && html.includes('id="complaintAttachments"'));
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

console.log(`RENDER ${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
