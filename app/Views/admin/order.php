<a class="admin-view-link" href="/admin/orders">Back to orders</a>

<header class="admin-page-heading">
    <div>
        <p>Administration · Retail order</p>
        <h1>Order #<?= $e($record['order_id']) ?></h1>
    </div>
    <div class="admin-record-status" aria-label="Order status">
        <span class="admin-status"><span aria-hidden="true"></span><?= $e($statusLabel($record['state'])) ?></span>
        <?php if ($record['archived_at'] !== null): ?>
            <span class="admin-status is-muted"><span aria-hidden="true"></span>Archived</span>
        <?php endif ?>
    </div>
</header>

<div class="admin-detail-grid">
    <div>
        <section class="admin-panel" aria-labelledby="order-summary-title">
            <h2 id="order-summary-title">Order summary</h2>
            <dl class="admin-facts">
                <div>
                    <dt>Customer</dt>
                    <dd><?= $e($record['customer_name']) ?> · <?= $e($record['customer_email']) ?></dd>
                </div>
                <div>
                    <dt>State</dt>
                    <dd><?= $e($statusLabel($record['state'])) ?></dd>
                </div>
                <div>
                    <dt>Placed</dt>
                    <dd><?= $e($date($record['created_at'])) ?></dd>
                </div>
                <div>
                    <dt>Total</dt>
                    <dd><?= $e($money((int) $record['total_minor'])) ?></dd>
                </div>
                <div>
                    <dt>Fulfilled</dt>
                    <dd><?= $e($date($record['fulfilled_at'])) ?></dd>
                </div>
                <div>
                    <dt>History</dt>
                    <dd><?= $record['archived_at'] === null ? 'Current' : 'Archived ' . $e($date($record['archived_at'])) ?></dd>
                </div>
            </dl>
        </section>

        <section class="admin-panel" aria-labelledby="order-items-title">
            <h2 id="order-items-title">Items</h2>
            <div class="admin-scroll-table" tabindex="0" role="region" aria-label="Order line items">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th scope="col">Item</th>
                            <th scope="col">SKU</th>
                            <th scope="col">Quantity</th>
                            <th scope="col">Unit price</th>
                            <th scope="col">Line total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($record['items'] as $item): ?>
                            <tr>
                                <th scope="row"><?= $e($item['product_name_snapshot']) ?></th>
                                <td><?= $e($item['sku_snapshot']) ?></td>
                                <td><?= $e($item['quantity']) ?></td>
                                <td><?= $e($money((int) $item['unit_price_minor'])) ?></td>
                                <td><?= $e($money((int) $item['total_minor'])) ?></td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <div class="admin-total">
                <span>Total</span>
                <strong><?= $e($money((int) $record['total_minor'])) ?></strong>
            </div>
        </section>

        <section class="admin-panel" aria-labelledby="order-payments-title">
            <h2 id="order-payments-title">Payment record</h2>
            <?php if ($record['payments'] === []): ?>
                <p>No payment attempt is recorded.</p>
            <?php else: ?>
                <div class="admin-scroll-table" tabindex="0" role="region" aria-label="Payment attempts">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th scope="col">State</th>
                                <th scope="col">Amount</th>
                                <th scope="col">Currency</th>
                                <th scope="col">Recorded</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($record['payments'] as $payment): ?>
                                <tr>
                                    <td><?= $e($statusLabel($payment['state'])) ?></td>
                                    <td><?= $e($money((int) $payment['amount_minor'])) ?></td>
                                    <td><?= $e($payment['currency']) ?></td>
                                    <td><?= $e($date($payment['created_at'])) ?></td>
                                </tr>
                            <?php endforeach ?>
                        </tbody>
                    </table>
                </div>
            <?php endif ?>
        </section>
    </div>

    <section class="admin-panel" aria-labelledby="order-actions-title">
        <h2 id="order-actions-title">Permitted actions</h2>
        <p>Payment records and order snapshots are managed by the checkout lifecycle.</p>
        <div class="admin-actions">
            <?php if ($record['state'] === 'confirmed' && $record['fulfilled_at'] === null): ?>
                <form action="/admin/order/fulfil" method="post">
                    <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
                    <input type="hidden" name="order_id" value="<?= $e($record['order_id']) ?>">
                    <button type="submit">Mark order fulfilled</button>
                </form>
            <?php endif ?>

            <?php if ($record['state'] === 'pending_payment'): ?>
                <form action="/admin/order/cancel" method="post">
                    <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
                    <input type="hidden" name="order_id" value="<?= $e($record['order_id']) ?>">
                    <button type="submit">Cancel unstarted order</button>
                </form>
            <?php endif ?>

            <form action="/admin/order/archive" method="post">
                <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
                <input type="hidden" name="order_id" value="<?= $e($record['order_id']) ?>">
                <input type="hidden" name="archived" value="<?= $record['archived_at'] === null ? '1' : '0' ?>">
                <button type="submit"><?= $record['archived_at'] === null ? 'Archive history record' : 'Restore history record' ?></button>
            </form>
        </div>
    </section>
</div>
