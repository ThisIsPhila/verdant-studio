<?php
$managedSections = $pageSections ?? null;
$homesManaged = is_array($managedSections);
$homesQueryFilters = $filters ?? [];
unset($homesQueryFilters['budget_minor'], $homesQueryFilters['page']);
$homesQueryFilters = array_filter($homesQueryFilters, static fn (mixed $value): bool => is_string($value) && $value !== '');
$homesProductSection = null;
$homesProductsRendered = false;

if ($homesManaged) {
    foreach ($managedSections as $section) {
        if (!is_array($section)) { continue; }
        if (($section['section_key'] ?? '') === 'hero' && ($section['section_type'] ?? '') === 'hero') {
            require __DIR__ . '/homes-hero.php';
        } elseif (($section['section_key'] ?? '') === 'collection' && ($section['section_type'] ?? '') === 'collection') {
            require __DIR__ . '/homes-arrangements.php';
        } elseif (($section['section_key'] ?? '') === 'products' && ($section['section_type'] ?? '') === 'product_grid') {
            $homesProductSection = $section;
            require __DIR__ . '/homes-products.php';
            $homesProductsRendered = true;
        }
    }
} else {
    $section = [];
    require __DIR__ . '/homes-hero.php';
    if (!empty($compositions)) { require __DIR__ . '/homes-arrangements.php'; }
    $homesProductSection = null;
    require __DIR__ . '/homes-products.php';
    $homesProductsRendered = true;
}

// Product selection is a fixed commerce control; optional editorial sections may be hidden.
if (!$homesProductsRendered) {
    $homesProductSection = null;
    require __DIR__ . '/homes-products.php';
}

if ($errors ?? []) {
    ?><div class="alert" role="alert"><h2>Please check your filters</h2><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach ?></ul></div><?php
}
?>
