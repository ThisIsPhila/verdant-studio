<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
putenv('APP_ENV=local');

use Verdant\Http\View;
use Verdant\Storefront\RentalDiscoveryService;
use Verdant\Support\ValidationException;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
};
$tomorrow = (new DateTimeImmutable('tomorrow', new DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
$after = (new DateTimeImmutable($tomorrow, new DateTimeZone('Africa/Johannesburg')))->modify('+1 day')->format('Y-m-d');
$dates = RentalDiscoveryService::normalizeDates(['start_date' => $tomorrow, 'end_date' => $after]);
$check($dates['has_dates'] && $dates['start_date'] === $tomorrow, 'Valid inclusive rental dates normalize without changing the selected strings.');
$check(RentalDiscoveryService::normalizeDates([])['has_dates'] === false, 'Date-free browsing remains available without an availability claim.');
foreach ([['start_date' => $tomorrow], ['start_date' => ['bad'], 'end_date' => $after], ['start_date' => '2026-02-30', 'end_date' => '2026-03-01'], ['start_date' => $after, 'end_date' => $tomorrow]] as $invalid) {
    try { RentalDiscoveryService::normalizeDates($invalid); throw new RuntimeException('An invalid rental date selection was accepted.'); }
    catch (ValidationException) { ++$checks; }
}
$check(!RentalDiscoveryService::isEligibleProduct(['product_id' => 1, 'rent_enabled' => 1, 'rental_daily_rate_minor' => 50, 'product_kind' => 'cut_flower']), 'Sale-only flower rental offers are rejected defensively.');
$check(!RentalDiscoveryService::isEligibleProduct(['product_id' => 1, 'rent_enabled' => 1, 'rental_daily_rate_minor' => null, 'product_kind' => 'plant']), 'Rental rows without an authoritative daily rate are excluded.');

$viewData = [
    'title' => 'Rental collection', 'actor' => null, 'errors' => [], 'rentalDates' => $dates,
    'filters' => ['kind' => '', 'mode' => 'rent'], 'page' => 1, 'hasNext' => false,
    'products' => [
        ['product_id' => 71, 'sku' => 'RENT-PLANT', 'name' => 'Rental plant', 'category_name' => 'Plants', 'product_kind' => 'plant', 'size_label' => 'Large', 'selling_unit' => 'Each', 'image_path' => null, 'image_alt' => 'Plant', 'buy_enabled' => 1, 'rent_enabled' => 1, 'retail_price_minor' => 19000, 'rental_daily_rate_minor' => 500, 'display_mode' => 'rent', 'rental_quote' => ['available' => true, 'available_quantity' => 2, 'days' => 2, 'total_minor' => 1000]],
        ['product_id' => 72, 'sku' => 'RENT-POT', 'name' => 'Rental planter', 'category_name' => 'Pots', 'product_kind' => 'accessory', 'size_label' => 'Medium', 'selling_unit' => 'Each', 'image_path' => null, 'image_alt' => 'Planter', 'buy_enabled' => 1, 'rent_enabled' => 1, 'retail_price_minor' => 9900, 'rental_daily_rate_minor' => 200, 'display_mode' => 'rent', 'rental_quote' => ['available' => false, 'available_quantity' => 0, 'days' => 2, 'total_minor' => 400]],
    ],
    'categories' => [], 'options' => ['kind' => ['plant', 'accessory'], 'mode' => ['buy', 'rent']],
    'page' => 1, 'hasNext' => true,
    'filterPanel' => ['action' => '/catalogue', 'legend' => 'Filter & sort', 'fields' => [], 'active' => [], 'submit_label' => 'Apply filters'],
    'queryFilters' => ['mode' => 'rent', 'start_date' => $tomorrow, 'end_date' => $after],
    'pageSections' => [
        ['section_key' => 'hero', 'section_type' => 'hero', 'heading' => 'Rent greenery', 'body' => 'Choose dates before checking availability.', 'link_path' => '/catalogue?mode=rent', 'link_label' => 'See rental offers'],
        ['section_key' => 'dates', 'section_type' => 'editorial', 'heading' => 'Choose dates', 'body' => 'Browsing does not reserve stock.', 'link_path' => '/catalogue?mode=rent', 'link_label' => 'Find an item'],
    ],
];
[$viewData['filterPanel'], $viewData['queryFilters']] = (new ReflectionMethod(\Verdant\Http\CatalogueController::class, 'filterPresentation'))
    ->invoke(null, $viewData, $dates);
ob_start();
View::render('catalogue', $viewData);
$html = (string) ob_get_clean();
$check(preg_match('/<h1 id="catalogue-title"[^>]*>Rent greenery<\/h1>/', $html) === 1 && str_contains($html, 'Choose dates'), 'Rental editorial sections render while the date search remains in the page.');
$check(str_contains($html, 'name="start_date"') && str_contains($html, 'name="end_date"') && str_contains($html, 'value="' . $tomorrow . '"'), 'The rental date form preserves inclusive selected dates.');
$check(substr_count($html, 'name="start_date"') === 3 && substr_count($html, 'name="end_date"') === 3, 'The date search, filters and sort form retain the selected rental range.');
$check(str_contains($html, 'Rental dates: ' . $tomorrow . ' to ' . $after) && !str_contains($html, 'budget_minor'), 'Selected dates are visible and internal filter values stay private.');
$check(str_contains($html, 'R5.00') && str_contains($html, 'R10.00 for one item') && str_contains($html, 'Rented pieces and purchased extras are listed separately'), 'Rate and dated total appear with mixed purchase/return guidance.');
$check(!str_contains($html, 'R190.00') && !str_contains($html, 'R99.00') && !str_contains($html, '/cart/add'), 'Rental-only cards hide retail prices and purchase actions.');
$check(str_contains($html, '/rental?product_id=71&amp;start_date=' . $tomorrow . '&amp;end_date=' . $after), 'The rental quote flow receives the selected dates.');
$check(str_contains($html, 'start_date=' . $tomorrow . '&amp;end_date=' . $after . '&amp;page=2'), 'Pagination retains the selected rental dates.');
$check(str_contains($html, 'Unavailable for those dates') && str_contains($html, '/arrangements?mode=rent'), 'Unavailable feedback and arrangement discovery remain available.');

$rentalData = [
    'title' => 'Check rental availability', 'actor' => null, 'errors' => [],
    'input' => ['product_id' => '71', 'quantity' => '1', 'start_date' => $tomorrow, 'end_date' => $after],
    'quote' => ['available' => true, 'product_name_snapshot' => 'Rental plant', 'start_date' => $tomorrow, 'end_date_exclusive' => (new DateTimeImmutable($after))->modify('+1 day')->format('Y-m-d'), 'days' => 2, 'available_quantity' => 2, 'daily_rate_minor' => 500, 'total_minor' => 1000, 'product_id' => 71, 'quantity' => 1],
];
ob_start();
View::render('rental', $rentalData);
$quoteHtml = (string) ob_get_clean();
$check(str_contains($quoteHtml, '/rental/checkout') && str_contains($quoteHtml, 'Stock is checked again before a reservation is held'), 'The established quote page retains its guarded checkout transition and no-hold guidance.');
$check(str_contains($quoteHtml, 'name="start_date"') && str_contains($quoteHtml, 'name="end_date"') && str_contains($quoteHtml, '/catalogue?mode=rent'), 'Standalone quote view preserves dates and returns to rental discovery.');

echo "Rental discovery presentation checks: {$checks} passed, 0 failed.\n";
