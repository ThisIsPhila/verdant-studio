<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Admin\AdminNavigation;
use Verdant\Admin\RentalReturnContext;

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException($label);
    ++$checks;
    echo "PASS: $label\n";
};

$calendar = RentalReturnContext::from([
    'return_origin' => 'calendar', 'return_month' => '2026-11', 'return_view' => 'agenda',
]);
$check($calendar->url() === '/admin/rental-calendar?month=2026-11&view=agenda'
    && $calendar->detailQuery(73) === '/admin/rental?id=73&return_origin=calendar&return_month=2026-11&return_view=agenda'
    && AdminNavigation::activePath('/admin/rental?id=73&return_origin=calendar&return_month=2026-11&return_view=agenda') === '/admin/rental-calendar',
    'A valid calendar month/view stays selected in detail and active navigation.');

$list = RentalReturnContext::from(['return_origin' => 'list', 'return_group' => 'history', 'return_page' => '3']);
$check($list->url() === '/admin/rentals?group=history&page=3'
    && $list->detailQuery(73) === '/admin/rental?id=73&return_origin=list&return_group=history&return_page=3',
    'A validated list group and page survive the detail link.');

$external = RentalReturnContext::from([
    'return_origin' => 'calendar', 'return_month' => '2026-11', 'return_view' => 'agenda',
    'return_url' => 'https://attacker.test/',
]);
$check($external->url() === '/admin/rental-calendar?month=2026-11&view=agenda',
    'An arbitrary posted URL is ignored while the safe route keys remain valid.');

$malformed = RentalReturnContext::from(['return_origin' => 'calendar', 'return_month' => '2101-01', 'return_view' => 'agenda']);
$check($malformed->url() === '/admin/rentals?group=current&page=1'
    && AdminNavigation::activePath('/admin/rental?id=73&return_origin=calendar&return_month=2101-01&return_view=agenda') === '/admin/rentals',
    'Out-of-range calendar context falls back to the rental list and its navigation parent.');

$legacyReturns = RentalReturnContext::from(['origin' => 'returns', 'return_page' => '2']);
$check($legacyReturns->url() === '/admin/returns?page=2'
    && AdminNavigation::activePath('/admin/rental?id=73&origin=returns&return_page=2') === '/admin/returns',
    'The existing returns detail origin remains compatible with its active navigation parent.');

echo "Rental return-context checks: $checks passed, 0 failed.\n";
