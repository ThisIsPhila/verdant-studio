<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Admin\AdminAccess;
use Verdant\Admin\CatalogueAdminRepository;
use Verdant\Admin\CatalogueAdminService;
use Verdant\Admin\VariantAdminRepository;
use Verdant\Admin\VariantAdminService;
use Verdant\Inventory\CapacityRepository;
use Verdant\Inventory\InventoryRepository;
use Verdant\Catalogue\CatalogueService;
use Verdant\Catalogue\ProductFamilyRepository;
use Verdant\Catalogue\ProductFamilyService;
use Verdant\Catalogue\ProductRepository;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Persistence\TransactionManager;
use Verdant\Support\ValidationException;

if (PHP_SAPI !== 'cli' || preg_match('/^verdant_test_[a-z0-9_]+$/', getenv('DB_NAME') ?: '') !== 1) {
    fwrite(STDERR, "Use an isolated verdant_test_ database.\n");
    exit(2);
}

$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$tag = bin2hex(random_bytes(6));
$checks = 0;
$adminId = $customerId = $archivedAdminId = $categoryId = null;
$plantId = $plantSmallId = $potId = $wrongAccessoryId = $familyId = $accessoryFamilyId = null;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
};
$reject = static function (callable $operation, string $message) use ($check): void {
    try {
        $operation();
    } catch (ValidationException|DomainException) {
        $check(true, $message);
        return;
    }
    throw new RuntimeException($message);
};

try {
    $statement = $db->prepare("INSERT INTO customers(email,password_hash,name,role,account_state) VALUES(?,?,?,'admin','active')");
    $statement->execute(['variants-admin-' . $tag . '@example.test', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'Variants Admin']);
    $adminId = (int) $db->insert_id;
    $admin = ['customer_id' => $adminId, 'role' => 'admin'];
    $statement = $db->prepare("INSERT INTO customers(email,password_hash,name,role,account_state,archived_at) VALUES(?,?,?,'admin','archived',UTC_TIMESTAMP())");
    $statement->execute(['variants-archived-admin-' . $tag . '@example.test', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'Archived Variants Admin']);
    $archivedAdminId = (int) $db->insert_id;
    $statement = $db->prepare("INSERT INTO customers(email,password_hash,name,role,account_state) VALUES(?,?,?,'customer','active')");
    $statement->execute(['variants-customer-' . $tag . '@example.test', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'Variants Customer']);
    $customerId = (int) $db->insert_id;

    $statement = $db->prepare('INSERT INTO categories(name) VALUES(?)');
    $statement->execute(['Variant test ' . $tag]);
    $categoryId = (int) $db->insert_id;
    $createProduct = static function (string $sku, string $name, string $kind, string $subtype = 'other') use ($db, $categoryId): int {
        $statement = $db->prepare(
            "INSERT INTO products(category_id,sku,name,description,product_kind,accessory_subtype,selling_unit,size_label,
                 light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor)
             VALUES(?,?,?,'Synthetic variant test product',?,?, 'Each','Test size','unknown','unknown','unknown',1,1,1200,300)",
        );
        $statement->execute([$categoryId, $sku, $name, $kind, $subtype]);
        $id = (int) $db->insert_id;
        $inventory = $db->prepare('INSERT INTO inventory(product_id,on_hand) VALUES(?,7)');
        $inventory->execute([$id]);
        return $id;
    };
    $plantId = $createProduct('V-' . strtoupper($tag) . '-PL', 'Synthetic Monstera', 'plant');
    $potId = $createProduct('V-' . strtoupper($tag) . '-POT', 'Synthetic stone pot', 'accessory', 'pot');
    $wrongAccessoryId = $createProduct('V-' . strtoupper($tag) . '-TOOL', 'Synthetic tool', 'accessory', 'tool');

    $variants = new VariantAdminService(new VariantAdminRepository($db), new TransactionManager($db), new AdminAccess($db));
    $catalogueAdmin = new CatalogueAdminService(
        new CatalogueAdminRepository($db), new InventoryRepository($db), new CapacityRepository($db),
        new TransactionManager($db), new AdminAccess($db),
    );
    $familyId = $variants->saveFamily($admin, null, [
        'family_code' => 'variant-test-' . $tag, 'name' => 'Synthetic Monstera family', 'product_kind' => 'plant',
    ]);
    $beforeInventory = $db->query('SELECT product_id,on_hand,version FROM inventory WHERE product_id IN (' . $plantId . ',' . $potId . ') ORDER BY product_id')->fetch_all(MYSQLI_ASSOC);
    $plantInput = [
        'category_id' => (string) $categoryId, 'sku' => 'V-' . strtoupper($tag) . '-PL',
        'name' => 'Synthetic Monstera', 'description' => 'Synthetic variant test product', 'product_kind' => 'plant',
        'family_id' => (string) $familyId, 'variant_label' => 'Large', 'accessory_subtype' => 'other',
        'selling_unit' => 'Each', 'size_label' => 'Test size', 'colour_label' => '',
        'light_level' => 'unknown', 'watering_level' => 'unknown', 'toxicity' => 'unknown',
        'image_path' => '', 'image_alt' => '', 'buy_enabled' => '1', 'rent_enabled' => '1',
        'retail_price' => '12.00', 'rental_daily_rate' => '3.00', 'on_hand' => '7', 'version' => '0',
    ];
    $catalogueAdmin->saveProduct($admin, $plantId, $plantInput);
    $check((int) $catalogueAdmin->product($admin, $plantId)['family_id'] === $familyId
        && $catalogueAdmin->product($admin, $plantId)['variant_label'] === 'Large',
        'Administrator assigns an existing stock-bearing SKU to a family through product editing.');
    $smallInput = array_replace($plantInput, [
        'sku' => 'V-' . strtoupper($tag) . '-PL-S', 'name' => 'Synthetic Monstera small',
        'family_id' => (string) $familyId, 'variant_label' => 'Small', 'buy_enabled' => '1', 'rent_enabled' => '0',
        'retail_price' => '24.50', 'rental_daily_rate' => '', 'on_hand' => '7', 'version' => '0',
    ]);
    $plantSmallId = $catalogueAdmin->saveProduct($admin, null, $smallInput);
    $check((int) $catalogueAdmin->product($admin, $plantSmallId)['family_id'] === $familyId,
        'Administrator can create a stock-bearing SKU directly inside an existing family.');
    $assignedInventory = $db->query('SELECT on_hand,version FROM inventory WHERE product_id=' . $plantId)->fetch_assoc();
    $check((int) $assignedInventory['on_hand'] === 7 && (int) $assignedInventory['version'] === 1,
        'Grouping preserves quantity while advancing the optimistic edit version.');
    $reject(fn () => $catalogueAdmin->saveProduct($admin, $plantId, array_replace($plantInput, [
        'name' => 'Stale form overwrite',
    ])), 'A stale family edit cannot overwrite a newer metadata update.');
    $accessoryFamilyId = $variants->saveFamily($admin, null, [
        'family_code' => 'variant-test-pot-' . $tag, 'name' => 'Synthetic accessory family', 'product_kind' => 'accessory',
    ]);
    $reject(fn () => $catalogueAdmin->saveProduct($admin, $plantId, array_replace($plantInput, [
        'family_id' => (string) $accessoryFamilyId, 'version' => '1',
    ])), 'A plant SKU cannot be assigned to an accessory family.');
    $reject(fn () => $catalogueAdmin->saveProduct($admin, $plantId, array_replace($plantInput, [
        'product_kind' => 'accessory', 'family_id' => (string) $familyId, 'version' => '1',
    ])), 'A product kind cannot be changed across its assigned family.');
    $reject(fn () => $catalogueAdmin->saveProduct($admin, $plantId, array_replace($plantInput, [
        'version' => '1', 'accessory_subtype' => 'pot',
    ])), 'A plant SKU cannot be classified as an accessory pot.');
    $check((int) $catalogueAdmin->product($admin, $plantId)['family_id'] === $familyId,
        'Rejected family changes preserve the existing assignment.');
    $potInput = [
        'category_id' => (string) $categoryId, 'sku' => 'V-' . strtoupper($tag) . '-POT',
        'name' => 'Synthetic stone pot', 'description' => 'Synthetic variant test product', 'product_kind' => 'accessory',
        'family_id' => (string) $accessoryFamilyId, 'variant_label' => 'Small', 'accessory_subtype' => 'pot',
        'selling_unit' => 'Each', 'size_label' => 'Test size', 'colour_label' => '',
        'light_level' => 'unknown', 'watering_level' => 'unknown', 'toxicity' => 'unknown',
        'image_path' => '', 'image_alt' => '', 'buy_enabled' => '1', 'rent_enabled' => '1',
        'retail_price' => '12.00', 'rental_daily_rate' => '3.00', 'on_hand' => '7', 'version' => '0',
    ];
    $catalogueAdmin->saveProduct($admin, $potId, $potInput);
    $check($catalogueAdmin->product($admin, $potId)['accessory_subtype'] === 'pot',
        'An administrator explicitly classifies an accessory SKU as a pot.');

    $pairId = $variants->saveCompatibility($admin, $plantId, $potId, 'demonstration');
    $check($pairId > 0, 'Administrator records an explicit plant/pot SKU compatibility.');
    $reject(fn () => $variants->saveCompatibility($admin, $plantId, $wrongAccessoryId, 'demonstration'),
        'A tool accessory cannot be recorded as a pot by category or name alone.');
    $reject(fn () => $variants->saveCompatibility($admin, $plantId, $plantId, 'demonstration'),
        'The same stock-bearing SKU cannot be both plant and pot.');
    $reject(fn () => $variants->saveFamily(['customer_id' => $customerId, 'role' => 'customer'], null, [
        'family_code' => 'denied-' . $tag, 'name' => 'Denied family', 'product_kind' => 'plant',
    ]), 'Customer account cannot create a product family.');
    $reject(fn () => $variants->saveFamily(['customer_id' => $archivedAdminId, 'role' => 'admin'], null, [
        'family_code' => 'archived-' . $tag, 'name' => 'Archived family', 'product_kind' => 'plant',
    ]), 'Archived administrator cannot create a product family.');
    $reject(fn () => $variants->saveFamily($admin, $familyId, [
        'name' => 'Changed kind', 'product_kind' => 'accessory',
    ]), 'A family with assigned SKU rows cannot change kind.');
    $reject(fn () => $variants->compatibilityPage(['customer_id' => $customerId, 'role' => 'customer']),
        'Customer account cannot read administrator compatibility management.');
    $reject(fn () => $variants->saveCompatibility($admin, 999999999, $potId, 'demonstration'),
        'Compatibility cannot reference a missing plant SKU.');
    $reject(fn () => $variants->saveCompatibility($admin, $plantId, $potId, 'unverified'),
        'Compatibility requires an allowed, truthful source status.');

    $catalogue = new CatalogueService(
        new ProductRepository($db),
        new ProductFamilyService(new ProductFamilyRepository($db)),
    );
    $detail = $catalogue->product($plantId);
    $variantById = [];
    foreach ($detail['variants'] as $variant) {
        $variantById[(int) $variant['product_id']] = $variant;
    }
    $check($detail !== null && $detail['family']['name'] === 'Synthetic Monstera family'
        && count($variantById) === 2 && isset($variantById[$plantId], $variantById[$plantSmallId]),
        'Customer product detail resolves the real family and both stock-bearing variant SKUs.');
    $check((int) $variantById[$plantSmallId]['retail_price_minor'] === 2450
        && (int) $variantById[$plantSmallId]['buy_enabled'] === 1
        && (int) $variantById[$plantSmallId]['rent_enabled'] === 0
        && $variantById[$plantSmallId]['rental_daily_rate_minor'] === null,
        'A selected variant exposes its own price and sale-only capability.');
    $check((int) $variantById[$plantId]['retail_price_minor'] === 1200
        && (int) $variantById[$plantId]['rental_daily_rate_minor'] === 300
        && (int) $variantById[$plantId]['rent_enabled'] === 1,
        'Another variant retains its independent price and rental capability.');
    $check(count($detail['compatible_pots']) === 1 && (int) $detail['compatible_pots'][0]['product_id'] === $potId
        && $detail['compatible_pots'][0]['source_status'] === 'demonstration',
        'Customer detail exposes only explicitly classified active pot compatibility with its source label.');
    $reject(fn () => $variants->setFamilyArchived($admin, $familyId, true),
        'A family with active member SKUs cannot be archived.');
    $catalogueAdmin->setProductArchived($admin, $plantId, true);
    $catalogueAdmin->setProductArchived($admin, $plantSmallId, true);
    $variants->setFamilyArchived($admin, $familyId, true);
    $check($variants->family($admin, $familyId)['archived_at'] !== null,
        'A family can be archived after its active SKU is archived.');
    $reject(fn () => $catalogueAdmin->setProductArchived($admin, $plantId, false),
        'An SKU cannot be restored under its archived family.');
    $reject(fn () => $catalogueAdmin->setProductArchived($admin, $plantSmallId, false),
        'A second SKU cannot be restored under its archived family.');
    $catalogueAdmin->saveProduct($admin, $plantId, array_replace($plantInput, [
        'version' => '1', 'name' => 'Synthetic archived Monstera edit',
    ]));
    $reject(fn () => $catalogueAdmin->saveProduct($admin, $plantId, array_replace($plantInput, [
        'version' => '2', 'variant_label' => 'Renamed while family archived',
    ])), 'An archived product cannot change its assignment within an archived family.');
    $variants->setFamilyArchived($admin, $familyId, false);
    $catalogueAdmin->setProductArchived($admin, $plantId, false);
    $catalogueAdmin->setProductArchived($admin, $plantSmallId, false);
    $check($catalogueAdmin->product($admin, $plantId)['family_name'] === 'Synthetic Monstera family',
        'Restoring archived family and SKU preserves their family history.');

    $db->query('UPDATE products SET archived_at=UTC_TIMESTAMP() WHERE product_id=' . $potId);
    $reject(fn () => $variants->saveCompatibility($admin, $plantId, $potId, 'owner_verified'),
        'An archived pot SKU cannot be used in a new compatibility record.');
    $detailAfterArchive = $catalogue->product($plantId);
    $check($detailAfterArchive['compatible_pots'] === [], 'Archived pot SKU is not offered as compatible.');
    $variants->setCompatibilityArchived($admin, $pairId, true);
    $storedPair = array_values(array_filter(
        $variants->compatibilityPage($admin)['compatibilities'],
        static fn (array $pair): bool => (int) $pair['compatibility_id'] === $pairId,
    ));
    $check(count($storedPair) === 1 && $storedPair[0]['archived_at'] !== null,
        'Compatibility is archived rather than deleted.');
    $check((int) $storedPair[0]['compatibility_id'] === $pairId,
        'Archived compatibility retains its identity and audit row.');

    $afterInventory = $db->query('SELECT product_id,on_hand,version FROM inventory WHERE product_id IN (' . $plantId . ',' . $potId . ') ORDER BY product_id')->fetch_all(MYSQLI_ASSOC);
    $beforeQuantities = array_map(static fn (array $row): array => [$row['product_id'], $row['on_hand']], $beforeInventory);
    $afterQuantities = array_map(static fn (array $row): array => [$row['product_id'], $row['on_hand']], $afterInventory);
    $check($beforeQuantities === $afterQuantities, 'Family assignment and compatibility edits do not change physical inventory quantities.');
    echo "Catalogue variant checks: $checks passed, 0 failed.\n";
} finally {
    // Runtime credentials intentionally cannot delete catalogue or identity history.
    // Each run uses random synthetic records in a dedicated test database.
    $db->close();
}
