'use strict';

(() => {
    const root = document.querySelector('[data-landing-pair]');
    const encoded = root?.querySelector('[data-pair-options]');
    if (!encoded) return;

    const options = JSON.parse(encoded.textContent);
    const plant = root.querySelector('[data-pair-plant]');
    const galleries = [...root.querySelectorAll('[data-media-gallery]')];
    const status = root.querySelector('[data-pair-status]');
    const currency = new Intl.NumberFormat('en-GB', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    let request;
    let sequence = 0;
    let previousFamily = options[0]?.pots[0]?.family || 0;

    function selectedPlant() {
        return options.find(item => String(item.id) === plant.value);
    }

    async function updatePreview() {
        const selected = selectedPlant();
        const pot = root.querySelector('[data-pair-pot]:checked:not(:disabled)');
        const selectedPot = selected.pots.find(item => String(item.id) === pot?.value);
        if (!selectedPot) return;
        previousFamily = selectedPot.family;
        root.querySelector('[data-pair-name]').textContent = `${selected.name} · ${selected.size}`;
        root.querySelector('[data-pair-pot-name]').textContent = selectedPot.name;
        root.querySelector('[data-pair-size]').textContent = `${selected.size} plant · Compatible pots`;
        root.querySelector('[data-pair-total]').textContent = `R${currency.format((selected.price + selectedPot.price) / 100)}`;
        root.querySelectorAll('[data-pair-summary-image]').forEach(image => {
            image.hidden = image.dataset.pairSummaryImage !== plant.value;
        });

        if (document.body.dataset.routePrefix) {
            status.textContent = '';
            return;
        }
        const ownSequence = ++sequence;
        request?.abort();
        request = new AbortController();
        status.textContent = 'Updating your pair…';
        try {
            const query = new URLSearchParams({id: plant.value, pot_id: pot.value});
            const response = await fetch(window.VerdantPresentation?.url(`/product?${query}`) || `/product?${query}`, {signal: request.signal});
            if (!response.ok) throw new Error('Preview unavailable');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const descriptor = page.querySelector('[data-media-gallery-data]');
            if (ownSequence !== sequence) return;
            if (!descriptor) throw new Error('Preview unavailable');
            const data = JSON.parse(descriptor.textContent);
            if (data.mode !== 'scene') throw new Error('Preview unavailable');
            for (const gallery of galleries) {
                gallery.querySelector('[data-media-main]')?.setAttribute('aria-label', data.scene_alt);
                gallery.dispatchEvent(new CustomEvent('verdant:media-scene-change', {detail: data}));
            }
            status.textContent = '';
        } catch (error) {
            if (error.name !== 'AbortError' && ownSequence === sequence) {
                status.textContent = 'Your selection is ready; the image could not be updated.';
            }
        }
    }

    function changePlant() {
        const selected = selectedPlant();
        const retained = selected.pots.find(item => previousFamily > 0 && item.family === previousFamily) || selected.pots[0];
        root.querySelectorAll('[data-pair-pots]').forEach(group => {
            const active = group.dataset.pairPots === plant.value;
            group.hidden = !active;
            group.querySelectorAll('[data-pair-pot]').forEach(input => {
                input.disabled = !active;
                input.checked = active && input.value === String(retained.id);
            });
        });
        root.querySelectorAll('[data-pair-variant]').forEach(button => {
            const variant = options.find(item => String(item.id) === button.dataset.pairVariant);
            button.hidden = selected.family > 0 ? variant.family !== selected.family : variant.id !== selected.id;
            button.setAttribute('aria-pressed', String(variant.id === selected.id));
        });
        updatePreview();
    }

    plant.addEventListener('change', changePlant);
    root.querySelectorAll('[data-pair-variant]').forEach(button => {
        button.addEventListener('click', () => {
            plant.value = button.dataset.pairVariant;
            changePlant();
        });
    });
    root.querySelectorAll('[data-pair-pot]').forEach(input => input.addEventListener('change', updatePreview));
})();
