'use strict';

const assert = require('node:assert/strict');
const Renderer = require('../public/assets/js/media/renderer.js');

function fixture(path, kind, profile = 'medium') {
  const common = { id: path, kind, path: `/assets/images/product-media/${path}.png`, source_width: 100, source_height: 100, crop: [0, 0, 100, 100] };
  if (kind === 'background') return { ...common, ground: [0.5, 0.9] };
  if (kind === 'plant') return { ...common, profile, root: [50, 80], measurement_status: 'unknown' };
  return { ...common, profile, canvas_offset: 0, anchor: [50, 60, 90], measurement_status: 'unknown' };
}

function fakeCanvasFactory() {
  return (width, height) => {
    const canvas = { width, height, draws: [], style: { setProperty() {} } };
    canvas.getContext = () => ({
      drawImage(source, ...args) {
        if (source && source.draws) canvas.draws.push(...source.draws);
        else canvas.draws.push({ source, args });
      },
      clearRect() {}, save() {}, restore() {}, beginPath() {}, rect() {}, clip() {}, translate() {}, scale() {}, fillRect() {},
      fill() {}, arc() {}, moveTo() {}, lineTo() {}, closePath() {}, set fillStyle(value) { canvas.fillStyle = value; },
      getImageData(_x, _y, w, h) { const data = new Uint8ClampedArray(w * h * 4); for (let i = 3; i < data.length; i += 4) data[i] = 255; return { data, width: w, height: h }; },
      putImageData(data) { canvas.pixelData = data; canvas.maskApplied = true; },
    });
    return canvas;
  };
}

function fakeImageFactory(delays = {}, failing = []) {
  return () => {
    const image = { naturalWidth: 100, naturalHeight: 100, onload: null, onerror: null };
    Object.defineProperty(image, 'src', { set(path) {
      image.path = path;
      setTimeout(() => failing.includes(path) ? image.onerror?.() : image.onload?.(), delays[path] || 0);
    } });
    return image;
  };
}

async function run() {
  const assets = {
    background: fixture('bg-one', 'background'),
    plant: fixture('plant-one', 'plant', 'small'),
    pot: fixture('pot-one', 'pot', 'medium'),
  };
  const loader = new Renderer.AssetLoader({ imageFactory: fakeImageFactory(), canvasFactory: fakeCanvasFactory() });
  const scene = await loader.prepareScene(assets);
  assert.equal(scene.background.width, 100, 'background is cropped to its registered source rectangle');
  assert.equal(scene.plant.anchor.root[1], 80, 'plant root anchor remains in crop coordinates');
  assert.equal(scene.pot.anchor.soilY, 60, 'pot rim anchor remains in crop coordinates');
  assert.equal(scene.pot.image.maskApplied, true, 'pot sheet cell edges are softened to keep neighbouring shadows out');
  assert.equal(Renderer.cameraScaleFor('large'), 1, 'large plant defines the base camera');
  assert.ok(Renderer.cameraScaleFor('medium') > Renderer.cameraScaleFor('large'), 'medium plant moves the camera closer');
  assert.ok(Renderer.cameraScaleFor('small') > Renderer.cameraScaleFor('medium'), 'small plant moves the camera closest');
  assert.equal(Renderer.CAMERA_DURATION_MS, 3000, 'camera transition keeps the approved slow duration');
  assert.equal(Renderer.CAMERA_EASING, 'cubic-bezier(.22,.61,.36,1)', 'camera uses the approved ease-out curve');

  const output = fakeCanvasFactory()(800, 1000);
  const fallback = { hidden: false };
  const status = { textContent: '' };
  const stage = { style: { values: {}, setProperty(name, value) { this.values[name] = value; } }, dataset: {} };
  const renderer = new Renderer.SceneRenderer({ canvas: output, fallbackElement: fallback, statusElement: status, stage, loader });
  const rendered = await renderer.render(assets);
  assert.equal(rendered.status, 'rendered', 'complete scene is committed after all assets load');
  assert.deepEqual(output.draws.map(draw => draw.source?.path), [
    assets.background.path, assets.pot.path, assets.plant.path, assets.pot.path,
  ], 'scene composites full background, attached pot/shadow, plant and pot front rim in order');
  assert.equal(fallback.hidden, true, 'successful scene hides static fallback');
  assert.equal(stage.style.values['--media-camera-scale'], String(Renderer.cameraScaleFor('small')), 'plant profile controls camera scale');
  assert.equal(stage.style.transition, 'transform 3000ms cubic-bezier(.22,.61,.36,1)', 'camera transition is gentle');
  assert.ok(Math.abs(rendered.geometry.plantX + scene.plant.anchor.root[0] * rendered.geometry.plantScale - 400) < 0.01, 'plant root lands on the shared scene centre');
  assert.ok(Math.abs(rendered.geometry.potX + scene.pot.anchor.x * rendered.geometry.potScale - 400) < 0.01, 'pot centre shares the plant root axis');
  assert.ok(Math.abs(rendered.geometry.soilY - (900 - 174)) < 0.01, 'pot opening aligns with the plant root');

  const standalone = await loader.prepare(assets.pot, 'pot');
  const standaloneCanvas = fakeCanvasFactory()(800, 1000);
  const neutralFraming = Renderer.drawStandalonePot(standaloneCanvas.getContext('2d'), scene.background, standalone, 1);
  const closerFraming = Renderer.drawStandalonePot(fakeCanvasFactory()(800, 1000).getContext('2d'), scene.background, standalone, 1.1);
  assert.equal(standaloneCanvas.draws[0].source.path, assets.background.path, 'standalone pot presentation starts with its separately selected context');
  assert.equal(standaloneCanvas.draws[1].source.path, assets.pot.path, 'standalone pot presentation draws the registered crop rather than the source sheet');
  assert.equal(neutralFraming.scale, closerFraming.scale, 'camera framing does not change the pot crop display scale');
  assert.equal(closerFraming.cameraScale, 1.1, 'standalone framing is carried independently from physical scale');
  assert.throws(() => Renderer.drawStandalonePot(standaloneCanvas.getContext('2d'), null, standalone, 1.16), /framing is invalid/i,
    'standalone framing is bounded');

  for (const profile of ['small', 'medium', 'large']) {
    for (const backgroundId of ['bg-stone', 'bg-homes', 'bg-entry']) {
      const choice = {
        background: fixture(backgroundId, 'background'),
        plant: fixture(`plant-${profile}`, 'plant', profile),
        pot: fixture(`pot-${profile}`, 'pot', profile),
      };
      const screen = fakeCanvasFactory()(800, 1000);
      const cameraStage = { style: { values: {}, setProperty(name, value) { this.values[name] = value; } }, dataset: {} };
      const view = new Renderer.SceneRenderer({ canvas: screen, loader, stage: cameraStage });
      const result = await view.render(choice);
      assert.equal(result.status, 'rendered', `${profile} plant renders with ${backgroundId}`);
      assert.equal(cameraStage.style.values['--media-camera-scale'], String(Renderer.cameraScaleFor(profile)), `${profile} plant controls camera regardless of selected backdrop`);
      assert.equal(screen.draws[0].source.path, choice.background.path, `${backgroundId} is the full-scene base layer`);
    }
  }

  const reducedStage = { style: { setProperty() {} }, dataset: {} };
  Renderer.applyCamera(reducedStage, 'small', true);
  assert.equal(reducedStage.style.transition, 'none', 'reduced motion removes camera animation');

  const slowPath = '/assets/images/product-media/slow-plant.png';
  const fastPath = '/assets/images/product-media/fast-plant.png';
  const raceLoader = new Renderer.AssetLoader({ imageFactory: fakeImageFactory({ [slowPath]: 30 }), canvasFactory: fakeCanvasFactory() });
  const raceOutput = fakeCanvasFactory()(800, 1000);
  const raceRenderer = new Renderer.SceneRenderer({ canvas: raceOutput, loader: raceLoader });
  const first = raceRenderer.render({ ...assets, plant: { ...assets.plant, id: 'slow', path: slowPath } });
  const second = raceRenderer.render({ ...assets, plant: { ...assets.plant, id: 'fast', path: fastPath } });
  const raceResults = await Promise.all([first, second]);
  assert.equal(raceResults[0].status, 'stale', 'older asynchronous selection is discarded');
  assert.equal(raceResults[1].status, 'rendered', 'latest selection wins');
  assert.ok(raceOutput.draws.some(draw => draw.source?.path === fastPath), 'latest plant is the visible scene');
  assert.ok(!raceOutput.draws.some(draw => draw.source?.path === slowPath), 'stale plant never replaces the latest scene');

  const plantOnly = await renderer.render({ ...assets, pot: null });
  assert.equal(plantOnly.status, 'rendered', 'plant-only selection uses the existing scene renderer');
  assert.ok(Math.abs(plantOnly.geometry.plantY + scene.plant.height * plantOnly.geometry.plantScale - 900) < .01, 'plant-only full crop stays above the floor without cutting the roots');
  assert.equal(Renderer.drawScaleReference(fakeCanvasFactory()(800, 1000), { ...scene, pot: null }, { plant: {}, pot: {} }), false, 'plant-only preview cannot make an unverified scale claim');

  const badOutput = fakeCanvasFactory()(800, 1000);
  const badFallback = { hidden: false };
  const badStatus = { textContent: '' };
  const badLoader = new Renderer.AssetLoader({ imageFactory: fakeImageFactory({}, ['/assets/images/product-media/missing.png']), canvasFactory: fakeCanvasFactory() });
  const badRenderer = new Renderer.SceneRenderer({ canvas: badOutput, fallbackElement: badFallback, statusElement: badStatus, loader: badLoader });
  const failure = await badRenderer.render({ ...assets, plant: { ...assets.plant, path: '/assets/images/product-media/missing.png' } });
  assert.equal(failure.status, 'fallback', 'failed asset retains static fallback');
  assert.equal(badFallback.hidden, false, 'fallback remains visible');
  assert.match(badStatus.textContent, /could not load/i, 'failure announces a concise recovery notice');

  const traversal = { ...assets, plant: { ...assets.plant, path: '/assets/images/product-media/../private.png' } };
  await assert.rejects(() => loader.prepareScene(traversal), /registered local asset path/i, 'arbitrary paths are rejected');
  assert.equal(Renderer.canShowScaleReference({ measurement_status: 'unknown', visible_height_mm: 400 }, { measurement_status: 'measured', pot_height_mm: 100 }), false, 'unmeasured assets cannot claim human scale');
  assert.equal(Renderer.canShowScaleReference({ measurement_status: 'measured', visible_height_mm: 400 }, { measurement_status: 'measured', pot_height_mm: 100 }), true, 'human scale requires both measured dimensions');
  const reference = fakeCanvasFactory()(800, 1000);
  const measurements = {
    plant: { measurement_status: 'measured', visible_height_mm: 400 },
    pot: { measurement_status: 'measured', pot_height_mm: 100 },
  };
  assert.equal(Renderer.drawScaleReference(reference, scene, { ...measurements, plant: { ...measurements.plant, measurement_status: 'unknown' } }), false, 'scale silhouette stays hidden until measurements are confirmed');
  assert.equal(reference.hidden, true, 'unknown measurements cannot leave a previously visible scale view behind');
  assert.equal(Renderer.drawScaleReference(reference, scene, measurements), true, 'measured scale reference uses a fixed 170cm silhouette');
  assert.equal(reference.hidden, false, 'measured product and human reference becomes visible');
  assert.ok(reference.draws.length >= 1, 'fixed-scale silhouette is drawn as vector shapes');

  console.log('Media renderer scenarios passed: nine plant/background combinations, alignment, reduced motion, loading race, fallback, path validation and measured scale reference.');
}

run().catch((error) => { console.error(error); process.exitCode = 1; });
