<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Catalogue\CollectionRepository;
use Verdant\Catalogue\CollectionService;
use Verdant\Catalogue\CatalogueService;
use Verdant\Catalogue\ProductFamilyRepository;
use Verdant\Catalogue\ProductFamilyService;
use Verdant\Catalogue\ProductRepository;
use Verdant\Http\CollectionController;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Support\ValidationException;

if (!preg_match('/^verdant_test_[a-z0-9_]+$/', getenv('DB_NAME') ?: '')) {
    fwrite(STDERR, "Use an isolated verdant_test_ database.\n"); exit(2);
}
$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$db->begin_transaction();
$checks = 0;
$check = function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) { throw new RuntimeException($label); }
    ++$checks;
};
$insert = static function (mysqli $db, string $sql, array $values): int {
    $statement = $db->prepare($sql);
    $statement->execute($values);
    $id = (int) $db->insert_id;
    $statement->close();
    return $id;
};
try {
    $category = $insert($db, "INSERT INTO categories(name) VALUES (?)", ['Collection checks ' . bin2hex(random_bytes(5))]);
    $archivedCategory = $insert($db, "INSERT INTO categories(name, archived_at) VALUES (?, UTC_TIMESTAMP())", ['Archived collection checks ' . bin2hex(random_bytes(5))]);
    $family = $insert($db, "INSERT INTO product_families(family_code,name,product_kind,archived_at) VALUES (?,?, 'plant', UTC_TIMESTAMP())", ['collection-test-' . bin2hex(random_bytes(4)), 'Archived test family']);
    $activeFamily = $insert($db, "INSERT INTO product_families(family_code,name,product_kind) VALUES (?,?, 'plant')", ['active-collection-test-' . bin2hex(random_bytes(4)), 'Active test family']);
    $makeProduct = static function (array $row) use ($insert, $category, $db): int {
        $familyId = $row['family'] ?? null;
        $buy = (bool) ($row['buy'] ?? true);
        $rent = (bool) ($row['rent'] ?? false);
        return $insert($db,
            'INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,product_kind,
                selling_unit,size_label,colour_label,details_status,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor,
                family_id,variant_label)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $row['category'] ?? $category, $row['sku'], $row['name'], 'Synthetic test item.',
                $row['light'] ?? 'unknown', $row['water'] ?? 'unknown', $row['toxicity'] ?? 'unknown', $row['kind'] ?? 'plant',
                'Each', $row['size'] ?? null, $row['colour'] ?? null, 'demonstration',
                (int) $buy, (int) $rent, $buy ? ($row['price'] ?? 1000) : null,
                $rent ? ($row['rate'] ?? 200) : null,
                $familyId, $row['variant'] ?? ($familyId === null ? null : 'Demo'),
            ],
        );
    };
    $token = strtoupper(bin2hex(random_bytes(4)));
    $plant = $makeProduct(['sku' => 'COL-' . $token . '-PLANT', 'name' => 'Verified demo plant', 'light' => 'indirect', 'water' => 'weekly', 'toxicity' => 'non_toxic', 'size' => 'Small', 'colour' => 'Green', 'buy' => true, 'rent' => true, 'price' => 5000, 'rate' => 300, 'family' => $activeFamily]);
    $unknownPlant = $makeProduct(['sku' => 'COL-' . $token . '-UNKNOWN', 'name' => 'Unknown demo plant', 'size' => 'Small', 'colour' => 'Green']);
    $unknownLabels = $makeProduct(['sku' => 'COL-' . $token . '-LABEL-UNKNOWN', 'name' => 'Unknown product labels', 'size' => 'Unknown', 'colour' => 'unknown']);
    $flower = $makeProduct(['sku' => 'COL-' . $token . '-FLOWER', 'name' => 'Synthetic flower', 'kind' => 'cut_flower', 'light' => 'indirect', 'buy' => true, 'rent' => false]);
    $flowerAlternative = $makeProduct(['sku' => 'COL-' . $token . '-FLOWER-ALT', 'name' => 'Alternate synthetic flower', 'kind' => 'cut_flower', 'buy' => true, 'rent' => false]);
    $rentalOnly = $makeProduct(['sku' => 'COL-' . $token . '-RENT', 'name' => 'Synthetic rental', 'kind' => 'accessory', 'buy' => false, 'rent' => true, 'rate' => 200]);
    $archivedFamilyProduct = $makeProduct(['sku' => 'COL-' . $token . '-ARCHIVE', 'name' => 'Archived family plant', 'family' => $family]);
    $archivedCategoryProduct = $makeProduct(['sku' => 'COL-' . $token . '-CAT', 'name' => 'Archived category plant', 'category' => $archivedCategory]);
    $archivedProduct = $makeProduct(['sku' => 'COL-' . $token . '-PRODUCT-ARCHIVE', 'name' => 'Archived product']);
    $db->query('UPDATE products SET archived_at = UTC_TIMESTAMP() WHERE product_id = ' . $archivedProduct);
    $collectionCode = 'test-' . strtolower($token);
    $collectionId = $insert($db, 'INSERT INTO collections(collection_code,name,description) VALUES(?,?,?)', [$collectionCode, 'Test edit', 'Synthetic test collection.']);
    $insert($db, 'INSERT INTO collection_products(collection_id,product_id,explanation,sort_order) VALUES(?,?,?,?)', [$collectionId, $plant, 'Explicit synthetic membership.', 1]);

    $service = new CollectionService(new CollectionRepository($db));
    $catalogue = new CatalogueService(new ProductRepository($db), new ProductFamilyService(new ProductFamilyRepository($db)), $service);
    $page = $service->browse(['light_level' => 'indirect', 'mode' => 'buy', 'size' => 'Small', 'colour' => 'Green', 'budget' => '50.00']);
    $productIds = array_map('intval', array_column($page['products'], 'product_id'));
    $check(in_array($plant, $productIds, true), 'Plant with known care and matching mode/size/colour/budget is included.');
    $check(!in_array($flower, $productIds, true), 'Plant-care filter does not classify a cut flower.');
    $check(!in_array($unknownPlant, $productIds, true), 'Unknown care is never a positive match.');
    $allProductIds = array_map('intval', array_column($service->browse(['category_id' => (string) $category])['products'], 'product_id'));
    $check(!in_array($archivedFamilyProduct, $allProductIds, true), 'Products in archived families are excluded.');
    $check(!in_array($archivedCategoryProduct, $allProductIds, true), 'Products in archived categories are excluded.');
    $check(!in_array($archivedProduct, $allProductIds, true), 'Archived products are excluded.');
    $rentPage = $service->browse(['mode' => 'rent', 'budget' => '2.00']);
    $check(in_array($rentalOnly, array_map('intval', array_column($rentPage['products'], 'product_id')), true), 'Rent mode applies daily rental budget to rent-enabled products.');
    $check(!in_array($rentalOnly, array_map('intval', array_column($service->browse(['mode' => 'buy'])['products'], 'product_id')), true), 'Rent-only items do not match buy mode.');
    $curated = $service->browse([], $collectionCode);
    $check(array_map('intval', array_column($curated['products'], 'product_id')) === [$plant], 'A collection lists only its explicit existing SKU membership.');
    $check($curated['products'][0]['collection_explanation'] === 'Explicit synthetic membership.', 'Curated membership has a deterministic explanation.');
    $integrated = $catalogue->catalogue(['category_id' => (string) $category, 'kind' => 'plant', 'mode' => 'buy', 'budget' => '50.00']);
    $integratedIds = array_map('intval', array_column($integrated['products'], 'product_id'));
    $check(in_array($plant, $integratedIds, true), 'The normal catalogue reuses extended collection filters.');
    $check(isset($integrated['products'][0]['category_name'], $integrated['products'][0]['product_kind'], $integrated['products'][0]['buy_enabled']), 'Integrated product rows retain catalogue card fields.');
    $detail = $catalogue->product($plant);
    $check(array_key_exists('family', $detail) && array_key_exists('variants', $detail) && array_key_exists('compatible_pots', $detail), 'Product family/variant detail shape remains available after browse integration.');
    foreach ([['light_level' => 'unknown'], ['kind' => 'unverified-kind'], ['budget' => '-1']] as $invalid) {
        try { $service->browse($invalid); throw new RuntimeException('Invalid/unknown filter accepted.'); }
        catch (ValidationException) { ++$checks; }
    }
    foreach ([['size' => 'Unknown'], ['colour' => 'UNKNOWN']] as $invalid) {
        try { $service->browse($invalid); throw new RuntimeException('Unknown catalogue label accepted as a positive filter.'); }
        catch (ValidationException) { ++$checks; }
    }
    $mediumVariant = $makeProduct(['sku' => 'COL-' . $token . '-MEDIUM', 'name' => 'Medium variant', 'family' => $activeFamily, 'size' => 'Standard', 'variant' => 'Medium']);
    $mediumResults = $service->browse(['category_id' => (string) $category, 'size' => 'Medium'])['products'];
    $check(in_array($mediumVariant, array_map('intval', array_column($mediumResults, 'product_id')), true), 'Medium size filter matches the named variant shown on its card, despite a legacy Standard size.');
    $standardResults = $service->browse(['category_id' => (string) $category, 'size' => 'Standard'])['products'];
    $check(!in_array($mediumVariant, array_map('intval', array_column($standardResults, 'product_id')), true), 'A named Medium variant does not also match its superseded Standard label.');
    $options = $service->filterOptions();
    $check(in_array('Medium', $options['size'], true), 'Size options include named variant sizes.');
    $check(!in_array('Unknown', $options['size'], true) && !in_array('unknown', $options['colour'], true), 'Unknown size and colour values are excluded from filter options.');

    $definitionId = $insert($db,
        "INSERT INTO composition_definitions(definition_code,name,arrangement_kind,strategy,status) VALUES(?,?, 'floral_arrangement','custom','active')",
        ['test-' . strtolower($token), 'Test floral arrangement'],
    );
    $purchaseComponent = $insert($db,
        "INSERT INTO composition_definition_components(definition_id,component_key,label,component_role,source_type,permitted_mode)
         VALUES(?, 'flowers','Purchased flowers','flower','product','sale')", [$definitionId],
    );
    $rentalComponent = $insert($db,
        "INSERT INTO composition_definition_components(definition_id,component_key,label,component_role,source_type,permitted_mode)
         VALUES(?, 'container','Rented container','container','product','rent')", [$definitionId],
    );
    $insert($db, 'INSERT INTO composition_component_product_allowlist(component_id,product_id) VALUES(?,?)', [$purchaseComponent, $flower]);
    $insert($db, 'INSERT INTO composition_component_product_allowlist(component_id,product_id) VALUES(?,?)', [$purchaseComponent, $flowerAlternative]);
    $insert($db, 'INSERT INTO composition_component_product_allowlist(component_id,product_id) VALUES(?,?)', [$rentalComponent, $rentalOnly]);
    $insert($db, 'INSERT INTO collection_compositions(collection_id,definition_id,explanation,sort_order) VALUES(?,?,?,?)', [$collectionId, $definitionId, 'Synthetic configured offer.', 1]);
    $bundle = $service->browse([], $collectionCode)['compositions'][0];
    $check($bundle['purchase_components'][0]['label'] === 'Purchased flowers', 'Configured offer labels purchased floral components explicitly.');
    $check($bundle['rental_components'][0]['label'] === 'Rented container', 'Configured offer labels rented components explicitly.');
    $db->query('UPDATE composition_component_product_allowlist SET archived_at = UTC_TIMESTAMP() WHERE component_id = ' . $purchaseComponent . ' AND product_id = ' . $flower);
    $check(count($service->browse([], $collectionCode)['compositions']) === 1, 'An archived allowed SKU does not remove a composition when another allowed SKU remains active.');
    $db->query('UPDATE composition_component_product_allowlist SET archived_at = UTC_TIMESTAMP() WHERE component_id = ' . $purchaseComponent . ' AND product_id = ' . $flowerAlternative);
    $check($service->browse([], $collectionCode)['compositions'] === [], 'Archived allowlists cannot qualify a required composition component.');
    $db->query('UPDATE composition_component_product_allowlist SET archived_at = NULL WHERE component_id = ' . $purchaseComponent . ' AND product_id = ' . $flower);
    $check(count($service->browse([], $collectionCode)['compositions']) === 1, 'Restoring one allowed SKU makes the configured composition discoverable again.');
    $db->query('UPDATE composition_definition_components SET archived_at = UTC_TIMESTAMP() WHERE component_id = ' . $rentalComponent);
    $archivedSlot = $service->browse([], $collectionCode)['compositions'][0];
    $check($archivedSlot['rental_components'] === [], 'An archived component slot is omitted from the collection offer breakdown.');
    $db->query('UPDATE composition_definition_components SET archived_at = NULL WHERE component_id = ' . $rentalComponent);
    $check($service->browse([], $collectionCode)['compositions'][0]['rental_components'] !== [], 'Restoring a component slot restores it to the collection offer breakdown.');
    $pot = $makeProduct(['sku' => 'COL-' . $token . '-POT', 'name' => 'Synthetic compatible pot', 'kind' => 'accessory', 'buy' => true, 'rent' => true]);
    $db->query('UPDATE products SET accessory_subtype = \'pot\' WHERE product_id = ' . $pot);
    $compatibleCode = 'test-compatible-' . strtolower($token);
    $compatibleDefinition = $insert($db,
        "INSERT INTO composition_definitions(definition_code,name,arrangement_kind,strategy,status) VALUES(?,?, 'plant_arrangement','custom','active')",
        [$compatibleCode, 'Test compatible plant and pot'],
    );
    $anchor = $insert($db,
        "INSERT INTO composition_definition_components(definition_id,component_key,label,component_role,source_type,permitted_mode)
         VALUES(?, 'plant','Plant','plant','family','either')", [$compatibleDefinition],
    );
    $compatiblePot = $insert($db,
        "INSERT INTO composition_definition_components(definition_id,component_key,label,component_role,source_type,
            compatibility_anchor_component_id,permitted_mode)
         VALUES(?, 'pot','Compatible pot','pot','compatible_pot',?,'either')", [$compatibleDefinition, $anchor],
    );
    $insert($db, 'INSERT INTO composition_component_family_allowlist(component_id,family_id) VALUES(?,?)', [$anchor, $activeFamily]);
    $compatibility = $insert($db, 'INSERT INTO product_compatibilities(plant_product_id,pot_product_id,source_status) VALUES(?,?,\'demonstration\')', [$plant, $pot]);
    $insert($db, 'INSERT INTO collection_compositions(collection_id,definition_id,explanation,sort_order) VALUES(?,?,?,?)', [$collectionId, $compatibleDefinition, 'Synthetic compatible option.', 2]);
    $compatibleCodes = static fn (): array => array_column($service->browse([], $collectionCode)['compositions'], 'definition_code');
    $check(in_array($compatibleCode, $compatibleCodes(), true), 'An active explicit plant-pot compatibility can qualify its configured offer.');
    $db->query('UPDATE product_compatibilities SET archived_at = UTC_TIMESTAMP() WHERE compatibility_id = ' . $compatibility);
    $check(!in_array($compatibleCode, $compatibleCodes(), true), 'Archived compatibility cannot qualify its configured offer.');
    $db->query('UPDATE product_compatibilities SET archived_at = NULL WHERE compatibility_id = ' . $compatibility);
    $check(in_array($compatibleCode, $compatibleCodes(), true), 'Restoring explicit compatibility restores the configured offer.');
    ob_start();
    (new CollectionController($service))->detail('floral-inspiration', [], null);
    $html = (string) ob_get_clean();
    $check(http_response_code() === 200 && str_contains($html, 'Flowers for the moment'), 'Collection controller renders an active collection page.');
    $check(str_contains($html, 'Purchased components') && str_contains($html, 'Rented components') && str_contains($html, 'Configure and check availability'), 'Rendered floral page presents the configured sale/rental split and composition entry.');
    $holdsBefore = (int) $db->query('SELECT COUNT(*) FROM retail_holds')->fetch_row()[0];
    $service->browse([], $collectionCode);
    $check((int) $db->query('SELECT COUNT(*) FROM retail_holds')->fetch_row()[0] === $holdsBefore, 'Opening a collection creates no stock hold.');
    echo "Collection checks: $checks passed, 0 failed.\n";
} finally {
    $db->rollback();
    $db->close();
}
