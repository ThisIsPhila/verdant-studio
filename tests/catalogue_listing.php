<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
putenv('APP_ENV=local');

use Verdant\Catalogue\CollectionService;
use Verdant\Http\CatalogueController;
use Verdant\Http\View;
use Verdant\Support\ValidationException;

$checks = 0;
function catalogueListingCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
}

$presentation = new ReflectionMethod(CatalogueController::class, 'filterPresentation');
[$filterPanel] = $presentation->invoke(null, [
    'products' => [], 'categories' => [],
    'options' => ['size' => ['Small', 'Medium', 'Large', 'Bunch of 10', 'Standard']],
    'filters' => ['size' => 'Medium'],
]);
$sizeField = null;
foreach ($filterPanel['fields'] as $field) {
    if (($field['name'] ?? '') === 'size') {
        $sizeField = $field;
    }
}
catalogueListingCheck($sizeField !== null, 'Catalogue exposes a Size filter.');
catalogueListingCheck(
    array_column($sizeField['options'] ?? [], 'value') === ['', 'Small', 'Medium', 'Large'],
    'Size filter offers only Any size plus Small/Medium/Large.',
);
catalogueListingCheck(($sizeField['value'] ?? null) === 'Medium', 'Selected size is preserved.');
$sizeLabels = implode(' ', array_column($sizeField['options'] ?? [], 'label'));
catalogueListingCheck(
    str_contains($sizeLabels, 'tabletop') && str_contains($sizeLabels, 'floor'),
    'Size options describe where each size fits.',
);

$listingProducts = [
    [
        'product_id' => 201, 'sku' => 'PLANT-201', 'name' => 'Monstera',
        'category_name' => 'Indoor plants', 'product_kind' => 'plant', 'image_path' => null,
        'image_alt' => 'Monstera leaves', 'size_label' => 'Medium', 'selling_unit' => 'Each',
        'light_level' => 'indirect', 'watering_level' => 'weekly', 'toxicity' => 'non_toxic',
        'buy_enabled' => 1, 'rent_enabled' => 1, 'retail_price_minor' => 28000, 'rental_daily_rate_minor' => 1800,
    ],
    [
        'product_id' => 202, 'sku' => 'PLANT-202', 'name' => 'Unmatched Oddity',
        'category_name' => 'Indoor plants', 'product_kind' => 'plant', 'image_path' => null,
        'image_alt' => 'Odd plant', 'size_label' => 'Small', 'selling_unit' => 'Each',
        'light_level' => 'indirect', 'watering_level' => 'weekly', 'toxicity' => 'non_toxic',
        'buy_enabled' => 1, 'rent_enabled' => 0, 'retail_price_minor' => 9900, 'rental_daily_rate_minor' => null,
    ],
    [
        'product_id' => 203, 'sku' => 'PLANT-203', 'name' => 'Rendered Fern',
        'category_name' => 'Indoor plants', 'product_kind' => 'plant', 'image_path' => null,
        'image_alt' => 'Fern', 'size_label' => 'Large', 'selling_unit' => 'Each',
        'light_level' => 'indirect', 'watering_level' => 'weekly', 'toxicity' => 'non_toxic',
        'buy_enabled' => 1, 'rent_enabled' => 0, 'retail_price_minor' => 18900, 'rental_daily_rate_minor' => null,
        'thumbnail' => ['mode' => 'scene', 'scene' => ['layers' => []], 'scene_alt' => 'Fern scene'],
    ],
];
[$listingPanel, $listingQuery] = $presentation->invoke(null, [
    'products' => $listingProducts, 'categories' => [], 'options' => ['size' => ['Small']],
    'filters' => ['size' => ''],
]);
$listingData = [
    'products' => $listingProducts, 'categories' => [], 'options' => ['size' => ['Small']],
    'filters' => ['size' => ''], 'total' => 38, 'page' => 1, 'hasNext' => true,
    'title' => 'Botanical catalogue', 'actor' => null, 'errors' => [],
    'filterPanel' => $listingPanel, 'queryFilters' => $listingQuery, 'pageSections' => [],
];
ob_start();
View::render('catalogue', $listingData);
$html = (string) ob_get_clean();

catalogueListingCheck(str_contains($html, '>38 products<'), 'Results heading shows the catalogue total, not the page count.');
catalogueListingCheck(!str_contains($html, 'products on this page'), 'Per-page wording no longer sits in the results heading.');
catalogueListingCheck(
    str_contains($html, 'Page 1') && str_contains($html, '3 on this page'),
    'Pagination region reports how many products are on this page.',
);
preg_match_all('/<article class="product-card catalogue-card">.*?<\/article>/s', $html, $cards);
$cardsByName = [];
foreach ($cards[0] as $cardHtml) {
    foreach (['Monstera', 'Unmatched Oddity', 'Rendered Fern'] as $name) {
        if (str_contains($cardHtml, $name)) {
            $cardsByName[$name] = $cardHtml;
        }
    }
}
catalogueListingCheck(
    isset($cardsByName['Monstera']) && str_contains($cardsByName['Monstera'], '/assets/vision/images/p-monstera.jpg'),
    'Prototype photography is kept on catalogue cards.',
);
catalogueListingCheck(
    isset($cardsByName['Rendered Fern']) && str_contains($cardsByName['Rendered Fern'], 'data-thumbnail-canvas'),
    'Internally rendered scenes are kept on catalogue cards.',
);
catalogueListingCheck(
    isset($cardsByName['Unmatched Oddity'])
        && str_contains($cardsByName['Unmatched Oddity'], 'vision-card-plain')
        && !str_contains($cardsByName['Unmatched Oddity'], '<img'),
    'Cards without photography or renders use a plain placeholder with no image.',
);
catalogueListingCheck(
    isset($cardsByName['Unmatched Oddity'])
        && str_contains($cardsByName['Unmatched Oddity'], 'aria-label="Unmatched Oddity"'),
    'Plain placeholder cards keep an accessible product link.',
);

$singleData = $listingData;
$singleData['products'] = [$listingProducts[1]];
$singleData['total'] = 1;
$singleData['hasNext'] = false;
ob_start();
View::render('catalogue', $singleData);
$singleHtml = (string) ob_get_clean();
catalogueListingCheck(str_contains($singleHtml, '>1 product<'), 'Single-result heading uses the singular.');
catalogueListingCheck(
    str_contains($singleHtml, 'aria-label="Catalogue pages"') && str_contains($singleHtml, '1 on this page'),
    'Per-page count stays visible even without additional pages.',
);

$varietyFilters = CollectionService::normalizeFilters(['sort' => 'variety']);
catalogueListingCheck(($varietyFilters['sort'] ?? null) === 'variety', 'Variety sort passes filter validation.');
try {
    CollectionService::normalizeFilters(['sort' => 'bogus']);
    catalogueListingCheck(false, 'Invalid sort orders are rejected.');
} catch (ValidationException $error) {
    catalogueListingCheck(isset($error->errors['sort']), 'Invalid sort orders are rejected.');
}
catalogueListingCheck(str_contains($html, '<option value="variety"'), 'Sort dropdown offers the Variety spread.');
catalogueListingCheck(
    str_contains($html, 'pagination-count') && str_contains($html, 'Page 1'),
    'Per-page count sits in its own pagination element.',
);

$searchFilters = CollectionService::normalizeFilters(['q' => '  fern ']);
catalogueListingCheck(($searchFilters['q'] ?? null) === 'fern', 'Search terms are trimmed like other filters.');
try {
    CollectionService::normalizeFilters(['q' => str_repeat('x', 121)]);
    catalogueListingCheck(false, 'Overlong search terms are rejected.');
} catch (ValidationException $error) {
    catalogueListingCheck(isset($error->errors['q']), 'Overlong search terms are rejected.');
}
[$searchPanel] = $presentation->invoke(null, [
    'products' => [], 'categories' => [], 'options' => [], 'filters' => ['q' => 'fern'],
]);
$searchChip = null;
foreach ($searchPanel['active'] ?? [] as $chip) {
    if (str_starts_with((string) ($chip['label'] ?? ''), 'Search:')) {
        $searchChip = $chip;
    }
}
catalogueListingCheck(
    $searchChip !== null && $searchChip['label'] === 'Search: fern' && ($searchChip['remove_href'] ?? null) === '/catalogue',
    'An active search renders a removable Search chip.',
);

$_SERVER['HTTP_HOST'] = 'verdant.localhost:8088';
$detailProduct = [
    'product_id' => 301, 'category_id' => 9, 'name' => 'Bird of paradise',
    'category_name' => 'Potted plants', 'product_kind' => 'plant',
    'display_size' => 'Medium', 'display_description' => 'Medium · Each · Potted plants',
    'variants' => [], 'compatible_pots' => [], 'selected_pot' => null,
    'selection_can_buy' => false, 'selection_can_rent' => false,
    'selection_retail_price_minor' => 0, 'selection_rental_rate_minor' => 0,
    'details_status' => 'demonstration',
];
ob_start();
View::render('product', ['product' => $detailProduct, 'title' => 'Bird of paradise', 'actor' => null, 'mediaGallery' => null, 'plantChoices' => []]);
$detailHtml = (string) ob_get_clean();
preg_match('/<p class="vision-product-configure">.*?<\/p>/s', $detailHtml, $configureParagraph);
catalogueListingCheck(
    isset($configureParagraph[0]) && str_contains($configureParagraph[0], 'class="btn-outline" href="/configure?plant=301"'),
    'Configure entry uses the secondary outline style.',
);
catalogueListingCheck(
    !isset($configureParagraph[0]) || !str_contains($configureParagraph[0], 'btn-solid'),
    'Configure entry is not styled as a primary call to action.',
);
catalogueListingCheck(str_contains($detailHtml, 'Medium · Each · Potted plants'), 'Product detail shows a description.');

echo "Catalogue listing checks: {$checks} passed, 0 failed.\n";
