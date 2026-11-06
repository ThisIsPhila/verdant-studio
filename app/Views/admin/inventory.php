<header class="admin-page-heading">
    <div>
        <p>Stock control</p>
        <h1>Inventory</h1>
        <p>Catalogue stock on hand and the retail and rental options recorded for each product.</p>
    </div>
</header>
<?php if (($errors ?? []) !== []): ?><div class="admin-error" role="alert"><strong>Choose a valid inventory page.</strong><ul><?php foreach ($errors as $message): ?><li><?= $e($message) ?></li><?php endforeach ?></ul></div><?php endif ?>
<section class="admin-panel" aria-labelledby="inventory-records-heading">
    <div class="admin-panel-heading"><h2 id="inventory-records-heading">Stock by product</h2></div>
    <?php if ($items === []): ?>
        <p class="admin-empty" role="status">No catalogue products are available.</p>
    <?php else: ?>
        <div class="admin-scroll-table" role="region" aria-label="Inventory records" tabindex="0">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Product</th>
                        <th scope="col">SKU</th>
                        <th scope="col">Category</th>
                        <th scope="col">On hand</th>
                        <th scope="col">Retail</th>
                        <th scope="col">Rental</th>
                        <th scope="col">Catalogue status</th>
                        <th scope="col">Stock record</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <th scope="row">
                                <?= $e($item['name']) ?>
                                <?php if (($item['variant_label'] ?? '') !== ''): ?><br><small><?= $e($item['variant_label']) ?></small><?php endif ?>
                            </th>
                            <td><?= $e($item['sku']) ?></td>
                            <td><?= $e($item['category_name']) ?></td>
                            <td><strong><?= $e($item['on_hand']) ?></strong></td>
                            <td><span class="admin-status<?= $item['buy_enabled'] ? '' : ' is-muted' ?>"><span aria-hidden="true"></span><?= $item['buy_enabled'] ? 'Offered' : 'Not offered' ?></span></td>
                            <td><span class="admin-status<?= $item['rent_enabled'] ? '' : ' is-muted' ?>"><span aria-hidden="true"></span><?= $item['rent_enabled'] ? 'Offered' : 'Not offered' ?></span></td>
                            <td><span class="admin-status<?= $item['archived_at'] === null ? '' : ' is-muted' ?>"><span aria-hidden="true"></span><?= $item['archived_at'] === null ? 'Active' : 'Archived' ?></span></td>
                            <td><a class="admin-view-link" href="/admin/product?id=<?= $e(rawurlencode((string) $item['product_id'])) ?>">Manage stock</a></td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</section>
<nav class="admin-pagination" aria-label="Inventory pages">
    <?php if ($page > 1): ?><a href="/admin/inventory?page=<?= $e($page - 1) ?>">Previous</a><?php endif ?>
    <span>Page <?= $e($page) ?></span>
    <?php if ($has_next): ?><a href="/admin/inventory?page=<?= $e($page + 1) ?>">Next</a><?php endif ?>
</nav>
