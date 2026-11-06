<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
putenv('APP_ENV=local');

use Verdant\Catalogue\CatalogueService;
use Verdant\Catalogue\ProductFamilyRepository;
use Verdant\Catalogue\ProductFamilyService;
use Verdant\Catalogue\ProductRepository;
use Verdant\Http\CatalogueController;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Support\Format;

if (getenv('APP_ENV') !== 'local' || (getenv('DB_NAME') ?: '') !== 'verdant_test_phase013_baseline') {
    fwrite(STDERR, "Use APP_ENV=local and DB_NAME=verdant_test_phase013_baseline.\n");
    exit(2);
}

$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$db->begin_transaction();
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
};
$controller = new CatalogueController(new CatalogueService(
    new ProductRepository($db),
    new ProductFamilyService(new ProductFamilyRepository($db)),
));
$render = static function (mixed $id) use ($controller): array {
    ob_start();
    $controller->detail($id);
    return [(string) ob_get_clean(), http_response_code()];
};

try {
    $ids = [];
    $statement = $db->prepare('SELECT product_id, sku FROM products WHERE sku IN (?, ?, ?, ?, ?) AND archived_at IS NULL');
    $skus = ['PL-MON-S', 'PL-MON-L', 'POT-STONE-S', 'FL-ROSE', 'VER-RENT-001'];
    $statement->execute($skus);
    foreach ($statement->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $ids[$row['sku']] = (int) $row['product_id'];
    }
    $statement->close();
    foreach ($skus as $sku) {
        if (!isset($ids[$sku])) {
            throw new RuntimeException("Required seeded product is unavailable: $sku.");
        }
    }
    $variantPrices = [];
    $statement = $db->prepare('SELECT sku, retail_price_minor FROM products WHERE sku IN (?, ?)');
    $statement->execute(['PL-MON-S', 'PL-MON-L']);
    foreach ($statement->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $variantPrices[$row['sku']] = Format::money($row['retail_price_minor']);
    }
    $statement->close();

    [$plantHtml, $plantStatus] = $render((string) $ids['PL-MON-S']);
    $check($plantStatus === 200 && str_contains($plantHtml, '<h1 id="product-title">Monstera</h1>'),
        'An active product renders its detail page.');
    $check(str_contains($plantHtml, 'Choose your plant') && str_contains($plantHtml, 'data-product-choice')
        && str_contains($plantHtml, 'data-choice-value="' . $ids['PL-MON-L'] . '"')
        && !str_contains($plantHtml, 'Choose size') && !str_contains($plantHtml, 'SKU PL-MON-S')
        && str_contains($plantHtml, 'Current product prices'),
        'Plant choices lead with names and preserve separate product identities without exposing codes.');
    $check(str_contains($plantHtml, 'action="/cart/add" method="post"')
        && str_contains($plantHtml, 'name="csrf"')
        && str_contains($plantHtml, 'name="product_id" value="' . $ids['PL-MON-S'] . '"')
        && str_contains($plantHtml, 'name="quantity"'),
        'Buy submits the selected stock-bearing SKU through the CSRF-protected cart contract.');
    $check(str_contains($plantHtml, 'plant_id=' . $ids['PL-MON-S'])
        && str_contains($plantHtml, 'Rent · dates needed'),
        'Rental-capable products enter the existing dated rental flow.');
    $check(str_contains($plantHtml, 'Choose your pot') && str_contains($plantHtml, 'Stone planter')
        && str_contains($plantHtml, 'plant_id=' . $ids['PL-MON-S']) && !str_contains($plantHtml, 'SKU POT-STONE-S'),
        'Stored compatible pots have a product-led configuration entry carrying the selected plant.');
    $check(!str_contains($plantHtml, 'Care information') && !str_contains($plantHtml, 'Synthetic'),
        'Missing care facts and internal fixture provenance are omitted from customer copy.');

    [$rentalHtml, $rentalStatus] = $render((string) $ids['VER-RENT-001']);
    $check($rentalStatus === 200 && !str_contains($rentalHtml, 'action="/cart/add"')
        && str_contains($rentalHtml, '/rental?product_id=' . $ids['VER-RENT-001'])
        && str_contains($rentalHtml, 'Rent · dates needed'),
        'Rental-only products show dated availability without a purchase action.');

    [$flowerHtml, $flowerStatus] = $render((string) $ids['FL-ROSE']);
    $check($flowerStatus === 200 && !str_contains($flowerHtml, 'SKU FL-ROSE')
        && str_contains($flowerHtml, 'action="/cart/add"')
        && !str_contains($flowerHtml, '/rental?product_id=' . $ids['FL-ROSE'])
        && !str_contains($flowerHtml, 'Rent · dates needed'),
        'Sale-only flowers render the buy action without any rental action.');

    [$missingHtml, $missingStatus] = $render('999999999');
    $check($missingStatus === 404 && str_contains($missingHtml, 'Product unavailable')
        && !str_contains($missingHtml, 'action="/cart/add"'),
        'Missing products render the safe unavailable state without commerce actions.');

    echo "Product presentation checks: {$checks} passed, 0 failed.\n";
} finally {
    $db->rollback();
    $db->close();
}
