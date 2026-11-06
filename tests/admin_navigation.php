<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/Views/components/_helpers.php';

use Verdant\Admin\AdminNavigation;
use Verdant\Support\Format;

$_SERVER['HTTPS'] = 'on';
\Verdant\Identity\SessionManager::start();
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    ++$checks;
};
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$e = Format::escape(...);
foreach ([
    '/admin/product?id=1' => '/admin/products',
    '/admin/order?id=1' => '/admin/orders',
    '/admin/rental?id=1' => '/admin/rentals',
    '/admin/rental?id=1&origin=returns' => '/admin/returns',
    '/admin/rental?id=1&origin=https://example.test' => '/admin/rentals',
    '/admin/rental?id=1&origin[]=returns' => '/admin/rentals',
    '/admin/rental-calendar?month=2026-10' => '/admin/rental-calendar',
] as $uri => $expected) {
    $_SERVER['REQUEST_URI'] = $uri;
    ob_start();
    require dirname(__DIR__) . '/app/Views/admin/nav.php';
    $html = ob_get_clean();
    $check(AdminNavigation::activePath($uri) === $expected, 'Details and untrusted origin choose a known local parent.');
    $check(substr_count($html, 'aria-current="page"') === 1 && str_contains($html, 'href="' . $expected . '" aria-current="page"'), 'One correct parent is highlighted.');
}
$check(!str_contains($html, 'Rentals &amp; returns') && str_contains($html, 'Item compatibility'), 'Navigation uses short distinct labels.');
$items = $errors = [];
$page = 1;
$has_next = false;
foreach ([[], ['name' => 'Trees & shrubs'], ['name' => ['invalid']]] as $values) {
    ob_start();
    require dirname(__DIR__) . '/app/Views/admin/categories.php';
    $category = ob_get_clean();
    $expected = is_string($values['name'] ?? null) ? $e($values['name']) : '';
    $check(str_contains($category, 'value="' . $expected . '"'), 'Missing, valid and invalid names render safely without warnings.');
}
$plants = [['product_id' => 1, 'name' => 'Fern', 'sku' => 'F-1', 'variant_label' => 'Medium']];
$pots = [['product_id' => 2, 'name' => 'Stone pot', 'sku' => 'P-1', 'variant_label' => 'Stone']];
$compatibilities = [];
foreach (['demonstration', 'owner_verified'] as $source) {
    $values = ['source_status' => $source];
    ob_start();
    require dirname(__DIR__) . '/app/Views/admin/compatibility.php';
    $compatibility = ob_get_clean();
    $check(str_contains($compatibility, 'Fit not checked') && str_contains($compatibility, 'Fit checked'), 'Fit status is written in plain language.');
    $check(str_contains($compatibility, 'value="' . $source . '" selected'), 'The stored fit status remains selected without automatic verification.');
}
restore_error_handler();
session_destroy();
echo "Admin navigation/presentation checks: $checks passed, 0 failed.\n";
