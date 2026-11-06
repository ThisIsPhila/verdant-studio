'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

class FakeElement {
  constructor(tagName = 'div') {
    this.tagName = tagName;
    this.dataset = {};
    this.children = [];
    this.listeners = new Map();
    this.hidden = false;
    this.value = '';
    this.textContent = '';
    this.className = '';
    this.attributes = {};
    this.isConnected = true;
    this.style = {};
    this.parent = null;
    this.form = null;
    this.id = '';
    this.returnValue = '';
  }

  addEventListener(name, callback) {
    if (!this.listeners.has(name)) this.listeners.set(name, []);
    this.listeners.get(name).push(callback);
  }

  emit(name, event = {}) {
    for (const callback of this.listeners.get(name) || []) callback({ currentTarget: this, preventDefault() {}, ...event });
  }

  dispatchEvent(event) { this.emit(event.type, event); return true; }
  setAttribute(name, value) { this.attributes[name] = value; }
  focus() { this.focused = true; }
  showModal() { this.open = true; this.showCount = (this.showCount || 0) + 1; }
  close(value = '') { this.open = false; this.returnValue = value; this.emit('close'); }
  getContext() { return { clearRect() {}, drawImage() {} }; }
  insertAdjacentElement(_position, node) { this.parent?.append(node); }

  append(...nodes) {
    for (const node of nodes) {
      node.parent = this;
      node.setConnected(this.isConnected);
      this.children.push(node);
    }
  }

  replaceChildren(...nodes) {
    this.children.forEach((child) => child.setConnected(false));
    this.children = [];
    this.append(...nodes);
  }

  setConnected(value) {
    this.isConnected = value;
    this.children.forEach((child) => child.setConnected(value));
  }

  descendants() { return this.children.flatMap((child) => [child, ...child.descendants()]); }
}

const asset = (id, kind) => ({ id, kind, name: id, path: `/assets/images/product-media/${id}.png`, crop: [0, 0, 10, 10] });

function pickerFixture({ assets, selected = '', required = true, kind = '', context = '', standaloneAsset = '' }) {
  const selectors = [
    '[data-picker-source]', '[data-picker-dialog]', '[data-picker-open]', '[data-picker-enhanced]',
    '[data-picker-choices]', '[data-picker-search]', '[data-picker-page]', '[data-picker-previous]',
    '[data-picker-next]', '[data-picker-assets]', '[data-picker-current]', '[data-picker-close]',
  ];
  const nodes = new Map(selectors.map((selector) => [selector, new FakeElement()]));
  const root = new FakeElement('section');
  root.dataset = { ...(kind ? { kindControl: 'kind-control' } : {}), ...(context ? { contextControl: 'context-control' } : {}), ...(standaloneAsset ? { standaloneAsset } : {}) };
  root.querySelector = (selector) => nodes.get(selector) || (selector.startsWith('label[for=') ? nodes.get('label') : null);
  const source = nodes.get('[data-picker-source]');
  source.id = 'asset-choice';
  source.value = selected;
  source.required = required;
  const form = new FakeElement('form');
  source.form = form;
  const label = new FakeElement('label');
  label.id = 'asset-label';
  nodes.set('label', label);
  const data = new FakeElement('script');
  data.textContent = JSON.stringify(assets);
  nodes.set('[data-picker-assets]', data);
  const ids = new Map();
  if (kind) { const node = new FakeElement('select'); node.value = kind; ids.set('kind-control', node); }
  if (context) { const node = new FakeElement('select'); node.value = context; ids.set('context-control', node); }
  for (const node of nodes.values()) node.setConnected(true);
  return { root, nodes, ids, source, form };
}

function runPicker(fixture, { prepareDelay = 0 } = {}) {
  const root = fixture.root;
  const loader = { prepare: (item) => item.id === 'plant-broken'
    ? Promise.reject(new Error('synthetic image failure'))
    : new Promise((resolve) => setTimeout(() => resolve({ image: {}, width: 10, height: 10 }), item.id === 'plant-slow' ? prepareDelay : 0)) };
  const document = {
    querySelectorAll: (selector) => selector === '[data-admin-asset-picker]' ? [root] : [],
    getElementById: (id) => fixture.ids.get(id) || null,
    createElement: (tag) => new FakeElement(tag),
  };
  const window = { VerdantMediaRenderer: { AssetLoader: class { constructor() { return loader; } } } };
  const context = { window, document, CSS: { escape: (value) => value }, Event: class { constructor(type, options) { this.type = type; Object.assign(this, options); } }, setTimeout, console };
  const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/media/admin-picker.js'), 'utf8');
  vm.runInNewContext(source, context, { filename: 'admin-picker.js' });
  return { ...fixture, loader };
}

async function run() {
  const standalone = runPicker(pickerFixture({
    assets: [asset('bg-stone', 'background'), asset('bg-homes', 'background'), asset('plant-one', 'plant')],
    selected: 'bg-homes', context: 'standalone-pot', standaloneAsset: 'bg-stone',
  }));
  assert.equal(standalone.source.value, '', 'an old room background is cleared from the enhanced standalone-pot picker');
  standalone.nodes.get('[data-picker-open]').emit('click');
  const visibleRadios = standalone.nodes.get('[data-picker-choices]').descendants().filter((node) => node.type === 'radio');
  assert.deepEqual(visibleRadios.map((node) => node.value), ['bg-stone'], 'standalone context filters room backgrounds from choices');
  assert.equal(standalone.nodes.get('[data-picker-page]').textContent, '1 of 1 · 1 images');

  let prevented = false;
  standalone.form.emit('submit', { preventDefault() { prevented = true; } });
  assert.equal(prevented, true, 'empty required picker prevents an unusable hidden-select submission');
  assert.equal(standalone.nodes.get('[data-picker-dialog]').open, true, 'empty required picker opens its dialog');
  assert.equal(standalone.nodes.get('[data-picker-search]').focused, true, 'empty required picker focuses its search control');
  assert.equal(standalone.source.required, false, 'enhanced hidden select no longer triggers browser focus validation');

  const kindFixture = runPicker(pickerFixture({
    assets: [asset('plant-one', 'plant'), asset('pot-one', 'pot')], selected: 'plant-one', kind: 'plant', required: true,
  }));
  kindFixture.ids.get('kind-control').value = 'pot';
  kindFixture.ids.get('kind-control').emit('change');
  assert.equal(kindFixture.source.value, '', 'changing media kind clears an incompatible selected asset');
  kindFixture.nodes.get('[data-picker-open]').emit('click');
  const kindRadios = kindFixture.nodes.get('[data-picker-choices]').descendants().filter((node) => node.type === 'radio');
  assert.deepEqual(kindRadios.map((node) => node.value), ['pot-one'], 'kind filter exposes only matching approved assets');

  const failedCrop = runPicker(pickerFixture({
    assets: [asset('plant-broken', 'plant')], selected: 'plant-broken', required: false,
  }));
  await new Promise((resolve) => setTimeout(resolve, 0));
  const failedPreviewNodes = failedCrop.nodes.get('[data-picker-current]').descendants();
  assert.equal(failedPreviewNodes.find((node) => node.tagName === 'img').hidden, true, 'crop failure never reveals the complete source sheet');
  assert.equal(failedPreviewNodes.find((node) => node.className === 'admin-asset-picker__preview-fallback').hidden, false,
    'crop failure retains a neutral named preview fallback');

  const stale = runPicker(pickerFixture({
    assets: [asset('plant-slow', 'plant'), asset('pot-one', 'pot')], required: false,
  }), { prepareDelay: 25 });
  stale.nodes.get('[data-picker-open]').emit('click');
  const oldCanvas = stale.nodes.get('[data-picker-choices]').descendants().find((node) => node.tagName === 'canvas');
  stale.nodes.get('[data-picker-search]').value = 'no match';
  stale.nodes.get('[data-picker-search]').emit('input');
  await new Promise((resolve) => setTimeout(resolve, 35));
  assert.equal(oldCanvas.isConnected, false, 'late crop result belongs to a detached choice after filtering');
  assert.equal(oldCanvas.hidden, true, 'late crop result cannot become visible after a newer search');

  console.log('Admin picker scenarios passed: standalone filtering, required empty selection, kind changes, crop failure fallback and stale crop rendering.');
}

run().catch((error) => { console.error(error); process.exitCode = 1; });
