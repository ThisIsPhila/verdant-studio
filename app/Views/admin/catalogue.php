<header class="admin-page-heading">
    <div>
        <p>Catalogue management</p>
        <h1>Products</h1>
        <p>Manage catalogue details and physical stock. Current holds and rental commitments protect stock reductions.</p>
    </div>
    <a class="button" href="/admin/product">Add product</a>
</header>
<?php if (($notice ?? null) !== null): ?><p class="admin-notice" role="status">Product changes saved.</p><?php endif ?>
<?php if (($errors ?? []) !== []): ?><div class="admin-error" role="alert"><strong>Page value needs attention.</strong><ul><?php foreach ($errors as $message): ?><li><?= $e($message) ?></li><?php endforeach ?></ul></div><?php endif ?>
<?php if ($items === []): ?>
    <p class="account-empty">No catalogue products have been added yet.</p>
<?php else: ?>
    <section class="admin-panel" aria-labelledby="catalogue-records-heading">
    <div class="admin-panel-heading"><h2 id="catalogue-records-heading">Catalogue products</h2></div>
    <div class="admin-table-wrap admin-scroll-table" role="region" aria-label="Product records" tabindex="0">
        <table class="admin-table">
            <thead><tr><th scope="col">Photo</th><th scope="col">Product</th><th scope="col">Category</th><th scope="col">Availability</th><th scope="col">Stock</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <?php
                $adminImage = \Verdant\Storefront\VisionLandingPresenter::image($item) ?? $image($item['image_path'] ?? null);
                $adminImageIsPlaceholder = str_contains($adminImage, 'botanical.svg') || str_contains($adminImage, '/placeholders/');
                $adminMode = !$item['buy_enabled'] ? 'Rent' : (!$item['rent_enabled'] ? 'Buy' : 'Buy or rent');
                ?>
                <tr>
                    <td><?php if (!$adminImageIsPlaceholder): ?><img class="admin-thumb" src="<?= $e($adminImage) ?>" alt="" loading="lazy"><?php else: ?><span class="admin-thumb admin-thumb--plain" aria-hidden="true"></span><?php endif ?></td>
                    <th scope="row"><strong><?= $e($item['name']) ?></strong><br><small><?= $e($item['sku']) ?> · <?= $e($label($item['product_kind'] ?? 'other')) ?></small></th>
                    <td><?= $e($item['category_name']) ?></td>
                    <td><span class="admin-mode-badge<?= $adminMode === 'Buy' ? ' admin-mode-badge--buy' : '' ?>"><?= $e($adminMode) ?></span><br><?php if ($item['buy_enabled']): ?>Retail <?= $e($money($item['retail_price_minor'])) ?><?php endif ?><?php if ($item['buy_enabled'] && $item['rent_enabled']): ?><br><?php endif ?><?php if ($item['rent_enabled']): ?>Rental <?= $e($money($item['rental_daily_rate_minor'])) ?>/day<?php endif ?></td>
                    <td><?= $e($item['on_hand']) ?> on hand</td>
                    <td><span class="admin-status<?= $item['archived_at'] === null ? '' : ' is-muted' ?>"><span aria-hidden="true"></span><?= $item['archived_at'] === null ? 'Active' : 'Archived' ?></span></td>
                    <td><div class="admin-record-status"><a href="/admin/product?id=<?= $e(rawurlencode((string) $item['product_id'])) ?>">Edit</a>
                        <form action="/admin/product/archive" method="post">
                            <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
                            <input type="hidden" name="product_id" value="<?= $e($item['product_id']) ?>">
                            <input type="hidden" name="archive" value="<?= $item['archived_at'] === null ? '1' : '0' ?>">
                            <button type="submit"><?= $item['archived_at'] === null ? 'Archive' : 'Restore' ?></button>
                        </form>
                    </div></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    </section>
<?php endif ?>
<nav class="admin-pagination" aria-label="Product pages">
    <?php if ($page > 1): ?><a href="/admin/products?page=<?= $e($page - 1) ?>">Previous</a><?php endif ?>
    <span>Page <?= $e($page) ?></span>
    <?php if ($has_next): ?><a href="/admin/products?page=<?= $e($page + 1) ?>">Next</a><?php endif ?>
</nav>
