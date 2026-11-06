<?php
$visionPage = true;
$visionComponentStyles = ['checkout'];
$copy = match ($state) {
    'cancelled' => ['Checkout cancelled', 'This checkout was cancelled. No payment is confirmed. Review your account before starting again.'],
    'succeeded' => ['Payment verified', 'Your sandbox payment is verified and the order is confirmed.'],
    'failed' => ['Payment not completed', 'Paystack confirmed the payment failed. The order is cancelled and the held stock is released.'],
    'initialization_failed' => ['Checkout could not start', 'Paystack did not accept this sandbox checkout. The order is cancelled and its stock is released. Your basket is saved; check your account email and start a new checkout.'],
    'reconciliation_required' => ['Payment needs checking', 'Paystack has not returned a final matching result. Your stock remains held until the status is checked.'],
    'expired' => ['Checkout expired', 'The prepared checkout expired before payment was confirmed. No order is confirmed; review your basket to begin again.'],
    'unavailable' => ['Payment unavailable', 'This order or payment reference could not be found for your account.'],
    default => ['Sandbox checkout unavailable', 'No payment was confirmed. Check the sandbox setup or try again later.'],
};
$checkoutProgressStep = 4;
?>
<div class="vision-container vision-checkout-page">
    <section class="vision-checkout-result" aria-labelledby="vision-payment-result-title">
        <p class="eyebrow">Order update · Step 4 of 4</p>
        <h1 id="vision-payment-result-title"><?= $e($copy[0]) ?></h1>
        <p><?= $e($copy[1]) ?></p>
        <?php require __DIR__ . '/vision/checkout-progress.php'; ?>
        <p class="vision-muted">Only the server-verified payment status can confirm this order.</p>
        <div class="vision-checkout-result__actions">
            <a class="btn-solid" href="/account">View your orders</a>
            <?php if (in_array($state, ['failed', 'initialization_failed', 'expired', 'cancelled'], true)): ?>
                <a class="btn-outline" href="/cart">Review saved basket</a>
            <?php endif ?>
        </div>
    </section>
</div>
