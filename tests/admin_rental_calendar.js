'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const script = fs.readFileSync('public/assets/js/admin-rental-calendar.js', 'utf8');

function harness({ month = '2026-10', view = 'month', scrollY = 120, savedReturn = null } = {}) {
  const origin = 'http://localhost';
  const location = { origin, pathname: '/admin/rental-calendar', search: `?month=${month}&view=${view}`, href: '' };
  location.href = origin + location.pathname + location.search;
  const listeners = {};
  const requests = [];
  const scrolls = [];
  const storage = new Map();
  if (savedReturn) storage.set('verdantRentalCalendarReturn', JSON.stringify(savedReturn));
  const active = { dataset: {} };

  const focusable = (key) => ({
    dataset: { calendarFocus: key }, focused: false,
    focus() { this.focused = true; document.activeElement = this; },
  });
  const makeRegion = (regionMonth, regionView) => {
    const focusables = new Map(['previous', 'next', 'view-month', 'view-agenda', 'month-picker'].map((key) => [key, focusable(key)]));
    const items = new Map([['89', focusable('item-89')]]);
    const status = {
      hidden: true, message: '', children: [],
      classList: { toggle() {} },
      replaceChildren() { this.children = []; this.message = ''; },
      append(child) { this.children.push(child); this.message += child.textContent || ''; },
    };
    return {
      id: 'rental-calendar-region', dataset: { calendarMonth: regionMonth, calendarView: regionView },
      attrs: {}, status, focusables,
      setAttribute(name, value) { this.attrs[name] = value; },
      removeAttribute(name) { delete this.attrs[name]; },
      querySelector(selector) {
        if (selector === '.rental-calendar-load-status') return status;
        const focusMatch = selector.match(/data-calendar-focus="([^"]+)"/);
        if (focusMatch) return focusables.get(focusMatch[1]) || null;
        const itemMatch = selector.match(/data-calendar-item-id="([^"]+)"/);
        if (itemMatch) return items.get(itemMatch[1]) || null;
        return null;
      },
      replaceWith(replacement) { document.region = replacement; },
    };
  };
  const document = {
    region: makeRegion(month, view), activeElement: active,
    querySelector(selector) { return selector === '#rental-calendar-region' ? this.region : null; },
    addEventListener(type, handler) { listeners[type] = handler; },
    createTextNode(textContent) { return { textContent }; },
    createElement(tag) { return { tagName: tag.toUpperCase(), textContent: '', href: '' }; },
  };
  const historyEntries = [{ url: location.href, state: null }];
  let historyIndex = 0;
  const history = {
    get state() { return historyEntries[historyIndex].state; },
    replaceState(state, _title, href) {
      historyEntries[historyIndex] = { state, url: new URL(href, location.href).href };
      location.href = historyEntries[historyIndex].url;
    },
    pushState(state, _title, href) {
      historyEntries.splice(historyIndex + 1);
      historyEntries.push({ state, url: new URL(href, location.href).href });
      historyIndex += 1;
      location.href = historyEntries[historyIndex].url;
    },
    back() {
      historyIndex -= 1;
      location.href = historyEntries[historyIndex].url;
      listeners['window:popstate']?.({ state: historyEntries[historyIndex].state });
    },
    get length() { return historyEntries.length; },
  };
  const window = {
    location, history, scrollY,
    addEventListener(type, handler) { listeners[`window:${type}`] = handler; },
    scrollTo(options) { scrolls.push(options); this.scrollY = options.top; },
  };
  const globals = {
    window, document, history, location,
    URL, URLSearchParams, AbortController,
    CSS: { escape: String },
    DOMParser: class {
      parseFromString(markup) {
        const region = markup.match(/data-calendar-month="([^"]+)" data-calendar-view="([^"]+)"/);
        return { querySelector: (selector) => selector === '#rental-calendar-region' && region
          ? makeRegion(region[1], region[2]) : null };
      }
    },
    FormData: class { constructor(form) { this.form = form; } entries() { return this.form.entries(); } },
    requestAnimationFrame(callback) { callback(); },
    sessionStorage: {
      getItem(key) { return storage.get(key) ?? null; },
      setItem(key, value) { storage.set(key, value); },
      removeItem(key) { storage.delete(key); },
    },
    fetch(url, options) {
      return new Promise((resolve, reject) => requests.push({ url, options, resolve, reject }));
    },
  };
  vm.runInNewContext(script, globals, { filename: 'admin-rental-calendar.js' });

  const clickNav = (href, key = 'next') => {
    const link = { href, dataset: { calendarFocus: key } };
    const event = {
      target: { closest(selector) { return selector.includes('[data-calendar-nav]') ? link : null; } },
      button: 0, defaultPrevented: false, metaKey: false, ctrlKey: false, shiftKey: false, altKey: false,
      preventDefault() { this.defaultPrevented = true; },
    };
    listeners.click(event);
    return event;
  };
  const respond = (request, markup = '<div id="rental-calendar-region" data-calendar-month="2026-11" data-calendar-view="month"></div>', options = {}) => {
    request.resolve({
      ok: options.ok ?? true, redirected: options.redirected ?? false,
      url: new URL(request.url).href,
      text: async () => markup,
    });
  };
  const flush = async () => { await new Promise((resolve) => setImmediate(resolve)); };
  return { document, location, window, history, historyEntries, listeners, requests, scrolls, storage, clickNav, respond, flush };
}

async function run() {
  const basic = harness({ scrollY: 340 });
  basic.window.scrollY = 340;
  basic.clickNav('/admin/rental-calendar?month=2026-11&view=month');
  assert.equal(basic.requests.length, 1, 'month navigation requests the server-rendered region');
  assert.match(basic.requests[0].url.href, /month=2026-11/);
  assert.match(basic.requests[0].url.href, /fragment=1/);
  basic.respond(basic.requests[0]);
  await basic.flush();
  assert.equal(basic.document.region.dataset.calendarMonth, '2026-11', 'only the calendar region is replaced after success');
  assert.equal(new URL(basic.location.href).searchParams.get('month'), '2026-11', 'successful month changes update the URL');
  assert.equal(basic.history.length, 2, 'successful navigation creates one browser history entry');
  assert.equal(basic.scrolls.at(-1).top, 340, 'month replacement preserves the viewport position');
  assert.equal(basic.document.region.focusables.get('next').focused, true, 'focus returns to the corresponding month control');

  const beforeBackEntries = basic.history.length;
  basic.history.back();
  assert.equal(basic.requests.length, 2, 'browser Back requests the prior calendar month');
  basic.respond(basic.requests[1], '<div id="rental-calendar-region" data-calendar-month="2026-10" data-calendar-view="month"></div>');
  await basic.flush();
  assert.equal(basic.document.region.dataset.calendarMonth, '2026-10', 'Back restores the prior server-rendered month');
  assert.equal(basic.history.length, beforeBackEntries, 'popstate does not add another history entry');
  assert.equal(basic.scrolls.at(-1).top, 340, 'Back restores the saved scroll position');

  const race = harness();
  race.clickNav('/admin/rental-calendar?month=2026-11&view=month');
  const staleRequest = race.requests[0];
  race.clickNav('/admin/rental-calendar?month=2026-12&view=month');
  const latestRequest = race.requests[1];
  assert.equal(staleRequest.options.signal.aborted, true, 'new navigation aborts the older fetch');
  race.respond(latestRequest, '<div id="rental-calendar-region" data-calendar-month="2026-12" data-calendar-view="month"></div>');
  await race.flush();
  race.respond(staleRequest, '<div id="rental-calendar-region" data-calendar-month="2026-11" data-calendar-view="month"></div>');
  await race.flush();
  assert.equal(race.document.region.dataset.calendarMonth, '2026-12', 'late stale response cannot replace the latest month');

  const failure = harness();
  failure.clickNav('/admin/rental-calendar?month=2026-11&view=month');
  failure.respond(failure.requests[0], '', { ok: false });
  await failure.flush();
  assert.equal(failure.document.region.dataset.calendarMonth, '2026-10', 'failed update leaves the previous calendar intact');
  assert.equal(failure.document.region.status.hidden, false, 'failure is announced in the status region');
  assert.match(failure.document.region.status.message, /Reload this month/, 'failure provides a normal-link retry');

  const picker = harness();
  const form = { entries: () => [['month', '2026-12'], ['view', 'agenda']], matches: (selector) => selector.endsWith('[data-calendar-picker]') };
  let prevented = false;
  picker.listeners.submit({ target: form, preventDefault() { prevented = true; } });
  assert.equal(prevented, true, 'valid picker selection is progressively enhanced');
  assert.match(picker.requests[0].url.href, /month=2026-12/);
  assert.match(picker.requests[0].url.href, /view=agenda/);
  picker.respond(picker.requests[0], '<div id="rental-calendar-region" data-calendar-month="2026-12" data-calendar-view="agenda"></div>');
  await picker.flush();
  assert.equal(picker.document.region.focusables.get('month-picker').focused, true, 'picker returns keyboard focus to the month control');

  const restored = harness({ month: '2026-11', view: 'agenda', savedReturn: {
    url: '/admin/rental-calendar?month=2026-11&view=agenda', scrollY: 515, itemId: '89',
  } });
  assert.equal(restored.scrolls.at(-1).top, 515, 'detail return restores the saved calendar viewport');
  assert.equal(restored.document.region.querySelector('[data-calendar-item-id="89"]').focused, true,
    'detail return restores focus to the opened calendar item');
  assert.equal(restored.storage.has('verdantRentalCalendarReturn'), false, 'one-use return state is cleared after restoration');

  const invalid = harness();
  invalid.clickNav('/admin/rental-calendar?month=2101-01&view=month');
  assert.equal(invalid.requests.length, 0, 'out-of-range months are left to the ordinary server navigation path');
  console.log('Rental calendar behavior scenarios passed: month update, history/scroll/focus restore, stale request, failure retry, picker and item return.');
}

run().catch((error) => { console.error(error); process.exitCode = 1; });
