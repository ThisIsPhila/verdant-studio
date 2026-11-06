'use strict';

const assert = require('node:assert/strict');
const Gallery = require('../public/assets/js/media/gallery.js');

let checks = 0;
function check(condition, message) { assert.ok(condition, message); checks += 1; }

class Element {
  constructor(dataset = {}) { this.dataset = dataset; this.hidden = false; this.textContent = ''; this.attributes = {}; this.listeners = {}; }
  addEventListener(name, callback) { this.listeners[name] = callback; }
  setAttribute(name, value) { this.attributes[name] = value; }
  click() { this.listeners.click?.({ currentTarget: this }); }
}

class Root extends Element {
  constructor(data, { buttons = [], withScale = true } = {}) {
    super();
    this.config = new Element(); this.config.textContent = typeof data === 'string' ? data : JSON.stringify(data);
    this.fallback = new Element(); this.fallback.hidden = false;
    this.status = new Element(); this.main = new Element(); this.main.hidden = true;
    this.scale = withScale ? new Element() : null; if (this.scale) this.scale.hidden = true;
    this.controls = new Element(); this.description = new Element();
    this.buttons = buttons.map((view) => new Element({ mediaView: view }));
    this.items = {
      '[data-media-gallery-data]': this.config,
      '[data-media-fallback]': this.fallback,
      '[data-media-status]': this.status,
      '[data-media-main]': this.main,
      '[data-media-scale]': this.scale,
      '.product-gallery__view-controls': this.controls,
      '[data-media-description]': this.description,
    };
  }
  querySelector(selector) { return this.items[selector] ?? null; }
  querySelectorAll(selector) { return selector === '[data-media-view]' ? this.buttons : []; }
  fire(name, detail) { this.listeners[name]?.({ detail }); }
}

function mockRendererApi({ fail = false, reference = true } = {}) {
  return {
    AssetLoader: class { async prepareScene(scene) { return { prepared: scene }; } },
    SceneRenderer: class {
      constructor(options) { this.options = options; this.scenes = []; }
      async render(scene) {
        this.scenes.push(scene);
        if (fail || scene.fail) {
          this.options.canvas.hidden = true;
          this.options.fallbackElement.hidden = false;
          this.options.statusElement.textContent = 'Could not load media — showing the standard product image.';
          return { status: 'fallback' };
        }
        this.options.canvas.hidden = false;
        this.options.fallbackElement.hidden = true;
        this.options.statusElement.textContent = '';
        return { status: 'rendered' };
      }
    },
    drawScaleReference(canvas, _scene, measurements) { canvas.hidden = !reference || !measurements; return !canvas.hidden; },
  };
}

async function run() {
  const descriptor = { mode: 'scene', scene: { id: 'one' }, scale_reference: { plant: {}, pot: {} }, description: 'Monstera in a compatible pot.' };
  const root = new Root(descriptor, { buttons: ['main', 'scale'] });
  const controller = Gallery.mount(root, mockRendererApi());
  await controller.ready;
  check(root.fallback.hidden && !root.main.hidden, 'successful scene replaces the ordinary product fallback');
  check(!root.controls.hidden && root.scale.hidden, 'measured scale control appears while the main view stays selected');
  check(root.buttons[0].attributes['aria-pressed'] === 'true' && root.buttons[1].attributes['aria-pressed'] === 'false', 'view controls expose their current state accessibly');
  check(controller.chooseView('scale') === 'scale' && root.scale.hidden === false && root.main.hidden, 'scale view can be selected');
  check(root.buttons[1].attributes['aria-pressed'] === 'true', 'selected scale view updates aria-pressed');
  check(controller.chooseView('main') === 'main' && !root.main.hidden && root.scale.hidden, 'product view can be restored');

  root.fire('verdant:media-scene-change', { scene: { id: 'two' }, description: 'New plant and pot.', scale_reference: null });
  await new Promise((resolve) => setImmediate(resolve));
  check(controller.renderer.scenes.at(-1).id === 'two', 'configuration scene-change event updates the same gallery');
  check(root.description.textContent === 'New plant and pot.' && root.controls.hidden, 'scene changes update descriptions and remove unverified scale controls');
  check(controller.chooseView('scale') === 'main', 'scale view cannot be selected when measurements are absent');

  const unknown = new Root({ ...descriptor, scale_reference: null }, { buttons: ['main'], withScale: false });
  const unknownController = Gallery.mount(unknown, mockRendererApi());
  await unknownController.ready;
  check(unknown.controls.hidden, 'unmeasured product does not reveal gallery view controls');

  const broken = new Root(descriptor, { buttons: ['main', 'scale'] });
  const brokenController = Gallery.mount(broken, mockRendererApi({ fail: true }));
  await brokenController.ready;
  check(!broken.fallback.hidden && broken.controls.hidden, 'failed assets retain the visible standard product image');
  check(/could not load media/i.test(broken.status.textContent), 'failed media exposes a short live status');

  const invalid = new Root('{bad json');
  check(Gallery.mount(invalid, mockRendererApi()) === null && /could not be read/i.test(invalid.status.textContent), 'invalid JSON leaves fallback visible and reports the issue');
  const staticGallery = new Root({ mode: 'static' });
  check(Gallery.mount(staticGallery, mockRendererApi()) === null, 'standalone pot galleries do not initialize a plant compositor');

  const mounted = new Root(descriptor);
  const document = { querySelectorAll: () => [mounted] };
  Gallery.mountAll(document, mockRendererApi());
  const firstMarker = mounted.dataset.mediaMounted;
  Gallery.mountAll(document, mockRendererApi());
  check(firstMarker === 'true', 'gallery mount is idempotent across page initialization');

  console.log(`Media gallery checks: ${checks} passed, 0 failed.`);
}

run().catch((error) => { console.error(error); process.exitCode = 1; });
