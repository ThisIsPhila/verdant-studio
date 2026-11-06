<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
putenv('APP_ENV=local');

use Verdant\Http\View;

$checks = 0;
function arrangementPresentationCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
}

$viewData = [
    'title' => 'Plant arrangements', 'actor' => null, 'errors' => [],
    'total' => 13, 'page' => 1, 'hasNext' => true,
    'options' => ['space' => ['homes' => 'Homes'], 'size' => ['Large', 'Small'], 'mode' => ['buy' => 'Buy', 'rent' => 'Rent or mixed']],
    'filters' => ['space' => 'homes', 'size' => 'Small', 'mode' => 'rent', 'budget' => '65.00', 'budget_minor' => 6500],
    'pageSections' => [
        ['section_key' => 'hero', 'section_type' => 'hero', 'heading' => 'Living arrangements', 'body' => 'Current active offers.',
            'media_path' => '/assets/images/catalogue/placeholders/plant-arrangement.svg', 'media_alt' => 'Placeholder',
            'link_path' => '//unsafe.example', 'link_label' => 'Configure'],
        ['section_key' => 'discovery', 'section_type' => 'arrangement_grid', 'heading' => 'A considered collection', 'body' => 'Select options to see current eligibility.'],
    ],
    'arrangements' => [
        [
            'definition_code' => 'mixed-starter', 'name' => 'A plant and a rented planter',
            'arrangement_kind' => 'plant_arrangement', 'strategy' => 'custom', 'spaces' => ['homes'],
            'sizes' => ['Small', 'Large'], 'modes' => ['rent'], 'has_mixed_modes' => true,
            'estimated_buy_floor_minor' => null, 'estimated_rent_floor_minor' => null,
            'mixed_buy_subtotal_minor' => 6500, 'mixed_rent_subtotal_minor' => 2000,
            'components' => [
                ['label' => 'Plant', 'required' => true, 'options' => [
                    ['name' => 'Monstera', 'sku' => 'TEST-PLANT', 'size_label' => 'Small', 'buy_price_minor' => 6500, 'rent_rate_minor' => null],
                ]],
                ['label' => 'Planter', 'required' => true, 'options' => [
                    ['name' => 'Stone planter', 'sku' => 'TEST-POT', 'size_label' => 'Large', 'buy_price_minor' => null, 'rent_rate_minor' => 2000],
                ]],
            ],
            'component_prices' => ['buy' => [6500], 'rent' => [2000]],
            'required_component_prices' => [['buy' => 6500, 'rent' => null], ['buy' => null, 'rent' => 2000]],
        ],
    ],
];

ob_start();
View::render('arrangements', $viewData);
$html = (string) ob_get_clean();
arrangementPresentationCheck(preg_match('/<h1[^>]*id="arrangements-title"[^>]*>Living arrangements<\/h1>/', $html) === 1, 'Registered arrangement hero renders.');
arrangementPresentationCheck(str_contains($html, 'src="/assets/vision/images/r-cluster.jpg"') && !str_contains($html, 'href="//unsafe.example"'), 'Hero accepts only a registered local image and rejects unsafe links.');
preg_match('/<details\b[^>]*class="[^" >]*filter-disclosure[^" >]*(?: [^"]*)?"[^>]*>/', $html, $filterDisclosure);
arrangementPresentationCheck(isset($filterDisclosure[0]) && preg_match('/\sopen(?:[\s=>])/', $filterDisclosure[0]) !== 1, 'Filters start collapsed without JavaScript.');
arrangementPresentationCheck(str_contains($html, 'value="homes" selected') && str_contains($html, 'value="Small" selected') && str_contains($html, 'value="rent" selected'), 'All selected filter values are preserved.');
arrangementPresentationCheck(str_contains($html, 'Space: Homes') && str_contains($html, 'Component size: Small') && str_contains($html, 'Mode: Buy or rent components'), 'Active chips explain current filters.');
arrangementPresentationCheck(str_contains($html, 'Minimum component estimate: ≤ R65.00') && !str_contains($html, 'budget_minor'), 'Arrangement budget chip is formatted and internal minor units stay private.');
arrangementPresentationCheck(str_contains($html, 'A plant and a rented planter') && str_contains($html, 'Monstera') && str_contains($html, 'Stone planter') && !str_contains($html, 'TEST-PLANT') && !str_contains($html, 'TEST-POT'), 'Arrangement components show product names without internal codes.');
arrangementPresentationCheck(str_contains($html, 'Rent R20.00/day') && str_contains($html, 'not a quote'), 'Prices identify daily rental rates and avoid implying a final quote.');
arrangementPresentationCheck(str_contains($html, 'Minimum component estimate budget (ZAR)') && str_contains($html, 'Rental estimates are per day'), 'Budget is described as an arrangement estimate, with rental units and later quote checks stated.');
arrangementPresentationCheck(str_contains($html, 'Required purchase components:') && str_contains($html, 'Required rental components:'), 'Mixed purchase and daily rental estimates render as distinct subtotal lines.');
arrangementPresentationCheck(str_contains($html, 'href="/configure?code=mixed-starter"') && str_contains($html, 'href="/configure"'), 'Cards and the required build-your-own entry link to configuration.');
arrangementPresentationCheck(str_contains($html, '/arrangements?space=homes') && str_contains($html, 'page=2'), 'Pagination preserves the selected filters.');

$viewData['pageSections'] = [];
ob_start();
View::render('arrangements', $viewData);
$hiddenHtml = (string) ob_get_clean();
arrangementPresentationCheck(!str_contains($hiddenHtml, 'arrangements-title'), 'Hiding optional managed hero removes the hero.');
arrangementPresentationCheck(str_contains($hiddenHtml, 'arrangement-results-title') && str_contains($hiddenHtml, 'href="/configure"'), 'Hiding optional sections preserves core discovery and configuration.');

$viewData['pageSections'] = [['section_key'=>'discovery','section_type'=>'arrangement_grid','heading'=>'First managed copy','body'=>'Discovery introduction'],['section_key'=>'hero','section_type'=>'hero','heading'=>'Second managed copy','body'=>'Arrangement introduction']];
ob_start(); View::render('arrangements', $viewData); $orderedHtml = (string) ob_get_clean();
arrangementPresentationCheck(strpos($orderedHtml, 'First managed copy') < strpos($orderedHtml, 'Second managed copy'), 'Optional sections follow stored order instead of hardcoded order.');
$viewData['pageSections'] = [];
ob_start(); View::render('arrangements', $viewData); $allHiddenHtml = (string) ob_get_clean();
arrangementPresentationCheck(!str_contains($allHiddenHtml, 'First managed copy') && !str_contains($allHiddenHtml, 'Second managed copy') && str_contains($allHiddenHtml, 'filter-disclosure'), 'Hidden optional copy stays hidden while mandatory filters remain.');

arrangementPresentationCheck(str_contains($html, 'arrangement-offer-grid') && str_contains($html, 'arrangement-offer-top') && str_contains($html, 'arrangement-offer-includes'), 'Offers use the reviewed image/includes card structure.');
arrangementPresentationCheck(str_contains($html, 'name="q"') && str_contains($html, 'aria-label="Arrangement space"') && str_contains($html, 'Beautiful greenery, just for the moment.'), 'Search, space tabs and rental banner follow the sample structure.');
arrangementPresentationCheck(str_contains($html, '<details class="arrangement-offer-options">') && !str_contains($html, '<details class="arrangement-offer-options" open'), 'Long eligible option lists stay available without overwhelming the card.');
echo "Arrangement discovery presentation checks: {$checks} passed, 0 failed.\n";
