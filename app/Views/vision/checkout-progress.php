<?php
$progressStep = (int) ($checkoutProgressStep ?? 2);
$progressLabel = $progressStep === 2 ? 'Prepared' : 'Server verification';
?>
<nav class="vision-checkout-progress" aria-label="Checkout progress">
    <ol>
        <li <?= $progressStep === 1 ? 'aria-current="step"' : '' ?>>Basket</li>
        <li <?= $progressStep === 2 ? 'aria-current="step"' : '' ?>>Prepared</li>
        <li <?= $progressStep === 3 ? 'aria-current="step"' : '' ?>>Start TEST</li>
        <li <?= $progressStep === 4 ? 'aria-current="step"' : '' ?>>Server verification</li>
    </ol>
    <p class="vision-checkout-progress__current">Step <?= $e($progressStep) ?> of 4 · <?= $e($progressLabel) ?></p>
</nav>
