/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

const COMMUNITY_CATEGORIES = [
  'Road / Infrastructure',
  'Noise / Public Disturbance',
  'Flooding / Drainage',
  'Public Safety',
  'Other',
];
const COMMUNITY_STATUSES = ['open', 'investigating', 'resolved'];
const COMMUNITY_SEVERITY_LABELS = {
  low: 'Low',
  medium: 'Medium',
  high: 'High',
  critical: 'Critical',
  unknown: 'Not available',
};
const COMMUNITY_STATUS_LABELS = {
  open: 'Open',
  investigating: 'Investigating',
  resolved: 'Resolved',
  unknown: 'Not available',
};

function communityLabel(map, value) {
  return Object.prototype.hasOwnProperty.call(map, value) ? map[value] : 'Not available';
}

function communityCategoryLabel(value) {
  return COMMUNITY_CATEGORIES.includes(value) ? value : 'Other';
}

function communityReportCount(value) {
  const count = typeof value === 'number' ? value : Number(value);
  if (!Number.isInteger(count) || count < 1) return 'Shared reports';
  return count === 1 ? '1 shared report' : `${count} shared reports`;
}

function communityFiledOn(value) {
  return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)
    ? `Filed ${value}`
    : 'Filing date not available';
}

function initCommunityConcerns() {
  const categorySelect = document.getElementById('communityCategory');
  const statusSelect = document.getElementById('communityStatus');
  const refreshButton = document.getElementById('communityRefresh');
  const moreButton = document.getElementById('communityMore');
  const retryButton = document.getElementById('communityRetry');
  const statusLine = document.getElementById('communityStatusLine');
  const cards = document.getElementById('communityCards');
  if (!categorySelect || !statusSelect || !refreshButton || !moreButton || !retryButton || !statusLine || !cards) return;

  fillSelect(categorySelect, COMMUNITY_CATEGORIES, 'All categories', value => value);
  fillSelect(statusSelect, COMMUNITY_STATUSES, 'All statuses', value => communityLabel(COMMUNITY_STATUS_LABELS, value));

  let generation = 0;
  let controller = null;
  let nextBeforeId = null;
  const seen = new Set();

  function setStatus(message) {
    statusLine.textContent = message;
  }

  function setBusy(busy) {
    categorySelect.disabled = busy;
    statusSelect.disabled = busy;
    refreshButton.disabled = busy;
    moreButton.disabled = busy;
    retryButton.disabled = busy;
  }

  function showAuthFailure() {
    seen.clear();
    cards.replaceChildren();
    moreButton.hidden = true;
    retryButton.hidden = true;
    nextBeforeId = null;
    statusLine.replaceChildren();
    const message = document.createElement('span');
    message.textContent = 'Sign in as a resident to view community concerns. ';
    const link = document.createElement('a');
    link.className = 'community-login';
    link.href = 'login.php';
    link.textContent = 'Resident login';
    statusLine.append(message, link);
  }

  function appendCard(card) {
    const id = Number(card && card.id);
    if (!Number.isInteger(id) || id <= 0 || seen.has(id)) return;
    seen.add(id);
    const article = document.createElement('article');
    article.className = 'community-card';
    const title = document.createElement('h2');
    title.textContent = `Concern #${id}`;
    const category = document.createElement('p');
    category.textContent = communityCategoryLabel(card.category);
    const severity = document.createElement('p');
    severity.textContent = `Severity: ${communityLabel(COMMUNITY_SEVERITY_LABELS, card.severity)}`;
    const status = document.createElement('p');
    status.textContent = `Status: ${communityLabel(COMMUNITY_STATUS_LABELS, card.status)}`;
    const filed = document.createElement('p');
    filed.textContent = communityFiledOn(card.filed_on);
    const count = document.createElement('p');
    count.textContent = communityReportCount(card.published_report_count);
    article.append(title, category, severity, status, filed, count);
    cards.append(article);
  }

  async function load(append) {
    const gen = ++generation;
    if (controller) controller.abort();
    controller = new AbortController();
    const signal = controller.signal;
    setBusy(true);
    retryButton.hidden = true;
    if (!append) {
      nextBeforeId = null;
      seen.clear();
      cards.replaceChildren();
      moreButton.hidden = true;
      setStatus('Loading community concerns…');
    } else {
      setStatus('Loading more concerns…');
    }

    const params = new URLSearchParams();
    params.set('limit', '20');
    if (categorySelect.value !== 'all') params.set('category', categorySelect.value);
    if (statusSelect.value !== 'all') params.set('status', statusSelect.value);
    if (append && nextBeforeId) params.set('before_id', String(nextBeforeId));

    try {
      const response = await fetch(`../api/complaints/public_list.php?${params.toString()}`, {
        method: 'GET',
        signal,
        headers: { Accept: 'application/json' },
      });
      if (gen !== generation) return;
      if (response.status === 401 || response.status === 403) {
        showAuthFailure();
        return;
      }
      const raw = await response.text();
      if (gen !== generation) return;
      let data = null;
      try {
        data = raw.trim() === '' ? null : JSON.parse(raw);
      } catch (error) {
        data = null;
      }
      if (!response.ok || !data || data.success !== true || !Array.isArray(data.complaints)
        || typeof data.has_more !== 'boolean' || !Object.prototype.hasOwnProperty.call(data, 'next_before_id')) {
        const message = data && typeof data.message === 'string' && data.message !== ''
          ? data.message
          : 'Community concerns could not be loaded.';
        throw new Error(message);
      }
      data.complaints.forEach(appendCard);
      const cursor = Number(data.next_before_id);
      nextBeforeId = Number.isInteger(cursor) && cursor > 0 ? cursor : null;
      moreButton.hidden = !(data.has_more === true && nextBeforeId !== null);
      if (!append && data.complaints.length === 0) setStatus('No shared concerns match these filters.');
      else if (data.has_more === true && nextBeforeId === null) setStatus('More concerns could not be loaded.');
      else setStatus('');
    } catch (error) {
      if (error.name === 'AbortError' || gen !== generation) return;
      if (append) {
        setStatus('More concerns could not be loaded.');
        retryButton.hidden = false;
      } else {
        seen.clear();
        cards.replaceChildren();
        moreButton.hidden = true;
        setStatus(error.message || 'Community concerns could not be loaded.');
      }
    } finally {
      if (gen === generation) setBusy(false);
    }
  }

  categorySelect.addEventListener('change', () => { load(false); });
  statusSelect.addEventListener('change', () => { load(false); });
  refreshButton.addEventListener('click', () => { load(false); });
  moreButton.addEventListener('click', () => {
    if (!nextBeforeId || moreButton.disabled) return;
    load(true);
  });
  retryButton.addEventListener('click', () => {
    if (!nextBeforeId || retryButton.disabled) return;
    load(true);
  });
  load(false);
}

function fillSelect(select, values, allLabel, labelFor) {
  select.replaceChildren();
  const all = document.createElement('option');
  all.value = 'all';
  all.textContent = allLabel;
  select.append(all);
  values.forEach(value => {
    const option = document.createElement('option');
    option.value = value;
    option.textContent = labelFor(value);
    select.append(option);
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initCommunityConcerns);
} else {
  initCommunityConcerns();
}
