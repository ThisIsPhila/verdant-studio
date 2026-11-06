'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/payment-forward.js', 'utf8');
let checks = 0;
for (const [href, allowed] of [
    ['https://checkout.paystack.com/test', true],
    ['https://checkout.paystack.com.evil.test/test', false],
    ['https://user@checkout.paystack.com/test', false],
    ['http://checkout.paystack.com/test', false],
    ['javascript:alert(1)', false],
    [null, false],
]) {
    const navigations = [];
    vm.runInNewContext(source, { URL, document: { getElementById: () => href ? { href } : null }, window: { location: { assign: url => navigations.push(url) } } });
    if ((navigations.length === 1) !== allowed) throw new Error('Unexpected navigation for ' + href);
    checks++;
}
console.log(`Payment navigation checks: ${checks} passed, 0 failed.`);
