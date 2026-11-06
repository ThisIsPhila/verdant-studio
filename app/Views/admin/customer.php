<a class="admin-view-link" href="/admin/customers">Back to customers</a>

<header class="admin-page-heading">
    <div>
        <p>Administration · Customer account</p>
        <h1><?= $e($record['name']) ?></h1>
        <p>Customer #<?= $e($record['customer_id']) ?></p>
    </div>
    <div class="admin-record-status" aria-label="Customer account status">
        <span class="admin-status<?= $record['account_state'] === 'archived' ? ' is-muted' : '' ?>">
            <span aria-hidden="true"></span><?= $e($statusLabel((string) $record['account_state'])) ?>
        </span>
        <?php if ($record['role'] === 'admin'): ?>
            <span class="admin-status is-muted"><span aria-hidden="true"></span>Administrator</span>
        <?php endif ?>
    </div>
</header>

<div class="admin-detail-grid">
    <section class="admin-panel" aria-labelledby="customer-details-title">
        <h2 id="customer-details-title">Contact and account</h2>
        <dl class="admin-facts">
            <div>
                <dt>Email</dt>
                <dd><a href="mailto:<?= $e($record['email']) ?>"><?= $e($record['email']) ?></a></dd>
            </div>
            <div>
                <dt>Phone</dt>
                <dd><?= $e($record['phone'] ?? 'Not provided') ?></dd>
            </div>
            <div>
                <dt>Account status</dt>
                <dd><?= $e($statusLabel((string) $record['account_state'])) ?></dd>
            </div>
            <div>
                <dt>Record type</dt>
                <dd><?= $record['role'] === 'admin' ? 'Administrator' : 'Customer' ?></dd>
            </div>
            <div>
                <dt>Created</dt>
                <dd><?= $e($date($record['created_at'])) ?></dd>
            </div>
            <?php if ($record['archived_at'] !== null): ?>
                <div>
                    <dt>Archived</dt>
                    <dd><?= $e($date($record['archived_at'])) ?></dd>
                </div>
            <?php endif ?>
        </dl>
    </section>

    <section class="admin-panel" aria-labelledby="customer-access-title">
        <h2 id="customer-access-title">Account access</h2>
        <?php if ($record['role'] === 'customer'): ?>
            <p>Archiving restricts this account while retaining its transaction history.</p>
            <div class="admin-actions">
                <form action="/admin/customer/archive" method="post">
                    <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
                    <input type="hidden" name="customer_id" value="<?= $e($record['customer_id']) ?>">
                    <input type="hidden" name="archived" value="<?= $record['account_state'] === 'active' ? '1' : '0' ?>">
                    <button type="submit"><?= $record['account_state'] === 'active' ? 'Archive customer account' : 'Restore customer account' ?></button>
                </form>
            </div>
        <?php else: ?>
            <p>Administrator accounts cannot be archived from this page.</p>
        <?php endif ?>
    </section>
</div>
