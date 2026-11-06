<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
use Verdant\Catalogue\CollectionService;
use Verdant\Support\ValidationException;
$checks = 0;
foreach (['', 'price_asc', 'price_desc'] as $sort) {
    if (CollectionService::normalizeFilters(['sort' => $sort])['sort'] !== $sort) throw new RuntimeException('Valid sort lost.');
    ++$checks;
}
foreach (['p.price DESC; DROP TABLE products', ['price_asc']] as $invalid) {
    try { CollectionService::normalizeFilters(['sort' => $invalid]); throw new RuntimeException('Unsafe sort accepted.'); }
    catch (ValidationException $error) { if (!isset($error->errors['sort'])) throw $error; ++$checks; }
}
echo "Catalogue sort checks: $checks passed.\n";
