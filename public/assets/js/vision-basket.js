'use strict';
document.addEventListener('click', event => {
    const step = event.target.closest('[data-quantity-step]');
    if (!step) return;
    const form = step.closest('form');
    const input = form.querySelector('input[name="quantity"]');
    const next = Math.max(1, Math.min(99, Number(input.value) + Number(step.dataset.quantityStep)));
    if (String(next) === input.value) return;
    input.value = String(next);
    form.requestSubmit();
});
