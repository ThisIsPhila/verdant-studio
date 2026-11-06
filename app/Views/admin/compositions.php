<?php
use Verdant\Admin\CompositionEditor;
$rows = $selected ? CompositionEditor::rows($selected, $values) : [];
if ($selected && $selected['strategy'] !== 'finished_stock' && in_array($addRequested ?? null, ['item', 'choice'], true) && count($rows) < 30) {
    $rows[] = CompositionEditor::blank($addRequested === 'choice');
}
?>
<link rel="stylesheet" href="/assets/css/admin-compositions.css?v=<?= $e((string) filemtime(dirname(__DIR__, 3) . '/public/assets/css/admin-compositions.css')) ?>">
<script src="/assets/js/admin-compositions.js" defer></script>
<header class="admin-page-heading"><div>
    <p>Store catalogue · Arrangements</p><h1>Arrangements</h1>
    <p>Manage the recipes offered to customers. Saved arrangements and existing checkouts keep their original item details.</p>
</div></header>
<?php if ($notice): ?><p class="admin-notice" role="status">Arrangement changes saved.</p><?php endif ?>
<?php if ($errors): ?><div class="admin-error" role="alert"><strong>Changes were not saved.</strong><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach ?></ul></div><?php endif ?>
<div class="recipe-layout">
    <aside class="admin-panel recipe-list" aria-labelledby="recipe-list-title">
        <h2 id="recipe-list-title">Your arrangements</h2>
        <label for="recipe-search">Find an arrangement</label>
        <input id="recipe-search" type="search" data-recipe-search placeholder="Search by name" hidden>
        <ul>
        <?php foreach ($definitions as $definition): ?>
            <li data-recipe-name="<?= $e(strtolower($definition['name'])) ?>">
                <a href="/admin/compositions?edit=<?= $e($definition['definition_id']) ?>"<?= ($selected['definition_id'] ?? null) === $definition['definition_id'] ? ' aria-current="page"' : '' ?>>
                    <strong><?= $e($definition['name']) ?></strong>
                    <span><?= $definition['strategy'] === 'finished_stock' ? 'Ready-made' : 'Customisable' ?> · <?= $e($label($definition['status'])) ?></span>
                </a>
            </li>
        <?php endforeach ?>
        </ul>
        <p class="admin-empty" data-recipe-empty <?= $definitions ? 'hidden' : '' ?>>No arrangements match.</p>
    </aside>
    <section class="admin-panel recipe-editor" aria-labelledby="recipe-editor-title">
    <?php if (!$selected): ?>
        <h2 id="recipe-editor-title">Choose an arrangement</h2><p>Select a recipe from the list to edit its details and included items.</p>
    <?php else: ?>
        <h2 id="recipe-editor-title"><?= $e($selected['name']) ?></h2>
        <form action="/admin/composition/save" method="post" data-recipe-editor data-next-index="<?= $e(count($rows)) ?>">
            <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
            <input type="hidden" name="definition_id" value="<?= $e($selected['definition_id']) ?>">
            <nav class="recipe-tabs" aria-label="Recipe sections">
                <?php foreach (['details' => 'Details', 'included' => 'Included items', 'choices' => 'Customer choices', 'review' => 'Review'] as $key => $caption): ?>
                    <a href="#recipe-<?= $e($key) ?>" data-recipe-tab="<?= $e($key) ?>"><?= $e($caption) ?></a>
                <?php endforeach ?>
            </nav>
            <section id="recipe-details" data-recipe-panel="details">
                <h3>Details</h3>
                <div class="admin-grid">
                    <label>Arrangement name <input name="name" maxlength="180" required value="<?= $e($values['name'] ?? $selected['name']) ?>"></label>
                    <label>Visibility <select name="status"><option value="active" <?= ($values['status'] ?? $selected['status']) === 'active' ? 'selected' : '' ?>>Active</option><option value="archived" <?= ($values['status'] ?? $selected['status']) === 'archived' ? 'selected' : '' ?>>Archived</option></select></label>
                </div>
                <p><?= $selected['strategy'] === 'finished_stock' ? 'A ready-made product with its own stock and price.' : 'A recipe assembled from the products and choices below.' ?></p>
                <details class="recipe-advanced"><summary>Advanced settings · Storefront reference</summary><dl class="detail-list"><dt>Link reference</dt><dd><?= $e($selected['definition_code']) ?></dd><dt>Arrangement type</dt><dd><?= $e($label($selected['arrangement_kind'])) ?></dd><dt>Stock strategy</dt><dd><?= $e($label($selected['strategy'])) ?></dd></dl><p>These references identify this recipe in existing saved arrangements and checkouts.</p></details>
            </section>
            <?php foreach (['included' => false, 'choices' => true] as $group => $choice): ?>
            <section id="recipe-<?= $e($group) ?>" data-recipe-panel="<?= $e($group) ?>">
                <h3><?= $choice ? 'Customer choices' : 'Included items' ?></h3>
                <p><?= $choice ? 'Set the alternatives, optional items and quantities customers can choose.' : 'Set the products and fixed quantities included in this recipe.' ?></p>
                <?php if ($selected['strategy'] === 'finished_stock'): ?>
                    <p>This ready-made arrangement uses its linked product directly; its product record manages stock and price.</p>
                <?php else: ?>
                    <div data-recipe-items="<?= $e($group) ?>">
                    <?php foreach ($rows as $index => $component): if ($component['customer_choice'] !== $choice) continue;
                        require __DIR__ . '/components/composition-item.php';
                    endforeach ?>
                    </div>
                    <a class="button recipe-add" data-recipe-add="<?= $e($group) ?>" href="/admin/compositions?edit=<?= $e($selected['definition_id']) ?>&amp;add=<?= $choice ? 'choice' : 'item' ?>#recipe-<?= $e($group) ?>">Add <?= $choice ? 'customer choice' : 'included item' ?></a>
                    <noscript><p>Save your current edits before opening the add-item link.</p></noscript>
                <?php endif ?>
            </section>
            <?php endforeach ?>
            <section id="recipe-review" data-recipe-panel="review">
                <h3>Review this recipe</h3>
                <p>Check the included items, available choices and permitted purchase/rental modes before saving.</p>
                <ul data-recipe-summary>
                <?php foreach ($rows as $component): ?>
                    <li><?= $e($component['label'] ?? 'New item') ?> · <?= $component['archived'] ? 'Archived' : ($component['customer_choice'] ? 'Customer choice' : 'Included') ?></li>
                <?php endforeach ?>
                </ul>
                <?php if ($selected['strategy'] === 'finished_stock' && $selected['finished_product_id']): ?><p><a class="admin-view-link" href="/admin/product?id=<?= $e($selected['finished_product_id']) ?>">Manage linked product, stock and price</a></p><?php endif ?>
                <p>Saving changes this recipe for future configurations. It does not reserve stock or change previous saved and checkout details.</p>
            </section>
            <div class="recipe-save"><button type="submit">Save arrangement</button><p role="status" data-recipe-message></p></div>
            <?php if ($selected['strategy'] !== 'finished_stock'): $index = '__INDEX__'; $component = CompositionEditor::blank(); ?>
                <template data-recipe-template><?php require __DIR__ . '/components/composition-item.php'; ?></template>
            <?php endif ?>
        </form>
    <?php endif ?>
    </section>
</div>
