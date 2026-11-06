// Native details provides the menu without JavaScript; Escape and outside clicks dismiss it.
'use strict';

const navigationMenu = document.querySelector('[data-site-menu]');

if (navigationMenu) {
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && navigationMenu.open) {
            navigationMenu.open = false;
            navigationMenu.querySelector('summary').focus();
        }
    });

    document.addEventListener('click', (event) => {
        if (navigationMenu.open && !navigationMenu.contains(event.target)) {
            navigationMenu.open = false;
        }
    });
}


// A session disclosure keeps the rest of the storefront written for customers.
const storeNotice = document.querySelector('[data-store-notice]');
if (storeNotice) {
    const acknowledged = document.cookie.split('; ').includes('verdant_store_notice=seen');
    if (!acknowledged) storeNotice.showModal();
    storeNotice.addEventListener('close', () => {
        document.cookie = `verdant_store_notice=seen; Path=/; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}`;
    });
}
