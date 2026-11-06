<?php
$visionPage = true;
$visionComponentStyles = ['checkout'];
$awaitingStart = $rental['state'] === 'pending_payment';
$checkoutProgressStep = $awaitingStart ? 2 : 4;
$hero = [
    'image' => '/assets/vision/images/hero-basket.jpg',
    'title' => 'Your checkout',
    'body' => 'Plants and floral styling for brighter spaces.',
    'eyebrow' => 'Rental ' . $rental['rental_id'] . ' · prepared',
];
require __DIR__ . '/vision/page-hero.php';
?>
<div class="vision-container vision-checkout-page">
    <p class="vision-checkout-breadcrumb"><a href="/account">My account</a> / Rental checkout</p>
    <header class="vision-checkout-heading">
        <p class="vision-muted">Your requested dates and capacity are held while payment is prepared.</p>
    </header>
    <?php require __DIR__ . '/vision/checkout-progress.php'; ?>
    <div class="vision-checkout-layout">
        <div class="vision-checkout-main">
            <?php
            $checkoutOverviewLabel = 'Prepared rental';
            $checkoutStatusHeading = 'Reservation status';
            $checkoutState = $rental['state'];
            require __DIR__ . '/vision/checkout-overview.php';
            ?>
        </div>
        <aside class="vision-checkout-panel" aria-labelledby="vision-checkout-action-title">
            <?php require __DIR__ . '/vision/checkout-total.php'; ?>
            <?php if ($awaitingStart && $sandboxEnabled): ?>
                <p class="eyebrow">Step 3 · Start TEST payment</p>
                <h2 id="vision-checkout-action-title">Continue to payment</h2>
                <form class="vision-checkout-action" action="/rental/payment/start" method="post">
                    <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
                    <button class="btn-solid" type="submit">Continue to Paystack sandbox</button>
                    <p class="vision-muted">TEST mode only. The server checks the payment result before confirming this rental.</p>
                </form>
            <?php elseif ($awaitingStart): ?>
                <p class="eyebrow">Payment setup</p>
                <h2 id="vision-checkout-action-title">Sandbox temporarily unavailable</h2>
                <p role="status">No payment was taken. Your requested dates remain held while this is resolved.</p>
            <?php else: ?>
                <p class="eyebrow">Step 4 · Server verification</p>
                <h2 id="vision-checkout-action-title">Payment status is being checked</h2>
                <p role="status">A browser return does not confirm this rental. Review its server status before starting another reservation.</p>
                <a class="btn-outline" href="/account/rental?id=<?= $e(rawurlencode((string) $rental['rental_id'])) ?>">Review rental status</a>
            <?php endif ?>
            <a class="vision-checkout-secondary" href="/account/rental?id=<?= $e(rawurlencode((string) $rental['rental_id'])) ?>">View this rental</a>
        </aside>
    </div>
</div>
