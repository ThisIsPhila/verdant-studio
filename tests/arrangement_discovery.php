<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Compositions\CompositionRepository;
use Verdant\Compositions\CompositionService;
use Verdant\Inventory\CapacityRepository;
use Verdant\Inventory\InventoryRepository;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Persistence\TransactionManager;
use Verdant\Storefront\ArrangementDiscoveryService;
use Verdant\Support\ValidationException;

if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'local' || !in_array(getenv('DB_NAME'), ['verdant_test_setup_phase012', 'verdant_test_phase015'], true)) {
    fwrite(STDERR, "Use only the isolated verdant_test_setup_phase012 schema.\n");
    exit(2);
}

$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$db->begin_transaction();
$tag = strtolower(bin2hex(random_bytes(5)));
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
};
$insert = static function (mysqli $connection, string $sql, array $parameters = []): int {
    $statement = $connection->prepare($sql);
    $statement->execute($parameters);
    $statement->close();
    return (int) $connection->insert_id;
};
$one = static function (mysqli $connection, string $sql, array $parameters = []): ?array {
    $statement = $connection->prepare($sql);
    $statement->execute($parameters);
    try { return $statement->get_result()->fetch_assoc() ?: null; }
    finally { $statement->close(); }
};

try {
    $categoryId = $insert($db, 'INSERT INTO categories(name) VALUES(?)', ['Arrangement discovery test ' . $tag]);
    $flowerId = $insert($db,
        "INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,product_kind,
             selling_unit,size_label,details_status,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor)
         VALUES(?,?,?,'Synthetic arrangement discovery test item','unknown','unknown','unknown','cut_flower',
             'Stem','Small','demonstration',1,0,6500,NULL)",
        [$categoryId, 'AD-' . strtoupper($tag) . '-FLOWER', 'Discovery test flower'],
    );
    $potId = $insert($db,
        "INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,product_kind,
             accessory_subtype,selling_unit,size_label,details_status,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor)
         VALUES(?,?,?,'Synthetic arrangement discovery test item','unknown','unknown','unknown','accessory','pot',
             'Each','Large','demonstration',0,1,NULL,2000)",
        [$categoryId, 'AD-' . strtoupper($tag) . '-POT', 'Discovery test planter'],
    );
    $definitionCode = 'discovery-' . $tag;
    $definitionId = $insert($db,
        "INSERT INTO composition_definitions(definition_code,name,arrangement_kind,strategy,status)
         VALUES(?,?,'floral_arrangement','custom','active')",
        [$definitionCode, 'Discovery test mixed arrangement'],
    );
    $flowerComponent = $insert($db,
        "INSERT INTO composition_definition_components
            (definition_id,component_key,label,component_role,source_type,required,min_selections,max_selections,
             min_quantity,max_quantity,permitted_mode,sort_order)
         VALUES(?,'flower','Purchased flower stems','flower','product',1,2,3,2,6,'sale',10)", [$definitionId],
    );
    $potComponent = $insert($db,
        "INSERT INTO composition_definition_components
            (definition_id,component_key,label,component_role,source_type,required,min_selections,max_selections,
             min_quantity,max_quantity,permitted_mode,sort_order)
         VALUES(?,'container','Rented planter','container','product',1,1,1,1,1,'rent',20)", [$definitionId],
    );
    $insert($db, 'INSERT INTO composition_component_product_allowlist(component_id,product_id) VALUES(?,?)', [$flowerComponent, $flowerId]);
    $insert($db, 'INSERT INTO composition_component_product_allowlist(component_id,product_id) VALUES(?,?)', [$potComponent, $potId]);

    $buyA = $insert($db,
        "INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,product_kind,
             selling_unit,size_label,details_status,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor)
         VALUES(?,?,?,'Synthetic arrangement discovery test item','unknown','unknown','unknown','cut_flower',
             'Stem','Small','demonstration',1,0,4000,NULL)",
        [$categoryId, 'AD-' . strtoupper($tag) . '-BUY-A', 'Discovery test buy item A'],
    );
    $buyB = $insert($db,
        "INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,product_kind,
             selling_unit,size_label,details_status,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor)
         VALUES(?,?,?,'Synthetic arrangement discovery test item','unknown','unknown','unknown','cut_flower',
             'Stem','Small','demonstration',1,0,5000,NULL)",
        [$categoryId, 'AD-' . strtoupper($tag) . '-BUY-B', 'Discovery test buy item B'],
    );
    $buyDefinitionCode = 'discovery-buy-' . $tag;
    $buyDefinitionId = $insert($db,
        "INSERT INTO composition_definitions(definition_code,name,arrangement_kind,strategy,status)
         VALUES(?,?,'floral_arrangement','custom','active')",
        [$buyDefinitionCode, 'Discovery test multi-item buy arrangement'],
    );
    foreach ([[$buyA, 'First required purchase'], [$buyB, 'Second required purchase']] as $index => [$productId, $label]) {
        $componentId = $insert($db,
            "INSERT INTO composition_definition_components
                (definition_id,component_key,label,component_role,source_type,required,min_selections,max_selections,
                 min_quantity,max_quantity,permitted_mode,sort_order)
             VALUES(?,?,?,'flower','product',1,1,1,1,1,'sale',?)",
            [$buyDefinitionId, 'buy-' . ($index + 1), $label, 10 + $index],
        );
        $insert($db, 'INSERT INTO composition_component_product_allowlist(component_id,product_id) VALUES(?,?)', [$componentId, $productId]);
    }

    $home = $one($db, "SELECT collection_id,state FROM collections WHERE collection_code='homes'");
    if ($home !== null && $home['state'] !== 'active') {
        throw new RuntimeException('The isolated test schema has an archived Homes collection; refusing to change its state.');
    }
    $homeId = $home === null
        ? $insert($db, "INSERT INTO collections(collection_code,name,description,state) VALUES('homes','Homes','Synthetic test membership','active')")
        : (int) $home['collection_id'];
    $insert($db, 'INSERT INTO collection_compositions(collection_id,definition_id,explanation,sort_order) VALUES(?,?,?,?)',
        [$homeId, $definitionId, 'Explicit synthetic space membership for discovery test.', 1]);
    $insert($db, 'INSERT INTO collection_compositions(collection_id,definition_id,explanation,sort_order) VALUES(?,?,?,?)',
        [$homeId, $buyDefinitionId, 'Explicit synthetic space membership for discovery test.', 2]);

    $compositions = new CompositionService(
        new CompositionRepository($db), new InventoryRepository($db), new CapacityRepository($db), new TransactionManager($db),
    );
    $discovery = new ArrangementDiscoveryService($db, $compositions);
    $beforeCheckouts = (int) ($one($db, 'SELECT COUNT(*) AS n FROM composition_checkouts')['n'] ?? 0);
    $search = $discovery->browse(['q' => 'Discovery test flower']);
    $check(count($search['arrangements']) >= 1, 'Search matches current eligible component names.');
    $check($discovery->browse(['q' => 'no-match-' . $tag])['arrangements'] === [], 'Search returns an actual empty result for unmatched text.');
    try { ArrangementDiscoveryService::normalizeFilters(['q' => ['invalid']], []); $check(false, 'Array search rejected.'); }
    catch (ValidationException) { $check(true, 'Array search rejected.'); }
    $all = $discovery->browse([]);
    $findCode = static function (array $rows, string $code): ?array {
        foreach ($rows as $row) { if ($row['definition_code'] === $code) { return $row; } }
        return null;
    };
    $mixed = $findCode($all['arrangements'], $definitionCode);
    $check($mixed !== null, 'Active mixed definition with eligible required components appears.');
    $check($mixed['spaces'] === ['homes'] && in_array('Small', $mixed['sizes'], true)
        && in_array('Large', $mixed['sizes'], true), 'Space comes from explicit Homes membership and sizes come from eligible SKU metadata.');
    $check($mixed['modes'] === ['rent'] && $mixed['has_mixed_modes'] === true,
        'Mixed definition advertises rent only when an eligible rental component exists and sale-only flowers remain purchases.');
    $check($mixed['estimated_buy_floor_minor'] === null && $mixed['estimated_rent_floor_minor'] === null
        && $mixed['mixed_buy_subtotal_minor'] === 26000 && $mixed['mixed_rent_subtotal_minor'] === 2000,
        'Mixed sale and daily rental minimums include required quantities and remain distinct estimates.');
    $flowerSummary = $mixed['components'][0];
    $check($flowerSummary['options'][0]['rent_rate_minor'] === null && $flowerSummary['options'][0]['buy_price_minor'] === 6500,
        'Sale-only flower options never appear rentable.');

    $homes = $findCode($discovery->browse(['space' => 'homes'])['arrangements'], $definitionCode);
    $small = $findCode($discovery->browse(['size' => 'Small'])['arrangements'], $definitionCode);
    $rent = $findCode($discovery->browse(['mode' => 'rent'])['arrangements'], $definitionCode);
    $withinCap = $findCode($discovery->browse(['budget' => '260.00'])['arrangements'], $definitionCode);
    $overCap = $findCode($discovery->browse(['budget' => '259.99'])['arrangements'], $definitionCode);
    $check($homes !== null && $small !== null && $rent !== null, 'Space, verified component size and rent filters match the eligible arrangement.');
    $check($withinCap !== null && $overCap === null, 'Budget compares the separate required purchase and rental estimate subtotals.');
    $buyCapIncludes = $findCode($discovery->browse(['mode' => 'buy', 'budget' => '50.00'])['arrangements'], $buyDefinitionCode);
    $buyCapExcludes = $findCode($discovery->browse(['mode' => 'buy', 'budget' => '89.99'])['arrangements'], $buyDefinitionCode);
    $buyExact = $findCode($discovery->browse(['mode' => 'buy', 'budget' => '90.00'])['arrangements'], $buyDefinitionCode);
    $check($buyCapIncludes === null && $buyCapExcludes === null && $buyExact !== null,
        'Arrangement budget sums two individually affordable required components and excludes their over-budget total.');
    $check($buyExact['estimated_buy_floor_minor'] === 9000, 'All-buy estimate sums current minimum prices for every required selection.');
    try { $discovery->browse(['size' => 'unknown']); throw new RuntimeException('Unknown component size matched positively.'); }
    catch (ValidationException) { ++$checks; }
    foreach ([['mode' => ['rent']], ['mode' => 'BUY'], ['space' => 'Homes']] as $invalidFilter) {
        try { $discovery->browse($invalidFilter); throw new RuntimeException('An array or unsupported case-sensitive filter was accepted.'); }
        catch (ValidationException) { ++$checks; }
    }
    $check((int) ($one($db, 'SELECT COUNT(*) AS n FROM composition_checkouts')['n'] ?? 0) === $beforeCheckouts,
        'GET discovery does not create composition checkout records.');
    echo "Arrangement discovery checks: {$checks} passed, 0 failed.\n";
} finally {
    $db->rollback();
    $db->close();
}
