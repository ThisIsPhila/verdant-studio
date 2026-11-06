'use strict';

(() => {
  let request = null;
  let sequence = 0;

  document.addEventListener('click', async (event) => {
    const choice = event.target.closest('a[data-product-choice]');
    if (!choice || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const current = choice.closest('[data-product-detail]');
    const url = new URL(choice.href, location.href);
    if (!current || url.origin !== location.origin || url.pathname !== (window.VerdantPresentation?.url('/product') || '/product')) return;
    event.preventDefault();
    const ownSequence = ++sequence;
    request?.abort();
    request = new AbortController();
    current.setAttribute('aria-busy', 'true');
    const status = current.querySelector('[data-product-selection-status]');
    if (status) status.textContent = 'Updating your selection…';

    try {
      const response = await fetch(url, { signal: request.signal, credentials: 'same-origin', headers: { Accept: 'text/html' }, cache: 'no-store' });
      if (!response.ok) throw new Error('This choice is unavailable. Please choose another item.');
      const html = await response.text();
      if (ownSequence !== sequence) return;
      const page = new DOMParser().parseFromString(html, 'text/html');
      const next = page.querySelector('[data-product-detail]');
      if (!next) throw new Error('This choice could not be loaded. Please try again.');
      const oldGallery = current.querySelector('[data-media-gallery]');
      const newGallery = next.querySelector('[data-media-gallery]');
      let descriptor = null;
      const encoded = newGallery?.querySelector('[data-media-gallery-data]');
      if (encoded) descriptor = JSON.parse(encoded.textContent);
      const preserveCamera = oldGallery?.querySelector('[data-media-main]') && descriptor?.mode === 'scene';
      next.querySelectorAll('script:not([type="application/json"])').forEach(script => script.remove());
      if (preserveCamera) newGallery.replaceWith(oldGallery);
      const quantity = current.querySelector('input[name="quantity"]')?.value;
      if (/^[1-9][0-9]?$/.test(quantity || '')) {
        const nextQuantity = next.querySelector('input[name="quantity"]');
        if (nextQuantity) nextQuantity.value = quantity;
      }
      current.replaceWith(next);
      const breadcrumbs = document.querySelector('.breadcrumbs');
      const newBreadcrumbs = page.querySelector('.breadcrumbs');
      if (breadcrumbs && newBreadcrumbs) breadcrumbs.replaceWith(newBreadcrumbs);
      if (preserveCamera) {
        const fallback = oldGallery.querySelector('[data-media-fallback]');
        if (fallback && descriptor.image) {
          fallback.src = descriptor.image.src;
          fallback.alt = descriptor.image.alt || '';
        }
        oldGallery.querySelector('[data-media-main]').setAttribute('aria-label', descriptor.scene_alt || 'Selected plant and pot');
        oldGallery.querySelector('[data-media-gallery-data]').textContent = JSON.stringify(descriptor);
        oldGallery.dispatchEvent(new CustomEvent('verdant:media-scene-change', { detail: descriptor }));
      } else {
        window.VerdantMediaGallery?.mountAll(document, window.VerdantMediaRenderer);
      }
      document.title = page.title;
      history.replaceState(null, '', url);
      const selected = next.querySelector(`[data-choice-kind="${choice.dataset.choiceKind}"][aria-current="page"]`);
      selected?.focus({ preventScroll: true });
      const nextStatus = next.querySelector('[data-product-selection-status]');
      if (nextStatus) nextStatus.textContent = 'Selection updated.';
    } catch (error) {
      if (error.name !== 'AbortError' && ownSequence === sequence && status) {
        status.textContent = error.message;
        status.classList.remove('visually-hidden');
      }
    } finally {
      if (ownSequence === sequence) document.querySelector('[data-product-detail]')?.removeAttribute('aria-busy');
    }
  });
})();
