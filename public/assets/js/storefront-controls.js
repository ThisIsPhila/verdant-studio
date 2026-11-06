'use strict';

// Local saved choices are independent of the authenticated basket.
document.querySelectorAll('[data-save-product]').forEach(button => {
    const key = `verdant:saved:${button.dataset.saveProduct}`;
    try { button.setAttribute('aria-pressed', String(localStorage.getItem(key) === '1')); } catch (_) {}
    button.addEventListener('click', () => {
        const saved = button.getAttribute('aria-pressed') !== 'true';
        button.setAttribute('aria-pressed', String(saved));
        try { saved ? localStorage.setItem(key, '1') : localStorage.removeItem(key); } catch (_) {}
    });
});

// Keep the approved links visible while these two destinations are not built.
document.querySelectorAll('[data-pending-space] a, a[href="#journal"], a[href="#about"]').forEach(link => {
    link.addEventListener('click', event => event.preventDefault());
});
