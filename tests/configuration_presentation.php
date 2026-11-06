<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Support\CommerceSummary;
use Verdant\Support\Format;

putenv('APP_ENV=local');
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
};

$quote = [
    'definition' => ['name' => 'Pairing'],
    'available' => true,
    'total_minor' => 1887,
    'lines' => [
        [
            'mode' => 'sale', 'product_name_snapshot' => 'Monstera', 'sku_snapshot' => 'PL-MON-S',
            'quantity' => 2, 'unit_price_minor' => 100, 'line_total_minor' => 999,
        ],
        [
            'mode' => 'rent', 'product_name_snapshot' => 'Stone pot', 'sku_snapshot' => 'POT-STONE-S',
            'quantity' => 2, 'daily_rate_minor' => 150, 'line_total_minor' => 888,
            'start_date' => '2026-10-01', 'end_date_exclusive' => '2026-10-04',
        ],
    ],
];
$summary = CommerceSummary::fromQuote($quote);
$check($summary['items'][0]['amount'] === 'R9.99' && $summary['items'][1]['amount'] === 'R8.88'
    && $summary['total'] === 'R18.87', 'Summary displays quote-authoritative line and total amounts without recalculating.');
$check(str_contains($summary['items'][0]['quantity'], '2 × R1.00 each')
    && str_contains($summary['items'][1]['quantity'], '2 × R1.50 per item/day × 3 days')
    && str_contains($summary['items'][1]['detail'], '1 Oct 2026–3 Oct 2026 inclusive')
    && !str_contains($summary['items'][1]['detail'], 'SKU POT-STONE-S'),
    'Summary names each SKU and explains quantity, rate, rental days and inclusive dates.');

$definition = [
    'name' => 'Plant and pot', 'definition_code' => 'plant-pot', 'arrangement_kind' => 'plant_pairing',
    'strategy' => 'components', 'components' => [
        [
            'component_id' => 1, 'component_key' => 'plant', 'label' => 'Plant', 'required' => true,
            'min_quantity' => 1, 'max_quantity' => 5, 'max_selections' => 2, 'source_type' => 'catalogue',
            'permitted_mode' => 'either', 'compatibility_anchor_component_id' => null,
            'product_options' => [[
                'product_id' => 11, 'name' => 'Monstera', 'sku' => 'PL-MON-S', 'buy_enabled' => true,
                'rent_enabled' => true, 'retail_price_minor' => 100, 'rental_daily_rate_minor' => 50,
            ]],
        ],
        [
            'component_id' => 2, 'component_key' => 'pot', 'label' => 'Compatible pot', 'required' => false,
            'min_quantity' => 1, 'max_quantity' => 5, 'max_selections' => 1, 'source_type' => 'compatible_pot',
            'permitted_mode' => 'either', 'compatibility_anchor_component_id' => 1, 'product_options' => [],
        ],
    ],
];
$selection = [['component_key' => 'plant', 'product_id' => 11, 'quantity' => 1, 'mode' => 'sale']];
$compatible = ['pot' => []];
$definitions = [['name' => 'Plant and pot', 'definition_code' => 'plant-pot', 'arrangement_kind' => 'plant_pairing', 'strategy' => 'components']];
$actor = null;
$finishedProduct = null;
$summary = null;
$errors = [];
$savedNotice = false;
$arrangementOffers = [];
$image = Format::imagePath(...);
$mediaProduct = null;
$mediaGallery = null;
$mediaNotice = '';
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$money = static fn (mixed $value): string => \Verdant\Support\Format::money($value);
$label = static fn (string $value): string => \Verdant\Support\Format::label($value);
$render = static function () use (&$title, &$actor, &$definitions, &$definition, &$selection, &$compatible, &$finishedProduct, &$quote, &$summary, &$errors, &$savedNotice, &$mediaProduct, &$mediaGallery, &$mediaNotice, &$e, &$image, &$money, &$label, &$arrangementOffers): string {
    ob_start();
    require dirname(__DIR__) . '/app/Views/compositions.php';
    return (string) ob_get_clean();
};

$title = 'Build an arrangement';
$definitionForView = $definition;
$definition = null;
$finishedProduct = null;
$selectionForView = $selection;
$quote = null;
$summary = null;
$html = $render();
$arrangementOffers = [[
    'definition_code' => 'set-calm-corner',
    'name' => 'Calm Corner',
    'arrangement_kind' => 'plant_arrangement',
    'strategy' => 'custom',
    'components' => [[
        'label' => 'Tree', 'quantity' => 1, 'required' => true,
        'options' => [[
            'name' => 'Olive tree', 'size_label' => 'Large',
            'buy_price_minor' => 85000, 'rent_rate_minor' => 4000,
        ]],
    ]],
    'estimated_buy_floor_minor' => 85000,
    'estimated_rent_floor_minor' => 4000,
    'has_mixed_modes' => false,
    'mixed_buy_subtotal_minor' => null,
    'mixed_rent_subtotal_minor' => null,
]];
$html = $render();
$check(str_contains($html, 'href="/configure?code=set-calm-corner"')
    && str_contains($html, 'class="arrangement-offer"')
    && str_contains($html, 'From R850.00')
    && str_contains($html, 'Rent from R40.00/day')
    && str_contains($html, 'Olive tree'),
    'Definition selection reuses the rich arrangement offer cards with imagery, includes and prices.');
$arrangementOffers = [];
$html = $render();
$check(str_contains($html, 'href="/configure?code=plant-pot"')
    && str_contains($html, 'class="arrangement-card"'), 'Definition selection falls back to arrangement cards without offer data.');

$definition = $definitionForView;
$selection = $selectionForView;
$quote = null;
$summary = null;
$html = $render();
$check(str_contains($html, 'data-rental-fields hidden')
    && str_contains($html, 'name="selection[0][start_date]" value="" disabled')
    && !str_contains($html, 'name="selection[0][start_date]" value="" required'),
    'Buy renders rental dates hidden and disabled when JavaScript is absent.');
$check(str_contains($html, 'formaction="/compositions/options" formnovalidate')
    && str_contains($html, 'Update choices')
    && str_contains($html, 'Choose a plant, then select Update choices'),
    'No-JavaScript compatible-pot and mode updates retain the existing POST contract.');
$mediaProduct = ['product_id' => 11, 'name' => 'Monstera'];
$mediaGallery = ['mode' => 'fallback', 'image' => ['src' => '/assets/images/botanical.svg', 'alt' => 'Monstera'],
    'description' => 'Showing the standard catalogue image.', 'scale_reference' => null];
$mediaNotice = 'The previous pot was cleared.';
$html = $render();
$check(str_contains($html, 'Your selected plant.')
    && str_contains($html, 'Select a plant or pot to update your pairing')
    && str_contains($html, 'The previous pot was cleared.')
    && !str_contains($html, 'Pair preview · this shows'),
    'Fallback configuration imagery is not described as a composed pair and explains the server refresh.');
$mediaProduct = null;
$mediaGallery = null;
$mediaNotice = '';
$check(substr_count($html, 'data-configuration-choice') === 3,
    'Every repeated plant slot and compatible-pot slot receives its own enhancement scope.');

$selection = [['component_key' => 'plant', 'product_id' => 11, 'quantity' => 1, 'mode' => 'rent', 'start_date' => '', 'end_date_inclusive' => '']];
$html = $render();
$check(str_contains($html, 'name="selection[0][mode]"')
    && str_contains($html, 'name="selection[0][start_date]" value="" required')
    && str_contains($html, 'name="selection[0][end_date_inclusive]" value="" required'),
    'Rent exposes required inclusive date fields after a no-JavaScript Update choices post.');

$definition['components'][0]['product_options'][0]['rent_enabled'] = false;
$html = $render();
$check(str_contains($html, '<option value="sale" selected')
    && str_contains($html, '<option value="rent"') && str_contains($html, 'disabled>Rent</option>')
    && str_contains($html, 'data-rental-fields hidden'),
    'A no-JavaScript refresh normalizes an unsupported rent selection to Buy and hides dates.');

$definition = null;
$definitions = [];
$html = $render();
$check(str_contains($html, 'No arrangements are available right now.')
    && str_contains($html, 'configuration.js'), 'Empty definition state stays safe and loads only the optional local enhancer.');

$definition = [
    'name' => 'Finished botanical piece', 'definition_code' => 'finished-piece', 'arrangement_kind' => 'plant_arrangement',
    'strategy' => 'finished_stock', 'finished_product_id' => 301, 'components' => [],
];
$finishedProduct = [
    'product_id' => 301, 'name' => 'Finished botanical piece', 'sku' => 'ARR-PLANT-01',
    'buy_enabled' => true, 'rent_enabled' => true,
];
$selection = [];
$html = $render();
$check(!str_contains($html, 'SKU ARR-PLANT-01')
    && str_contains($html, '<option value="sale" selected >Buy</option>')
    && str_contains($html, '<option value="rent"') && str_contains($html, '>Rent</option>')
    && str_contains($html, 'data-product-rent="true"')
    && str_contains($html, 'name="selection[0][start_date]" value="" disabled'),
    'Finished-stock rendering uses the explicitly supplied SKU and leaves its Buy/Rent modes available.');
$selection = [['component_key' => 'finished', 'product_id' => 301, 'quantity' => 1, 'mode' => 'rent', 'start_date' => '', 'end_date_inclusive' => '']];
$html = $render();
$check(str_contains($html, '<option value="rent" selected')
    && str_contains($html, 'name="selection[0][start_date]" value="" required')
    && str_contains($html, 'name="selection[0][end_date_inclusive]" value="" required'),
    'Finished-stock rental mode exposes required inclusive dates while preserving its fixed product ID.');

$script = file_get_contents(dirname(__DIR__) . '/public/assets/js/configuration.js');
$check(is_string($script) && str_contains($script, "input.required = renting")
    && str_contains($script, "rentalFields.hidden = !renting")
    && str_contains($script, 'data-sale-enabled') === false,
    'The optional enhancer toggles required dates and visibility without a client-side calculator.');

echo "Configuration presentation checks: {$checks} passed, 0 failed.\n";
