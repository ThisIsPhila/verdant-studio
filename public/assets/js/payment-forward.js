'use strict';
const checkoutLink = document.getElementById('paystack-checkout-link');
if (checkoutLink) {
    const destination = new URL(checkoutLink.href);
    if (destination.origin === 'https://checkout.paystack.com' && !destination.username && !destination.password) {
        window.location.assign(destination.href);
    }
}
