<header class="admin-page-heading">
    <div>
        <p>Administration · People</p>
        <h1>Customers</h1>
        <p>Review customer contact details, account status and transaction history.</p>
    </div>
</header>

<section class="admin-panel" aria-labelledby="customer-list-title">
    <div class="admin-panel-heading">
        <h2 id="customer-list-title">Customer records</h2>
        <p><?= $e(count($items)) ?> shown on this page</p>
    </div>

    <?php if ($items === []): ?>
        <p class="admin-empty" role="status">No customer records are available.</p>
    <?php else: ?>
        <div class="admin-scroll-table" tabindex="0" role="region" aria-label="Customer records">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Customer</th>
                        <th scope="col">Email</th>
                        <th scope="col">Phone</th>
                        <th scope="col">Account</th>
                        <th scope="col">Joined</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <th scope="row">
                                <a href="/admin/customer?id=<?= $e($item['customer_id']) ?>"><?= $e($item['name']) ?></a>
                                <br><small>#<?= $e($item['customer_id']) ?></small>
                            </th>
                            <td><a href="mailto:<?= $e($item['email']) ?>"><?= $e($item['email']) ?></a></td>
                            <td><?= $e($item['phone'] ?? 'Not provided') ?></td>
                            <td>
                                <span class="admin-status<?= $item['account_state'] === 'archived' ? ' is-muted' : '' ?>">
                                    <span aria-hidden="true"></span><?= $e($statusLabel((string) $item['account_state'])) ?>
                                </span>
                                <?php if ($item['role'] === 'admin'): ?>
                                    <br><small>Administrator</small>
                                <?php endif ?>
                            </td>
                            <td><?= $e($date($item['created_at'])) ?></td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>

    <nav class="admin-pagination" aria-label="Customer pages">
        <p>Page <?= $e($page) ?></p>
        <div>
            <?php if ($page > 1): ?>
                <a href="/admin/customers?page=<?= $e($page - 1) ?>">Previous page</a>
            <?php endif ?>
            <?php if ($has_next): ?>
                <a href="/admin/customers?page=<?= $e($page + 1) ?>">Next page</a>
            <?php endif ?>
        </div>
    </nav>
</section>
