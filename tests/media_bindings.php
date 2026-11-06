<?php

declare(strict_types=1);

use Verdant\Media\MediaAssetRegistry;
use Verdant\Media\MediaAssignmentRepository;
use Verdant\Media\MediaAssignmentService;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Support\ValidationException;

require dirname(__DIR__) . '/app/bootstrap.php';

if (!preg_match('/^verdant_test_[a-z0-9_]+$/', getenv('DB_NAME') ?: '')) {
    fwrite(STDERR, "Use an isolated verdant_test_ database.\n"); exit(2);
}
$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
};
$expectValidation = static function (callable $operation, string $message) use ($assert): void {
    try { $operation(); } catch (ValidationException) { $assert(true, $message); return; }
    throw new RuntimeException('Expected validation failure: ' . $message);
};
$actorId = 0;
$categoryId = 0;
$createdPlantersCategoryId = 0;
$productIds = [];
$bindingIds = [];
$mediaRows = $db->query('SELECT COUNT(*) AS total FROM product_media_bindings')->fetch_assoc();
if ((int) $mediaRows['total'] !== 0) {
    fwrite(STDERR, "Use a clean dedicated Phase014 test database.\n");
    exit(2);
}
try {
    $db->begin_transaction();
    $email = 'media-test-' . bin2hex(random_bytes(5)) . '@example.test';
    $stmt = $db->prepare("INSERT INTO customers(email,password_hash,name,role,account_state) VALUES(?, 'unused-test-hash', 'Media Test Admin', 'admin', 'active')");
    $stmt->execute([$email]); $actorId = (int) $db->insert_id; $stmt->close();
    $db->query("INSERT INTO categories(name) VALUES ('Media test " . bin2hex(random_bytes(4)) . "')"); $categoryId = (int) $db->insert_id;
    $sku = 'MEDIA-' . strtoupper(bin2hex(random_bytes(5)));
    $stmt = $db->prepare("INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,product_kind,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor) VALUES(?,?, 'Test plant','Synthetic','unknown','unknown','unknown','plant',1,1,100,10)");
    $stmt->execute([$categoryId, $sku]); $plantId = (int) $db->insert_id; $productIds[] = $plantId; $stmt->close();
    $stmt = $db->prepare("INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,product_kind,accessory_subtype,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor) VALUES(?,?, 'Test pot','Synthetic','unknown','unknown','unknown','accessory','pot',1,1,100,10)");
    $stmt->execute([$categoryId, $sku . '-POT']); $potId = (int) $db->insert_id; $productIds[] = $potId; $stmt->close();
    $stmt = $db->prepare("INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,product_kind,accessory_subtype,buy_enabled,retail_price_minor) VALUES(?,?, 'Test accessory','Synthetic','unknown','unknown','unknown','accessory','other',1,100)");
    $stmt->execute([$categoryId, $sku . '-OTHER']); $otherId = (int) $db->insert_id; $productIds[] = $otherId; $stmt->close();
    $categoryResult = $db->query("SELECT category_id FROM categories WHERE name = 'Planters & pots' AND archived_at IS NULL LIMIT 1");
    $potCategory = $categoryResult->fetch_assoc();
    if ($potCategory === null) {
        $db->query("INSERT INTO categories(name) VALUES ('Planters & pots')");
        $createdPlantersCategoryId = (int) $db->insert_id;
        $potCategoryId = $createdPlantersCategoryId;
    } else {
        $potCategoryId = (int) $potCategory['category_id'];
    }
    $categoryPotSku = $sku . '-POT-CATEGORY';
    $stmt = $db->prepare("INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,product_kind,accessory_subtype,buy_enabled,retail_price_minor) VALUES(?,?, 'Synthetic category pot','Test fixture','unknown','unknown','unknown','accessory','other',1,100)");
    $stmt->execute([$potCategoryId, $categoryPotSku]); $categoryPotId = (int) $db->insert_id; $productIds[] = $categoryPotId; $stmt->close();
    $db->commit();

    $repo = new MediaAssignmentRepository($db);
    $testConfig = require dirname(__DIR__) . '/config/product-media.php';
    $testConfig['sku_matches'] = [['sku' => $sku, 'asset_id' => 'plant-monstera-small-v01']];
    $registry = new MediaAssetRegistry($testConfig);
    $service = new MediaAssignmentService($repo, new Verdant\Persistence\TransactionManager($db), $registry);
    $plant = $service->saveProductAssignment($plantId, 'plant', 'plant-monstera-small-v01', null, null, 'unknown', null, $actorId);
    $assert($plant['lock_version'] === 1 && $plant['measurement_status'] === 'unknown', 'Plant assignment starts without invented dimensions.');
    $bindingIds[] = $plant['binding_id'];
    $pot = $service->saveProductAssignment($potId, 'pot', 'pot-stone-small-v01', null, null, 'unknown', null, $actorId);
    $bindingIds[] = $pot['binding_id'];
    $expectValidation(fn () => $service->saveProductAssignment($plantId, 'plant', 'pot-stone-small-v01', null, null, 'unknown', 1, $actorId), 'Asset kind must match slot.');
    $expectValidation(fn () => $service->saveProductAssignment($plantId, 'plant', 'plant-monstera-small-v01', 0, null, 'measured', 1, $actorId), 'Measured dimensions must be positive.');
    $expectValidation(fn () => $service->saveProductAssignment($plantId, 'plant', 'plant-monstera-small-v01', 800, null, 'unknown', 1, $actorId), 'Unknown dimensions are not stored as measurements.');
    $expectValidation(fn () => $service->saveProductAssignment($potId, 'pot', 'pot-stone-small-v01', 200, null, 'unknown', 1, $actorId), 'Geometry must match media kind.');
    $expectValidation(fn () => $service->saveProductAssignment($otherId, 'pot', 'pot-stone-small-v01', null, null, 'unknown', null, $actorId), 'Accessory subtype other cannot be assigned pot media.');
    $categoryPot = $repo->product($categoryPotId);
    $assert($categoryPot !== null && $categoryPot['accessory_subtype'] === 'other', 'Synthetic category-boundary product has the deliberately non-pot subtype.');
    $categoryPotMedia = $service->saveProductAssignment($categoryPotId, 'pot', 'pot-terracotta-medium-v01', null, null, 'unknown', null, $actorId);
    $bindingIds[] = $categoryPotMedia['binding_id'];
    $assert($categoryPotMedia['media_kind'] === 'pot', 'Planters & pots category permits its own product image without creating plant compatibility.');
    $plant = $service->saveProductAssignment($plantId, 'plant', 'plant-monstera-small-v01', null, null, 'unknown', 1, $actorId);
    $assert($plant['lock_version'] === 2, 'Optimistic update advances version.');
    $expectValidation(fn () => $service->saveProductAssignment($plantId, 'plant', 'plant-monstera-small-v01', null, null, 'unknown', 1, $actorId), 'Stale version is rejected.');
    $seededBefore = $service->seedDefaults();
    $assert($seededBefore['inserted'] === 4 && $seededBefore['existing'] === 1, 'Default seeding adds the standalone-pot context and preserves an existing plant assignment: ' . json_encode($seededBefore));
    $sceneRows = $db->query("SELECT binding_id FROM product_media_bindings WHERE target_key LIKE 'scene:%'")->fetch_all(MYSQLI_ASSOC);
    $sceneIds = array_column($sceneRows, 'binding_id');
    foreach ($sceneIds as $sceneId) $bindingIds[] = (int) $sceneId;
    $plant = $service->saveProductAssignment($plantId, 'plant', 'plant-zz-small-v01', null, null, 'unknown', 2, $actorId);
    $seededAgain = $service->seedDefaults();
    $assert($seededAgain['inserted'] === 0 && $seededAgain['existing'] === 5, 'Repeated defaults are add-only.');
    $assert($repo->binding($plant['binding_id'])['asset_id'] === 'plant-zz-small-v01', 'Default seed does not overwrite an owner assignment.');
    $scene = $service->saveSceneBackground('homes', 'bg-individual-entry-v01', 1, $actorId, 1.0);
    $assert($scene['asset_id'] === 'bg-individual-entry-v01' && $scene['lock_version'] === 2, 'Registered scene background can be updated with version checking.');
    $standalone = $service->saveSceneBackground('standalone-pot', 'bg-individual-stone-v01', 1, $actorId, 1.10);
    $assert($standalone['camera_scale'] === 1.1 && $standalone['lock_version'] === 2, 'Standalone-pot framing is a bounded camera setting stored on its own scene binding.');
    $expectValidation(fn () => $service->saveSceneBackground('standalone-pot', 'bg-individual-stone-v01', 2, $actorId, 1.16), 'Out-of-range standalone camera framing is rejected.');
    $expectValidation(fn () => $service->saveSceneBackground('standalone-pot', 'bg-individual-homes-v01', 2, $actorId), 'Room-scale backgrounds are rejected for standalone pots.');
    $expectValidation(fn () => $service->saveSceneBackground('homes', 'bg-individual-stone-v01', 2, $actorId, 1.10), 'Other scene contexts cannot receive standalone-pot camera framing.');
    $service->archiveBinding($plant['binding_id'], 3, $actorId);
    $archived = $repo->binding($plant['binding_id']);
    $assert($archived !== null && $archived['archived_at'] !== null, 'Archive preserves the assignment row.');
    $restored = $service->restoreBinding($plant['binding_id'], 4, $actorId);
    $assert($restored['archived_at'] === null && $restored['lock_version'] === 5, 'Restore reactivates same binding with a new version.');
    $history = $repo->history($plant['binding_id']);
    $assert(array_column($history, 'action') === ['create', 'update', 'update', 'archive', 'restore'], 'Assignment history is append-only by lifecycle event.');
    $assert($repo->binding($scene['binding_id'])['asset_id'] === 'bg-individual-entry-v01', 'Registered scene background persists.');
    echo "Media binding checks: $checks passed, 0 failed.\n";
} finally {
    // This file refuses non-test DB names; delete only its isolated synthetic rows.
    $allBindingIds = $db->query('SELECT binding_id FROM product_media_bindings')->fetch_all(MYSQLI_ASSOC);
    foreach (array_column($allBindingIds, 'binding_id') as $bindingId) {
        $stmt = $db->prepare('DELETE FROM product_media_history WHERE binding_id = ?'); $stmt->execute([$bindingId]); $stmt->close();
        $stmt = $db->prepare('DELETE FROM product_media_bindings WHERE binding_id = ?'); $stmt->execute([$bindingId]); $stmt->close();
    }
    foreach ($productIds as $productId) {
        $stmt = $db->prepare('DELETE FROM inventory WHERE product_id = ?'); $stmt->execute([$productId]); $stmt->close();
        $stmt = $db->prepare('DELETE FROM products WHERE product_id = ?'); $stmt->execute([$productId]); $stmt->close();
    }
    if ($categoryId) { $stmt = $db->prepare('DELETE FROM categories WHERE category_id = ?'); $stmt->execute([$categoryId]); $stmt->close(); }
    if ($createdPlantersCategoryId) { $stmt = $db->prepare('DELETE FROM categories WHERE category_id = ?'); $stmt->execute([$createdPlantersCategoryId]); $stmt->close(); }
    if ($actorId) { $stmt = $db->prepare('DELETE FROM customers WHERE customer_id = ?'); $stmt->execute([$actorId]); $stmt->close(); }
    $db->close();
}
