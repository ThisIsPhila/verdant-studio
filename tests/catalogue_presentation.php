<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
putenv('APP_ENV=local');

use Verdant\Http\CatalogueController;
use Verdant\Http\View;

$checks = 0;
function cataloguePresentationCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
}

$catalogueData = [
            'products' => [
                [
                    'product_id' => 101, 'sku' => 'PLANT-101', 'name' => 'Monstera deliciosa',
                    'category_name' => 'Indoor plants', 'product_kind' => 'plant', 'image_path' => null,
                    'image_alt' => 'Monstera leaves', 'size_label' => 'Large', 'selling_unit' => 'Each',
                    'light_level' => 'indirect', 'watering_level' => 'unknown', 'toxicity' => 'unknown',
                    'buy_enabled' => 1, 'rent_enabled' => 1, 'retail_price_minor' => 125000, 'rental_daily_rate_minor' => 800,
                ],
                [
                    'product_id' => 102, 'sku' => 'FLOWER-102', 'name' => 'White cut flowers',
                    'category_name' => 'Flowers', 'product_kind' => 'cut_flower', 'image_path' => null,
                    'image_alt' => 'White cut flowers', 'size_label' => null, 'selling_unit' => 'Bundle',
                    'light_level' => 'unknown', 'watering_level' => 'unknown', 'toxicity' => 'unknown',
                    'buy_enabled' => 1, 'rent_enabled' => 0, 'retail_price_minor' => 2500, 'rental_daily_rate_minor' => null,
                ],
                [
                    'product_id' => 103, 'sku' => 'TOOL-103', 'name' => 'Decorative plant stand',
                    'category_name' => 'Accessories', 'product_kind' => 'accessory', 'image_path' => null,
                    'image_alt' => 'Plant stand', 'size_label' => null, 'selling_unit' => 'Each',
                    'light_level' => 'unknown', 'watering_level' => 'unknown', 'toxicity' => 'unknown',
                    'buy_enabled' => 0, 'rent_enabled' => 1, 'retail_price_minor' => null, 'rental_daily_rate_minor' => 350,
                ],
            ],
            'categories' => [['category_id' => 7, 'name' => 'Indoor plants'], ['category_id' => 8, 'name' => 'Flowers']],
            'options' => [
                'kind' => ['plant', 'cut_flower', 'accessory'], 'mode' => ['buy', 'rent'],
                'size' => ['Large'], 'colour' => ['Green'], 'light_level' => ['indirect'],
                'watering_level' => ['weekly'], 'toxicity' => ['non_toxic'],
            ],
            'filters' => [
                'category_id' => '7', 'kind' => 'plant', 'mode' => 'rent', 'size' => 'Large', 'colour' => '',
                'budget' => '500.25', 'budget_minor' => 50025, 'light_level' => 'indirect',
                'watering_level' => '', 'toxicity' => '',
            ],
            'page' => 2, 'hasNext' => true,
        ];
$pagePresentation = new ReflectionMethod(CatalogueController::class, 'filterPresentation');
[$filterPanel, $queryFilters] = $pagePresentation->invoke(null, $catalogueData);
$catalogueData += [
    'title' => 'Botanical catalogue', 'actor' => null, 'errors' => [],
    'filterPanel' => $filterPanel, 'queryFilters' => $queryFilters,
    'pageSections' => [[
        'section_key' => 'hero', 'section_type' => 'hero', 'heading' => 'Managed catalogue',
        'body' => 'An edited introduction.', 'link_path' => '//unsafe.example', 'link_label' => 'Unsafe link',
    ]],
];
ob_start();
View::render('catalogue', $catalogueData);
$html = (string) ob_get_clean();

cataloguePresentationCheck(str_contains($html, '<details class="filter-disclosure vision-filter-disclosure">') && !str_contains($html, '<details class="filter-disclosure vision-filter-disclosure" open'), 'Catalogue filters are collapsed by default.');
cataloguePresentationCheck(str_contains($html, 'value="500.25"') && str_contains($html, 'inputmode="decimal"'), 'Decimal budget query state is preserved for touch keyboards.');
cataloguePresentationCheck(str_contains($html, 'value="rent" checked') && str_contains($html, 'value="indirect" checked'), 'Selected product mode and care filters are preserved.');
cataloguePresentationCheck(str_contains($html, 'Indoor plants') && str_contains($html, 'Mode: Rent') && str_contains($html, 'Budget: ≤ R 500.25 / day'), 'Active filter chips use readable labels.');
cataloguePresentationCheck(!str_contains($html, 'budget_minor'), 'Internal budget_minor never leaks into visible/query URLs.');
cataloguePresentationCheck(str_contains($html, '/catalogue?') && str_contains($html, 'page=3') && str_contains($html, 'budget=500.25'), 'Pagination retains public filter values and the decimal budget.');
cataloguePresentationCheck(substr_count($html, 'class="product-card catalogue-card"') === 3, 'All returned product kinds use the compact shared card.');
cataloguePresentationCheck(!str_contains($html, 'SKU PLANT-101') && str_contains($html, 'Light: Indirect'), 'Browsing cards hide internal SKU codes and retain verified care.');

preg_match_all('/<article class="product-card catalogue-card">.*?<\/article>/s', $html, $cards);
$flowerCard = null;
$rentalCard = null;
foreach ($cards[0] as $cardHtml) {
    if (str_contains($cardHtml, 'White cut flowers')) { $flowerCard = $cardHtml; }
    if (str_contains($cardHtml, 'Decorative plant stand')) { $rentalCard = $cardHtml; }
}
cataloguePresentationCheck($flowerCard !== null && !str_contains($flowerCard, '/rental?product_id='), 'Purchase-only flowers do not display a rental action.');
cataloguePresentationCheck($rentalCard !== null && !str_contains($rentalCard, '/cart/add') && str_contains($rentalCard, '/rental?product_id=103'), 'Rental-only products show a date entry and no purchase form.');
cataloguePresentationCheck(str_contains($html, 'name="csrf"') && str_contains($html, 'name="quantity" value="1"'), 'Buy action uses the existing CSRF-protected cart POST contract.');
cataloguePresentationCheck(preg_match('/<h1 id="catalogue-title"[^>]*>Managed catalogue<\/h1>/', $html) === 1 && !str_contains($html, 'href="//unsafe.example"'), 'Optional managed rental hero renders safe text and rejects unsafe links.');

$catalogueData['pageSections'] = [['section_key'=>'dates','section_type'=>'editorial','heading'=>'First managed copy','body'=>'Date guidance'],['section_key'=>'hero','section_type'=>'hero','heading'=>'Second managed copy','body'=>'Rental introduction']];
ob_start(); View::render('catalogue', $catalogueData); $orderedHtml = (string) ob_get_clean();
cataloguePresentationCheck(strpos($orderedHtml, 'First managed copy') < strpos($orderedHtml, 'Second managed copy'), 'Optional sections follow stored order instead of hardcoded order.');
$catalogueData['pageSections'] = [];
ob_start(); View::render('catalogue', $catalogueData); $allHiddenHtml = (string) ob_get_clean();
cataloguePresentationCheck(!str_contains($allHiddenHtml, 'First managed copy') && !str_contains($allHiddenHtml, 'Second managed copy') && str_contains($allHiddenHtml, 'filter-disclosure'), 'Hidden optional copy stays hidden while mandatory filters remain.');

echo "Catalogue presentation checks: {$checks} passed, 0 failed.\n";
