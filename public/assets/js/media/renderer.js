'use strict';

(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.VerdantMediaRenderer = api;
}(typeof window === 'undefined' ? globalThis : window, function () {
  const SCENE = Object.freeze({ width: 800, height: 1000, groundY: 900 });
  const CAMERA_SCALE = Object.freeze({ small: 1.38, medium: 1.17, large: 1 });
  // Display framing only; these targets are not measurements or fit claims.
  const ILLUSTRATIVE_TARGET_PIXELS = Object.freeze({
    small: { pot: 132, plant: 300 },
    medium: { pot: 174, plant: 390 },
    large: { pot: 218, plant: 490 },
  });
  const CAMERA_DURATION_MS = 3000;
  const CAMERA_EASING = 'cubic-bezier(.22,.61,.36,1)';
  const REFERENCE_PIXELS_PER_MM = 0.45;
  const KIND_SET = new Set(['background', 'plant', 'pot']);

  function pick(asset, camelName, snakeName) {
    return asset[camelName] ?? asset[snakeName];
  }

  function validateAsset(asset, expectedKind) {
    if (!asset || typeof asset !== 'object' || !KIND_SET.has(asset.kind) || asset.kind !== expectedKind) {
      throw new TypeError(`Expected registered ${expectedKind} media.`);
    }
    if (typeof asset.path !== 'string' || !asset.path.startsWith('/assets/images/product-media/')) {
      throw new TypeError('Media must use a registered local asset path.');
    }
    let decodedPath;
    try { decodedPath = decodeURIComponent(asset.path); } catch { throw new TypeError('Media must use a registered local asset path.'); }
    if (decodedPath.includes('\\') || decodedPath.includes('?') || decodedPath.includes('#')
      || decodedPath.split('/').some((part) => part === '.' || part === '..')) {
      throw new TypeError('Media must use a registered local asset path.');
    }
    const sourceWidth = Number(pick(asset, 'sourceWidth', 'source_width'));
    const sourceHeight = Number(pick(asset, 'sourceHeight', 'source_height'));
    const crop = asset.crop;
    if (!Array.isArray(crop) || crop.length !== 4 || !crop.every(Number.isInteger)
      || crop[0] < 0 || crop[1] < 0 || crop[2] <= 0 || crop[3] <= 0
      || crop[0] + crop[2] > sourceWidth || crop[1] + crop[3] > sourceHeight) {
      throw new TypeError('Media crop is outside the registered source image.');
    }
    return { sourceWidth, sourceHeight, crop };
  }

  function cameraScaleFor(profile) {
    if (!Object.hasOwn(CAMERA_SCALE, profile)) throw new TypeError('Plant profile is not supported.');
    return CAMERA_SCALE[profile];
  }

  function applyCamera(stage, profile, reducedMotion = null) {
    const scale = cameraScaleFor(profile);
    if (reducedMotion === null) {
      reducedMotion = typeof globalThis.matchMedia === 'function'
        && globalThis.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }
    stage.style.transformOrigin = '50% 90%';
    stage.style.transition = reducedMotion ? 'none' : `transform ${CAMERA_DURATION_MS}ms ${CAMERA_EASING}`;
    stage.style.transform = `scale(${scale})`;
    stage.style.setProperty?.('--media-camera-scale', String(scale));
    if (stage.dataset) stage.dataset.cameraProfile = profile;
    return scale;
  }

  function canvasFactory(width, height) {
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    return canvas;
  }

  class AssetLoader {
    constructor({ imageFactory = () => new Image(), canvasFactory: makeCanvas = canvasFactory } = {}) {
      this.imageFactory = imageFactory;
      this.canvasFactory = makeCanvas;
      this.images = new Map();
      this.layers = new Map();
    }

    loadImage(path) {
      if (this.images.has(path)) return this.images.get(path);
      const promise = new Promise((resolve, reject) => {
        const image = this.imageFactory();
        image.onload = () => resolve(image);
        image.onerror = () => reject(new Error(`Could not load ${path}`));
        image.src = path;
      }).catch((error) => {
        this.images.delete(path);
        throw error;
      });
      this.images.set(path, promise);
      return promise;
    }

    async prepare(asset, expectedKind) {
      const { sourceWidth, sourceHeight, crop } = validateAsset(asset, expectedKind);
      const cacheKey = JSON.stringify([asset.path, expectedKind, crop, pick(asset, 'canvasOffset', 'canvas_offset') || 0]);
      if (this.layers.has(cacheKey)) return this.layers.get(cacheKey);
      const layerPromise = this.loadImage(asset.path).then((image) => {
        if ((image.naturalWidth || image.width) !== sourceWidth || (image.naturalHeight || image.height) !== sourceHeight) {
          throw new Error(`Registered dimensions do not match ${asset.path}`);
        }
        const offset = expectedKind === 'pot' ? Number(pick(asset, 'canvasOffset', 'canvas_offset') || 0) : 0;
        const layer = this.canvasFactory(crop[2] + offset, crop[3]);
        const context = layer.getContext('2d');
        if (!context) throw new Error('Canvas rendering is unavailable.');
        context.drawImage(image, crop[0], crop[1], crop[2], crop[3], offset, 0, crop[2], crop[3]);
        if (expectedKind === 'pot') this.softenPotEdges(layer, crop, offset);
        const prepared = { asset, image: layer, width: layer.width, height: layer.height };
        if (expectedKind === 'plant') {
          const rootAnchor = asset.root;
          if (!Array.isArray(rootAnchor) || rootAnchor.length !== 2 || !rootAnchor.every(Number.isFinite)
            || rootAnchor[0] < 0 || rootAnchor[0] > crop[2] || rootAnchor[1] <= 0 || rootAnchor[1] > crop[3]) {
            throw new TypeError('Plant root anchor is outside the registered crop.');
          }
          prepared.anchor = { root: rootAnchor.slice() };
          prepared.top = this.findOpaqueTop(layer, rootAnchor[1]);
          cameraScaleFor(asset.profile);
        }
        if (expectedKind === 'pot') {
          const anchor = asset.anchor;
          if (!Array.isArray(anchor) || anchor.length !== 3 || !anchor.every(Number.isFinite)
            || anchor[0] < 0 || anchor[0] > crop[2] || anchor[1] < 0 || anchor[1] >= anchor[2] || anchor[2] > crop[3]) {
            throw new TypeError('Pot ground and soil anchors are outside the registered crop.');
          }
          prepared.anchor = { x: anchor[0] + offset, soilY: anchor[1], groundY: anchor[2] };
          cameraScaleFor(asset.profile);
        }
        return prepared;
      }).catch((error) => {
        this.layers.delete(cacheKey);
        throw error;
      });
      this.layers.set(cacheKey, layerPromise);
      return layerPromise;
    }

    softenPotEdges(layer, crop, offset) {
      try {
        const context = layer.getContext('2d');
        const pixels = context.getImageData(0, 0, layer.width, layer.height);
        const leftCell = crop[0] === 0;
        for (let y = 0; y < layer.height; y += 1) {
          for (let x = 0; x < layer.width; x += 1) {
            const localX = x - offset;
            let alpha = 1;
            if (leftCell) {
              if (y <= 530 && localX >= 561) alpha = 0;
              if (y > 530) alpha = Math.max(0, Math.min(1, (631 - localX) / 28));
            } else if (y > 530) {
              alpha = Math.max(0, Math.min(1, (localX - 58) / 28, (561 - localX) / 32));
            }
            if (alpha !== 1) pixels.data[(y * layer.width + x) * 4 + 3] *= alpha;
          }
        }
        context.putImageData(pixels, 0, 0);
      } catch { /* If pixel access is restricted, keep the registered crop intact. */ }
    }

    findOpaqueTop(layer, rootY) {
      try {
        const pixels = layer.getContext('2d').getImageData(0, 0, layer.width, Math.max(1, Math.ceil(rootY))).data;
        for (let y = 0; y < Math.ceil(rootY); y += 1) {
          for (let x = 0; x < layer.width; x += 1) {
            if (pixels[(y * layer.width + x) * 4 + 3] > 16) return y;
          }
        }
      } catch { /* Cross-origin or canvas restrictions fall back to the registered crop edge. */ }
      return 0;
    }

    async prepareScene({ background, plant, pot }) {
      if (!background || !plant) throw new TypeError('A registered background and plant are required.');
      const [preparedBackground, preparedPlant, preparedPot] = await Promise.all([
        this.prepare(background, 'background'),
        this.prepare(plant, 'plant'),
        pot ? this.prepare(pot, 'pot') : Promise.resolve(null),
      ]);
      return { background: preparedBackground, plant: preparedPlant, pot: preparedPot, profile: plant.profile };
    }
  }

  function drawPot(context, pot, placement) {
    const { x, y, scale } = placement;
    context.drawImage(pot.image, x, y, pot.width * scale, pot.height * scale);
  }

  function drawPlant(context, plant, placement) {
    context.drawImage(plant.image, placement.x, placement.y, plant.width * placement.scale, plant.height * placement.scale);
  }

  function drawScene(context, scene) {
    const { background, plant, pot, profile } = scene;
    const plantTargets = ILLUSTRATIVE_TARGET_PIXELS[profile];
    if (!pot) {
      const plantScale = plantTargets.plant / Math.max(1, plant.anchor.root[1] - plant.top);
      const plantX = SCENE.width / 2 - plant.anchor.root[0] * plantScale;
      const plantY = SCENE.groundY - plant.height * plantScale;
      context.drawImage(background.image, 0, 0, SCENE.width, SCENE.height);
      drawPlant(context, plant, { x: plantX, y: plantY, scale: plantScale });
      return { plantScale, plantX, plantY, soilY: SCENE.groundY };
    }
    const potTargets = ILLUSTRATIVE_TARGET_PIXELS[pot.asset.profile];
    const potSourceHeight = pot.anchor.groundY - pot.anchor.soilY;
    const potScale = potTargets.pot / potSourceHeight;
    const potX = SCENE.width / 2 - pot.anchor.x * potScale;
    const potY = SCENE.groundY - pot.anchor.groundY * potScale;
    const soilY = potY + pot.anchor.soilY * potScale;
    const visiblePlantSourceHeight = Math.max(1, plant.anchor.root[1] - plant.top);
    const plantScale = plantTargets.plant / visiblePlantSourceHeight;
    const plantX = SCENE.width / 2 - plant.anchor.root[0] * plantScale;
    const plantY = soilY - plant.anchor.root[1] * plantScale;
    context.drawImage(background.image, 0, 0, SCENE.width, SCENE.height);
    drawPot(context, pot, { x: potX, y: potY, scale: potScale });
    drawPlant(context, plant, { x: plantX, y: plantY, scale: plantScale });
    context.save();
    context.beginPath();
    const frontRimStart = Math.max(0, pot.anchor.soilY - 10);
    context.rect(potX, potY + frontRimStart * potScale, pot.width * potScale, (pot.height - frontRimStart) * potScale);
    context.clip();
    drawPot(context, pot, { x: potX, y: potY, scale: potScale });
    context.restore();
    return { potScale, plantScale, plantX, plantY, potX, potY, soilY };
  }

  function drawStandalonePot(context, background, pot, cameraScale = 1) {
    if (!pot || !pot.asset || !Object.hasOwn(ILLUSTRATIVE_TARGET_PIXELS, pot.asset.profile)
      || !Number.isFinite(cameraScale) || cameraScale < 0.85 || cameraScale > 1.15) {
      throw new TypeError('The standalone planter framing is invalid.');
    }
    context.save();
    context.translate(SCENE.width / 2, SCENE.groundY);
    context.scale(cameraScale, cameraScale);
    context.translate(-SCENE.width / 2, -SCENE.groundY);
    if (background && background.image) context.drawImage(background.image, 0, 0, SCENE.width, SCENE.height);
    else {
      context.fillStyle = '#f1eee6';
      context.fillRect(0, 0, SCENE.width, SCENE.height);
    }
    const sourceHeight = pot.anchor.groundY - pot.anchor.soilY;
    if (!Number.isFinite(sourceHeight) || sourceHeight <= 0) {
      context.restore();
      throw new TypeError('The planter crop has no valid ground anchor.');
    }
    const scale = ILLUSTRATIVE_TARGET_PIXELS[pot.asset.profile].pot / sourceHeight;
    const x = SCENE.width / 2 - pot.anchor.x * scale;
    const y = SCENE.groundY - pot.anchor.groundY * scale;
    drawPot(context, pot, { x, y, scale });
    context.restore();
    return { scale, x, y, cameraScale };
  }

  class SceneRenderer {
    constructor({ canvas, loader = new AssetLoader(), fallbackElement = null, statusElement = null, stage = canvas } = {}) {
      if (!canvas) throw new TypeError('A scene canvas is required.');
      this.canvas = canvas;
      this.loader = loader;
      this.fallbackElement = fallbackElement;
      this.statusElement = statusElement;
      this.stage = stage;
      this.sequence = 0;
    }

    async render(descriptor, { reducedMotion = null } = {}) {
      const request = ++this.sequence;
      try {
        const scene = await this.loader.prepareScene(descriptor);
        if (request !== this.sequence) return { status: 'stale' };
        const buffer = this.loader.canvasFactory(SCENE.width, SCENE.height);
        const context = buffer.getContext('2d');
        if (!context) throw new Error('Canvas rendering is unavailable.');
        const geometry = drawScene(context, scene);
        if (request !== this.sequence) return { status: 'stale' };
        const visible = this.canvas.getContext('2d');
        if (!visible) throw new Error('Canvas rendering is unavailable.');
        this.canvas.width = SCENE.width;
        this.canvas.height = SCENE.height;
        visible.clearRect(0, 0, SCENE.width, SCENE.height);
        visible.drawImage(buffer, 0, 0);
        this.canvas.hidden = false;
        if (this.fallbackElement) this.fallbackElement.hidden = true;
        if (this.statusElement) this.statusElement.textContent = '';
        applyCamera(this.stage, scene.profile, reducedMotion);
        return { status: 'rendered', geometry, profile: scene.profile };
      } catch (error) {
        if (request !== this.sequence) return { status: 'stale' };
        this.canvas.hidden = true;
        if (this.fallbackElement) this.fallbackElement.hidden = false;
        if (this.statusElement) this.statusElement.textContent = `${error.message} — showing the standard product image.`;
        return { status: 'fallback', error };
      }
    }
  }

  function canShowScaleReference(plant, pot) {
    const plantHeight = Number(pick(plant, 'visibleHeightMm', 'visible_height_mm'));
    const potHeight = Number(pick(pot, 'potHeightMm', 'pot_height_mm'));
    return pick(plant, 'measurementStatus', 'measurement_status') === 'measured'
      && pick(pot, 'measurementStatus', 'measurement_status') === 'measured'
      && Number.isFinite(plantHeight) && plantHeight > 0
      && Number.isFinite(potHeight) && potHeight > 0;
  }

  function drawScaleReference(canvas, scene, measurements) {
    if (!scene || !scene.pot || !measurements || !canShowScaleReference(measurements.plant, measurements.pot)) {
      if (canvas) canvas.hidden = true;
      return false;
    }
    const context = canvas.getContext('2d');
    if (!context) {
      canvas.hidden = true;
      return false;
    }
    canvas.width = SCENE.width;
    canvas.height = SCENE.height;
    context.clearRect(0, 0, SCENE.width, SCENE.height);
    context.drawImage(scene.background.image, 0, 0, SCENE.width, SCENE.height);
    const ppm = REFERENCE_PIXELS_PER_MM;
    const plantHeight = Number(pick(measurements.plant, 'visibleHeightMm', 'visible_height_mm'));
    const potHeight = Number(pick(measurements.pot, 'potHeightMm', 'pot_height_mm'));
    const potSourceHeight = scene.pot.anchor.groundY - scene.pot.anchor.soilY;
    const potScale = potHeight * ppm / potSourceHeight;
    const potX = SCENE.width * 0.68 - scene.pot.anchor.x * potScale;
    const potY = SCENE.groundY - scene.pot.anchor.groundY * potScale;
    const soilY = potY + scene.pot.anchor.soilY * potScale;
    const plantSourceHeight = Math.max(1, scene.plant.anchor.root[1] - scene.plant.top);
    const plantScale = plantHeight * ppm / plantSourceHeight;
    const plantX = SCENE.width * 0.68 - scene.plant.anchor.root[0] * plantScale;
    const plantY = soilY - scene.plant.anchor.root[1] * plantScale;
    drawPot(context, scene.pot, { x: potX, y: potY, scale: potScale });
    drawPlant(context, scene.plant, { x: plantX, y: plantY, scale: plantScale });
    context.save();
    context.beginPath();
    const frontRimStart = Math.max(0, scene.pot.anchor.soilY - 10);
    context.rect(potX, potY + frontRimStart * potScale, scene.pot.width * potScale, (scene.pot.height - frontRimStart) * potScale);
    context.clip();
    drawPot(context, scene.pot, { x: potX, y: potY, scale: potScale });
    context.restore();
    drawHumanSilhouette(context, SCENE.width * 0.2, SCENE.groundY, 1700 * ppm);
    canvas.hidden = false;
    return true;
  }

  function drawHumanSilhouette(context, centerX, groundY, heightPx) {
    const unit = heightPx / 1700;
    context.save();
    context.fillStyle = 'rgba(64, 68, 57, .72)';
    context.beginPath();
    context.arc(centerX, groundY - heightPx + 34 * unit, 34 * unit, 0, Math.PI * 2);
    context.fill();
    context.beginPath();
    context.moveTo(centerX - 32 * unit, groundY - heightPx + 74 * unit);
    context.lineTo(centerX + 32 * unit, groundY - heightPx + 74 * unit);
    context.lineTo(centerX + 68 * unit, groundY - heightPx + 600 * unit);
    context.lineTo(centerX + 38 * unit, groundY - heightPx + 606 * unit);
    context.lineTo(centerX + 18 * unit, groundY - heightPx + 340 * unit);
    context.lineTo(centerX + 18 * unit, groundY - heightPx + 970 * unit);
    context.lineTo(centerX + 48 * unit, groundY);
    context.lineTo(centerX + 8 * unit, groundY);
    context.lineTo(centerX - 4 * unit, groundY - heightPx + 990 * unit);
    context.lineTo(centerX - 30 * unit, groundY);
    context.lineTo(centerX - 70 * unit, groundY);
    context.lineTo(centerX - 42 * unit, groundY - heightPx + 970 * unit);
    context.lineTo(centerX - 42 * unit, groundY - heightPx + 340 * unit);
    context.lineTo(centerX - 62 * unit, groundY - heightPx + 606 * unit);
    context.lineTo(centerX - 92 * unit, groundY - heightPx + 600 * unit);
    context.closePath();
    context.fill();
    context.restore();
  }

  return Object.freeze({
    SCENE,
    CAMERA_DURATION_MS,
    CAMERA_EASING,
    AssetLoader,
    SceneRenderer,
    applyCamera,
    cameraScaleFor,
    canShowScaleReference,
    drawScaleReference,
    drawStandalonePot,
  });
}));
