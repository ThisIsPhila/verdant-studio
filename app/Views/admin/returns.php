<header class="admin-page-heading">
    <div>
        <p>Administration · Sales and rentals</p>
        <h1>Returns</h1>
        <p>Manage returned rental assets separately from purchase returns.</p>
    </div>
    <a class="admin-view-link" href="/admin/rental-calendar">Rental calendar</a>
</header>

<section class="admin-panel" aria-labelledby="rental-returns-title">
    <div class="admin-panel-heading">
        <h2 id="rental-returns-title">Rental asset returns</h2>
        <p><?= $e($total) ?> issued <?= $total === 1 ? 'item' : 'items' ?> with outstanding returns</p>
    </div>
    <p>Open a rental to record the quantity physically received. Partially returned and archived reservations stay here until all issued items are returned.</p>
    <?php if ($items === []): ?>
        <p class="admin-empty" role="status">All issued rental items have been returned.</p>
    <?php else: ?>
    <div class="admin-scroll-table" role="region" aria-label="Outstanding rental returns" tabindex="0">
        <table class="admin-table">
            <thead><tr><th scope="col">Item</th><th scope="col">Customer</th><th scope="col">Last rental day</th><th scope="col">Issued</th><th scope="col">Received</th><th scope="col">Outstanding</th><th scope="col">Action</th></tr></thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <th scope="row"><?= $e($item['product_name_snapshot']) ?><br><small>Rental #<?= $e($item['rental_id']) ?><?= $item['archived_at'] !== null ? ' · Archived' : '' ?></small></th>
                    <td><?= $e($item['customer_name']) ?></td>
                    <td><?= $e($date($item['end_date_exclusive'], true)) ?><?php if ($item['end_date_exclusive'] <= $today): ?><br><strong>Overdue</strong><?php endif ?></td>
                    <td><?= $e($item['issued_quantity']) ?></td>
                    <td><?= $e($item['returned_quantity']) ?></td>
                    <td><?= $e($item['outstanding_quantity']) ?></td>
                    <td><a href="/admin/rental?id=<?= $e($item['rental_id']) ?>&amp;origin=returns#rental-item-<?= $e($item['rental_item_id']) ?>">Review and record return</a></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php endif ?>
    <?php if ($pages > 1): ?>
    <nav class="admin-pagination" aria-label="Return pages">
        <p>Page <?= $e($page) ?> of <?= $e($pages) ?> · <?= $e($total) ?> items</p>
        <div>
            <?php if ($page > 1): ?><a href="/admin/returns?page=<?= $e($page - 1) ?>">Previous</a><?php endif ?>
            <?php if ($page < $pages): ?><a href="/admin/returns?page=<?= $e($page + 1) ?>">Next</a><?php endif ?>
        </div>
    </nav>
    <?php endif ?>
</section>

<section class="admin-panel" aria-labelledby="purchase-returns-title">
    <div class="admin-panel-heading"><h2 id="purchase-returns-title">Purchase returns</h2><span class="admin-status is-muted">Coming soon</span></div>
    <p>Order return requests and refund processing will be managed here. For now, customer messages are available in the enquiry inbox and the original purchase is available under Orders.</p>
    <div class="admin-record-status"><a class="admin-view-link" href="/admin/orders">View orders</a><a class="admin-view-link" href="/admin/enquiries">View enquiries</a></div>
</section>
