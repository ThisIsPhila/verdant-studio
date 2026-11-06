<?php $categoryName = $values['name'] ?? ''; ?>
<header class="admin-page-heading"><div>
    <p>Catalogue management</p>
    <h1>Categories</h1>
    <p>Categories with active products must be moved or cleared before archival.</p>
</div></header>
<?php if (($notice ?? null) !== null): ?><p class="admin-notice" role="status">Category changes saved.</p><?php endif ?>
<?php if ($errors !== []): ?><div class="admin-error" role="alert"><strong>Category change needs attention.</strong><ul><?php foreach ($errors as $message): ?><li><?= $e($message) ?></li><?php endforeach ?></ul></div><?php endif ?>
<section class="admin-panel">
    <div class="admin-panel-heading"><h2><?= !empty($values['category_id']) ? 'Edit category' : 'Add category' ?></h2></div>
    <form class="admin-form" action="/admin/category/save" method="post">
        <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
        <?php if (!empty($values['category_id']) && is_scalar($values['category_id'])): ?><input type="hidden" name="category_id" value="<?= $e($values['category_id']) ?>"><?php endif ?>
        <label class="field">Category name <input name="name" maxlength="120" required value="<?= $e(is_scalar($categoryName) ? $categoryName : '') ?>"></label>
        <div class="admin-record-status"><button type="submit">Save category</button></div>
    </form>
</section>
<?php if ($items === []): ?>
    <p class="account-empty">No categories have been created.</p>
<?php else: ?>
    <section class="admin-panel" aria-labelledby="category-records-heading">
    <div class="admin-panel-heading"><h2 id="category-records-heading">Category records</h2></div>
    <div class="admin-table-wrap admin-scroll-table" role="region" aria-label="Category records" tabindex="0">
        <table class="admin-table"><thead><tr><th scope="col">Category</th><th scope="col">Products</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>
        <?php foreach ($items as $item): ?>
            <tr><th scope="row"><?= $e($item['name']) ?></th><td><?= $e($item['product_count']) ?></td><td><span class="admin-status<?= $item['archived_at'] === null ? '' : ' is-muted' ?>"><span aria-hidden="true"></span><?= $item['archived_at'] === null ? 'Active' : 'Archived' ?></span></td><td><div class="admin-record-status">
                <a href="/admin/categories?edit=<?= $e(rawurlencode((string) $item['category_id'])) ?>">Edit</a>
                <form action="/admin/category/archive" method="post"><input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>"><input type="hidden" name="category_id" value="<?= $e($item['category_id']) ?>"><input type="hidden" name="archive" value="<?= $item['archived_at'] === null ? '1' : '0' ?>"><button type="submit"><?= $item['archived_at'] === null ? 'Archive' : 'Restore' ?></button></form>
            </div></td></tr>
        <?php endforeach ?>
        </tbody></table>
    </div>
    </section>
<?php endif ?>
<nav class="admin-pagination" aria-label="Category pages"><?php if ($page > 1): ?><a href="/admin/categories?page=<?= $e($page - 1) ?>">Previous</a><?php endif ?><span>Page <?= $e($page) ?></span><?php if ($has_next): ?><a href="/admin/categories?page=<?= $e($page + 1) ?>">Next</a><?php endif ?></nav>
