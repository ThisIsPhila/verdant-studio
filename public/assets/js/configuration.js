(() => {
    'use strict';

    const syncChoice = (choice) => {
        const product = choice.querySelector('[data-product-select]');
        const mode = choice.querySelector('[data-mode-select]');
        const rentalFields = choice.querySelector('[data-rental-fields]');
        if (!mode || !rentalFields) return;

        let canBuy = choice.dataset.productSale === 'true';
        let canRent = choice.dataset.productRent === 'true';
        if (product) {
            const selected = product.options[product.selectedIndex];
            canBuy = selected?.dataset.saleEnabled === 'true';
            canRent = selected?.dataset.rentEnabled === 'true';
        }

        const saleMode = [...mode.options].find((option) => option.value === 'sale');
        const rentMode = [...mode.options].find((option) => option.value === 'rent');
        if (saleMode) saleMode.disabled = !canBuy;
        if (rentMode) rentMode.disabled = !canRent;
        if (mode.selectedOptions[0]?.disabled) {
            const available = [...mode.options].find((option) => !option.disabled);
            if (available) mode.value = available.value;
        }

        const renting = mode.value === 'rent' && canRent;
        rentalFields.hidden = !renting;
        rentalFields.querySelectorAll('input[type="date"]').forEach((input) => {
            input.disabled = !renting;
            input.required = renting;
        });
    };

    const initialize = () => {
    document.querySelectorAll('[data-configuration-choice]').forEach((choice) => {
        const product = choice.querySelector('[data-product-select]');
        const mode = choice.querySelector('[data-mode-select]');
        product?.addEventListener('change', () => syncChoice(choice));
        mode?.addEventListener('change', () => syncChoice(choice));
        syncChoice(choice);
    });

    const form = typeof document.querySelector === 'function' ? document.querySelector('.composition-builder') : null;
    const preview = typeof document.querySelector === 'function'
        ? document.querySelector('.configuration-media-preview [data-media-gallery]') : null;
    const refreshStatus = typeof document.querySelector === 'function'
        ? document.querySelector('[data-configuration-refresh-status]') : null;
    if (form) {
        form.querySelectorAll('[data-product-select]').forEach((product) => {
            product.addEventListener('change', () => {
                const message = 'Choices changed. Select Update choices to load the server-checked pairing and refresh this preview.';
                if (refreshStatus) refreshStatus.textContent = message;
                if (preview) {
                    const fallback = preview.querySelector('[data-media-fallback]');
                    const main = preview.querySelector('[data-media-main]');
                    const scale = preview.querySelector('[data-media-scale]');
                    const status = preview.querySelector('[data-media-status]');
                    if (main) main.hidden = true;
                    if (scale) scale.hidden = true;
                    if (fallback) fallback.hidden = false;
                    preview.dataset.needsRefresh = 'true';
                    if (status) status.textContent = message;
                }
            });
        });
    }
    };
    initialize();
    if (typeof document.addEventListener === 'function') document.addEventListener('verdant:configuration-refresh', initialize);
})();
