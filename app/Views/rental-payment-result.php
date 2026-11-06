<?php
$visionPage = true;
$visionComponentStyles = ['checkout'];
$copy = match ($state) {
    'cancelled' => ['Checkout cancelled', 'This checkout was cancelled. No payment is confirmed. Review your account before starting again.'],
    'succeeded' => ['Rental payment verified', 'Your sandbox payment is verified and the rental is confirmed.'],
    'failed' => ['Payment not completed', 'Paystack confirmed the payment failed. The rental hold has been released.'],
    'cancelled_paid' => ['Rental cancelled', 'This rental was cancelled after its sandbox payment was verified. Its capacity is released. No refund was processed.'],
    'reconciliation_required' => ['Payment needs checking', 'A final matching result is not available yet. Your requested dates remain held while the payment is checked.'],
    'expired' => ['Checkout expired', 'The prepared rental checkout expired before payment was confirmed. Review availability before starting a new reservation.'],
    'initialization_failed' => ['Checkout could not start', 'The sandbox checkout could not be started. No payment was confirmed. Review the rental in your account.'],
    'cannot_cancel' => ['Rental not cancelled', $message ?? 'This rental cannot be cancelled while a payment or issued items remain unresolved.'],
    'unavailable' => ['Rental payment unavailable', 'This rental or payment reference could not be found for your account.'],
    default => ['Sandbox checkout unavailable', 'No payment was confirmed. Check the local sandbox setup or try again later.'],
};
$checkoutProgressStep = 4;
?>
<div class="vision-container vision-checkout-page">
    <section class="vision-checkout-result" aria-labelledby="vision-payment-result-title">
        <p class="eyebrow">Rental update · Step 4 of 4</p>
        <h1 id="vision-payment-result-title"><?= $e($copy[0]) ?></h1>
        <p><?= $e($copy[1]) ?></p>
        <?php require __DIR__ . '/vision/checkout-progress.php'; ?>
        <p class="vision-muted">Only the server-verified payment status can confirm this rental.</p>
        <?php if (!empty($rentalId)): ?>
            <a class="btn-solid" href="/account/rental?id=<?= $e(rawurlencode((string) $rentalId)) ?>">View rental details</a>
        <?php else: ?>
            <a class="btn-solid" href="/account">View your rentals</a>
        <?php endif ?>
    </section>
</div>
