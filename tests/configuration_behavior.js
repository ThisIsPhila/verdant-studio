'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const makeSelect = (options, initialValue) => ({
    options,
    value: initialValue,
    selectedIndex: options.findIndex((option) => option.value === initialValue),
    get selectedOptions() { return this.options.filter((option) => option.value === this.value); },
    addEventListener(event, handler) { this.handlers ??= {}; this.handlers[event] ??= []; this.handlers[event].push(handler); },
    change() { this.handlers?.change?.forEach((handler) => handler()); },
});

const makeChoice = ({ sale = true, rent = true, modeValue = 'sale', products = true } = {}) => {
    const product = products ? makeSelect([
        { value: '', dataset: {} },
        { value: 'both', dataset: { saleEnabled: 'true', rentEnabled: 'true' } },
        { value: 'buy-only', dataset: { saleEnabled: 'true', rentEnabled: 'false' } },
    ], 'both') : null;
    const mode = makeSelect([{ value: 'sale', disabled: false }, { value: 'rent', disabled: false }], modeValue);
    const dates = [{ disabled: true, required: false }, { disabled: true, required: false }];
    const rentalFields = { hidden: true, querySelectorAll: () => dates };
    const choice = {
        dataset: { productSale: sale ? 'true' : 'false', productRent: rent ? 'true' : 'false' },
        querySelector(selector) {
            return selector === '[data-product-select]' ? product
                : selector === '[data-mode-select]' ? mode
                    : selector === '[data-rental-fields]' ? rentalFields : null;
        },
    };
    return { choice, product, mode, dates, rentalFields };
};

const first = makeChoice();
const second = makeChoice();
const finished = makeChoice({ modeValue: 'sale', products: false });
const choices = [first, second, finished];
const fallback = { hidden: false };
const mainCanvas = { hidden: false };
const scaleCanvas = { hidden: false };
const mediaStatus = { textContent: '' };
const preview = {
    dataset: {},
    querySelector(selector) {
        return selector === '[data-media-fallback]' ? fallback
            : selector === '[data-media-main]' ? mainCanvas
                : selector === '[data-media-scale]' ? scaleCanvas
                    : selector === '[data-media-status]' ? mediaStatus : null;
    },
};
const refreshStatus = { textContent: 'Select Update choices after changing a plant or pot.' };
const form = { querySelectorAll: () => [first.product, second.product].filter(Boolean) };
const source = fs.readFileSync('public/assets/js/configuration.js', 'utf8');
vm.runInNewContext(source, { document: {
    querySelectorAll: () => choices.map((entry) => entry.choice),
    querySelector(selector) {
        return selector === '.composition-builder' ? form
            : selector === '.configuration-media-preview [data-media-gallery]' ? preview
                : selector === '[data-configuration-refresh-status]' ? refreshStatus : null;
    },
} });

assert.ok(first.rentalFields.hidden && second.rentalFields.hidden, 'Independent Buy choices start with dates hidden.');
assert.ok(first.dates.every((input) => input.disabled && !input.required), 'Buy dates are disabled and optional.');

first.mode.value = 'rent';
first.mode.change();
assert.ok(!first.rentalFields.hidden && second.rentalFields.hidden, 'Changing one choice to Rent reveals only its own dates.');
assert.ok(first.dates.every((input) => !input.disabled && input.required)
    && second.dates.every((input) => input.disabled && !input.required), 'Date requirements remain isolated per choice.');

second.mode.value = 'rent';
second.mode.change();
assert.ok(!second.rentalFields.hidden && !first.rentalFields.hidden, 'Each repeated choice can independently enable rental dates.');

first.product.value = 'buy-only';
first.product.selectedIndex = 2;
first.product.change();
assert.equal(first.mode.value, 'sale', 'Changing one choice to a sale-only SKU switches its unsupported mode.');
assert.equal(first.mode.options[1].disabled, true, 'Unsupported mode is disabled for that SKU.');
assert.ok(first.rentalFields.hidden && !second.rentalFields.hidden, 'SKU changes affect only their own rental date group.');
assert.ok(preview.dataset.needsRefresh === 'true' && mainCanvas.hidden && scaleCanvas.hidden && !fallback.hidden,
    'A changed plant or pot hides the stale pair preview until the server refreshes it.');
assert.ok(refreshStatus.textContent.includes('server-checked pairing') && mediaStatus.textContent.includes('Update choices'),
    'The page explains that Update choices refreshes the preview from server-checked options.');

finished.mode.value = 'rent';
finished.mode.change();
assert.ok(!finished.rentalFields.hidden && finished.dates.every((input) => input.required),
    'A fixed finished-stock SKU can toggle Buy/Rent without a product selector.');

console.log('Configuration behavior checks: 7 passed, 0 failed.');
