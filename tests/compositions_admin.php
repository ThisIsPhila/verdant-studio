<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Admin\AdminAccess;
use Verdant\Admin\CompositionAdminRepository;
use Verdant\Admin\CompositionAdminService;
use Verdant\Http\AdminCompositionController;
use Verdant\Compositions\CompositionRepository;
use Verdant\Compositions\CompositionService;
use Verdant\Inventory\CapacityRepository;
use Verdant\Inventory\InventoryRepository;
use Verdant\Persistence\DatabaseException;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Persistence\TransactionManager;
use Verdant\Support\ValidationException;

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
    } catch (ValidationException|DomainException) {
        $check(true, $message);
        return;
    }
    throw new RuntimeException($message);
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
    try {
        return $statement->get_result()->fetch_assoc() ?: null;
    } finally {
        $statement->close();
    }
};

try {
    $adminId = $insert($db,
        "INSERT INTO customers(email,password_hash,name,role,account_state) VALUES(?, 'test-hash', 'Composition Editor', 'admin', 'active')",
        ['composition-admin-' . strtolower($tag) . '@example.test'],
    );
    $insert($db, 'INSERT INTO admin_allowlist (email) VALUES (?)', ['composition-admin-' . strtolower($tag) . '@example.test']);
    $customerId = $insert($db,
        "INSERT INTO customers(email,password_hash,name,role,account_state) VALUES(?, 'test-hash', 'Composition Customer', 'customer', 'active')",
        ['composition-user-' . strtolower($tag) . '@example.test'],
    );
    $admin = ['customer_id' => $adminId, 'role' => 'admin'];
    $customer = ['customer_id' => $customerId, 'role' => 'customer'];
    $categoryId = $insert($db, 'INSERT INTO categories(name) VALUES(?)', ['Composition admin ' . $tag]);
    $makeProduct = static function (string $suffix, string $name) use ($db, $insert, $categoryId, $tag): int {
        $productId = $insert($db,
            "INSERT INTO products(category_id,sku,name,description,product_kind,accessory_subtype,selling_unit,
                light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor)
             VALUES(?,?,?,'Synthetic admin fixture','plant','other','Each','unknown','unknown','unknown',1,0,1550,NULL)",
            [$categoryId, 'CA-' . $tag . '-' . $suffix, $name],
        );
        $insert($db, 'INSERT INTO inventory(product_id,on_hand) VALUES(?,8)', [$productId]);
        return $productId;
    };
    $oldProductId = $makeProduct('OLD', 'Admin fixture plant old');
    $newProductId = $makeProduct('NEW', 'Admin fixture plant new');
    $floralProductId = $insert($db,
        "INSERT INTO products(category_id,sku,name,description,product_kind,accessory_subtype,selling_unit,
            light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor)
         VALUES(?,?,?,'Synthetic finished floral piece','floral_arrangement','other','Each','unknown','unknown','unknown',1,0,1999,NULL)",
        [$categoryId, 'CA-' . $tag . '-FLORAL', 'Admin fixture finished floral piece'],
    );
    $insert($db, 'INSERT INTO inventory(product_id,on_hand) VALUES(?,8)', [$floralProductId]);
    $definitionId = $insert($db,
        "INSERT INTO composition_definitions(definition_code,name,arrangement_kind,strategy) VALUES(?,?, 'plant_arrangement','custom')",
        ['admin-test-' . strtolower($tag), 'Admin fixture arrangement'],
    );
    $componentId = $insert($db,
        "INSERT INTO composition_definition_components(definition_id,component_key,label,component_role,source_type,
             required,min_selections,max_selections,min_quantity,max_quantity,permitted_mode,sort_order)
         VALUES(?, 'plant', 'Original plant choice', 'plant', 'product', 1, 1, 1, 1, 4, 'sale', 10)",
        [$definitionId],
    );
    $insert($db, 'INSERT INTO composition_component_product_allowlist(component_id,product_id) VALUES(?,?)', [$componentId, $oldProductId]);

    $transactions = new TransactionManager($db);
    $adminService = new CompositionAdminService(new CompositionAdminRepository($db), $transactions, new AdminAccess($db));
    $compositionService = new CompositionService(
        new CompositionRepository($db), new InventoryRepository($db), new CapacityRepository($db), $transactions,
    );
    $reject(fn () => $adminService->page($customer), 'A customer cannot load protected composition administration.');
    $reject(fn () => $adminService->save($customer, $definitionId, []), 'A customer cannot mutate composition definitions.');

    $savedId = $compositionService->save($customerId, 'Historic saved plant', 'admin-test-' . strtolower($tag), [
        ['component_key' => 'plant', 'product_id' => $oldProductId, 'quantity' => 1, 'mode' => 'sale'],
    ]);
    $checkoutKey = bin2hex(random_bytes(24));
    $checkout = $compositionService->createPending($customerId, 'admin-test-' . strtolower($tag), [
        ['component_key' => 'plant', 'product_id' => $oldProductId, 'quantity' => 1, 'mode' => 'sale'],
    ], $checkoutKey);
    $before = $one($db,
        'SELECT c.name, c.status, (SELECT COUNT(*) FROM saved_compositions s WHERE s.definition_id=c.definition_id) AS saved_count,
                (SELECT COUNT(*) FROM composition_checkouts x WHERE x.definition_id=c.definition_id) AS checkout_count
         FROM composition_definitions c WHERE c.definition_id = ?',
        [$definitionId],
    );
    $historicalCheckout = $one($db,
        'SELECT components_snapshot FROM composition_checkout_lines WHERE composition_checkout_id = ?',
        [$checkout['composition_checkout_id']],
    );
    $historicalComponents = json_decode((string) $historicalCheckout['components_snapshot'], true, 512, JSON_THROW_ON_ERROR);

    $adminService->save($admin, $definitionId, [
        'name' => 'Edited arrangement name', 'status' => 'active',
        'components' => [[
            'component_id' => (string) $componentId, 'component_key' => 'plant', 'label' => 'Updated plant choice',
            'component_role' => 'plant', 'source_type' => 'product', 'required' => '1', 'archived' => '0',
            'min_selections' => '1', 'max_selections' => '1', 'min_quantity' => '1', 'max_quantity' => '4',
            'permitted_mode' => 'sale', 'sort_order' => '10', 'product_ids' => [(string) $newProductId],
            'family_ids' => [], 'anchor_key' => '',
        ]],
    ]);
    $afterDefinition = $compositionService->definition('admin-test-' . strtolower($tag));
    $check($afterDefinition !== null && $afterDefinition['name'] === 'Edited arrangement name'
        && count($afterDefinition['components'][0]['product_options']) === 1
        && (int) $afterDefinition['components'][0]['product_options'][0]['product_id'] === $newProductId,
        'Admin updates the definition and active SKU allowlist used by new quotes.');
    $oldAllowlist = $one($db,
        'SELECT archived_at FROM composition_component_product_allowlist WHERE component_id = ? AND product_id = ?',
        [$componentId, $oldProductId],
    );
    $savedSnapshot = $one($db,
        'SELECT component_label_snapshot, product_name_snapshot, sku_snapshot, retail_price_minor_snapshot
         FROM saved_composition_components WHERE saved_composition_id = ?',
        [$savedId],
    );
    $afterCheckout = $one($db,
        'SELECT components_snapshot FROM composition_checkout_lines WHERE composition_checkout_id = ?',
        [$checkout['composition_checkout_id']],
    );
    $afterHistoricalComponents = json_decode((string) $afterCheckout['components_snapshot'], true, 512, JSON_THROW_ON_ERROR);
    $afterCounts = $one($db,
        'SELECT c.name, c.status, (SELECT COUNT(*) FROM saved_compositions s WHERE s.definition_id=c.definition_id) AS saved_count,
                (SELECT COUNT(*) FROM composition_checkouts x WHERE x.definition_id=c.definition_id) AS checkout_count
         FROM composition_definitions c WHERE c.definition_id = ?',
        [$definitionId],
    );
    $check($oldAllowlist['archived_at'] !== null && $savedSnapshot['component_label_snapshot'] === 'Original plant choice'
        && $savedSnapshot['sku_snapshot'] === 'CA-' . $tag . '-OLD' && (int) $savedSnapshot['retail_price_minor_snapshot'] === 1550
        && $afterHistoricalComponents === $historicalComponents,
        'Removing an allowed SKU is a soft archive and leaves saved and checkout snapshots unchanged.');
    $check($afterCounts['name'] === 'Edited arrangement name' && $before['saved_count'] === $afterCounts['saved_count']
        && $before['checkout_count'] === $afterCounts['checkout_count'],
        'Editing component rules preserves saved records and checkout history.');

    try {
        $adminService->save($admin, $definitionId, [
            'name' => 'This parent update must roll back', 'status' => 'active',
            'components' => [[
                'component_id' => '', 'component_key' => 'plant', 'label' => 'Duplicate key row',
                'component_role' => 'plant', 'source_type' => 'product', 'required' => '1', 'archived' => '0',
                'min_selections' => '1', 'max_selections' => '1', 'min_quantity' => '1', 'max_quantity' => '4',
                'permitted_mode' => 'sale', 'sort_order' => '20', 'product_ids' => [(string) $newProductId],
                'family_ids' => [], 'anchor_key' => '',
            ]],
        ]);
        throw new RuntimeException('A component-key collision should have rolled the edit back.');
    } catch (DatabaseException $error) {
        if ($error->getCode() !== 1062) {
            throw $error;
        }
    }
    $rolledBack = $one($db, 'SELECT name FROM composition_definitions WHERE definition_id = ?', [$definitionId]);
    $componentRows = $one($db, 'SELECT COUNT(*) AS n FROM composition_definition_components WHERE definition_id = ?', [$definitionId]);
    $check($rolledBack['name'] === 'Edited arrangement name' && (int) $componentRows['n'] === 1,
        'A late database uniqueness failure rolls back the parent and all component edits.');

    $reject(fn () => $adminService->save($admin, $definitionId, [
        'name' => 'Invalid flower rental', 'status' => 'active',
        'components' => [[
            'component_id' => (string) $componentId, 'component_key' => 'plant', 'label' => 'Bad flower',
            'component_role' => 'flower', 'source_type' => 'product', 'required' => '1', 'archived' => '0',
            'min_selections' => '1', 'max_selections' => '1', 'min_quantity' => '1', 'max_quantity' => '4',
            'permitted_mode' => 'rent', 'sort_order' => '10', 'product_ids' => [(string) $newProductId],
            'family_ids' => [], 'anchor_key' => '',
        ]],
    ]), 'Flower components cannot be configured as rentals.');
    $unchanged = $one($db, 'SELECT name FROM composition_definitions WHERE definition_id = ?', [$definitionId]);
    $check($unchanged['name'] === 'Edited arrangement name', 'Invalid edits roll back without changing the definition.');
    $reject(fn () => $adminService->save($admin, $definitionId, [
        'name' => 'Finished arrangement as foliage', 'status' => 'active',
        'components' => [[
            'component_id' => (string) $componentId, 'component_key' => 'plant', 'label' => 'Bad foliage',
            'component_role' => 'foliage', 'source_type' => 'product', 'required' => '1', 'archived' => '0',
            'min_selections' => '1', 'max_selections' => '1', 'min_quantity' => '1', 'max_quantity' => '4',
            'permitted_mode' => 'sale', 'sort_order' => '10', 'product_ids' => [(string) $floralProductId],
            'family_ids' => [], 'anchor_key' => '',
        ]],
    ]), 'Finished floral arrangements cannot be configured as cut foliage components.');

    $adminService->save($admin, $definitionId, [
        'name' => 'Archived arrangement', 'status' => 'archived',
        'components' => [[
            'component_id' => (string) $componentId, 'component_key' => 'plant', 'label' => 'Updated plant choice',
            'component_role' => 'plant', 'source_type' => 'product', 'required' => '1', 'archived' => '1',
            'min_selections' => '1', 'max_selections' => '1', 'min_quantity' => '1', 'max_quantity' => '4',
            'permitted_mode' => 'sale', 'sort_order' => '10', 'product_ids' => [], 'family_ids' => [], 'anchor_key' => '',
        ]],
    ]);
    $archivedRow = $one($db, 'SELECT archived_at FROM composition_definition_components WHERE component_id = ?', [$componentId]);
    $check($archivedRow['archived_at'] !== null && $compositionService->definition('admin-test-' . strtolower($tag)) === null,
        'Admin can archive a definition and its component without deleting history.');

    $adminService->save($admin, $definitionId, [
        'name' => 'Restored arrangement', 'status' => 'active',
        'components' => [[
            'component_id' => (string) $componentId, 'component_key' => 'plant', 'label' => 'Restored plant choice',
            'component_role' => 'plant', 'source_type' => 'product', 'required' => '1', 'archived' => '0',
            'min_selections' => '1', 'max_selections' => '1', 'min_quantity' => '1', 'max_quantity' => '4',
            'permitted_mode' => 'sale', 'sort_order' => '10', 'product_ids' => [(string) $newProductId],
            'family_ids' => [], 'anchor_key' => '',
        ]],
    ]);
    $restored = $compositionService->definition('admin-test-' . strtolower($tag));
    $newLink = $one($db,
        'SELECT archived_at FROM composition_component_product_allowlist WHERE component_id = ? AND product_id = ?',
        [$componentId, $newProductId],
    );
    $check($restored !== null && $newLink['archived_at'] === null, 'Archived definitions and their allowlist entries can be restored.');

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $controller = new AdminCompositionController($adminService);
    $renderRejected = static function (array $post) use ($controller, $admin): array {
        $initialLevel = ob_get_level();
        ob_start();
        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });
        try {
            $controller->save($post, $admin);
            $status = http_response_code();
        } finally {
            restore_error_handler();
            $body = '';
            while (ob_get_level() > $initialLevel) {
                $body = (string) ob_get_clean() . $body;
            }
        }
        return [$status ?? 200, $body];
    };
    [$nameArrayStatus, $nameArrayBody] = $renderRejected([
        'definition_id' => (string) $definitionId, 'name' => ['forged' => 'value'], 'status' => 'active', 'components' => [],
    ]);
    $check($nameArrayStatus === 422 && str_contains($nameArrayBody, 'Changes were not saved')
        && !str_contains($nameArrayBody, 'Warning:'), 'Array-valued definition names render a usable 422 without warnings.');
    [$componentArrayStatus, $componentArrayBody] = $renderRejected([
        'definition_id' => (string) $definitionId, 'name' => 'Bad nested component', 'status' => 'active',
        'components' => [['component_key' => ['nested' => 'value'], 'label' => new stdClass()]],
    ]);
    $check($componentArrayStatus === 422 && str_contains($componentArrayBody, 'Changes were not saved')
        && !str_contains($componentArrayBody, 'Warning:'), 'Malformed nested component fields render safely without warnings.');
    [$allowlistStatus, $allowlistBody] = $renderRejected([
        'definition_id' => (string) $definitionId, 'name' => 'Bad allowlist object', 'status' => 'active',
        'components' => [[
            'component_id' => (string) $componentId, 'component_key' => 'plant', 'label' => 'Plant',
            'component_role' => 'plant', 'source_type' => 'product', 'required' => '1', 'archived' => '0',
            'min_selections' => '1', 'max_selections' => '1', 'min_quantity' => '1', 'max_quantity' => '4',
            'permitted_mode' => 'sale', 'sort_order' => '10', 'product_ids' => [], 'family_ids' => [],
            'product_allowlist' => ['hostile' => new stdClass()], 'family_allowlist' => ['hostile' => new stdClass()],
        ]],
    ]);
    $check($allowlistStatus === 422 && str_contains($allowlistBody, 'Changes were not saved')
        && !str_contains($allowlistBody, 'Warning:'), 'Forged allowlist objects are ignored in the 422 render path.');

    ob_start();
    $controller->definitions(['edit' => (string) $definitionId], $admin);
    $editorHtml = (string) ob_get_clean();
    $check(substr_count($editorHtml, 'data-recipe-tab=') === 4, 'The selected recipe exposes four focused editor sections.');
    $check(str_contains($editorHtml, 'data-recipe-template') && !str_contains($editorHtml, 'components[1][label]'), 'New components are template-only until explicitly added.');
    $check(str_contains($editorHtml, 'data-picker-search') && str_contains($editorHtml, 'data-picker-more'), 'Product picking has search and bounded display controls.');
    $check(str_contains($editorHtml, 'Advanced settings') && str_contains($editorHtml, 'data-recipe-summary'), 'Advanced rules and a review summary remain available.');
    $normalized = \Verdant\Admin\CompositionEditor::rows(['components' => []], ['components' => [[
        'label' => 'Archived rule', 'archived' => '1', 'required' => '0',
        'product_ids' => [(string) $newProductId], 'family_ids' => [],
    ]]]);
    $check($normalized[0]['archived'] && !$normalized[0]['required'] && $normalized[0]['product_ids'] === [$newProductId], 'Failed-post rendering preserves archive, optional state and selected IDs.');

    echo 'Composition admin checks: ' . $checks . " passed, 0 failed.\n";
} finally {
    $db->close();
}
