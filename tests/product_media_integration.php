<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
putenv('APP_ENV=local');

use Verdant\Catalogue\CatalogueService;
use Verdant\Catalogue\ProductFamilyRepository;
use Verdant\Catalogue\ProductFamilyService;
use Verdant\Catalogue\ProductRepository;
use Verdant\Http\CatalogueController;
use Verdant\Media\MediaAssetRegistry;
use Verdant\Media\MediaAssignmentRepository;
use Verdant\Media\MediaPresenter;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;

if (getenv('APP_ENV') !== 'local' || (getenv('DB_NAME') ?: '') !== 'verdant_test_phase014') {
    fwrite(STDERR, "Use APP_ENV=local and DB_NAME=verdant_test_phase014.\n");
    exit(2);
}

$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$db->begin_transaction();
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
};
$fixtureIds = [];
$findFixture = $db->prepare('SELECT product_id FROM products WHERE sku = ? AND archived_at IS NULL');
foreach (['PL-MON-S', 'POT-STONE-S', 'FL-ROSE'] as $sku) {
    $findFixture->execute([$sku]);
    $row = $findFixture->get_result()->fetch_assoc();
    if ($row === null) throw new RuntimeException("Required seeded product is unavailable: $sku.");
    $fixtureIds[$sku] = (int) $row['product_id'];
}
$findFixture->close();

// These registry-backed media and compatibility rows exist only inside this test transaction.
$insertBinding = $db->prepare('INSERT INTO product_media_bindings (product_id,target_key,media_kind,asset_id) VALUES (?,?,?,?)');
foreach ([
    [$fixtureIds['PL-MON-S'], 'plant', 'plant-monstera-small-v01'],
    [$fixtureIds['POT-STONE-S'], 'pot', 'pot-stone-small-v01'],
] as [$productId, $kind, $assetId]) {
    $insertBinding->execute([$productId, 'product:' . $productId, $kind, $assetId]);
}
$insertBinding->execute([null, 'scene:product-detail', 'background', 'bg-individual-stone-v01']);
$insertBinding->close();
$insertCompatibility = $db->prepare("INSERT INTO product_compatibilities (plant_product_id,pot_product_id,source_status) VALUES (?,?,'demonstration') ON DUPLICATE KEY UPDATE archived_at = NULL, source_status = 'demonstration'");
$insertCompatibility->execute([$fixtureIds['PL-MON-S'], $fixtureIds['POT-STONE-S']]);
$insertCompatibility->close();

$catalogue = new CatalogueService(new ProductRepository($db), new ProductFamilyService(new ProductFamilyRepository($db)));
$controller = new CatalogueController($catalogue, null, null, new MediaPresenter(
    new MediaAssignmentRepository($db), new MediaAssetRegistry(),
));
$render = static function (string $sku) use ($db, $controller, $fixtureIds): array {
    ob_start();
    $controller->detail((string) $fixtureIds[$sku]);
    return [(string) ob_get_clean(), http_response_code()];
};

try {
    [$plant, $plantStatus] = $render('PL-MON-S');
    $assert($plantStatus === 200 && str_contains($plant, 'data-media-gallery')
        && str_contains($plant, 'data-media-main') && str_contains($plant, 'data-media-fallback')
        && str_contains($plant, 'plant-monstera-small-v01') && str_contains($plant, 'pot-stone-small-v01')
        && str_contains($plant, 'bg-individual-stone-v01'),
        'A mapped plant detail combines its registered plant, server-compatible pot and active product background.');
    $assert(!str_contains($plant, 'Demonstration catalogue image'),
        'The old duplicate-image caption is removed when the shared gallery replaces the placeholder.');
    $assert(str_contains($plant, 'aria-label="Product view showing Monstera with Stone planter."'),
        'The rendered canvas gets a useful accessible name from its selected catalogue products.');
    $assert(str_contains($plant, 'action="/cart/add"') && str_contains($plant, 'Choose rental dates')
        && str_contains($plant, 'Choose your plant') && str_contains($plant, 'Choose your pot'),
        'The media gallery leaves variant, purchase, rental and compatibility journeys available.');

    [$pot, $potStatus] = $render('POT-STONE-S');
    $assert($potStatus === 200 && str_contains($pot, 'data-media-gallery')
        && str_contains($pot, 'product-gallery__frame--static') && !str_contains($pot, 'data-media-main'),
        'A standalone planter receives a cropped product surface without a plant/pot composition.');

    [$flower, $flowerStatus] = $render('FL-ROSE');
    $assert($flowerStatus === 200 && !str_contains($flower, 'data-media-gallery')
        && !str_contains($flower, 'SKU FL-ROSE') && str_contains($flower, 'action="/cart/add"')
        && !str_contains($flower, '/rental?product_id='),
        'Unmapped sale-only flowers retain ordinary product presentation and no rental action.');

    echo "Product media integration checks: $checks passed, 0 failed.\n";
} finally {
    $db->rollback();
    $db->close();
}
