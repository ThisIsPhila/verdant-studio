<?php
$visionPage = true;
require_once __DIR__ . '/components/_helpers.php';
$activeKind = (string) ($filters['kind'] ?? '');
$activeMode = (string) ($filters['mode'] ?? '');

if ($activeMode === 'rent') {
    $visionComponentStyles = ['discovery', 'arrangements'];
    require __DIR__ . '/vision/rentals.php';
    return;
}

$introSections = $pageSections ?? [['section_key' => 'intro', 'section_type' => 'editorial', 'heading' => 'Plants for your space.', 'body' => 'Plants, trees, flowers and planters for a calmer, greener space.']];
foreach ($introSections as $intro) {
    $introKey = $intro['section_key'] ?? '';
    if (in_array($introKey, ['intro', 'hero'], true)) {
        $hero = ['image' => '/assets/vision/images/hero-plants.jpg', 'title' => $intro['heading'], 'body' => $intro['body'], 'eyebrow' => 'Shop the collection', 'id' => 'catalogue-title'];
        require __DIR__ . '/vision/page-hero.php';
    } elseif (($intro['section_type'] ?? '') === 'editorial') {
        ?><aside class="vision-container vision-guidance"><h2><?= $e($intro['heading']) ?></h2><p><?= $e($intro['body']) ?></p></aside><?php
    }
}
?>
<section class="vision-container vision-catalogue" aria-label="Product catalogue">
    <nav class="vision-category-tabs" aria-label="Product categories">
        <a href="/catalogue" <?= ($queryFilters ?? []) === [] ? 'aria-current="page"' : '' ?>>All products</a>
        <?php foreach ($categories as $category): ?>
            <a href="/catalogue?<?= $e(http_build_query(array_merge($queryFilters ?? [], ['category_id' => $category['category_id'], 'page' => 1]))) ?>" <?= (string) ($filters['category_id'] ?? '') === (string) $category['category_id'] ? 'aria-current="page"' : '' ?>><?= $e($category['name']) ?></a>
        <?php endforeach ?>
    </nav>
    <?php if (!empty($errors)): ?><div class="alert" role="alert"><h2>Please check your filters</h2><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach ?></ul></div><?php endif ?>
    <div class="vision-catalogue-layout">
        <?php require __DIR__ . '/vision/catalogue-filters.php'; ?>
        <div class="vision-catalogue-results">
            <?php $searchAction = '/catalogue'; $searchPreserve = $queryFilters ?? []; $searchId = 'catalogue-search'; $searchLabel = 'Search products'; require __DIR__ . '/vision/catalogue-search.php'; ?>
            <?php $catalogueTotal = (int) ($total ?? count($products)); ?>
            <div class="vision-results-heading"><p><?= $e($catalogueTotal) ?> <?= $catalogueTotal === 1 ? 'product' : 'products' ?></p>
                <form action="/catalogue" method="get" class="vision-sort">
                    <?php foreach ($queryFilters ?? [] as $key => $value): if ($key === 'sort') continue; ?><input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>"><?php endforeach ?>
                    <label for="catalogue-sort">Sort by</label><select id="catalogue-sort" name="sort"><option value="variety" <?= in_array($filters['sort'] ?? 'variety', ['variety', ''], true) ? 'selected' : '' ?>>Variety</option><option value="name" <?= ($filters['sort'] ?? '') === 'name' ? 'selected' : '' ?>>Name</option><option value="price_asc" <?= ($filters['sort'] ?? '') === 'price_asc' ? 'selected' : '' ?>>Price: low to high</option><option value="price_desc" <?= ($filters['sort'] ?? '') === 'price_desc' ? 'selected' : '' ?>>Price: high to low</option></select><button class="vision-small-button">Sort</button>
                </form>
            </div>
            <?php if (!empty($filterPanel['active'])): ?><ul class="active-filters vision-filter-chips" aria-label="Active filters"><?php foreach ($filterPanel['active'] as $chip): ?><li><?= $e($chip['label']) ?> <a href="<?= $e($chip['remove_href']) ?>" aria-label="Remove <?= $e($chip['label']) ?>">×</a></li><?php endforeach ?></ul><?php endif ?>
            <?php if (!$products): ?><div class="empty-state"><h2>No products match these filters</h2><p>Try a different combination.</p><a href="/catalogue" class="btn-outline">View all products</a></div><?php endif ?>
            <div class="vision-product-grid"><?php foreach ($products as $product): require __DIR__ . '/vision/catalogue-card.php'; endforeach ?></div>
            <nav class="pagination" aria-label="Catalogue pages"><div class="pagination-links"><?php if (($page ?? 1) > 1): ?><a href="/catalogue?<?= $e(http_build_query(array_merge($queryFilters ?? [], ['page' => $page - 1]))) ?>">Previous</a><?php endif ?><span>Page <?= $e($page) ?></span><?php if (!empty($hasNext)): ?><a href="/catalogue?<?= $e(http_build_query(array_merge($queryFilters ?? [], ['page' => $page + 1]))) ?>">Next</a><?php endif ?></div><p class="pagination-count"><?= $e(count($products)) ?> on this page</p></nav>
        </div>
    </div>
</section>
<script src="/assets/js/vision-catalogue.js?v=<?= $e((string) filemtime(dirname(__DIR__, 2) . '/public/assets/js/vision-catalogue.js')) ?>" defer></script>
