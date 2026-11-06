<?php
$recordId = $record[$history['kind'] . '_id'];
$recordImage = \Verdant\Storefront\VisionLandingPresenter::image(['name' => $record['first_product_name'] ?? '']);
?>
<article class="account-record">
    <?php if ($recordImage !== null): ?><img src="<?= $e($recordImage) ?>" alt="<?= $e($record['first_product_name']) ?>" loading="lazy"><?php endif ?>
    <div class="vision-record-details"><h3><?= $history['kind'] === 'order' ? 'Order' : 'Rental' ?> <?= $e($recordId) ?></h3>
        <?php if ($history['kind'] === 'order'): ?><p class="vision-muted"><?= $e($date($record['created_at'])) ?></p><?php else: ?><p class="vision-muted">Rental period</p><p><?= $e($date($record['start_date'] ?? null)) ?> – <?= $e($date($record['end_date_exclusive'] ?? null, true)) ?></p><?php endif ?>
        <p class="font-display text-xl"><?= $e($money($record['total_minor'])) ?></p>
    </div>
    <div class="vision-record-actions"><p class="vision-record-status"><?= $e($statusLabel($record['state'])) ?></p><a class="btn-outline" href="/account/<?= $e($history['kind']) ?>?id=<?= $e($recordId) ?>" aria-label="View <?= $e($history['kind']) ?> <?= $e($recordId) ?>">View details</a></div>
</article>
