<?php
$formValue = static function (string $key, mixed $default = '') use ($values): string {
    $value = $values[$key] ?? $default;
    return is_scalar($value) ? (string) $value : '';
};
$minorToDecimal = static function (mixed $minor): string {
    $digits = (string) $minor;
    if ($digits === '' || !ctype_digit($digits)) {
        return '';
    }
    $digits = str_pad(ltrim($digits, '0') ?: '0', 3, '0', STR_PAD_LEFT);
    return substr($digits, 0, -2) . '.' . substr($digits, -2);
};
$retailPriceValue = isset($values['retail_price'])
    ? $formValue('retail_price')
    : (isset($values['retail_price_minor']) ? $minorToDecimal($values['retail_price_minor']) : '');
$rentalPriceValue = isset($values['rental_daily_rate'])
    ? $formValue('rental_daily_rate')
    : (isset($values['rental_daily_rate_minor']) ? $minorToDecimal($values['rental_daily_rate_minor']) : '');
?>
<header class="admin-page-heading"><div>
    <p>Catalogue management · Product catalogue</p>
    <h1><?= $e($title) ?></h1>
</div></header>
<?php if ($errors !== []): ?><div class="admin-error" role="alert"><strong>Please correct the product details.</strong><ul><?php foreach ($errors as $message): ?><li><?= $e($message) ?></li><?php endforeach ?></ul></div><?php endif ?>
<?php if ($categories === []): ?><p class="account-empty">Create an active category before adding a product. <a href="/admin/categories">Manage categories</a>.</p><?php else: ?>
<form class="admin-form" action="/admin/product/save" method="post">
    <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
    <?php if ($product !== null): ?><input type="hidden" name="product_id" value="<?= $e($product['product_id']) ?>"><input type="hidden" name="version" value="<?= $e($formValue('version', $product['version'])) ?>"><?php endif ?>
    <section class="admin-panel">
    <div class="admin-panel-heading"><h2>Product identity</h2></div>
    <label>Category
        <select name="category_id" required><?php foreach ($categories as $category): ?><option value="<?= $e($category['category_id']) ?>" <?= $formValue('category_id') === (string) $category['category_id'] ? 'selected' : '' ?>><?= $e($category['name']) ?></option><?php endforeach ?></select>
    </label>
    <label>SKU <input name="sku" maxlength="64" required value="<?= $e($formValue('sku')) ?>" autocomplete="off"></label>
    <label>Product name <input name="name" maxlength="180" required value="<?= $e($formValue('name')) ?>"></label>
    <label>Description <textarea name="description" rows="5" maxlength="10000" required><?= $e($formValue('description')) ?></textarea></label>
    <div class="admin-grid">
        <label>Product kind
            <select name="product_kind" required><?php foreach (['plant', 'cut_flower', 'cut_foliage', 'accessory', 'plant_arrangement', 'floral_arrangement', 'other'] as $option): ?><option value="<?= $e($option) ?>" <?= $formValue('product_kind', 'other') === $option ? 'selected' : '' ?>><?= $e($label($option)) ?></option><?php endforeach ?></select>
        </label>
        <label>Product family
            <select name="family_id"><option value="">Standalone SKU</option><?php foreach (($families ?? []) as $family): ?><option value="<?= $e($family['family_id']) ?>" <?= $formValue('family_id', $product['family_id'] ?? '') === (string) $family['family_id'] ? 'selected' : '' ?>><?= $e($family['name']) ?> · <?= $e($label($family['product_kind'])) ?><?= $family['archived_at'] !== null ? ' (archived; retain history)' : '' ?></option><?php endforeach ?></select>
            <span class="help">Families group SKUs only. Each variant keeps its own stock and price.</span>
        </label>
        <label>Variant label <input name="variant_label" maxlength="120" value="<?= $e($formValue('variant_label')) ?>" placeholder="Small, Large, or 30 cm"></label>
        <label>Accessory type
            <select name="accessory_subtype" required><?php foreach (['other', 'pot', 'tool', 'care'] as $option): ?><option value="<?= $e($option) ?>" <?= $formValue('accessory_subtype', 'other') === $option ? 'selected' : '' ?>><?= $e($label($option)) ?></option><?php endforeach ?></select>
            <span class="help">Only products explicitly classified as pots can have plant compatibility.</span>
        </label>
        <label>Selling unit <input name="selling_unit" maxlength="80" value="<?= $e($formValue('selling_unit')) ?>" placeholder="Each, bunch, or bottle"></label>
        <label>Size / description <input name="size_label" maxlength="120" value="<?= $e($formValue('size_label')) ?>"></label>
        <label>Colour <input name="colour_label" maxlength="80" value="<?= $e($formValue('colour_label')) ?>"></label>
    </div>
    </section>
    <section class="admin-panel">
    <div class="admin-panel-heading"><h2>Care and imagery</h2></div>
    <div class="admin-grid">
        <label>Light
            <select name="light_level" required><?php foreach (['unknown', 'bright_indirect', 'indirect', 'full_sun', 'low_light'] as $option): ?><option value="<?= $e($option) ?>" <?= $formValue('light_level', 'unknown') === $option ? 'selected' : '' ?>><?= $e($label($option)) ?></option><?php endforeach ?></select>
        </label>
        <label>Watering
            <select name="watering_level" required><?php foreach (['unknown', 'weekly', 'keep_moist', 'regular', 'sparingly'] as $option): ?><option value="<?= $e($option) ?>" <?= $formValue('watering_level', 'unknown') === $option ? 'selected' : '' ?>><?= $e($label($option)) ?></option><?php endforeach ?></select>
        </label>
        <label>Toxicity
            <select name="toxicity" required><?php foreach (['unknown', 'toxic_to_pets', 'non_toxic'] as $option): ?><option value="<?= $e($option) ?>" <?= $formValue('toxicity', 'unknown') === $option ? 'selected' : '' ?>><?= $e($label($option)) ?></option><?php endforeach ?></select>
        </label>
    </div>
    <label>Local image path (optional) <input name="image_path" maxlength="255" value="<?= $e($formValue('image_path')) ?>" placeholder="/assets/images/catalogue/placeholders/plant.svg"><span class="help">Use an existing file under the local catalogue image folder.</span></label>
    <label>Image alt text <input name="image_alt" maxlength="180" value="<?= $e($formValue('image_alt')) ?>"><span class="help">Label placeholders as illustrations, not product photographs.</span></label>
    </section>
    <section class="admin-panel">
    <div class="admin-panel-heading"><h2>Availability and stock</h2></div>
    <div class="admin-grid">
        <label>Available for retail?
            <select name="buy_enabled"><option value="1" <?= $formValue('buy_enabled', '1') === '1' ? 'selected' : '' ?>>Yes</option><option value="0" <?= $formValue('buy_enabled', '1') === '0' ? 'selected' : '' ?>>No</option></select>
        </label>
        <label>Retail price (R) <input name="retail_price" inputmode="decimal" placeholder="129.00" value="<?= $e($retailPriceValue) ?>"></label>
        <label>Available for rental?
            <select name="rent_enabled"><option value="1" <?= $formValue('rent_enabled', isset($values['rental_daily_rate_minor']) ? '1' : '0') === '1' ? 'selected' : '' ?>>Yes</option><option value="0" <?= $formValue('rent_enabled', isset($values['rental_daily_rate_minor']) ? '1' : '0') === '0' ? 'selected' : '' ?>>No</option></select>
        </label>
        <label>Rental daily rate (R) <input name="rental_daily_rate" inputmode="decimal" placeholder="7.50" value="<?= $e($rentalPriceValue) ?>"></label>
    </div>
    <p class="help">Cut flowers, cut foliage and floral arrangements are sale-only. Plant care values may remain unknown.</p>
    <label>Physical stock on hand
        <input type="number" name="on_hand" min="0" max="1000000" required value="<?= $e($formValue('on_hand', '0')) ?>">
        <?php if ($product !== null): ?><span class="help">Stock changes are checked against current retail and rental commitments. Reload if another change was saved.</span><?php else: ?><span class="help">Maximum 1,000,000 units.</span><?php endif ?>
    </label>
    </section>
    <div class="admin-record-status"><button type="submit">Save product</button><a href="/admin/products">Cancel</a></div>
</form>
<?php endif ?>
