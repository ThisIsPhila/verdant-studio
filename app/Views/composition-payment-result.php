<?php
$visionPage = true;
$visionComponentStyles = ['checkout'];
$copy = match ($state) {
    'cancelled' => ['Checkout cancelled', 'This checkout was cancelled. No payment is confirmed. Review your account before starting again.'],
    'succeeded' => ['Payment verified', 'Your purchased and rented components were confirmed together. Only rented goods need to be returned.'],
    'failed', 'initialization_failed' => ['Payment not completed', 'The test payment did not complete. The arrangement’s sale and rental reservations have been released. You can prepare a new arrangement.'],
    'reconciliation_required' => ['Payment needs checking', 'The payment needs verification or capacity review. The system will not partially confirm this arrangement. Recheck its status before trying another checkout.'],
    'expired' => ['Checkout expired', 'This prepared arrangement expired before payment was confirmed. Check its history and prepare a fresh checkout if the components are still available.'],
    'sandbox_unavailable' => ['Payment unavailable', 'The test checkout is unavailable. Review this arrangement before trying again.'],
    default => ['Payment unavailable', 'This arrangement payment is unavailable for this account. Open your arrangement history to continue.'],
};
$checkoutProgressStep = 4;
?>
<div class="vision-container vision-checkout-page">
    <section class="vision-checkout-result" aria-labelledby="vision-payment-result-title">
        <p class="eyebrow">Arrangement · sandbox payment</p>
        <h1 id="vision-payment-result-title"><?= $e($copy[0]) ?></h1>
        <p><?= $e($copy[1]) ?></p>
        <?php require __DIR__ . '/vision/checkout-progress.php'; ?>
        <p class="vision-muted">Only the server-verified payment result can confirm this arrangement.</p>
        <div class="vision-checkout-result__actions">
            <a class="btn-solid" href="/compositions/saved">Arrangement history</a>
            <a class="btn-outline" href="/compositions">Build an arrangement</a>
        </div>
    </section>
</div>
