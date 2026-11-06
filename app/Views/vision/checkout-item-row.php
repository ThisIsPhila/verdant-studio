<?php
$checkoutRowImage = \Verdant\Storefront\VisionLandingPresenter::image(['name' => $summaryItem['label'] ?? '']);
?>
<li class="vision-checkout-item<?= $checkoutRowImage !== null ? ' vision-checkout-item--image' : '' ?>">
    <?php if ($checkoutRowImage !== null): ?>
        <img src="<?= $e($checkoutRowImage) ?>" alt="<?= $e($summaryItem['label'] ?? '') ?>" loading="lazy">
    <?php endif ?>
    <div class="vision-checkout-item__copy">
        <strong><?= $e($summaryItem['label'] ?? '') ?></strong>
        <?php if (!empty($summaryItem['detail'])): ?><p><?= $e($summaryItem['detail']) ?></p><?php endif ?>
        <?php if (!empty($summaryItem['quantity'])): ?><p><?= $e($summaryItem['quantity']) ?></p><?php endif ?>
    </div>
    <?php if (isset($summaryItem['amount'])): ?><strong class="vision-checkout-item__amount"><?= $e($summaryItem['amount']) ?></strong><?php endif ?>
</li>
