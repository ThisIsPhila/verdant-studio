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

if (PHP_SAPI !== 'cli' || preg_match('/^verdant_test_[a-z0-9_]+$/', getenv('DB_NAME') ?: '') !== 1) {
    fwrite(STDERR, "Use an isolated verdant_test_ database.\n");
    exit(2);
}

$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$tag = strtoupper(bin2hex(random_bytes(5)));
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
};
$reject = static function (callable $operation, string $message) use ($check): void {
    try {
        $operation();
    } catch (DomainException|InvalidArgumentException) {
        $check(true, $message);
        return;
    }
    throw new RuntimeException($message);
};
$runFixture = static function (string $path): string {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/' . $path) . ' apply 2>&1';
    exec($command, $output, $status);
    if ($status !== 0) {
        throw new RuntimeException('A reviewed add-only fixture failed; inspect its local safe output.');
    }
    return implode("\n", $output);
};
$one = static function (mysqli $connection, string $sql, array $parameters = []): ?array {
    $statement = $connection->prepare($sql);
    $statement->execute($parameters);
    try {
        return $statement->get_result()->fetch_assoc() ?: null;
    } finally {
        $statement->close();
    }
};
$insert = static function (mysqli $connection, string $sql, array $parameters = []): int {
    $statement = $connection->prepare($sql);
    $statement->execute($parameters);
    $statement->close();
    return (int) $connection->insert_id;
};
$createCustomer = static function (mysqli $connection, string $label) use ($tag, $insert): int {
    return $insert($connection,
        "INSERT INTO customers(email,password_hash,name,role,account_state) VALUES(?, 'test-hash', ?, 'customer', 'active')",
        ['composition-' . strtolower($tag) . '-' . $label . '@example.test', 'Composition ' . $label],
    );
};
$createProduct = static function (
    mysqli $connection,
    int $categoryId,
    string $suffix,
    string $kind = 'plant',
    string $subtype = 'other',
    int $stock = 8,
    bool $buy = true,
    bool $rent = true,
    int $price = 1200,
    int $daily = 300,
) use ($tag, $insert): int {
    $id = $insert($connection,
        "INSERT INTO products(category_id,sku,name,description,product_kind,accessory_subtype,selling_unit,
             light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor)
         VALUES(?,?,?,'Synthetic composition test item',?,?,'Each','unknown','unknown','unknown',?,?,?,?)",
        [$categoryId, 'CMP-' . $tag . '-' . $suffix, 'Synthetic ' . $suffix, $kind, $subtype,
            (int) $buy, (int) $rent, $buy ? $price : null, $rent ? $daily : null],
    );
    $insert($connection, 'INSERT INTO inventory(product_id,on_hand) VALUES(?,?)', [$id, $stock]);
    return $id;
};
$createDefinition = static function (
    mysqli $connection,
    string $code,
    string $name,
    array $rules,
) use ($insert): void {
    $definitionId = $insert($connection,
        "INSERT INTO composition_definitions(definition_code,name,arrangement_kind,strategy)
         VALUES(?,?,'plant_arrangement','custom')",
        [$code, $name],
    );
    foreach ($rules as $index => $rule) {
        $componentId = $insert($connection,
            'INSERT INTO composition_definition_components
                (definition_id,component_key,label,component_role,source_type,required,min_selections,max_selections,
                 min_quantity,max_quantity,permitted_mode,sort_order)
             VALUES(?,?,?,? ,\'product\',1,1,5,1,20,?,?)',
            [$definitionId, $rule['key'], $rule['label'], $rule['role'], $rule['mode'], ($index + 1) * 10],
        );
        foreach ($rule['products'] as $productId) {
            $insert($connection, 'INSERT INTO composition_component_product_allowlist(component_id,product_id) VALUES(?,?)', [$componentId, $productId]);
        }
    }
};

try {
    $check(str_contains($runFixture('database/bin/seed-catalogue-variants.php'), 'fixture complete'),
        'Variant prerequisites are applied through their add-only fixture.');
    $firstFixture = $runFixture('database/bin/seed-compositions.php');
    $secondFixture = $runFixture('database/bin/seed-compositions.php');
    $check(str_contains($firstFixture, 'Summary:') && str_contains($secondFixture, 'already processed'),
        'Composition demo fixture is transactionally add-only and replay-safe.');
    $fixtureCount = (int) ($one($db,
        "SELECT COUNT(*) AS n FROM composition_fixture_ledger WHERE fixture_code='starter-compositions-v1' AND disposition='inserted'")['n'] ?? 0);
    $expectedFixtureCount = count(require dirname(__DIR__) . '/database/seeds/compositions.php');
    $check($fixtureCount === $expectedFixtureCount, 'Fixture provides all declared compatible plant/pot, custom, finished and floral definitions.');

    $service = new CompositionService(
        new CompositionRepository($db), new InventoryRepository($db), new CapacityRepository($db), new TransactionManager($db),
    );
    $fixed = $service->definition('starter-plant-compatible-pot');
    $plantSmall = (int) $one($db, 'SELECT product_id FROM products WHERE sku=\'PL-MON-S\'')['product_id'];
    $plantLarge = (int) $one($db, 'SELECT product_id FROM products WHERE sku=\'PL-MON-L\'')['product_id'];
    $potSmall = (int) $one($db, 'SELECT product_id FROM products WHERE sku=\'POT-STONE-S\'')['product_id'];
    $potLarge = (int) $one($db, 'SELECT product_id FROM products WHERE sku=\'POT-STONE-L\'')['product_id'];
    $check($fixed !== null && count($fixed['components']) === 2
        && count($fixed['components'][0]['product_options']) > 0,
        'Definition read exposes only active allowlisted SKU options.');
    $pots = $service->compatiblePots('starter-plant-compatible-pot', 'pot', $plantSmall);
    $check(count($pots) === 1 && (int) $pots[0]['product_id'] === $potSmall,
        'Plant selector resolves only explicitly compatible pot options.');
    $reject(fn () => $service->quote('starter-plant-compatible-pot', [
        ['component_key' => 'plant', 'product_id' => $plantSmall, 'quantity' => 1, 'mode' => 'sale'],
        ['component_key' => 'pot', 'product_id' => $potLarge, 'quantity' => 1, 'mode' => 'sale'],
    ]), 'A pot for another Monstera size is rejected even when both SKUs are active.');

    $flower = (int) $one($db, 'SELECT product_id FROM products WHERE sku=\'FL-ROSE\'')['product_id'];
    $container = (int) $one($db, 'SELECT product_id FROM products WHERE sku=\'POT-STONE-S\'')['product_id'];
    $reject(fn () => $service->quote('starter-bouquet-rental-container', [
        ['component_key' => 'flower', 'product_id' => $flower, 'quantity' => 1, 'mode' => 'rent',
            'start_date' => '2026-10-10', 'end_date_inclusive' => '2026-10-10'],
        ['component_key' => 'container', 'product_id' => $container, 'quantity' => 1, 'mode' => 'rent',
            'start_date' => '2026-10-10', 'end_date_inclusive' => '2026-10-10'],
    ]), 'Cut flowers cannot be rented through a composition definition.');

    $categoryId = $insert($db, 'INSERT INTO categories(name) VALUES(?)', ['Composition tests ' . $tag]);
    $plant = $createProduct($db, $categoryId, 'PLANT', 'plant', 'other', 9, true, true, 2100, 450);
    $pot = $createProduct($db, $categoryId, 'POT', 'accessory', 'pot', 9, true, true, 800, 200);
    $saleOnlyPlant = $createProduct($db, $categoryId, 'SALE', 'plant', 'other', 9, true, false, 1700, 0);
    $rentalOnlyPlant = $createProduct($db, $categoryId, 'RENT', 'plant', 'other', 9, false, true, 0, 500);
    $shared = $createProduct($db, $categoryId, 'SHARED', 'plant', 'other', 2, true, true, 1000, 250);
    $rollbackProduct = $createProduct($db, $categoryId, 'ROLLBACK', 'plant', 'other', 1, true, true, 1000, 250);
    $definitionPrefix = 'test-' . strtolower($tag) . '-';
    $createDefinition($db, $definitionPrefix . 'pair', 'Synthetic plant and pot', [
        ['key' => 'plant', 'label' => 'Plant', 'role' => 'plant', 'mode' => 'either', 'products' => [$plant, $saleOnlyPlant, $rentalOnlyPlant]],
        ['key' => 'pot', 'label' => 'Pot', 'role' => 'pot', 'mode' => 'either', 'products' => [$pot]],
    ]);
    $createDefinition($db, $definitionPrefix . 'shared', 'Synthetic shared plant', [
        ['key' => 'plant', 'label' => 'Plant', 'role' => 'plant', 'mode' => 'either', 'products' => [$shared]],
    ]);
    $createDefinition($db, $definitionPrefix . 'rollback', 'Synthetic rollback plant', [
        ['key' => 'plant', 'label' => 'Plant', 'role' => 'plant', 'mode' => 'either', 'products' => [$rollbackProduct]],
    ]);
    $createDefinition($db, $definitionPrefix . 'rental', 'Synthetic rental-only plant', [
        ['key' => 'plant', 'label' => 'Plant', 'role' => 'plant', 'mode' => 'rent', 'products' => [$rentalOnlyPlant]],
    ]);

    $customer = $createCustomer($db, 'owner');
    $otherCustomer = $createCustomer($db, 'other');
    $tomorrow = (new DateTimeImmutable('now', new DateTimeZone('Africa/Johannesburg')))->modify('+5 days')->format('Y-m-d');
    $pairCode = $definitionPrefix . 'pair';
    $mixedSelection = [
        ['component_key' => 'pot', 'product_id' => $pot, 'quantity' => 1, 'mode' => 'rent',
            'start_date' => $tomorrow, 'end_date_inclusive' => $tomorrow],
        ['component_key' => 'plant', 'product_id' => $plant, 'quantity' => 1, 'mode' => 'sale'],
    ];
    $beforeQuote = (int) ($one($db, 'SELECT COUNT(*) AS n FROM composition_checkouts')['n'] ?? 0);
    $beforeHolds = (int) ($one($db, 'SELECT COUNT(*) AS n FROM retail_holds')['n'] ?? 0);
    $beforeAllocations = (int) ($one($db, 'SELECT COUNT(*) AS n FROM rental_allocations')['n'] ?? 0);
    $quote = $service->quote($pairCode, $mixedSelection);
    $check($quote['available'] && $quote['total_minor'] === 2300 && count($quote['lines']) === 2,
        'Mixed quote uses current sale price and inclusive rental-day rate.');
    $check((int) ($one($db, 'SELECT COUNT(*) AS n FROM composition_checkouts')['n'] ?? 0) === $beforeQuote
        && (int) ($one($db, 'SELECT COUNT(*) AS n FROM retail_holds')['n'] ?? 0) === $beforeHolds
        && (int) ($one($db, 'SELECT COUNT(*) AS n FROM rental_allocations')['n'] ?? 0) === $beforeAllocations,
        'Quote creates no checkout, child order, rental or stock hold/allocation.');
    $savedId = $service->save($customer, 'Desk plant set', $pairCode, $mixedSelection);
    $saved = $service->saved($customer, $savedId);
    $check($saved !== null && $saved['name'] === 'Desk plant set' && count($saved['components']) === 2
        && $saved['components'][0]['product_name_snapshot'] !== '',
        'Saved composition belongs to its customer and retains selection/name/price snapshots.');
    $check($service->saved($otherCustomer, $savedId) === null && count($service->savedList($otherCustomer)) === 0,
        'Another customer cannot read the saved configuration.');
    $check($service->archiveSaved($customer, $savedId) && $service->saved($customer, $savedId) === null,
        'Owner can archive a saved configuration without deleting its history.');

    $saleOnly = [
        ['component_key' => 'plant', 'product_id' => $plant, 'quantity' => 1, 'mode' => 'sale'],
        ['component_key' => 'pot', 'product_id' => $pot, 'quantity' => 1, 'mode' => 'sale'],
    ];
    $saleCheckout = $service->createPending($customer, $pairCode, $saleOnly, 'sale-' . strtolower($tag) . '-checkout');
    $check($saleCheckout['rental_id'] === null && $saleCheckout['order_role'] === 'sale'
        && count($saleCheckout['lines']) === 2 && $saleCheckout['total_minor'] === 2900,
        'Sale-only preparation creates real order items and snapshots without a rental leg.');

    $mixedKey = 'mixed-' . strtolower($tag) . '-checkout';
    $mixedCheckout = $service->createPending($customer, $pairCode, $mixedSelection, $mixedKey);
    $storedPlantLine = array_values(array_filter($mixedCheckout['lines'], static fn (array $line): bool => (int) $line['product_id'] === $plant))[0];
    $insert($db, 'UPDATE products SET retail_price_minor=retail_price_minor+777 WHERE product_id=?', [$plant]);
    $replay = $service->createPending($customer, $pairCode, array_reverse($mixedSelection), $mixedKey);
    $check($mixedCheckout['composition_checkout_id'] === $replay['composition_checkout_id'] && $replay['existing'],
        'Idempotent retry with reordered equivalent choices returns one immutable pending checkout.');
    $check($mixedCheckout['order_role'] === 'sale' && $mixedCheckout['rental_id'] !== null
        && count($mixedCheckout['lines']) === 2 && $mixedCheckout['total_minor'] === 2300,
        'Mixed preparation snapshots one sale leg and one rental leg with an aggregate amount.');
    $check(count($mixedCheckout['lines'][0]['components_snapshot']) === 1
        && count($mixedCheckout['lines'][1]['components_snapshot']) === 1,
        'Each merged child line retains readable selected-component identity.');
    $replayPlantLine = array_values(array_filter($replay['lines'], static fn (array $line): bool => (int) $line['product_id'] === $plant))[0];
    $check($replayPlantLine['unit_price_minor'] === $storedPlantLine['unit_price_minor']
        && $replay['total_minor'] === $mixedCheckout['total_minor'],
        'Pending checkout replay retains its authoritative historic price after catalogue repricing.');
    $plantAfterCheckout = $one($db, 'SELECT i.on_hand FROM inventory i WHERE i.product_id=?', [$plant]);
    $check((int) $plantAfterCheckout['on_hand'] === 9,
        'Pending composition places a sale hold without decrementing physical stock.');
    $reject(fn () => $service->createPending($customer, $pairCode, [
        ['component_key' => 'plant', 'product_id' => $plant, 'quantity' => 2, 'mode' => 'sale'],
        ['component_key' => 'pot', 'product_id' => $pot, 'quantity' => 1, 'mode' => 'rent',
            'start_date' => $tomorrow, 'end_date_inclusive' => $tomorrow],
    ], $mixedKey), 'A checkout key cannot be reused for a changed selection.');

    $sharedCode = $definitionPrefix . 'shared';
    $sharedSelection = [
        ['component_key' => 'plant', 'product_id' => $shared, 'quantity' => 1, 'mode' => 'sale'],
        ['component_key' => 'plant', 'product_id' => $shared, 'quantity' => 1, 'mode' => 'rent',
            'start_date' => $tomorrow, 'end_date_inclusive' => $tomorrow],
    ];
    $overbookedQuote = $service->quote($sharedCode, [
        ['component_key' => 'plant', 'product_id' => $shared, 'quantity' => 2, 'mode' => 'sale'],
        ['component_key' => 'plant', 'product_id' => $shared, 'quantity' => 1, 'mode' => 'rent',
            'start_date' => $tomorrow, 'end_date_inclusive' => $tomorrow],
    ]);
    $check(!$overbookedQuote['available'],
        'Read-only quote accounts for same-SKU sale and rental demand together.');
    $sharedCheckout = $service->createPending($customer, $sharedCode, $sharedSelection, 'shared-' . strtolower($tag) . '-checkout');
    $check(count($sharedCheckout['lines']) === 2 && $sharedCheckout['rental_id'] !== null
        && (int) $one($db, 'SELECT on_hand FROM inventory WHERE product_id=?', [$shared])['on_hand'] === 2,
        'Same-SKU sale and dated rental holds share stock while physical on-hand stays unchanged.');

    $rollbackKey = 'rollback-' . strtolower($tag) . '-checkout';
    $reject(fn () => $service->createPending($customer, $definitionPrefix . 'rollback', [
        ['component_key' => 'plant', 'product_id' => $rollbackProduct, 'quantity' => 1, 'mode' => 'sale'],
        ['component_key' => 'plant', 'product_id' => $rollbackProduct, 'quantity' => 1, 'mode' => 'rent',
            'start_date' => $tomorrow, 'end_date_inclusive' => $tomorrow],
    ], $rollbackKey), 'Sale-plus-rental over-allocation fails after sale hold staging.');
    $rollbackOrderKey = 'cmp_' . substr(hash('sha256', $customer . ':' . $rollbackKey), 0, 64);
    $rollbackRentalKey = 'cmp_' . substr(hash('sha256', 'rental:' . $customer . ':' . $rollbackKey), 0, 64);
    $check((int) ($one($db, 'SELECT COUNT(*) AS n FROM sales_orders WHERE checkout_key=?', [$rollbackOrderKey])['n'] ?? 0) === 0
        && (int) ($one($db, 'SELECT COUNT(*) AS n FROM rentals WHERE checkout_key=?', [$rollbackRentalKey])['n'] ?? 0) === 0
        && (int) ($one($db, 'SELECT COUNT(*) AS n FROM composition_checkouts WHERE customer_id=? AND checkout_key=?', [$customer, $rollbackKey])['n'] ?? 0) === 0
        && (int) $one($db, 'SELECT on_hand FROM inventory WHERE product_id=?', [$rollbackProduct])['on_hand'] === 1,
        'Failed combined capacity restores the full transaction: no child, parent or inventory mutation remains.');

    $rentalOnly = $service->createPending($customer, $definitionPrefix . 'rental', [
        ['component_key' => 'plant', 'product_id' => $rentalOnlyPlant, 'quantity' => 2, 'mode' => 'rent',
            'start_date' => $tomorrow, 'end_date_inclusive' => $tomorrow],
    ], 'rental-' . strtolower($tag) . '-checkout');
    $check($rentalOnly['order_role'] === 'payment_anchor' && $rentalOnly['rental_id'] !== null
        && count($rentalOnly['lines']) === 1
        && (int) ($one($db, 'SELECT COUNT(*) AS n FROM order_items WHERE order_id=?', [$rentalOnly['order_id']])['n'] ?? 0) === 0,
        'Rent-only composition records an explicitly flagged empty payment anchor and a real rental claim.');

    $finished = $service->quote('starter-finished-living-wall', [
        ['component_key' => 'finished', 'product_id' => (int) $one($db, 'SELECT product_id FROM products WHERE sku=\'VG-N\'')['product_id'],
            'quantity' => 2, 'mode' => 'sale'],
    ]);
    $check(count($finished['lines']) === 1 && $finished['lines'][0]['quantity'] === 2
        && count($finished['lines'][0]['components']) === 1,
        'Finished-stock quote allocates only its own selected SKU and quantity.');
    $finishedRental = $service->quote('starter-finished-living-wall', [
        ['component_key' => 'finished', 'product_id' => (int) $one($db, 'SELECT product_id FROM products WHERE sku=\'VG-N\'')['product_id'],
            'quantity' => 1, 'mode' => 'rent', 'start_date' => $tomorrow, 'end_date_inclusive' => $tomorrow],
    ]);
    $check($finishedRental['lines'][0]['mode'] === 'rent' && $finishedRental['total_minor'] === 13000,
        'Finished-stock rental quote uses its own SKU rental rate and selected dates.');
    $reject(fn () => $service->quote('starter-finished-living-wall', [
        ['component_key' => 'finished', 'product_id' => $plantSmall, 'quantity' => 1, 'mode' => 'sale'],
    ]), 'Finished-stock selection cannot substitute another SKU or expand into nested components.');

    echo "Composition service checks: $checks passed, 0 failed.\n";
} finally {
    // Runtime credentials intentionally retain no DELETE permission; synthetic records stay in the isolated test schema.
    $db->close();
}
