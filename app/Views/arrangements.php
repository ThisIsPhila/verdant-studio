<?php
$visionPage = true;
$visionComponentStyles = ['customer-records', 'arrangements'];
require_once __DIR__ . '/components/_helpers.php';
$sectionInput = $pageSections ?? null;
$arrangementSections = [];
foreach (is_array($sectionInput) ? $sectionInput : [] as $section) {
    if (is_array($section) && isset($section['section_key'])) {
        $arrangementSections[$section['section_key']] = $section;
    }
}
$sectionsManaged = is_array($sectionInput);
$heroSection = $arrangementSections['hero'] ?? null;
$discoverySection = $arrangementSections['discovery'] ?? null;
$queryFilters = $filters;
unset($queryFilters['budget_minor'], $queryFilters['page'], $queryFilters['estimated_buy_floor_minor'], $queryFilters['estimated_rent_floor_minor']);
$queryFilters = array_filter($queryFilters, static fn ($value): bool => $value !== '' && $value !== null);
$activeChips = [];
if (($filters['q'] ?? '') !== '') { $activeChips['q'] = 'Search: ' . $filters['q']; }
if (($filters['space'] ?? '') !== '') { $activeChips['space'] = 'Space: ' . ($options['space'][$filters['space']] ?? 'Homes'); }
if (($filters['size'] ?? '') !== '') { $activeChips['size'] = 'Component size: ' . $filters['size']; }
if (($filters['mode'] ?? '') !== '') { $activeChips['mode'] = 'Mode: ' . ($filters['mode'] === 'rent' ? 'Buy or rent components' : 'Buy only'); }
if (($filters['budget'] ?? '') !== '') { $activeChips['budget'] = 'Minimum component estimate: ≤ ' . $money($filters['budget_minor']); }
?>

<?php
$presentationSections = $sectionsManaged ? $sectionInput : [['section_key' => 'hero', 'section_type' => 'hero']];
foreach ($presentationSections as $section) {
    if (($section['section_key'] ?? '') === 'hero' && ($section['section_type'] ?? '') === 'hero') {
        $heroSection = $section;
        require __DIR__ . '/sections/arrangements-hero.php';
    } elseif (($section['section_key'] ?? '') === 'discovery' && ($section['section_type'] ?? '') === 'arrangement_grid') { ?>
        <details class="arrangement-about"><summary>About this collection</summary>
            <h2><?= $e($section['heading']) ?></h2><p><?= $e($section['body']) ?></p>
            <?php if (!empty($section['link_path']) && !empty($section['link_label'])): ?><a href="<?= $e(verdant_view_local_path($section['link_path'], '/catalogue')) ?>"><?= $e($section['link_label']) ?></a><?php endif ?>
        </details>
    <?php }
}
?>

<?php if ($errors): ?>
    <div class="alert" role="alert"><h2>Please check your filters</h2><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach ?></ul></div>
<?php endif ?>

<section class="arrangement-shop" aria-labelledby="arrangement-results-title">
    <div class="arrangement-shop-heading">
        <div>
            <p class="eyebrow">Designed to belong together</p>
            <h2 id="arrangement-results-title">Find an arrangement for your space.</h2>

        </div>
        <form class="arrangement-search" action="/arrangements" method="get">
            <label for="arrangement-search">Search arrangements</label>
            <input id="arrangement-search" type="search" name="q" placeholder="Search arrangements" maxlength="120" value="<?= $e($filters['q'] ?? '') ?>">
            <?php foreach (['space','size','mode','budget'] as $key): if (($filters[$key] ?? '') === '') continue; ?>
                <input type="hidden" name="<?= $e($key) ?>" value="<?= $e($filters[$key]) ?>">
            <?php endforeach ?>
            <button type="submit">Search</button>
        </form>
    </div>
    <nav class="arrangement-space-tabs" aria-label="Arrangement space">
        <?php foreach (['' => 'All spaces'] + $options['space'] as $spaceValue => $spaceLabel):
            $spaceQuery = $queryFilters; unset($spaceQuery['page']);
            if ($spaceValue === '') unset($spaceQuery['space']); else $spaceQuery['space'] = $spaceValue;
        ?>
        <a href="/arrangements<?= $spaceQuery ? '?' . $e(http_build_query($spaceQuery)) : '' ?>"<?= $filters['space'] === $spaceValue ? ' aria-current="page"' : '' ?>><?= $e($spaceLabel) ?></a>
        <?php endforeach ?>
    </nav>
    <div class="arrangement-results-toolbar">
        <p class="vision-arrangement-count" role="status"><?= $e($total) ?> available arrangement<?= $total === 1 ? '' : 's' ?></p>
    </div>

    <?php if ($activeChips !== []): ?>
        <ul class="active-filters" aria-label="Active arrangement filters">
            <?php foreach ($activeChips as $key => $chipLabel): ?>
                <?php $removeFilters = $queryFilters; unset($removeFilters[$key]); ?>
                <li><span><?= $e($chipLabel) ?></span><a href="/arrangements<?= $removeFilters === [] ? '' : '?' . $e(http_build_query($removeFilters)) ?>" aria-label="Remove <?= $e($chipLabel) ?> filter">×</a></li>
            <?php endforeach ?>
            <li><a href="/arrangements">Clear all</a></li>
        </ul>
    <?php endif ?>

    <details class="filter-disclosure vision-arrangement-filters">
        <summary>Filter arrangements <span class="muted">Space, component size, mode and budget</span></summary>
        <form class="filter-form" action="/arrangements" method="get">
            <input type="hidden" name="q" value="<?= $e($filters['q'] ?? '') ?>">
            <div class="filter-fields">
                <div class="field"><label for="arrangement-space">Space</label><select id="arrangement-space" name="space"><option value="">Any space</option><?php foreach ($options['space'] as $value => $spaceLabel): ?><option value="<?= $e($value) ?>" <?= $filters['space'] === $value ? 'selected' : '' ?>><?= $e($spaceLabel) ?></option><?php endforeach ?></select></div>
                <div class="field"><label for="arrangement-size">Component size</label><select id="arrangement-size" name="size"><option value="">Any size</option><?php foreach ($options['size'] as $value): ?><option value="<?= $e($value) ?>" <?= $filters['size'] === $value ? 'selected' : '' ?>><?= $e($value) ?></option><?php endforeach ?></select></div>
                <div class="field"><label for="arrangement-mode">Mode</label><select id="arrangement-mode" name="mode"><option value="">Buy or rent components</option><?php foreach ($options['mode'] as $value => $modeLabel): ?><option value="<?= $e($value) ?>" <?= $filters['mode'] === $value ? 'selected' : '' ?>><?= $e($modeLabel) ?></option><?php endforeach ?></select></div>
                <div class="field"><label for="arrangement-budget">Minimum component estimate budget (ZAR)</label><input id="arrangement-budget" name="budget" inputmode="decimal" pattern="[0-9]+(\.[0-9]{1,2})?" value="<?= $e($filters['budget']) ?>" placeholder="e.g. 500"><small>Uses required selections and minimum quantities. Rental estimates are per day; final options, dates and totals are checked during configuration.</small></div>
            </div>
            <div class="form-actions"><button class="button" type="submit">Apply filters</button><a class="text-link" href="/arrangements">Clear filters</a></div>
        </form>
    </details>

    <?php if ($arrangements === []): ?>
        <div class="empty-state"><h3>No arrangements match these filters</h3><p>Try a different component size, mode or budget. Configuration checks current eligible options.</p><a class="button" href="/arrangements">Clear filters</a></div>
    <?php else: ?>
        <div class="arrangement-offer-grid">
            <?php foreach ($arrangements as $arrangement): ?>
                <?php require __DIR__ . '/components/arrangement-offer-card.php'; ?>
            <?php endforeach ?>
        </div>
    <?php endif ?>

    <?php if ($page > 1 || $hasNext): ?>
        <?php $pageQuery = $queryFilters; if ($filters['budget'] !== '') { $pageQuery['budget'] = $filters['budget']; } ?>
        <nav class="pagination" aria-label="Arrangement result pages">
            <?php if ($page > 1): ?><a href="/arrangements?<?= $e(http_build_query(array_merge($pageQuery, ['page' => $page - 1]))) ?>">Previous</a><?php endif ?>
            <span>Page <?= $e($page) ?></span>
            <?php if ($hasNext): ?><a href="/arrangements?<?= $e(http_build_query(array_merge($pageQuery, ['page' => $page + 1]))) ?>">Next</a><?php endif ?>
        </nav>
    <?php endif ?>
</section>

<aside class="arrangement-rental-banner" aria-labelledby="arrangement-rental-title">
    <div><p class="eyebrow">For a special occasion</p><h2 id="arrangement-rental-title">Beautiful greenery, just for the moment.</h2><p>Explore plants and complete sets available to rent.</p></div>
    <a class="button button--primary" href="/catalogue?mode=rent">Browse rentals →</a>
</aside>
