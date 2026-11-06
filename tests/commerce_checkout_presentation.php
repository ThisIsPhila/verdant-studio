<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
putenv('APP_ENV=local');

use Verdant\Http\View;
use Verdant\Support\CommerceSummary;

$checks = 0;
function commercePresentationCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
}
function renderCommerceView(string $name, array $data): string
{
    ob_start();
    View::render($name, $data + ['title' => 'Presentation fixture', 'actor' => null]);
    return (string) ob_get_clean();
}

$order = [
    'order_id' => 41, 'state' => 'pending_payment', 'total_minor' => 9999,
    'items' => [[
        'product_name_snapshot' => '<Rose & fern>', 'sku_snapshot' => 'ROSE-41', 'quantity' => 2,
        'unit_price_minor' => 1250, 'total_minor' => 2500,
    ]],
];
$orderSummary = CommerceSummary::fromRetailOrder($order);
commercePresentationCheck($orderSummary['items'][0]['amount'] !== $orderSummary['total'], 'Line and order amounts are both taken from their own persisted values, never summed in the view helper.');
$checkoutHtml = renderCommerceView('checkout', [
    'order' => $order, 'summary' => $orderSummary, 'sandboxEnabled' => true,
]);
commercePresentationCheck(str_contains($checkoutHtml, 'ROSE-41') && str_contains($checkoutHtml, '&lt;Rose &amp; fern&gt;'), 'Retail checkout presents escaped persisted product name and SKU.');
commercePresentationCheck(str_contains($checkoutHtml, 'action="/payment/start"') && str_contains($checkoutHtml, 'name="csrf"'), 'Retail TEST start preserves its CSRF protected POST contract.');
commercePresentationCheck(str_contains($checkoutHtml, 'aria-label="Checkout progress"') && str_contains($checkoutHtml, 'Server verification'), 'Prepared retail checkout exposes the ordered progress stages.');

$cartItem = [
    'product_id' => 8, 'name' => '<Bird of paradise>', 'sku' => 'BOP-8',
    'retail_price_minor' => 2500, 'quantity' => 2, 'line_total_minor' => 5000, 'errors' => [],
];
$cartHtml = renderCommerceView('cart', [
    'items' => [$cartItem], 'total_minor' => 5000, 'valid' => true,
    'errors' => [], 'checkoutValidated' => false,
]);
commercePresentationCheck(str_contains($cartHtml, 'class="basket-cards"') && str_contains($cartHtml, '&lt;Bird of paradise&gt;'), 'Basket uses responsive semantic cards and escapes catalog data.');
commercePresentationCheck(str_contains($cartHtml, 'action="/cart/update"') && str_contains($cartHtml, 'action="/cart/remove"') && str_contains($cartHtml, 'action="/checkout"'), 'Basket retains update, remove, and prepare POST actions.');
commercePresentationCheck(substr_count($cartHtml, 'name="csrf"') === 3, 'Each basket mutation and checkout form includes its CSRF token.');

$rental = [
    'rental_id' => 55, 'state' => 'pending_payment', 'total_minor' => 3210,
    'items' => [[
        'product_name_snapshot' => 'Olive tree', 'sku_snapshot' => 'OLIVE-55', 'quantity' => 1,
        'start_date' => '2026-10-13', 'end_date_exclusive' => '2026-10-16',
        'daily_rate_minor' => 1000, 'total_minor' => 3000,
    ]],
];
$rentalSummary = CommerceSummary::fromRental($rental);
commercePresentationCheck(str_contains($rentalSummary['items'][0]['detail'], 'return required')
    && str_contains($rentalSummary['items'][0]['detail'], 'inclusive')
    && str_contains($rentalSummary['items'][0]['quantity'], '3 days'), 'Rental snapshot summary includes inclusive dates, return requirement, and duration.');
$rentalHtml = renderCommerceView('rental-checkout', ['rental' => $rental, 'summary' => $rentalSummary, 'sandboxEnabled' => true]);
commercePresentationCheck(str_contains($rentalHtml, 'action="/rental/payment/start"') && str_contains($rentalHtml, 'name="csrf"'), 'Rental TEST start preserves its CSRF protected POST contract.');

$composition = [
    'composition_checkout_id' => 73, 'definition_name_snapshot' => 'Patio set',
    'state' => 'pending_payment', 'total_minor' => 8100, 'sale_total_minor' => 5000,
    'rental_total_minor' => 3100, 'payment_reference' => null, 'payment_state' => null,
    'lines' => [[
        'product_name_snapshot' => 'Fern', 'sku_snapshot' => 'FERN-1', 'mode' => 'sale',
        'quantity' => 1, 'unit_price_minor' => 5000, 'line_total_minor' => 5000,
    ], [
        'product_name_snapshot' => 'Ceramic pot', 'sku_snapshot' => 'POT-2', 'mode' => 'rent',
        'quantity' => 1, 'daily_rate_minor' => 1000, 'start_date' => '2026-10-13',
        'end_date_exclusive' => '2026-10-17', 'line_total_minor' => 3100,
    ]],
];
$compositionSummary = CommerceSummary::fromCompositionCheckout($composition);
commercePresentationCheck(str_contains($compositionSummary['items'][0]['detail'], 'Buy · no rental return')
    && str_contains($compositionSummary['items'][1]['detail'], 'Rent · return required'), 'Mixed arrangement lines communicate purchase and rental return obligations separately.');
$compositionHtml = renderCommerceView('composition-pending', ['checkout' => $composition, 'summary' => $compositionSummary]);
commercePresentationCheck(str_contains($compositionHtml, 'action="/compositions/payment/start"')
    && str_contains($compositionHtml, 'name="composition_checkout_id"')
    && str_contains($compositionHtml, 'action="/compositions/cancel"'), 'Arrangement start and cancel retain their distinct CSRF-protected form contracts.');

$resultHtml = renderCommerceView('payment-result', ['state' => 'reconciliation_required']);
commercePresentationCheck(str_contains($resultHtml, 'Payment needs checking')
    && str_contains($resultHtml, 'browser return') === false
    && str_contains($resultHtml, 'server-verified payment status'), 'Pending verification is clearly not presented as a client-confirmed payment.');
$failedHtml = renderCommerceView('rental-payment-result', ['state' => 'failed', 'rentalId' => 55]);
commercePresentationCheck(str_contains($failedHtml, 'Payment not completed') && str_contains($failedHtml, 'View rental details'), 'Rental failure has a recoverable account destination.');
$expiredHtml = renderCommerceView('payment-result', ['state' => 'expired']);
commercePresentationCheck(str_contains($expiredHtml, 'Checkout expired') && str_contains($expiredHtml, 'Review saved basket'), 'Expired retail checkout is explicit and offers basket recovery.');
$confirmedHtml = renderCommerceView('composition-payment-result', ['state' => 'succeeded']);
commercePresentationCheck(str_contains($confirmedHtml, 'confirmed together') && str_contains($confirmedHtml, 'Only rented goods'), 'Verified composition result communicates atomic confirmation and rental-only returns.');

foreach (['payment-result', 'rental-payment-result', 'composition-payment-result'] as $resultView) {
    $cancelledHtml = renderCommerceView($resultView, ['state' => 'cancelled', 'rentalId' => 55]);
    commercePresentationCheck(str_contains($cancelledHtml, 'Checkout cancelled') && str_contains($cancelledHtml, 'No payment is confirmed'), 'Cancelled checkout has explicit copy without presenting payment success.');
}

echo "Commerce checkout presentation checks: {$checks} passed, 0 failed.\n";
