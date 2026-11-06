<?php
/** Read-only overview for an already prepared transaction. The controller supplies all labels and totals. */
?>
<section class="vision-checkout-overview" aria-label="<?= $e($checkoutOverviewLabel ?? 'Prepared checkout') ?>">
    <div class="vision-checkout-overview__status">
        <span><?= $e($checkoutStatusHeading ?? 'Status') ?></span>
        <strong><?= $e($statusLabel($checkoutState ?? '')) ?></strong>
    </div>
    <h2><?= $e($summary['title']) ?></h2>
    <ul class="vision-checkout-items" aria-label="<?= $e($summary['title']) ?>">
        <?php foreach ($summary['items'] as $summaryItem): ?>
            <?php require __DIR__ . '/checkout-item-row.php'; ?>
        <?php endforeach ?>
    </ul>
</section>
