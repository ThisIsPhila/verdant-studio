<?php
$visionPage = true;
$visionComponentStyles = ['checkout'];
$awaitingStart = $checkout['state'] === 'pending_payment';
$checkoutProgressStep = $awaitingStart ? 2 : 4;
$hero = [
    'image' => '/assets/vision/images/hero-basket.jpg',
    'title' => 'Your checkout',
    'body' => 'Plants and floral styling for brighter spaces.',
    'eyebrow' => 'Arrangement checkout · ' . $checkout['composition_checkout_id'],
];
require __DIR__ . '/vision/page-hero.php';
?>
<div class="vision-container vision-checkout-page">
    <header class="vision-checkout-heading">
        <h2><?= $e($checkout['definition_name_snapshot']) ?></h2>
        <?php if ($checkout['state'] === 'pending_payment'): ?>
            <p class="vision-muted">Sale and dated rental components are held together while payment is prepared.</p>
        <?php elseif ($checkout['state'] === 'confirmed'): ?>
            <p class="vision-muted">Your purchased and rented components were confirmed together. Only rented components need to be returned.</p>
        <?php elseif ($checkout['state'] === 'cancelled'): ?>
            <p class="vision-muted">This arrangement checkout is cancelled. Its saved component snapshots remain in your history.</p>
        <?php else: ?>
            <p class="vision-muted">This arrangement needs payment or capacity review. Check its server status before trying another checkout.</p>
        <?php endif ?>
    </header>
    <?php require __DIR__ . '/vision/checkout-progress.php'; ?>
    <div class="vision-checkout-layout">
        <div class="vision-checkout-main">
            <?php
            $checkoutOverviewLabel = 'Prepared arrangement';
            $checkoutStatusHeading = 'Preparation status';
            $checkoutState = $checkout['state'];
            require __DIR__ . '/vision/checkout-overview.php';
            ?>
            <?php if ($checkout['state'] === 'confirmed'): ?>
                <div class="vision-checkout-links">
                    <?php if ($checkout['order_role'] === 'sale'): ?><a href="/account/order?id=<?= $e($checkout['order_id']) ?>">View purchased order</a><?php endif ?>
                    <?php if ($checkout['rental_id'] !== null): ?><a href="/account/rental?id=<?= $e($checkout['rental_id']) ?>">View rental and returns</a><?php endif ?>
                </div>
            <?php endif ?>
        </div>
        <aside class="vision-checkout-panel" aria-labelledby="vision-checkout-action-title">
            <?php require __DIR__ . '/vision/checkout-total.php'; ?>
            <?php if ($awaitingStart): ?>
                <p class="eyebrow">Step 3 · Start TEST payment</p>
                <h2 id="vision-checkout-action-title">One payment for the arrangement</h2>
                <?php if ($checkout['payment_reference'] !== null): ?>
                    <p class="vision-muted">A sandbox attempt already exists. Reference: <?= $e($checkout['payment_reference']) ?> · Attempt: <?= $e($checkout['payment_state'] ?? 'unknown') ?>.</p>
                <?php else: ?>
                    <p class="vision-muted">One sandbox payment covers the sale and rental components together.</p>
                <?php endif ?>
                <form class="vision-checkout-action" method="post" action="/compositions/payment/start">
                    <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
                    <input type="hidden" name="composition_checkout_id" value="<?= $e($checkout['composition_checkout_id']) ?>">
                    <button class="btn-solid" type="submit"><?= $checkout['payment_reference'] !== null ? 'Continue sandbox payment' : 'Pay for arrangement in TEST' ?></button>
                </form>
                <?php if ($checkout['payment_reference'] === null): ?>
                    <form class="vision-checkout-action" method="post" action="/compositions/cancel">
                        <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
                        <input type="hidden" name="composition_checkout_id" value="<?= $e($checkout['composition_checkout_id']) ?>">
                        <button class="btn-outline" type="submit">Cancel arrangement checkout</button>
                    </form>
                <?php endif ?>
            <?php elseif ($checkout['payment_reference'] !== null): ?>
                <p class="eyebrow">Step 4 · Server verification</p>
                <h2 id="vision-checkout-action-title">Payment status is being checked</h2>
                <p role="status">Sandbox attempt <?= $e($checkout['payment_state'] ?? 'unknown') ?> · reference <?= $e($checkout['payment_reference']) ?>. The browser return is not confirmation; the server verifies both components together.</p>
                <?php if ($checkout['state'] === 'reconciliation_required'): ?><a class="btn-outline" href="/payment/return?reference=<?= $e(rawurlencode($checkout['payment_reference'])) ?>">Check sandbox payment status</a><?php endif ?>
            <?php else: ?>
                <p class="eyebrow">Arrangement status</p>
                <h2 id="vision-checkout-action-title">Review your arrangement</h2>
                <p class="vision-muted">Your arrangement history shows its saved checkout and current server status.</p>
            <?php endif ?>
            <a class="vision-checkout-secondary" href="/compositions/saved">Arrangement history</a>
            <a class="vision-checkout-secondary" href="/compositions">Build another arrangement</a>
        </aside>
    </div>
</div>
