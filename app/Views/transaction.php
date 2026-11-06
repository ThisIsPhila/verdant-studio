<?php
$visionPage = true;
$visionComponentStyles = ['customer-records'];
$recordId = $kind === 'order' ? $record['order_id'] : $record['rental_id'];
$unissued = $kind === 'rental' && $record['state'] === 'confirmed'
    && array_reduce($record['items'], static fn (bool $all, array $item): bool => $all && (int) $item['issued_quantity'] === 0, true);
$canCancelRental = $kind === 'rental'
    && (($record['state'] === 'pending_payment' && empty($record['payments'])) || $unissued);
$issued = $kind === 'rental' ? array_sum(array_map(static fn (array $item): int => (int) $item['issued_quantity'], $record['items'])) : 0;
$returned = $kind === 'rental' ? array_sum(array_map(static fn (array $item): int => (int) $item['returned_quantity'], $record['items'])) : 0;
?>
<div class="vision-container customer-record-page" aria-labelledby="customer-record-title">
    <nav class="customer-back" aria-label="Breadcrumb"><a href="/account">My account</a><span aria-hidden="true">/</span><span><?= $e($title) ?></span></nav>
    <header class="customer-record-header">
        <div>
            <p class="eyebrow"><?= $kind === 'order' ? 'Purchase record' : 'Rental record' ?></p>
            <h1 id="customer-record-title"><?= $kind === 'order' ? 'Order' : 'Rental' ?> #<?= $e($recordId) ?></h1>
            <?php if ($kind === 'rental' && $record['items'] !== []): ?><p class="customer-record-subtitle">Rental period · <?= $e($date($record['items'][0]['start_date'])) ?> – <?= $e($date($record['items'][0]['end_date_exclusive'], true)) ?></p><?php endif ?>
        </div>
        <p class="customer-record-status"><span class="customer-status-dot<?= $record['state'] === 'cancelled' ? ' is-muted' : '' ?>" aria-hidden="true"></span><?= $e($statusLabel($record['state'])) ?></p>
    </header>

    <?php if ($kind === 'rental' && $record['state'] === 'cancelled' && array_filter($record['payments'], static fn (array $payment): bool => $payment['state'] === 'succeeded')): ?>
        <p class="customer-notice" role="status">This rental was cancelled after its sandbox payment was verified. Its capacity is released. No refund was processed.</p>
    <?php endif ?>

    <div class="customer-record-layout">
        <div class="customer-record-main">
            <section class="customer-record-card" aria-labelledby="record-items-title">
                <div class="customer-card-heading"><div><p class="eyebrow">Your items</p><h2 id="record-items-title"><?= $kind === 'order' ? 'Order summary' : 'Rental summary' ?></h2></div><span class="customer-card-note"><?= count($record['items']) ?> line<?= count($record['items']) === 1 ? '' : 's' ?></span></div>
                <div class="customer-table-wrap" tabindex="0" role="region" aria-label="<?= $e($title) ?> items">
                    <table class="customer-record-table">
                        <caption class="visually-hidden"><?= $e($title) ?> items</caption>
                        <thead><tr><th scope="col">Product</th><th scope="col">Quantity</th><th scope="col"><?= $kind === 'order' ? 'Unit price' : 'Daily rate' ?></th><?php if ($kind === 'rental'): ?><th scope="col">Dates (inclusive)</th><th scope="col">Issued</th><th scope="col">Returned</th><th scope="col">Outstanding</th><?php endif ?><th scope="col">Total</th></tr></thead>
                        <tbody><?php foreach ($record['items'] as $item): ?><tr>
                            <th scope="row"><?= $e($item['product_name_snapshot']) ?></th>
                            <td data-label="Quantity"><?= $e($item['quantity']) ?></td>
                            <td data-label="<?= $kind === 'order' ? 'Unit price' : 'Daily rate' ?>"><?= $e($money($item[$kind === 'order' ? 'unit_price_minor' : 'daily_rate_minor'])) ?></td>
                            <?php if ($kind === 'rental'): ?><td data-label="Dates"><?= $e($date($item['start_date'])) ?> – <?= $e($date($item['end_date_exclusive'], true)) ?></td><td data-label="Issued"><?= $e($item['issued_quantity']) ?></td><td data-label="Returned"><?= $e($item['returned_quantity']) ?></td><td data-label="Outstanding"><?= $e((int) $item['issued_quantity'] - (int) $item['returned_quantity']) ?></td><?php endif ?>
                            <td data-label="Total"><strong><?= $e($money($item['total_minor'])) ?></strong></td>
                        </tr><?php endforeach ?></tbody>
                    </table>
                </div>
                <div class="customer-total-row"><span><?= $kind === 'rental' ? 'Rental total' : 'Order total' ?></span><strong><?= $e($money($record['total_minor'])) ?></strong></div>
            </section>

            <?php if ($kind === 'rental'): ?>
                <section class="customer-record-card" aria-labelledby="rental-progress-title">
                    <div class="customer-card-heading"><div><p class="eyebrow">Rental progress</p><h2 id="rental-progress-title">Issue and return history</h2></div></div>
                    <dl class="customer-rental-progress"><div><dt>Issued</dt><dd><?= $e($issued) ?> of <?= $e(array_sum(array_column($record['items'], 'quantity'))) ?> items</dd></div><div><dt>Returned</dt><dd><?= $e($returned) ?> items</dd></div><div><dt>Outstanding</dt><dd><?= $e(max(0, $issued - $returned)) ?> items</dd></div></dl>
                </section>
            <?php endif ?>
        </div>

        <aside class="customer-record-aside">
            <?php if ($kind === 'rental' && $canCancelRental): ?>
                <section class="customer-cancel-card" aria-labelledby="cancel-rental-title">
                    <p class="eyebrow">Need to change plans?</p><h2 id="cancel-rental-title">Cancel this reservation</h2>
                    <p><?php if ($record['state'] === 'pending_payment'): ?>No payment attempt has been made, so cancelling releases the held dates.<?php else: ?>Cancelling releases the dates. Any payment is not refunded by this action.<?php endif ?></p>
                    <form class="customer-record-action" action="/rental/cancel" method="post"><input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>"><input type="hidden" name="rental_id" value="<?= $e($record['rental_id']) ?>"><button class="button button--secondary" type="submit">Cancel reservation</button></form>
                </section>
            <?php endif ?>

            <section class="customer-record-card" aria-labelledby="payment-history-title">
                <p class="eyebrow">Transactions</p><h2 id="payment-history-title">Payment history</h2>
                <?php if (!$record['payments']): ?><p class="customer-muted">No payment attempts recorded.</p><?php else: ?>
                    <ul class="customer-payment-list"><?php foreach ($record['payments'] as $payment): ?><li><div><strong><?= $e($statusLabel($payment['state'])) ?></strong><span><?= $e($payment['currency']) ?> <?= $e($money($payment['amount_minor'])) ?></span></div>
                        <?php if ($kind === 'order' && ($payment['provider_code'] ?? null) === 'paystack' && in_array($payment['state'], ['pending', 'reconciliation_required'], true)): ?><a class="customer-record-link" href="/payment/return?reference=<?= $e(rawurlencode($payment['provider_reference'])) ?>">Check sandbox payment status</a>
                        <?php elseif ($kind === 'rental' && ($payment['provider_code'] ?? null) === 'paystack' && in_array($payment['state'], ['pending', 'reconciliation_required'], true)): ?><a class="customer-record-link" href="/rental/payment/return?reference=<?= $e(rawurlencode($payment['provider_reference'])) ?>">Check rental payment status</a><?php endif ?>
                    </li><?php endforeach ?></ul><?php endif ?>
            </section>
        </aside>
    </div>
    <p class="customer-record-footer"><a class="customer-record-link" href="/account">← Back to my history</a></p>
</div>
