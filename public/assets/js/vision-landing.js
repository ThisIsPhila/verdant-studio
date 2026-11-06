'use strict';
(() => {
    const section = document.querySelector('#shop');
    section?.querySelectorAll('[data-vision-filter]').forEach(button => {
        button.setAttribute('aria-pressed', String(button.dataset.visionFilter === 'All'));
        button.addEventListener('click', () => {
            section.querySelectorAll('[data-vision-filter]').forEach(tab => {
                tab.setAttribute('aria-pressed', String(tab === button));
                tab.classList.remove('border-b', 'border-foreground', 'text-foreground');
            });
            section.querySelectorAll('[data-vision-category]').forEach(card => {
                card.hidden = button.dataset.visionFilter !== 'All' && card.dataset.visionCategory !== button.dataset.visionFilter;
            });
        });
    });
    document.querySelectorAll('[data-vision-pending]').forEach(link => link.addEventListener('click', event => event.preventDefault()));
    document.querySelector('[data-vision-newsletter]')?.addEventListener('submit', event => {
        event.preventDefault();
        let status = event.currentTarget.parentElement.querySelector('[role="status"]');
        if (!status) {
            status = document.createElement('span');
            status.setAttribute('role', 'status');
            event.currentTarget.after(status);
        }
        status.textContent = 'Newsletter subscriptions are not available yet.';
    });
})();
