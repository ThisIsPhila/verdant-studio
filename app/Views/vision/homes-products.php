<?php
$homesSectionHeading = $homesProductSection['heading'] ?? 'Beautiful plants for brighter living spaces.';
$homesSectionBody = $homesProductSection['body'] ?? 'Browse the products currently included in the Homes collection.';
$homesAction = '/collections/homes';
$categoriesById = [];
foreach ($categories ?? [] as $homeCategory) {
    $categoriesById[(string) ($homeCategory['category_id'] ?? '')] = (string) ($homeCategory['name'] ?? '');
}
$homesActiveFilters = [];
$homesFilterLabels = [
    'category_id' => 'Category', 'kind' => 'Product type', 'mode' => 'Mode', 'size' => 'Size', 'colour' => 'Colour',
    'budget' => 'Budget', 'light_level' => 'Light', 'watering_level' => 'Watering', 'toxicity' => 'Safety',
];
foreach ($homesQueryFilters as $key => $value) {
    if ($key === 'sort') { continue; }
    $remaining = $homesQueryFilters;
    unset($remaining[$key]);
    $display = match ($key) {
        'category_id' => (string) (($categoriesById ?? [])[$value] ?? $value),
        'mode' => $value === 'buy' ? 'Buy' : 'Rent',
        'budget' => isset($filters['budget_minor']) ? '≤ ' . $money($filters['budget_minor']) : $value,
        default => ucfirst(str_replace('_', ' ', $value)),
    };
    if ($key === 'budget' && ($filters['mode'] ?? '') === 'rent') { $display .= ' per day'; }
    $homesActiveFilters[] = ['label' => ($homesFilterLabels[$key] ?? 'Filter') . ': ' . $display, 'href' => $homesAction . ($remaining === [] ? '' : '?' . http_build_query($remaining))];
}
?>
<section class="vision-homes-products vision-discovery-section" id="homes-products" aria-labelledby="homes-products-title">
    <div class="vision-discovery-heading">
        <div><p class="eyebrow">Shop home plants</p><h2 id="homes-products-title"><?= $e($homesSectionHeading) ?></h2><p><?= $e($homesSectionBody) ?></p><span class="sr-only" id="collection-products-heading">Current Homes catalogue products</span></div>
        <p class="vision-discovery-note">Browsing does not reserve stock</p>
    </div>
    <?php if ($homesActiveFilters !== []): ?>
        <ul class="active-filters vision-filter-chips" aria-label="Active Homes filters">
            <?php foreach ($homesActiveFilters as $activeFilter): ?><li><?= $e($activeFilter['label']) ?> <a href="<?= $e($activeFilter['href']) ?>" aria-label="Remove <?= $e($activeFilter['label']) ?> filter">×</a></li><?php endforeach ?>
            <li><a href="/collections/homes">Clear all</a></li>
        </ul>
    <?php endif ?>
    <div class="vision-catalogue-layout">
        <aside class="vision-filter-column">
            <details class="filter-disclosure">
                <summary>Filter the Homes edit</summary>
                <form action="/collections/homes" method="get" class="vision-filter-form">
                    <div class="vision-filter-group"><label for="homes-category">Category</label><select id="homes-category" name="category_id"><option value="">All categories</option><?php foreach ($categories ?? [] as $category): ?><option value="<?= $e($category['category_id']) ?>" <?= (string) ($filters['category_id'] ?? '') === (string) $category['category_id'] ? 'selected' : '' ?>><?= $e($category['name']) ?></option><?php endforeach ?></select></div>
                    <div class="vision-filter-group"><label for="homes-kind">Product type</label><select id="homes-kind" name="kind"><option value="">Any type</option><?php foreach ($options['kind'] ?? [] as $option): ?><option value="<?= $e($option) ?>" <?= ($filters['kind'] ?? '') === $option ? 'selected' : '' ?>><?= $e($label($option)) ?></option><?php endforeach ?></select></div>
                    <div class="vision-filter-group"><label for="homes-mode">Mode</label><select id="homes-mode" name="mode"><option value="">Buy or rent</option><?php foreach ($options['mode'] ?? [] as $option): ?><option value="<?= $e($option) ?>" <?= ($filters['mode'] ?? '') === $option ? 'selected' : '' ?>><?= $option === 'buy' ? 'Buy' : 'Rent' ?></option><?php endforeach ?></select></div>
                    <div class="vision-filter-group"><label for="homes-size">Size</label><select id="homes-size" name="size"><option value="">Any verified size</option><?php foreach ($options['size'] ?? [] as $option): ?><option value="<?= $e($option) ?>" <?= ($filters['size'] ?? '') === $option ? 'selected' : '' ?>><?= $e($option) ?></option><?php endforeach ?></select></div>
                    <div class="vision-filter-group"><label for="homes-colour">Colour</label><select id="homes-colour" name="colour"><option value="">Any verified colour</option><?php foreach ($options['colour'] ?? [] as $option): ?><option value="<?= $e($option) ?>" <?= ($filters['colour'] ?? '') === $option ? 'selected' : '' ?>><?= $e($option) ?></option><?php endforeach ?></select></div>
                    <div class="vision-filter-group"><label for="homes-budget">Maximum budget (ZAR)</label><input id="homes-budget" name="budget" inputmode="decimal" pattern="[0-9]+(\.[0-9]{1,2})?" value="<?= $e($filters['budget'] ?? '') ?>" placeholder="e.g. 500"></div>
                    <?php foreach (['light_level' => 'Plant light', 'watering_level' => 'Plant watering', 'toxicity' => 'Plant safety'] as $careKey => $careLabel): ?>
                        <?php if (!empty($options[$careKey])): ?><div class="vision-filter-group"><label for="homes-<?= $e($careKey) ?>"><?= $e($careLabel) ?></label><select id="homes-<?= $e($careKey) ?>" name="<?= $e($careKey) ?>"><option value="">Any verified value</option><?php foreach ($options[$careKey] as $option): ?><option value="<?= $e($option) ?>" <?= ($filters[$careKey] ?? '') === $option ? 'selected' : '' ?>><?= $e($label($option)) ?></option><?php endforeach ?></select></div><?php endif ?>
                    <?php endforeach ?>
                    <div class="vision-filter-actions"><button class="btn-solid" type="submit">Apply filters</button><a href="/collections/homes">Clear</a></div>
                </form>
            </details>
        </aside>
        <div class="vision-catalogue-results">
            <div class="vision-results-heading"><p><?= $e(count($products)) ?> products on this page</p>
                <form action="/collections/homes" method="get" class="vision-sort">
                    <?php foreach ($homesQueryFilters as $key => $value): if ($key === 'sort') continue; ?><input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>"><?php endforeach ?>
                    <label for="homes-sort">Sort by</label><select id="homes-sort" name="sort"><option value="">Curated order</option><option value="price_asc" <?= ($filters['sort'] ?? '') === 'price_asc' ? 'selected' : '' ?>>Price: low to high</option><option value="price_desc" <?= ($filters['sort'] ?? '') === 'price_desc' ? 'selected' : '' ?>>Price: high to low</option></select><button class="vision-small-button" type="submit">Sort</button>
                </form>
            </div>
            <?php if (!$products): ?><div class="empty-state"><h2>No products match these filters</h2><p>Try a different combination or clear the filters.</p><a href="/collections/homes" class="btn-outline">Clear filters</a></div><?php endif ?>
            <div class="vision-product-grid"><?php foreach ($products as $product): require __DIR__ . '/catalogue-card.php'; endforeach ?></div>
            <?php if (($page ?? 1) > 1 || !empty($hasNext)): ?><nav class="pagination" aria-label="Homes product pages"><?php if (($page ?? 1) > 1): ?><a href="/collections/homes?<?= $e(http_build_query(array_merge($homesQueryFilters, ['page' => $page - 1]))) ?>">Previous</a><?php endif ?><span>Page <?= $e($page) ?></span><?php if (!empty($hasNext)): ?><a href="/collections/homes?<?= $e(http_build_query(array_merge($homesQueryFilters, ['page' => $page + 1]))) ?>">Next</a><?php endif ?></nav><?php endif ?>
        </div>
    </div>
    <aside class="vision-homes-build">
        <div><p class="eyebrow">Make it your own</p><h3>Build a plant arrangement.</h3><p>Choose a plant and planter from current options. Compatibility and availability are checked in configuration.</p></div>
        <a class="btn-solid" href="/configure">Explore plant &amp; pot pairings <span aria-hidden="true">→</span></a>
    </aside>
    <p class="vision-homes-more"><a href="/collections">← All collections</a> · <a href="/catalogue">Browse full catalogue</a></p>
</section>
