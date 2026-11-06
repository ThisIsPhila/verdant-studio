'use strict';
(() => {
    document.querySelectorAll('[data-password-toggle]').forEach(button => {
        const input = document.getElementById(button.getAttribute('aria-controls'));
        if (!input) return;
        button.hidden = false;
        button.addEventListener('click', () => {
            const visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            button.setAttribute('aria-pressed', String(visible));
            button.setAttribute('aria-label', `${visible ? 'Hide' : 'Show'} ${button.dataset.passwordLabel}`);
            const show = button.querySelector('[data-password-show]');
            const hide = button.querySelector('[data-password-hide]');
            if (show) show.hidden = visible;
            if (hide) hide.hidden = !visible;
            if (document.body.dataset.routePrefix) button.textContent = button.getAttribute('aria-label');
        });
    });
})();
