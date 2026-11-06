'use strict';
// Card scenes reuse the product renderer and one decoded-asset cache per page.
(() => {
  const api = window.VerdantMediaRenderer;
  if (!api) return;
  const loader = new api.AssetLoader();
  const render = async (element) => {
    if (element.dataset.thumbnailMounted) return;
    element.dataset.thumbnailMounted = 'true';
    try {
      const scene = JSON.parse(element.querySelector('[data-thumbnail-scene]').textContent);
      const canvas = element.querySelector('[data-thumbnail-canvas]');
      if (element.dataset.thumbnailMode === 'pot') {
        const [background, pot] = await Promise.all([
          scene.background ? loader.prepare(scene.background, 'background') : Promise.resolve(null),
          loader.prepare(scene.pot, 'pot'),
        ]);
        const ctx = canvas.getContext('2d');
        api.drawStandalonePot(ctx, background, pot, scene.camera_scale || 1);
        canvas.hidden = false;
        element.querySelector('[data-thumbnail-fallback]').hidden = true;
        return;
      }
      const renderer = new api.SceneRenderer({canvas, loader, fallbackElement: element.querySelector('[data-thumbnail-fallback]')});
      await renderer.render(scene, {reducedMotion: true});
      canvas.style.transform = 'none';
    } catch { /* Keep the catalogue image available when media cannot load. */ }
  };
  const observer = 'IntersectionObserver' in window ? new IntersectionObserver((entries) => {
    for (const entry of entries) if (entry.isIntersecting) { observer.unobserve(entry.target); render(entry.target); }
  }, {rootMargin: '300px'}) : null;
  const mountAll = () => document.querySelectorAll('[data-product-thumbnail]').forEach(element => {
    if (element.dataset.thumbnailMounted) return;
    if (observer) observer.observe(element);
    else render(element);
  });
  document.addEventListener('verdant:configuration-refresh', mountAll);
  mountAll();
})();
