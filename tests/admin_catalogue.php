<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Admin\AdminAccess;
use Verdant\Admin\CatalogueAdminRepository;
use Verdant\Admin\CatalogueAdminService;
use Verdant\Inventory\CapacityRepository;
use Verdant\Inventory\InventoryRepository;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseException;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Persistence\TransactionManager;
use Verdant\Support\ValidationException;

if (PHP_SAPI !== 'cli' || preg_match('/^verdant_test_[a-z0-9_]+$/', getenv('DB_NAME') ?: '') !== 1) {
    fwrite(STDERR, "Use an isolated verdant_test_ database.\n");
    exit(2);
}

$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
};
$adminId = null;
$archivedAdminId = null;
$customerId = null;
$categoryId = null;
$productId = null;
$rentalId = null;
$orderId = null;
try {
    $emailTail = bin2hex(random_bytes(6));
    $statement = $db->prepare("INSERT INTO customers(email,password_hash,name,role,account_state) VALUES(?,?,?,'admin','active')");
    $statement->execute(['admin-' . $emailTail . '@example.test', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'Synthetic Admin']);
    $adminId = (int) $db->insert_id;
    $allowlist = $db->prepare('INSERT INTO admin_allowlist (email) VALUES (?)');
    $allowlist->execute(['admin-' . $emailTail . '@example.test']);
    $allowlist->close();
    $admin = ['customer_id' => $adminId, 'role' => 'admin'];
    $statement = $db->prepare("INSERT INTO customers(email,password_hash,name,role,account_state,archived_at) VALUES(?,?,?,'admin','archived',UTC_TIMESTAMP())");
    $statement->execute(['archived-admin-' . $emailTail . '@example.test', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'Synthetic Archived Admin']);
    $archivedAdminId = (int) $db->insert_id;
    $statement = $db->prepare("INSERT INTO customers(email,password_hash,name,role,account_state) VALUES(?,?,?,'customer','active')");
    $statement->execute(['customer-' . $emailTail . '@example.test', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'Synthetic Customer']);
    $customerId = (int) $db->insert_id;

    $repository = new CatalogueAdminRepository($db);
    $inventory = new InventoryRepository($db);
    $capacity = new CapacityRepository($db);
    $service = new CatalogueAdminService($repository, $inventory, $capacity, new TransactionManager($db), new AdminAccess($db));

    try {
        $service->products(['customer_id' => $customerId, 'role' => 'admin']);
        throw new RuntimeException('A customer was granted admin access.');
    } catch (DomainException) {
        ++$checks;
    }
    try {
        $service->products(['customer_id' => $archivedAdminId, 'role' => 'admin']);
        throw new RuntimeException('An archived administrator was granted access.');
    } catch (DomainException) {
        ++$checks;
    }
    $categoryId = $service->saveCategory($admin, null, 'Admin test ' . $emailTail);
    $check($categoryId > 0, 'Admin can create a category.');

    $input = [
        'category_id' => (string) $categoryId, 'sku' => 'AT-' . strtoupper($emailTail),
        'name' => 'Synthetic admin product', 'description' => 'A test-only catalogue item.',
        'product_kind' => 'plant', 'selling_unit' => 'Each', 'size_label' => 'Standard', 'colour_label' => '',
        'light_level' => 'indirect', 'watering_level' => 'weekly', 'toxicity' => 'non_toxic',
        'image_path' => '', 'buy_enabled' => '1', 'rent_enabled' => '1',
        'retail_price' => '12.99', 'rental_daily_rate' => '7.50', 'on_hand' => '8',
    ];
    $productId = $service->saveProduct($admin, null, $input);
    $product = $service->product($admin, $productId);
    $check((int) $product['retail_price_minor'] === 1299 && (int) $product['rental_daily_rate_minor'] === 750,
        'Decimal prices are stored as exact integer minor units.');
    $check($product['product_kind'] === 'plant' && $product['size_label'] === 'Standard'
        && $product['selling_unit'] === 'Each', 'Typed catalogue details persist with the stock-bearing SKU.');
    $check((int) $product['on_hand'] === 8 && (int) $product['version'] === 0, 'Product creation creates physical inventory.');

    try {
        $service->saveProduct($admin, null, array_replace($input, [
            'sku' => 'AT-FL-' . strtoupper($emailTail), 'product_kind' => 'cut_flower',
        ]));
        throw new RuntimeException('The admin service accepted rental for cut flowers.');
    } catch (ValidationException) {
        ++$checks;
    }
    try {
        $statement = $db->prepare("UPDATE products SET product_kind='cut_flower' WHERE product_id=?");
        $statement->execute([$productId]);
        throw new RuntimeException('The database accepted a floral kind on a rentable product.');
    } catch (mysqli_sql_exception $error) {
        $check($error->getCode() === 3819, 'The database CHECK prevents floral rental even for direct writes.');
    }
    $accessoryCategory = $service->saveCategory($admin, null, 'Accessory test ' . $emailTail);
    $accessory = $service->saveProduct($admin, null, array_replace($input, [
        'category_id' => (string) $accessoryCategory,
        'sku' => 'AT-ACC-' . strtoupper($emailTail), 'product_kind' => 'accessory',
        'light_level' => 'indirect', 'watering_level' => 'weekly', 'toxicity' => 'non_toxic',
        'rent_enabled' => '0', 'rental_daily_rate' => '',
    ]));
    $check($service->product($admin, $accessory)['light_level'] === 'unknown'
        && $service->product($admin, $accessory)['watering_level'] === 'unknown'
        && $service->product($admin, $accessory)['toxicity'] === 'unknown',
        'Non-plant catalogue kinds cannot retain unverified plant-care claims.');

    $page = $service->products($admin, 1);
    $check(isset($page['items'], $page['page'], $page['has_next']), 'Admin product lists return consistent pagination fields.');
    try {
        $service->saveProduct($admin, $productId, array_replace($input, ['version' => '0', 'on_hand' => '1000001']));
        throw new RuntimeException('An out-of-range inventory quantity was accepted.');
    } catch (ValidationException) {
        ++$checks;
    }
    $invalid = $input;
    $invalid['retail_price'] = '1.999';
    try {
        $service->saveProduct($admin, null, $invalid);
        throw new RuntimeException('A price with more than two decimals was accepted.');
    } catch (ValidationException) {
        ++$checks;
    }
    $invalid = $input;
    $invalid['on_hand'] = '-1';
    try {
        $service->saveProduct($admin, null, $invalid);
        throw new RuntimeException('A negative inventory quantity was accepted.');
    } catch (ValidationException) {
        ++$checks;
    }
    $invalid = $input;
    $invalid['retail_price'] = '99999999999999999.99';
    try {
        $service->saveProduct($admin, null, $invalid);
        throw new RuntimeException('An excessive price was accepted.');
    } catch (ValidationException) {
        ++$checks;
    }
    $invalid = $input;
    $invalid['category_id'] = '99999999999999999';
    try {
        $service->saveProduct($admin, null, $invalid);
        throw new RuntimeException('A missing category was accepted.');
    } catch (ValidationException) {
        ++$checks;
    }

    $updated = $input;
    $updated['version'] = '0';
    $updated['on_hand'] = '9';
    $service->saveProduct($admin, $productId, $updated);
    $check((int) $service->product($admin, $productId)['version'] === 1, 'Inventory edits increment the optimistic version.');
    try {
        $service->saveProduct($admin, $productId, $updated);
        throw new RuntimeException('A stale inventory form was accepted.');
    } catch (ValidationException) {
        ++$checks;
    }

    $statement = $db->prepare("INSERT INTO rentals(customer_id,checkout_key,state) VALUES(?,?,'pending_payment')");
    $statement->execute([$customerId, 'admin-test-rental-' . $emailTail]);
    $rentalId = (int) $db->insert_id;
    $start = (new DateTimeImmutable('now', new DateTimeZone('Africa/Johannesburg')))->modify('+3 days')->format('Y-m-d');
    $end = (new DateTimeImmutable($start, new DateTimeZone('Africa/Johannesburg')))->modify('+4 days')->format('Y-m-d');
    $statement = $db->prepare('INSERT INTO rental_items(rental_id,product_id,product_name_snapshot,sku_snapshot,quantity,start_date,end_date_exclusive,daily_rate_minor) VALUES(?,?,?,?,?,?,?,?)');
    $statement->execute([$rentalId, $productId, 'Synthetic admin product', $input['sku'], 7, $start, $end, 750]);
    $rentalItemId = (int) $db->insert_id;
    $statement = $db->prepare("INSERT INTO rental_allocations(rental_item_id,quantity,state) VALUES(?,7,'held')");
    $statement->execute([$rentalItemId]);

    $order = $db->prepare("INSERT INTO sales_orders(customer_id,checkout_key,state) VALUES(?,?,'confirmed')");
    $order->execute([$customerId, 'admin-test-order-' . $emailTail]);
    $orderId = (int) $db->insert_id;
    $statement = $db->prepare('INSERT INTO order_items(order_id,product_id,product_name_snapshot,sku_snapshot,quantity,unit_price_minor) VALUES(?,?,?,?,1,1299)');
    $statement->execute([$orderId, $productId, 'Original snapshot name', $input['sku']]);
    $orderItemId = (int) $db->insert_id;

    $reserved = array_replace($input, ['version' => '1', 'on_hand' => '6']);
    try {
        $service->saveProduct($admin, $productId, $reserved);
        throw new RuntimeException('Stock below a future held rental was accepted.');
    } catch (ValidationException) {
        ++$checks;
    }
    $changed = array_replace($input, [
        'version' => '1', 'on_hand' => '9', 'name' => 'Edited admin product',
        'sku' => 'AT-EDIT-' . strtoupper($emailTail), 'retail_price' => '13.50',
    ]);
    $service->saveProduct($admin, $productId, $changed);
    $snapshot = $db->prepare('SELECT product_name_snapshot, sku_snapshot, unit_price_minor FROM order_items WHERE order_item_id = ?');
    $snapshot->execute([$orderItemId]);
    $snapshotRow = $snapshot->get_result()->fetch_assoc();
    $check($snapshotRow['product_name_snapshot'] === 'Original snapshot name'
        && $snapshotRow['sku_snapshot'] === $input['sku'] && (int) $snapshotRow['unit_price_minor'] === 1299,
        'Catalogue edits preserve existing retail order snapshots.');
    $beforeArchiveCapacity = $capacity->retailAvailable($productId);
    $service->setProductArchived($admin, $productId, true);
    $afterArchiveCapacity = $capacity->retailAvailable($productId);
    $check($beforeArchiveCapacity === 2 && $afterArchiveCapacity === 2,
        'Archiving a product retains the held rental claim against physical stock.');

    try {
        $service->saveCategory($admin, null, 'Admin test ' . $emailTail);
        throw new RuntimeException('A duplicate category name was accepted.');
    } catch (DatabaseException $error) {
        $check($error->getCode() === 1062, 'Duplicate category names are rejected by the unique constraint.');
    }

    $service->setProductArchived($admin, $productId, false);
    try {
        $service->setCategoryArchived($admin, $categoryId, true);
        throw new RuntimeException('A category with an active product was archived.');
    } catch (ValidationException) {
        ++$checks;
    }
    $service->setProductArchived($admin, $productId, true);
    $service->setCategoryArchived($admin, $categoryId, true);
    try {
        $service->setProductArchived($admin, $productId, false);
        throw new RuntimeException('A product was restored into an archived category.');
    } catch (ValidationException) {
        ++$checks;
    }
    $service->setCategoryArchived($admin, $categoryId, false);
    $service->setProductArchived($admin, $productId, false);
    $check($service->product($admin, $productId)['archived_at'] === null, 'Archived products and categories can be restored.');

    echo "Admin catalogue checks: $checks passed, 0 failed.\n";
} finally {
    $db->close();
}
