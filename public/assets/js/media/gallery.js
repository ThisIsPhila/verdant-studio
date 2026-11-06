'use strict';

(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.VerdantMediaGallery = api;
  if (root && root.document) {
    const mountAll = () => api.mountAll(root.document, root.VerdantMediaRenderer);
    if (root.document.readyState === 'loading') root.document.addEventListener('DOMContentLoaded', mountAll, { once: true });
    else mountAll();
  }
}(typeof window === 'undefined' ? null : window, function () {
  function setView(view, mainCanvas, scaleCanvas, buttons) {
    const selected = view === 'scale' && scaleCanvas ? 'scale' : 'main';
    if (mainCanvas) mainCanvas.hidden = selected !== 'main';
    if (scaleCanvas) scaleCanvas.hidden = selected !== 'scale';
    for (const button of buttons) {
      const active = button.dataset.mediaView === selected;
      button.setAttribute('aria-pressed', String(active));
    }
    return selected;
  }

  function mount(root, rendererApi) {
    const configElement = root.querySelector('[data-media-gallery-data]');
    const fallback = root.querySelector('[data-media-fallback]');
    const status = root.querySelector('[data-media-status]');
    if (!configElement || !fallback) return null;
    let data;
    try { data = JSON.parse(configElement.textContent); } catch {
      if (status) status.textContent = 'Product image settings could not be read. Showing the standard product image.';
      return null;
    }
    if (data.mode === 'standalone-pot' && rendererApi && rendererApi.drawStandalonePot) {
      const canvas = root.querySelector('[data-media-standalone]');
      const fallbackImage = root.querySelector('[data-media-standalone-fallback]');
      const status = root.querySelector('[data-media-standalone-status]');
      if (!canvas || !data.scene || !data.scene.pot) return null;
      const loader = new rendererApi.AssetLoader();
      Promise.all([
        data.scene.background ? loader.prepare(data.scene.background, 'background') : Promise.resolve(null),
        loader.prepare(data.scene.pot, 'pot'),
      ]).then(([background, pot]) => {
        const context = canvas.getContext('2d');
        if (!context) throw new Error('Product image rendering is unavailable.');
        rendererApi.drawStandalonePot(context, background, pot, data.scene.camera_scale || 1);
        canvas.hidden = false;
        if (fallbackImage) fallbackImage.hidden = true;
        if (status) status.textContent = '';
      }).catch(() => {
        canvas.hidden = true;
        if (fallbackImage) fallbackImage.hidden = false;
        if (status) status.textContent = 'The selected planter crop could not be loaded. Showing the standard product placeholder.';
      });
      return { ready: Promise.resolve(true) };
    }
    if (data.mode !== 'scene' || !data.scene || !rendererApi) return null;

    const mainCanvas = root.querySelector('[data-media-main]');
    const scaleCanvas = root.querySelector('[data-media-scale]');
    const controls = root.querySelector('.product-gallery__view-controls');
    const buttons = Array.from(root.querySelectorAll('[data-media-view]'));
    if (!mainCanvas) return null;
    const loader = new rendererApi.AssetLoader();
    const renderer = new rendererApi.SceneRenderer({
      canvas: mainCanvas,
      stage: mainCanvas,
      loader,
      fallbackElement: fallback,
      statusElement: status,
    });
    let scene = data.scene;
    let reference = data.scale_reference || null;
    let activeView = 'main';

    function updateDescription() {
      const description = root.querySelector('[data-media-description]');
      if (description && typeof data.description === 'string') description.textContent = data.description;
    }

    async function renderScene() {
      activeView = setView('main', mainCanvas, scaleCanvas, buttons);
      const result = await renderer.render(scene);
      if (result.status !== 'rendered') {
        if (controls) controls.hidden = true;
        return result;
      }
      if (reference && scaleCanvas) {
        try {
          const prepared = await loader.prepareScene(scene);
          const visible = rendererApi.drawScaleReference(scaleCanvas, prepared, reference);
          if (!visible) reference = null;
          else scaleCanvas.hidden = activeView !== 'scale';
        } catch {
          scaleCanvas.hidden = true;
          reference = null;
        }
      }
      if (controls) controls.hidden = !(reference && scaleCanvas);
      updateDescription();
      return result;
    }

    function chooseView(view) {
      if (view === 'scale' && (!reference || !scaleCanvas)) return 'main';
      activeView = setView(view, mainCanvas, scaleCanvas, buttons);
      return activeView;
    }

    for (const button of buttons) {
      button.addEventListener('click', () => chooseView(button.dataset.mediaView));
    }
    root.addEventListener('verdant:media-scene-change', (event) => {
      if (!event.detail || !event.detail.scene) return;
      scene = event.detail.scene;
      reference = event.detail.scale_reference || null;
      if (typeof event.detail.description === 'string') data.description = event.detail.description;
      renderScene();
    });

    const ready = renderScene();
    return { renderer, ready, renderScene, chooseView, get activeView() { return activeView; } };
  }

  function mountAll(document, rendererApi) {
    for (const root of document.querySelectorAll('[data-media-gallery]')) {
      if (root.dataset.mediaMounted === 'true') continue;
      root.dataset.mediaMounted = 'true';
      mount(root, rendererApi);
    }
  }

  return Object.freeze({ mount, mountAll, setView });
}));

// Catalogue/configured thumbnail toggle on the product page. Delegated so it
// survives product-selection swaps, which replace the gallery markup when a
// pot or variant changes. Without JS the catalogue image stays visible.
(function () {
  if (typeof document === 'undefined') return;
  let selectedView = 'catalogue';
  function apply(gallery) {
    const buttons = gallery.querySelectorAll('[data-gallery-view]');
    const panels = gallery.querySelectorAll('[data-gallery-panel]');
    if (!buttons.length || !panels.length) return;
    for (const button of buttons) {
      button.setAttribute('aria-pressed', String(button.getAttribute('data-gallery-view') === selectedView));
    }
    for (const panel of panels) {
      panel.hidden = panel.getAttribute('data-gallery-panel') !== selectedView;
    }
  }
  function applyAll() {
    const galleries = document.querySelectorAll('.vision-product-gallery');
    for (const gallery of galleries) apply(gallery);
  }
  function copyConfiguredThumb() {
    const gallery = document.querySelector('.vision-product-gallery');
    if (!gallery) return;
    const main = gallery.querySelector('[data-gallery-panel="configured"] canvas');
    const thumb = gallery.querySelector('[data-gallery-configured-thumb]');
    if (!main || !thumb || main.width === 0) return;
    try {
      const context = thumb.getContext('2d');
      context.clearRect(0, 0, thumb.width, thumb.height);
      context.drawImage(main, 0, 0, thumb.width, thumb.height);
    } catch (error) { /* Keep the previous thumbnail on restricted canvases. */ }
  }
  document.addEventListener('click', (event) => {
    if (!event.target || typeof event.target.closest !== 'function') return;
    const button = event.target.closest('[data-gallery-view]');
    if (!button) return;
    selectedView = button.getAttribute('data-gallery-view');
    applyAll();
    copyConfiguredThumb();
  });
  document.addEventListener('verdant:media-scene-change', () => {
    window.setTimeout(copyConfiguredThumb, 600);
  });
  if (typeof MutationObserver === 'function') {
    new MutationObserver((records) => {
      for (const record of records) {
        for (const node of record.addedNodes) {
          if (node instanceof Element && (node.matches('.vision-product-gallery') || node.querySelector('.vision-product-gallery'))) {
            applyAll();
            window.setTimeout(copyConfiguredThumb, 600);
            return;
          }
        }
      }
    }).observe(document.documentElement, { childList: true, subtree: true });
  }
  window.setTimeout(copyConfiguredThumb, 1500);
})();
