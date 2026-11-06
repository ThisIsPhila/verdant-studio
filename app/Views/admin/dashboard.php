<div class="admin-page-heading">
    <div>
        <p>Dashboard</p>
        <h1>Store overview</h1>
        <p>Current catalogue, customers, orders and rentals.</p>
    </div>
    <a class="admin-view-link" href="<?= $e(\Verdant\Config\TrustedOrigins::store()) ?>">View store ↗</a>
</div>

<section class="admin-stats" aria-label="Store summary">
    <article class="admin-stat">
        <span>Active products</span>
        <strong><?= $e($counts['product_count']) ?></strong>
        <a href="/admin/products">View products</a>
    </article>
    <article class="admin-stat">
        <span>Active customers</span>
        <strong><?= $e($counts['customer_count']) ?></strong>
        <a href="/admin/customers">View customers</a>
    </article>
    <article class="admin-stat">
        <span>Retail orders</span>
        <strong><?= $e($counts['order_count']) ?></strong>
        <a href="/admin/orders">View orders</a>
    </article>
    <article class="admin-stat">
        <span>Rentals</span>
        <strong><?= $e($counts['rental_count']) ?></strong>
        <a href="/admin/rentals">View rentals</a>
    </article>
</section>

<div class="admin-overview-grid">
    <section class="admin-panel">
        <div class="admin-panel-heading">
            <h2>Recent orders</h2>
            <a href="/admin/orders">All orders</a>
        </div>
        <?php if ($recentOrders === []): ?>
            <p class="admin-empty">No retail orders yet.</p>
        <?php else: ?>
            <div class="admin-table-region" role="region" aria-label="Recent orders" tabindex="0">
                <table class="admin-table">
                    <thead><tr><th scope="col">Order</th><th scope="col">Customer</th><th scope="col">Status</th><th scope="col">Date</th><th scope="col">Total</th></tr></thead>
                    <tbody>
                        <?php foreach ($recentOrders as $order): ?>
                            <tr>
                                <td><a href="/admin/order?id=<?= $e($order['order_id']) ?>">#<?= $e($order['order_id']) ?></a></td>
                                <td><?= $e($order['customer_name']) ?></td>
                                <td><span class="admin-status"><?= $e($statusLabel($order['state'])) ?></span></td>
                                <td><?= $e($date($order['created_at'])) ?></td>
                                <td><?= $e($money((int) $order['total_minor'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="admin-panel">
        <div class="admin-panel-heading">
            <h2>Recent rentals</h2>
            <a href="/admin/rentals">All rentals</a>
        </div>
        <?php if ($recentRentals === []): ?>
            <p class="admin-empty">No rentals yet.</p>
        <?php else: ?>
            <div class="admin-table-region" role="region" aria-label="Recent rentals" tabindex="0">
                <table class="admin-table">
                    <thead><tr><th scope="col">Rental</th><th scope="col">Customer</th><th scope="col">Status</th><th scope="col">Dates</th><th scope="col">Created</th></tr></thead>
                    <tbody>
                        <?php foreach ($recentRentals as $rental): ?>
                            <tr>
                                <td><a href="/admin/rental?id=<?= $e($rental['rental_id']) ?>">#<?= $e($rental['rental_id']) ?></a></td>
                                <td><?= $e($rental['customer_name']) ?></td>
                                <td><span class="admin-status"><?= $e($statusLabel($rental['state'])) ?></span></td>
                                <td><?php if ($rental['start_date'] !== null && $rental['end_date_exclusive'] !== null): ?><?= $e($date($rental['start_date'])) ?> – <?= $e($date($rental['end_date_exclusive'], true)) ?><?php else: ?>—<?php endif; ?></td>
                                <td><?= $e($date($rental['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($counts['outstanding_rental_count'] > 0): ?>
    <section class="admin-panel admin-attention-panel">
        <div class="admin-panel-heading">
            <div>
                <h2>Outstanding returns</h2>
                <p><?= $e($counts['outstanding_rental_count']) ?> active rental<?= (int) $counts['outstanding_rental_count'] === 1 ? '' : 's' ?> with issued items still to return</p>
            </div>
            <a href="/admin/rentals">Rental operations</a>
        </div>
        <?php if ($outstandingReturns === []): ?>
            <p class="admin-empty">No outstanding issued items.</p>
        <?php else: ?>
            <ul class="admin-attention-list">
                <?php foreach ($outstandingReturns as $rental): ?>
                    <li>
                        <a href="/admin/rental?id=<?= $e($rental['rental_id']) ?>">
                            <span>Rental #<?= $e($rental['rental_id']) ?> · <?= $e($rental['customer_name']) ?></span>
                            <span><?= $e($rental['outstanding_quantity']) ?> item<?= (int) $rental['outstanding_quantity'] === 1 ? '' : 's' ?> · due <?= $e($date($rental['end_date_exclusive'], true)) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>
