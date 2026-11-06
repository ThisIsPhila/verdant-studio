'use strict';
(() => {
    const filters = document.querySelector('.vision-filter-disclosure');
    if (filters) {
        const desktop = window.matchMedia('(min-width: 1024px)');
        const update = () => { filters.open = desktop.matches; };
        desktop.addEventListener('change', update);
        update();
    }

    document.querySelectorAll('.vision-filter-form, .vision-sort').forEach(form => {
        form.addEventListener('change', () => {
            if (form.checkValidity()) form.requestSubmit();
        });
    });
})();
