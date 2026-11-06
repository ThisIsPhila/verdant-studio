<?php
$collectionCode = (string) ($collection['collection_code'] ?? '');
$collectionPath = '/collections/' . rawurlencode($collectionCode);
$collectionImages = [
    'green-events' => '/assets/vision/images/gatherings.jpg',
    'floral-inspiration' => '/assets/vision/images/p-wild-bouquet.jpg',
    'desk-greenery' => '/assets/vision/images/workspaces.jpg',
];
$collectionImage = $collectionImages[$collectionCode] ?? '/assets/vision/images/hero-plants.jpg';
?>
<section class="page-hero relative overflow-hidden bg-surface vision-collection-page-hero">
    <img src="<?= $e($collectionImage) ?>" alt="" width="1920" height="640" class="absolute inset-0 h-full w-full object-cover object-left">
    <div class="page-hero__veil" aria-hidden="true"></div>
    <div class="relative mx-auto max-w-7xl px-6 py-16 md:py-20"><div class="max-w-xl">
        <p class="eyebrow">Curated botanical ideas</p>
        <h1><?= $e($collection['name'] ?? 'Botanical collection') ?></h1>
        <?php if (!empty($collection['description'])): ?><p><?= $e($collection['description']) ?></p><?php endif ?>
    </div></div>
</section>
<?php
$collectionQueryFilters = $filters ?? [];
unset($collectionQueryFilters['budget_minor'], $collectionQueryFilters['page']);
$collectionQueryFilters = array_filter($collectionQueryFilters, static fn (mixed $value): bool => is_string($value) && $value !== '');
$categoryNames = [];
foreach ($categories ?? [] as $category) { $categoryNames[(string) ($category['category_id'] ?? '')] = (string) ($category['name'] ?? ''); }
$collectionActiveFilters = [];
$collectionFilterLabels = [
    'category_id' => 'Category', 'kind' => 'Product type', 'mode' => 'Mode', 'size' => 'Size', 'colour' => 'Colour',
    'budget' => 'Budget', 'light_level' => 'Light', 'watering_level' => 'Watering', 'toxicity' => 'Safety', 'q' => 'Search',
];
foreach ($collectionQueryFilters as $key => $value) {
    if ($key === 'sort') { continue; }
    $remaining = $collectionQueryFilters;
    unset($remaining[$key]);
    $display = match ($key) {
        'category_id' => $categoryNames[$value] ?? $value,
        'mode' => $value === 'buy' ? 'Buy' : 'Rent',
        'budget' => isset($filters['budget_minor']) ? '≤ ' . $money($filters['budget_minor']) : $value,
        'q' => $value,
        default => $label($value),
    };
    if ($key === 'budget' && ($filters['mode'] ?? '') === 'rent') { $display .= ' per day'; }
    $collectionActiveFilters[] = ['label' => ($collectionFilterLabels[$key] ?? 'Filter') . ': ' . $display, 'href' => $collectionPath . ($remaining === [] ? '' : '?' . http_build_query($remaining))];
}
?>
<section class="vision-discovery-section vision-collection-detail" aria-labelledby="collection-products-title">
    <?php if (!empty($errors)): ?><div class="alert" role="alert"><h2>Please check your filters</h2><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach ?></ul></div><?php endif ?>
    <div class="vision-discovery-heading"><div><p class="eyebrow">Curated existing products</p><h2 id="collection-products-title">Explore the <?= $e($collection['name']) ?> selection.</h2><p>Products remain part of the catalogue; collection membership sets this selection order.</p></div><p class="vision-discovery-note">Browsing does not reserve stock</p></div>
    <?php if ($collectionActiveFilters !== []): ?>
        <ul class="active-filters vision-filter-chips" aria-label="Active collection filters">
            <?php foreach ($collectionActiveFilters as $activeFilter): ?><li><?= $e($activeFilter['label']) ?> <a href="<?= $e($activeFilter['href']) ?>" aria-label="Remove <?= $e($activeFilter['label']) ?> filter">×</a></li><?php endforeach ?>
            <li><a href="<?= $e($collectionPath) ?>">Clear all</a></li>
        </ul>
    <?php endif ?>
    <div class="vision-catalogue-layout">
        <aside class="vision-filter-column">
            <details class="filter-disclosure">
                <summary>Filter this collection</summary>
                <form action="<?= $e($collectionPath) ?>" method="get" class="vision-filter-form">
                    <?php if (!empty($filters['q'])): ?><input type="hidden" name="q" value="<?= $e($filters['q']) ?>"><?php endif ?>
                    <div class="vision-filter-group"><label for="collection-category">Category</label><select id="collection-category" name="category_id"><option value="">All categories</option><?php foreach ($categories ?? [] as $category): ?><option value="<?= $e($category['category_id']) ?>" <?= (string) ($filters['category_id'] ?? '') === (string) $category['category_id'] ? 'selected' : '' ?>><?= $e($category['name']) ?></option><?php endforeach ?></select></div>
                    <div class="vision-filter-group"><label for="collection-kind">Product type</label><select id="collection-kind" name="kind"><option value="">Any type</option><?php foreach ($options['kind'] ?? [] as $option): ?><option value="<?= $e($option) ?>" <?= ($filters['kind'] ?? '') === $option ? 'selected' : '' ?>><?= $e($label($option)) ?></option><?php endforeach ?></select></div>
                    <div class="vision-filter-group"><label for="collection-mode">Mode</label><select id="collection-mode" name="mode"><option value="">Buy or rent</option><?php foreach ($options['mode'] ?? [] as $option): ?><option value="<?= $e($option) ?>" <?= ($filters['mode'] ?? '') === $option ? 'selected' : '' ?>><?= $option === 'buy' ? 'Buy' : 'Rent' ?></option><?php endforeach ?></select></div>
                    <div class="vision-filter-group"><label for="collection-size">Size</label><select id="collection-size" name="size"><option value="">Any verified size</option><?php foreach ($options['size'] ?? [] as $option): ?><option value="<?= $e($option) ?>" <?= ($filters['size'] ?? '') === $option ? 'selected' : '' ?>><?= $e(\Verdant\Catalogue\CollectionService::SIZE_FILTER_LABELS[$option] ?? $option) ?></option><?php endforeach ?></select></div>
                    <div class="vision-filter-group"><label for="collection-colour">Colour</label><select id="collection-colour" name="colour"><option value="">Any verified colour</option><?php foreach ($options['colour'] ?? [] as $option): ?><option value="<?= $e($option) ?>" <?= ($filters['colour'] ?? '') === $option ? 'selected' : '' ?>><?= $e($option) ?></option><?php endforeach ?></select></div>
                    <div class="vision-filter-group"><label for="collection-budget">Maximum budget (ZAR)</label><input id="collection-budget" name="budget" inputmode="decimal" pattern="[0-9]+(\.[0-9]{1,2})?" value="<?= $e($filters['budget'] ?? '') ?>" placeholder="e.g. 500"></div>
                    <?php foreach (['light_level' => 'Plant light', 'watering_level' => 'Plant watering', 'toxicity' => 'Plant safety'] as $careKey => $careLabel): ?>
                        <?php if (!empty($options[$careKey])): ?><div class="vision-filter-group"><label for="collection-<?= $e($careKey) ?>"><?= $e($careLabel) ?></label><select id="collection-<?= $e($careKey) ?>" name="<?= $e($careKey) ?>"><option value="">Any verified value</option><?php foreach ($options[$careKey] as $option): ?><option value="<?= $e($option) ?>" <?= ($filters[$careKey] ?? '') === $option ? 'selected' : '' ?>><?= $e($label($option)) ?></option><?php endforeach ?></select></div><?php endif ?>
                    <?php endforeach ?>
                    <div class="vision-filter-actions"><button class="btn-solid" type="submit">Apply filters</button><a href="<?= $e($collectionPath) ?>">Clear</a></div>
                </form>
            </details>
        </aside>
        <div class="vision-catalogue-results">
            <?php $searchAction = $collectionPath; $searchPreserve = $collectionQueryFilters ?? []; $searchId = 'collection-search'; $searchLabel = 'Search this collection'; require __DIR__ . '/catalogue-search.php'; ?>
            <div class="vision-results-heading"><p><?= $e(count($products)) ?> products on this page</p>
                <form action="<?= $e($collectionPath) ?>" method="get" class="vision-sort">
                    <?php foreach ($collectionQueryFilters as $key => $value): if ($key === 'sort') continue; ?><input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>"><?php endforeach ?>
                    <label for="collection-sort">Sort by</label><select id="collection-sort" name="sort"><option value="">Curated order</option><option value="price_asc" <?= ($filters['sort'] ?? '') === 'price_asc' ? 'selected' : '' ?>>Price: low to high</option><option value="price_desc" <?= ($filters['sort'] ?? '') === 'price_desc' ? 'selected' : '' ?>>Price: high to low</option></select><button class="vision-small-button" type="submit">Sort</button>
                </form>
            </div>
            <?php if (empty($products)): ?><div class="empty-state"><h2>No products match these filters</h2><p>Try removing a filter or exploring another collection.</p><a href="<?= $e($collectionPath) ?>" class="btn-outline">Clear filters</a></div><?php endif ?>
            <div class="vision-product-grid"><?php foreach ($products as $product): require __DIR__ . '/catalogue-card.php'; endforeach ?></div>
            <?php if (($page ?? 1) > 1 || !empty($hasNext)): ?><nav class="pagination" aria-label="Collection product pages"><?php if (($page ?? 1) > 1): ?><a href="<?= $e($collectionPath) ?>?<?= $e(http_build_query(array_merge($collectionQueryFilters, ['page' => $page - 1]))) ?>">Previous</a><?php endif ?><span>Page <?= $e($page) ?></span><?php if (!empty($hasNext)): ?><a href="<?= $e($collectionPath) ?>?<?= $e(http_build_query(array_merge($collectionQueryFilters, ['page' => $page + 1]))) ?>">Next</a><?php endif ?></nav><?php endif ?>
        </div>
    </div>
</section>
<?php if (!empty($compositions)): $arrangementImage = $collectionImage; require __DIR__ . '/collection-arrangements.php'; endif ?>
<section class="vision-discovery-section vision-collection-footer"><p><a href="/collections">← All collections</a> · <a href="/catalogue">Browse full catalogue</a></p></section>
