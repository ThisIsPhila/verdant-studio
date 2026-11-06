<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
putenv('APP_ENV=local');

use Verdant\Http\View;

$checks = 0;
function homesCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
}

$viewData = [
    'title' => 'Homes', 'actor' => null, 'errors' => [],
    'collection' => ['collection_code' => 'homes', 'name' => 'Homes', 'description' => 'Residential inspiration.'],
    'collections' => [],
    'products' => [
        [
            'product_id' => 101, 'sku' => 'TREE-OLIVE', 'name' => 'Olive tree', 'description' => 'A catalogue product.',
            'image_path' => null, 'image_alt' => 'Olive tree placeholder', 'product_kind' => 'plant',
            'category_name' => 'Indoor plants', 'size_label' => 'Medium', 'selling_unit' => 'Each',
            'light_level' => 'unknown', 'watering_level' => 'unknown', 'toxicity' => 'unknown',
            'buy_enabled' => 1, 'rent_enabled' => 0, 'retail_price_minor' => 195000, 'rental_daily_rate_minor' => null,
        ],
    ],
    'options' => [
        'kind' => ['plant'], 'mode' => ['buy'], 'size' => ['Medium'], 'colour' => [],
        'light_level' => [], 'watering_level' => [], 'toxicity' => [],
    ],
    'filters' => [
        'category_id' => '', 'kind' => 'plant', 'mode' => 'buy', 'size' => 'Medium', 'colour' => '', 'budget' => '500.00',
        'budget_minor' => 50000, 'light_level' => '', 'watering_level' => '', 'toxicity' => '',
    ],
    'page' => 1, 'hasNext' => true,
    'compositions' => [
        [
            'definition_code' => 'starter-plant-compatible-pot', 'name' => 'Monstera with a compatible planter',
            'arrangement_kind' => 'plant_arrangement', 'strategy' => 'fixed',
            'explanation' => 'Configured planter choices use the current compatibility rules.',
            'purchase_components' => [['label' => 'Monstera plant', 'required' => true]],
            'choice_components' => [['label' => 'Explicitly compatible planter', 'required' => true]],
            'rental_components' => [],
        ],
        [
            'definition_code' => 'starter-finished-living-wall', 'name' => 'Stocked living wall panel',
            'arrangement_kind' => 'plant_arrangement', 'strategy' => 'finished_stock',
            'explanation' => 'This definition uses its current finished stock offer.',
            'purchase_components' => [], 'choice_components' => [], 'rental_components' => [],
        ],
    ],
    'pageSections' => [
        ['section_key' => 'hero', 'section_type' => 'hero', 'position' => 10, 'heading' => 'A greener feeling at home', 'body' => 'Current residential edit.',
            'media_path' => '/assets/images/botanical.svg', 'media_alt' => 'Botanical illustration', 'link_path' => '//unsafe.example', 'link_label' => 'Explore'],
        ['section_key' => 'collection', 'section_type' => 'collection', 'position' => 20, 'heading' => 'Pair plants and planters', 'body' => 'Configure current offers.'],
        ['section_key' => 'products', 'section_type' => 'product_grid', 'position' => 30, 'heading' => 'Individual catalogue pieces', 'body' => 'Existing catalogue pieces.'],
        ['section_key' => 'unregistered', 'section_type' => 'arbitrary_template', 'position' => 40, 'heading' => '<script>Untrusted section</script>'],
    ],
];

$managedSections = $viewData['pageSections'];
ob_start();
View::render('collections', $viewData);
$html = (string) ob_get_clean();
homesCheck(str_contains($html, '<h1 id="homes-title">A greener feeling at home</h1>'), 'Homes uses its managed residential hero.');
homesCheck(str_contains($html, 'src="/assets/vision/images/homes.jpg"') && !str_contains($html, 'href="//unsafe.example"'), 'Hero media is local and unsafe managed links are rejected.');
homesCheck(str_contains($html, 'Individual catalogue pieces') && str_contains($html, 'Olive tree'), 'Homes presents individual existing catalogue products.');
homesCheck(str_contains($html, 'href="/configure"') && str_contains($html, 'Build a plant arrangement'), 'Homes offers a build-your-own configuration entry.');
homesCheck(str_contains($html, 'Monstera plant') && str_contains($html, 'Explicitly compatible planter'), 'Composition cards show configured component breakdowns.');
homesCheck(str_contains($html, 'href="/configure?code=starter-plant-compatible-pot"'), 'A composition links to the configuration journey.');
homesCheck(str_contains($html, 'Inspiration · configure to check availability') && str_contains($html, 'Stocked arrangement · check current availability'), 'Inspiration is distinguished from a currently stocked arrangement.');
homesCheck(str_contains($html, 'Browsing creates no stock hold') && str_contains($html, 'Browsing does not reserve stock'), 'Browsing and offers are described as non-reserving.');
homesCheck(strpos($html, 'A greener feeling at home') < strpos($html, 'Pair plants and planters')
    && strpos($html, 'Pair plants and planters') < strpos($html, 'Individual catalogue pieces')
    && strpos($html, 'Individual catalogue pieces') < strpos($html, 'collection-products-heading'),
    'Managed Homes hero, arrangement and product introduction render in stored section order before the fixed product list.');
homesCheck(str_contains($html, '<details class="filter-disclosure">') && !str_contains($html, '<details class="filter-disclosure" open'), 'Homes filters use a native disclosure that starts closed.');
homesCheck(str_contains($html, 'Product type: Plant') && str_contains($html, 'value="plant" selected')
    && str_contains($html, 'size=Medium') && str_contains($html, 'budget=500.00'), 'Homes active chips and pagination preserve selected filters.');
homesCheck(!str_contains($html, 'Untrusted section') && !str_contains($html, '<script>'), 'Unknown managed section types cannot select or inject a template.');

$viewData['pageSections'] = [];
ob_start();
View::render('collections', $viewData);
$hiddenHtml = (string) ob_get_clean();
homesCheck(!str_contains($hiddenHtml, 'homes-title') && !str_contains($hiddenHtml, 'homes-arrangements')
    && !str_contains($hiddenHtml, 'Pair plants and planters') && !str_contains($hiddenHtml, 'Individual catalogue pieces'),
    'Hidden optional Homes hero, arrangement and product editorial content are omitted.');
homesCheck(str_contains($hiddenHtml, 'collection-products-heading') && str_contains($hiddenHtml, 'Olive tree'), 'Hiding optional sections preserves required catalogue selection.');
homesCheck(str_contains($hiddenHtml, 'href="/collections"') && str_contains($hiddenHtml, 'href="/catalogue"'), 'Core discovery navigation remains available when optional sections are hidden.');
homesCheck(str_contains($hiddenHtml, 'href="/configure"'), 'The required build-your-own configuration path remains visible when optional sections are hidden.');
homesCheck(str_contains($hiddenHtml, '<details class="filter-disclosure">') && !str_contains($hiddenHtml, '<details class="filter-disclosure" open'), 'Core Homes filters remain available and closed when every optional section is hidden.');

$reorderedSections = $managedSections;
$viewData['pageSections'] = [$reorderedSections[2], $reorderedSections[1], $reorderedSections[0]];
ob_start();
View::render('collections', $viewData);
$reorderedHtml = (string) ob_get_clean();
homesCheck(strpos($reorderedHtml, 'Individual catalogue pieces') < strpos($reorderedHtml, 'Pair plants and planters')
    && strpos($reorderedHtml, 'Pair plants and planters') < strpos($reorderedHtml, 'A greener feeling at home'),
    'Changing stored order changes managed section order without moving the fixed catalogue controls.');

$otherCollectionData = $viewData;
$otherCollectionData['collection'] = ['collection_code' => 'floral-inspiration', 'name' => 'Floral inspiration', 'description' => 'Another collection remains generic.'];
$otherCollectionData['pageSections'] = [];
ob_start();
View::render('collections', $otherCollectionData);
$otherCollectionHtml = (string) ob_get_clean();
homesCheck(str_contains($otherCollectionHtml, '<h1>Floral inspiration</h1>') && !str_contains($otherCollectionHtml, 'homes-title'), 'Other collections keep their generic collection presentation.');

$seed = require dirname(__DIR__) . '/database/seeds/collections.php';
$homesDefinition = null;
foreach ($seed as $definition) {
    if (($definition['code'] ?? null) === 'homes') { $homesDefinition = $definition; break; }
}
homesCheck($homesDefinition !== null, 'The append-only starter fixture includes Homes.');
homesCheck(array_column($homesDefinition['products'], 0) === ['TREE-OLIVE', 'PL-MON-S', 'PL-BIRD', 'POT-STONE-S', 'POT-CHAR'], 'Homes membership names existing, explicit product SKUs.');
homesCheck(count($homesDefinition['compositions']) === 5, 'Homes references five explicitly seeded compositions (two starter sets plus three rental sets).');

echo "Homes presentation checks: {$checks} passed, 0 failed.\n";
