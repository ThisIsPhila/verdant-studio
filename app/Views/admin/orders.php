<header class="admin-page-heading">
    <div>
        <p>Administration</p>
        <h1>Retail orders</h1>
        <p>Review payment and fulfilment history.</p>
    </div>
</header>

<section class="admin-panel" aria-labelledby="order-list-title">
    <h2 id="order-list-title">Order records</h2>

    <?php if ($items === []): ?>
        <p>No retail orders are available.</p>
    <?php else: ?>
        <div class="admin-scroll-table" tabindex="0" role="region" aria-label="Retail order records">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Order</th>
                        <th scope="col">Customer</th>
                        <th scope="col">State</th>
                        <th scope="col">Total</th>
                        <th scope="col">Placed</th>
                        <th scope="col">Fulfilled</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <th scope="row">
                                <a href="/admin/order?id=<?= $e($item['order_id']) ?>">#<?= $e($item['order_id']) ?></a>
                                <?php if ($item['archived_at'] !== null): ?>
                                    <br><small>Archived</small>
                                <?php endif ?>
                            </th>
                            <td>
                                <?= $e($item['customer_name']) ?>
                                <br><small><?= $e($item['customer_email']) ?></small>
                            </td>
                            <td>
                                <span class="admin-status"><span aria-hidden="true"></span><?= $e($statusLabel($item['state'])) ?></span>
                            </td>
                            <td><?= $e($money((int) $item['total_minor'])) ?></td>
                            <td><?= $e($date($item['created_at'])) ?></td>
                            <td><?= $e($date($item['fulfilled_at'])) ?></td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>

    <nav class="admin-pagination" aria-label="Order pages">
        <p>Page <?= $e($page) ?></p>
        <div>
            <?php if ($page > 1): ?>
                <a href="/admin/orders?page=<?= $e($page - 1) ?>">Previous page</a>
            <?php endif ?>
            <?php if ($has_next): ?>
                <a href="/admin/orders?page=<?= $e($page + 1) ?>">Next page</a>
            <?php endif ?>
        </div>
    </nav>
</section>
