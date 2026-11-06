<?php
$rentalEditorialSections = is_array($pageSections ?? null) ? $pageSections : [];
$rentalEditorialRendered = false;
foreach ($rentalEditorialSections as $rentalSection) {
    if (!is_array($rentalSection)) { continue; }
    if (($rentalSection['section_type'] ?? '') === 'hero' && in_array(($rentalSection['section_key'] ?? ''), ['hero', 'intro'], true)) {
        $rentalHeroMedia = $rentalSection['media_path'] ?? null;
        if (is_string($rentalHeroMedia) && (
            str_starts_with($rentalHeroMedia, '/assets/images/catalogue/placeholders/')
            || $rentalHeroMedia === '/assets/images/product-media/hero-scenes-v02.png'
        )) {
            $rentalHeroMedia = '/assets/vision/images/gatherings.jpg';
        }
        $hero = [
            'image' => verdant_view_asset_path($rentalHeroMedia) ?? '/assets/vision/images/gatherings.jpg',
            'title' => $rentalSection['heading'] ?? 'Green spaces, for a little while.',
            'body' => $rentalSection['body'] ?? 'Thoughtfully selected plants, trees, pots and curated sets for events, offices and temporary spaces.',
            'eyebrow' => 'Plant rentals for beautiful spaces', 'id' => 'catalogue-title',
        ];
        require __DIR__ . '/page-hero.php';
        $rentalEditorialRendered = true;
    } elseif (($rentalSection['section_type'] ?? '') === 'editorial') {
        ?><aside class="vision-container vision-guidance"><h2><?= $e($rentalSection['heading'] ?? '') ?></h2><p><?= $e($rentalSection['body'] ?? '') ?></p></aside><?php
        $rentalEditorialRendered = true;
    }
}
if (!is_array($pageSections ?? null)) {
    $hero = [
        'image' => '/assets/vision/images/gatherings.jpg', 'title' => 'Green spaces, for a little while.',
        'body' => 'Thoughtfully selected plants, trees, pots and curated sets for events, offices and temporary spaces.',
        'eyebrow' => 'Plant rentals for beautiful spaces', 'id' => 'catalogue-title',
    ];
    require __DIR__ . '/page-hero.php';
}

$rentalFilters = $queryFilters ?? [];
$rentalFilters['mode'] = 'rent';
unset($rentalFilters['page']);
$rentalCategoryBase = $rentalFilters;
unset($rentalCategoryBase['kind'], $rentalCategoryBase['category_id']);
$rentalCategories = [
    ['name' => 'Plants', 'description' => 'Lush greenery for every occasion.', 'image' => '/assets/vision/images/p-bird.jpg', 'fit' => 'contain', 'query' => ['kind' => 'plant']],
];
foreach ($categories ?? [] as $category) {
    $categoryName = (string) ($category['name'] ?? '');
    $normalized = strtolower($categoryName);
    if (preg_match('/\b(tree|trees)\b/', $normalized) === 1) {
        $rentalCategories[] = ['name' => 'Trees', 'description' => 'Statement trees, big impact.', 'image' => '/assets/vision/images/r-olive.jpg', 'fit' => 'contain', 'query' => ['category_id' => (string) $category['category_id']]];
        break;
    }
}
foreach ($categories ?? [] as $category) {
    $categoryName = (string) ($category['name'] ?? '');
    if (preg_match('/\b(pot|pots|planter|planters)\b/i', $categoryName) === 1) {
        $rentalCategories[] = ['name' => 'Pots', 'description' => 'Beautiful pots to complete your look.', 'image' => '/assets/vision/images/p-stone.jpg', 'fit' => 'contain', 'query' => ['category_id' => (string) $category['category_id']]];
        break;
    }
}
?>
<section class="vision-discovery-section vision-rentals" aria-labelledby="rental-categories-title">
    <?php if (!empty($errors)): ?><div class="alert" role="alert"><h2>Please check your rental details</h2><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach ?></ul></div><?php endif ?>
    <div class="vision-rental-date-panel">
        <div>
            <p class="eyebrow">Choose your dates</p>
            <h2>Find the right plants for your rental period.</h2>
            <p>Choose a period to check eligible plants and a rental quote. Checking dates does not reserve stock.</p>
        </div>
        <?php
        $dateQuery = $rentalFilters;
        unset($dateQuery['start_date'], $dateQuery['end_date']);
        ?>
        <form action="/catalogue" method="get" class="vision-rental-date-form">
            <?php foreach ($dateQuery as $key => $value): ?><input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>"><?php endforeach ?>
            <label for="rental-start">Start date<input id="rental-start" type="date" name="start_date" value="<?= $e($rentalDates['start_date'] ?? '') ?>" required></label>
            <label for="rental-end">Return date<input id="rental-end" type="date" name="end_date" value="<?= $e($rentalDates['end_date'] ?? '') ?>" min="<?= $e($rentalDates['start_date'] ?? '') ?>" required></label>
            <button class="btn-solid" type="submit">Check availability <span aria-hidden="true">→</span></button>
        </form>
    </div>

    <div class="vision-discovery-heading">
        <div><p class="eyebrow">Rental categories</p><h2 id="rental-categories-title">Explore our rental collection.</h2></div>
        <a class="vision-discovery-reset" href="/catalogue?mode=rent">All rentals <span aria-hidden="true">→</span></a>
    </div>
    <div class="vision-rental-categories">
        <?php foreach ($rentalCategories as $category):
            $categoryQuery = array_merge($rentalCategoryBase, $category['query'], ['page' => 1]);
            $component = $category + ['href' => '/catalogue?' . http_build_query($categoryQuery), 'selected' => (($filters['kind'] ?? '') === ($category['query']['kind'] ?? null)) || (($filters['category_id'] ?? '') === ($category['query']['category_id'] ?? null))];
            require __DIR__ . '/rental-category-card.php';
        endforeach ?>
        <?php
        $component = [
            'name' => 'Curated sets',
            'description' => 'Cohesive plants, pots and styling.',
            'image' => '/assets/vision/images/gatherings.jpg',
            'fit' => 'cover',
            'href' => '/arrangements?mode=rent',
            'selected' => false,
        ];
        require __DIR__ . '/rental-category-card.php';
        ?>
    </div>

    <section class="vision-rental-service" aria-labelledby="rental-service-title">
        <div><p class="eyebrow">A considered setup</p><h2 id="rental-service-title">Delivered, styled and collected.</h2></div>
        <div><h3>Your rental period</h3><p>Choose delivery and return dates to check eligible stock and current quotes.</p></div>
        <div><h3>Care and collection</h3><p>The store confirms care guidance and collection details during the rental journey. Rented pieces and purchased extras are listed separately; purchased items do not need to be returned.</p></div>
    </section>
    <section class="vision-rental-arrangements" aria-labelledby="rental-arrangements-title">
        <div><p class="eyebrow">Curated arrangements</p><h2 id="rental-arrangements-title">Ready-made combinations for your space.</h2>
            <p>Explore the active rental arrangements and check their current components, availability and quote in the arrangement builder.</p></div>
        <?php $rentalSets = is_array($rentalSets ?? null) ? $rentalSets : []; ?>
        <?php if ($rentalSets !== []): ?>
        <div class="arrangement-offer-grid vision-rental-sets">
            <?php foreach ($rentalSets as $arrangement): ?>
                <?php require __DIR__ . '/../components/arrangement-offer-card.php'; ?>
            <?php endforeach ?>
        </div>
        <?php endif ?>
        <a class="btn-outline" href="/arrangements?mode=rent">Explore rental arrangements <span aria-hidden="true">→</span></a>
    </section>
    <div class="vision-rental-results">
        <div class="vision-discovery-heading">
            <div><p class="eyebrow">Available rental products</p><h2><?= !empty($rentalDates['has_dates']) ? 'Your selected rental period' : 'Popular plants and pieces for rent.' ?></h2></div>
            <p class="vision-discovery-note"><?= $e(count($products)) ?> eligible product<?= count($products) === 1 ? '' : 's' ?> on this page</p>
        </div>
        <div class="vision-catalogue-layout">
            <?php require __DIR__ . '/catalogue-filters.php'; ?>
            <div class="vision-catalogue-results">
                <?php $searchAction = '/catalogue'; $searchPreserve = $rentalFilters ?? []; $searchId = 'rental-search'; $searchLabel = 'Search rentals'; require __DIR__ . '/catalogue-search.php'; ?>
                <div class="vision-results-heading"><p><?= $e(count($products)) ?> products on this page</p>
                    <form action="/catalogue" method="get" class="vision-sort">
                        <?php foreach ($queryFilters ?? [] as $key => $value): if ($key === 'sort') continue; ?><input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>"><?php endforeach ?>
                        <label for="rental-sort">Sort by</label><select id="rental-sort" name="sort"><option value="variety" <?= in_array($filters['sort'] ?? 'variety', ['variety', ''], true) ? 'selected' : '' ?>>Variety</option><option value="name" <?= ($filters['sort'] ?? '') === 'name' ? 'selected' : '' ?>>Name</option><option value="price_asc" <?= ($filters['sort'] ?? '') === 'price_asc' ? 'selected' : '' ?>>Price: low to high</option><option value="price_desc" <?= ($filters['sort'] ?? '') === 'price_desc' ? 'selected' : '' ?>>Price: high to low</option></select><button class="vision-small-button" type="submit">Sort</button>
                    </form>
                </div>
                <?php if (!empty($filterPanel['active'])): ?><ul class="active-filters vision-filter-chips" aria-label="Active filters"><?php foreach ($filterPanel['active'] as $chip): ?><li><?= $e($chip['label']) ?> <a href="<?= $e($chip['remove_href']) ?>" aria-label="Remove <?= $e($chip['label']) ?>">×</a></li><?php endforeach ?></ul><?php endif ?>
                <?php if (!$products): ?><div class="empty-state"><h2>No rentals match these filters</h2><p>Try another category or rental period. Flowers are available to buy only.</p><a href="/catalogue?mode=rent" class="btn-outline">View all rentals</a></div><?php endif ?>
                <div class="vision-product-grid"><?php foreach ($products as $product): require __DIR__ . '/catalogue-card.php'; endforeach ?></div>
                <?php if (($page ?? 1) > 1 || !empty($hasNext)): ?><nav class="pagination" aria-label="Rental product pages"><?php if (($page ?? 1) > 1): ?><a href="/catalogue?<?= $e(http_build_query(array_merge($rentalFilters, ['page' => $page - 1]))) ?>">Previous</a><?php endif ?><span>Page <?= $e($page) ?></span><?php if (!empty($hasNext)): ?><a href="/catalogue?<?= $e(http_build_query(array_merge($rentalFilters, ['page' => $page + 1]))) ?>">Next</a><?php endif ?></nav><?php endif ?>
            </div>
        </div>
    </div>
</section>
<script src="/assets/js/vision-catalogue.js?v=<?= $e((string) filemtime(dirname(__DIR__, 3) . '/public/assets/js/vision-catalogue.js')) ?>" defer></script>
