'use strict';
(() => {
    let request;
    let sequence = 0;
    document.addEventListener('change', async event => {
        const radio = event.target.closest('[data-vision-choice]');
        if (!radio) return;
        const form = radio.closest('.composition-builder');
        const choice = radio.closest('[data-configuration-choice]');
        const select = choice.querySelector('[data-product-select]');
        select.value = radio.value;
        select.dispatchEvent(new Event('change', {bubbles: true}));
        const revision = ++sequence;
        request?.abort();
        request = new AbortController();
        const status = document.querySelector('[data-configuration-refresh-status]');
        if (status) status.textContent = 'Updating compatible choices…';
        try {
            const response = await fetch(window.VerdantPresentation?.url('/compositions/options') || '/compositions/options', {method: 'POST', body: new FormData(form), signal: request.signal});
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const nextForm = page.querySelector('.configuration-media-form');
            if (revision !== sequence) return;
            if (!response.ok || !nextForm) throw new Error('Choices could not be updated.');
            document.querySelector('.configuration-media-form').replaceWith(nextForm);
            const currentPreview = document.querySelector('.configuration-media-preview');
            const nextPreview = page.querySelector('.configuration-media-preview');
            const descriptor = nextPreview?.querySelector('[data-media-gallery-data]');
            const currentGallery = currentPreview?.querySelector('[data-media-gallery]');
            const data = descriptor ? JSON.parse(descriptor.textContent) : null;
            if (currentGallery && data?.scene) {
                currentGallery.querySelector('[data-media-main]')?.setAttribute('aria-label', data.scene_alt || 'Selected plant and pot');
                const currentData = currentGallery.querySelector('[data-media-gallery-data]');
                if (currentData) currentData.textContent = descriptor.textContent;
                currentGallery.dispatchEvent(new CustomEvent('verdant:media-scene-change', {detail:data}));
                delete currentGallery.dataset.needsRefresh;
            } else if (nextPreview) {
                if (currentPreview) {
                    currentPreview.replaceWith(nextPreview);
                } else {
                    // First selection on a page that opened without a preview:
                    // insert it ahead of the form instead of dropping it.
                    document.querySelector('.configuration-media-form')
                        ?.before(nextPreview);
                }
                window.VerdantMediaGallery?.mountAll(document, window.VerdantMediaRenderer);
            }
            const summary = document.querySelector('[data-vision-quote]');
            const nextSummary = page.querySelector('[data-vision-quote]');
            if (summary && nextSummary) summary.replaceWith(nextSummary);
            document.dispatchEvent(new Event('verdant:configuration-refresh'));
            // Keep keyboard focus on the chosen product after its form is refreshed.
            document.querySelector(`input[data-vision-choice][value="${radio.value}"]`)?.focus({preventScroll:true});
        } catch (error) {
            if (error.name === 'AbortError') return;
            if (status) status.textContent = 'Unable to refresh choices. Select Update choices to continue.';
        }
    });
})();
