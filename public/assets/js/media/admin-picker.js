'use strict';

// Progressive enhancement for the protected admin asset forms and registry cards.
(() => {
  const renderer = window.VerdantMediaRenderer;
  const loader = renderer ? new renderer.AssetLoader() : null;
  const pageSize = 12;

  const imageNode = (asset, className = '') => {
    const image = document.createElement('img');
    image.src = asset.path;
    image.alt = '';
    image.loading = 'lazy';
    if (className) image.className = className;
    return image;
  };

  const cropInto = async (asset, image, canvas, fallback = null) => {
    if (!loader || !asset || !asset.crop || !asset.kind || !['plant', 'pot', 'background'].includes(asset.kind)) return false;
    try {
      const prepared = await loader.prepare(asset, asset.kind);
      if (!canvas.isConnected) return false;
      const context = canvas.getContext('2d');
      if (!context) return false;
      canvas.width = prepared.width;
      canvas.height = prepared.height;
      context.clearRect(0, 0, canvas.width, canvas.height);
      context.drawImage(prepared.image, 0, 0);
      canvas.hidden = false;
      image.hidden = true;
      if (fallback) fallback.hidden = true;
      return true;
    } catch {
      return false;
    }
  };

  const parse = (node) => {
    try {
      const value = JSON.parse(node.textContent || '[]');
      return Array.isArray(value) ? value.filter((asset) => asset && typeof asset.id === 'string' && typeof asset.path === 'string') : [];
    } catch {
      return [];
    }
  };

  const mountCropCard = (container) => {
    const descriptor = container.querySelector('[data-asset-descriptor]');
    const image = container.querySelector('img');
    if (!descriptor || !image) return;
    let asset;
    try { asset = JSON.parse(descriptor.textContent || 'null'); } catch { return; }
    if (!asset || !asset.crop) return;
    const canvas = document.createElement('canvas');
    canvas.className = 'admin-media-asset__canvas';
    canvas.hidden = true;
    image.hidden = true;
    const fallback = container.querySelector('[data-crop-fallback]');
    image.insertAdjacentElement('afterend', canvas);
    cropInto(asset, image, canvas, fallback);
  };

  const mountPicker = (root) => {
    const source = root.querySelector('[data-picker-source]');
    const dialog = root.querySelector('[data-picker-dialog]');
    const open = root.querySelector('[data-picker-open]');
    const enhanced = root.querySelector('[data-picker-enhanced]');
    const choices = root.querySelector('[data-picker-choices]');
    const search = root.querySelector('[data-picker-search]');
    const pageLabel = root.querySelector('[data-picker-page]');
    const previous = root.querySelector('[data-picker-previous]');
    const next = root.querySelector('[data-picker-next]');
    const assetsNode = root.querySelector('[data-picker-assets]');
    if (!source || !dialog || !open || !enhanced || !choices || !search || !pageLabel || !previous || !next
      || typeof dialog.showModal !== 'function' || typeof dialog.close !== 'function') return;
    const assets = parse(assetsNode);
    if (!assets.length) return;

    const label = root.querySelector(`label[for="${CSS.escape(source.id)}"]`);
    const labelId = label?.id || `${source.id}-label`;
    if (label && !label.id) label.id = labelId;
    open.setAttribute('aria-labelledby', labelId);
    source.hidden = true;
    const required = source.required;
    // Native constraint validation cannot focus a hidden select. Server validation remains authoritative.
    source.required = false;
    enhanced.hidden = false;
    let page = 0;

    const selectedAsset = () => assets.find((asset) => asset.id === source.value) || null;
    const contextControl = root.dataset.contextControl ? document.getElementById(root.dataset.contextControl) : null;
    if (contextControl?.value === 'standalone-pot' && selectedAsset()
      && selectedAsset().id !== (root.dataset.standaloneAsset || '')) source.value = '';
    const drawCurrent = () => {
      const current = root.querySelector('[data-picker-current]');
      if (!current) return;
      current.replaceChildren();
      const asset = selectedAsset();
      if (!asset) {
        const message = document.createElement('span');
        message.textContent = source.value ? 'Selected value is not an approved image.' : 'No image selected.';
        current.append(message);
        return;
      }
      const image = imageNode(asset);
      const canvas = document.createElement('canvas');
      canvas.hidden = true;
      const fallback = document.createElement('span');
      fallback.className = 'admin-asset-picker__preview-fallback';
      fallback.textContent = asset.crop ? 'Cropped preview unavailable' : 'Preview unavailable';
      if (asset.crop) image.hidden = true;
      else fallback.hidden = true;
      current.append(image, canvas, fallback);
      const copy = document.createElement('span');
      copy.textContent = `${asset.name || asset.id}${asset.profile ? ` · ${asset.profile}` : ''}`;
      current.append(copy);
      cropInto(asset, image, canvas, fallback);
    };

    const filteredAssets = () => {
      const query = search.value.trim().toLocaleLowerCase();
      const kindControlId = root.dataset.kindControl;
      const kindControl = kindControlId ? document.getElementById(kindControlId) : null;
      const kind = kindControl?.value || '';
      const contextControlId = root.dataset.contextControl;
      const contextControl = contextControlId ? document.getElementById(contextControlId) : null;
      const standaloneAsset = root.dataset.standaloneAsset || '';
      return assets.filter((asset) => (!kind || asset.kind === kind)
        && (contextControl?.value !== 'standalone-pot' || asset.id === standaloneAsset)
        && (!query || `${asset.name || ''} ${asset.id} ${asset.profile || ''}`.toLocaleLowerCase().includes(query)));
    };

    const drawChoices = () => {
      const filtered = filteredAssets();
      const pageCount = Math.max(1, Math.ceil(filtered.length / pageSize));
      page = Math.min(page, pageCount - 1);
      choices.replaceChildren();
      const visible = filtered.slice(page * pageSize, (page + 1) * pageSize);
      if (!visible.length) {
        const empty = document.createElement('p');
        empty.textContent = 'No approved images match this search.';
        choices.append(empty);
      }
      visible.forEach((asset, index) => {
        const choice = document.createElement('label');
        choice.className = 'admin-asset-picker__choice';
        const input = document.createElement('input');
        input.type = 'radio';
        input.name = `${source.id}-preview-choice`;
        input.value = asset.id;
        input.checked = source.value === asset.id;
        input.setAttribute('aria-label', `${asset.name || asset.id}${asset.profile ? `, ${asset.profile}` : ''}`);
        const copy = document.createElement('span');
        copy.className = 'admin-asset-picker__choice-copy';
        const image = imageNode(asset);
        const canvas = document.createElement('canvas');
        canvas.hidden = true;
        const fallback = document.createElement('span');
        fallback.className = 'admin-asset-picker__preview-fallback';
        fallback.textContent = asset.crop ? 'Cropped preview unavailable' : 'Preview unavailable';
        if (asset.crop) image.hidden = true;
        else fallback.hidden = true;
        const name = document.createElement('strong');
        name.textContent = asset.name || asset.id;
        copy.append(image, canvas, fallback, name);
        if (asset.profile) {
          const secondary = document.createElement('small');
          secondary.textContent = asset.profile;
          copy.append(secondary);
        }
        choice.append(input, copy);
        choices.append(choice);
        if (input.checked) input.dataset.selectedBefore = 'true';
        input.addEventListener('change', () => {
          if (!input.checked) return;
          source.value = asset.id;
          source.dispatchEvent(new Event('change', { bubbles: true }));
          drawCurrent();
          dialog.close('selected');
          open.focus();
        });
        cropInto(asset, image, canvas, fallback);
        if (index === 0) input.dataset.pageFirst = 'true';
      });
      pageLabel.textContent = `${page + 1} of ${pageCount} · ${filtered.length} images`;
      previous.disabled = page === 0;
      next.disabled = page >= pageCount - 1;
    };

    root.querySelector('[data-picker-close]')?.addEventListener('click', () => dialog.close('cancel'));
    open.addEventListener('click', () => {
      page = 0;
      drawChoices();
      dialog.showModal();
      search.focus();
    });
    search.addEventListener('input', () => { page = 0; drawChoices(); });
    previous.addEventListener('click', () => { page = Math.max(0, page - 1); drawChoices(); });
    next.addEventListener('click', () => { page += 1; drawChoices(); });
    const refreshFilters = () => {
      page = 0;
      const selected = selectedAsset();
      const kindControl = root.dataset.kindControl ? document.getElementById(root.dataset.kindControl) : null;
      const contextControl = root.dataset.contextControl ? document.getElementById(root.dataset.contextControl) : null;
      const wrongKind = selected && kindControl?.value && selected.kind !== kindControl.value;
      const wrongContext = selected && contextControl?.value === 'standalone-pot'
        && selected.id !== (root.dataset.standaloneAsset || '');
      if (selected && (wrongKind || wrongContext)) {
        source.value = '';
        source.dispatchEvent(new Event('change', { bubbles: true }));
      }
      drawChoices();
    };
    if (root.dataset.kindControl) document.getElementById(root.dataset.kindControl)?.addEventListener('change', refreshFilters);
    if (root.dataset.contextControl) document.getElementById(root.dataset.contextControl)?.addEventListener('change', refreshFilters);
    source.addEventListener('change', drawCurrent);
    if (required) source.form?.addEventListener('submit', (event) => {
      if (source.value !== '') return;
      event.preventDefault();
      page = 0;
      drawChoices();
      dialog.showModal();
      search.focus();
    });
    dialog.addEventListener('close', () => { if (dialog.returnValue !== 'selected') open.focus(); });
    drawCurrent();
  };

  const mountStandalonePotPreview = (root) => {
    const dataNode = root.querySelector('[data-standalone-pot-preview-data]');
    const canvas = root.querySelector('[data-standalone-pot-canvas]');
    const sceneSelect = document.getElementById('media-scene');
    const assetSelect = document.getElementById('scene-asset');
    const scaleSelect = document.getElementById('pot-camera-scale');
    if (!dataNode || !canvas || !renderer || !sceneSelect || !assetSelect || !scaleSelect) return;
    let data;
    try { data = JSON.parse(dataNode.textContent || '{}'); } catch { return; }
    if (!data.pot || !Array.isArray(data.backgrounds)) return;
    const backgrounds = new Map(data.backgrounds.map((asset) => [asset.id, asset]));
    let request = 0;
    const draw = async () => {
      const currentRequest = ++request;
      if (sceneSelect.value !== 'standalone-pot') return;
      try {
        const backgroundAsset = backgrounds.get(assetSelect.value) || null;
        const [background, pot] = await Promise.all([
          backgroundAsset ? loader.prepare(backgroundAsset, 'background') : Promise.resolve(null),
          loader.prepare(data.pot, 'pot'),
        ]);
        if (currentRequest !== request) return;
        const context = canvas.getContext('2d');
        if (!context) return;
        context.clearRect(0, 0, canvas.width, canvas.height);
        renderer.drawStandalonePot(context, background, pot, Number(scaleSelect.value || 1));
        canvas.dataset.previewReady = 'true';
      } catch {
        if (currentRequest === request) canvas.dataset.previewReady = 'false';
      }
    };
    const syncContext = () => {
      const visible = sceneSelect.value === 'standalone-pot';
      root.querySelector('[data-standalone-pot-controls]')?.toggleAttribute('hidden', !visible);
      root.toggleAttribute('hidden', !visible);
      if (visible) draw();
      else ++request;
    };
    sceneSelect.addEventListener('change', syncContext);
    assetSelect.addEventListener('change', draw);
    scaleSelect.addEventListener('change', draw);
    root.querySelector('[data-reset-pot-framing]')?.addEventListener('click', () => {
      scaleSelect.value = '1.00';
      draw();
      scaleSelect.focus();
    });
    syncContext();
  };

  document.querySelectorAll('[data-admin-asset-picker]').forEach(mountPicker);
  document.querySelectorAll('[data-asset-crop-preview]').forEach(mountCropCard);
  document.querySelectorAll('[data-standalone-pot-preview]').forEach(mountStandalonePotPreview);
})();
