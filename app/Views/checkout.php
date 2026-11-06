<?php
$visionPage = true;
$visionComponentStyles = ['checkout'];
$awaitingStart = $order['state'] === 'pending_payment';
$checkoutProgressStep = $awaitingStart ? 2 : 4;
$hero = [
    'image' => '/assets/vision/images/hero-basket.jpg',
    'title' => 'Your checkout',
    'body' => 'Plants and floral styling for brighter spaces.',
    'eyebrow' => 'Order ' . $order['order_id'] . ' · prepared',
];
require __DIR__ . '/vision/page-hero.php';
?>
<div class="vision-container vision-checkout-page">
    <header class="vision-checkout-heading">
        <p class="vision-muted">Your prepared order is ready. Review the saved item and price snapshots before continuing.</p>
    </header>
    <?php require __DIR__ . '/vision/checkout-progress.php'; ?>
    <div class="vision-checkout-layout">
        <div class="vision-checkout-main">
            <?php
            $checkoutOverviewLabel = 'Prepared order';
            $checkoutStatusHeading = 'Order status';
            $checkoutState = $order['state'];
            require __DIR__ . '/vision/checkout-overview.php';
            ?>
        </div>
        <aside class="vision-checkout-panel" aria-labelledby="vision-checkout-action-title">
            <?php require __DIR__ . '/vision/checkout-total.php'; ?>
            <?php if ($awaitingStart && $sandboxEnabled): ?>
                <p class="eyebrow">Step 3 · Start TEST payment</p>
                <h2 id="vision-checkout-action-title">Continue to payment</h2>
                <form class="vision-checkout-action" action="/payment/start" method="post">
                    <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
                    <button class="btn-solid" type="submit">Continue to Paystack sandbox</button>
                    <p class="vision-muted">TEST mode only. The server checks the payment result before confirming this order.</p>
                </form>
            <?php elseif ($awaitingStart): ?>
                <p class="eyebrow">Payment setup</p>
                <h2 id="vision-checkout-action-title">Sandbox temporarily unavailable</h2>
                <p role="status">No payment was taken. Your prepared items remain held while this is resolved.</p>
            <?php else: ?>
                <p class="eyebrow">Step 4 · Server verification</p>
                <h2 id="vision-checkout-action-title">Payment status is being checked</h2>
                <p role="status">A browser return does not confirm payment. Review the server status before starting another checkout.</p>
                <a class="btn-outline" href="/account">View your orders</a>
            <?php endif ?>
            <a class="vision-checkout-secondary" href="/account">View your account</a>
        </aside>
    </div>
</div>
