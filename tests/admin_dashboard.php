<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Admin\AdminAccess;
use Verdant\Admin\DashboardRepository;
use Verdant\Admin\DashboardService;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;

$databaseName = getenv('DB_NAME') ?: '';
if (PHP_SAPI !== 'cli' || (getenv('APP_ENV') ?: '') !== 'local'
    || preg_match('/^verdant_test_[a-z0-9_]+$/', $databaseName) !== 1) {
    fwrite(STDERR, "Use APP_ENV=local and an isolated verdant_test_ database.\n");
    exit(2);
}

$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    ++$checks;
    echo "PASS: $label\n";
};
$insert = static function (string $sql, array $parameters = []) use ($db): int {
    $statement = $db->prepare($sql);
    try {
        $statement->execute($parameters);
        return (int) $db->insert_id;
    } finally {
        $statement->close();
    }
};
$scalar = static function (string $sql, array $parameters = []) use ($db): int {
    $statement = $db->prepare($sql);
    try {
        $statement->execute($parameters);
        return (int) ($statement->get_result()->fetch_row()[0] ?? 0);
    } finally {
        $statement->close();
    }
};

$db->begin_transaction();
try {
    $tag = bin2hex(random_bytes(8));
    $service = new DashboardService(new DashboardRepository($db), new AdminAccess($db));
    $adminId = $insert(
        "INSERT INTO customers(email,password_hash,name,role,account_state) VALUES(?, 'unused fixture hash', ?, 'admin', 'active')",
        ['dashboard-admin-' . $tag . '@example.test', 'Dashboard Admin ' . $tag],
    );
    $insert('INSERT INTO admin_allowlist (email) VALUES (?)', ['dashboard-admin-' . $tag . '@example.test']);
    $adminActor = ['customer_id' => $adminId, 'role' => 'admin'];
    $initial = $service->overview($adminActor);
    $customerId = $insert(
        "INSERT INTO customers(email,password_hash,name,role,account_state) VALUES(?, 'unused fixture hash', ?, 'customer', 'active')",
        ['dashboard-customer-' . $tag . '@example.test', 'Dashboard Customer ' . $tag],
    );
    $archivedCustomerId = $insert(
        "INSERT INTO customers(email,password_hash,name,role,account_state,archived_at) VALUES(?, 'unused fixture hash', ?, 'customer', 'archived', UTC_TIMESTAMP())",
        ['dashboard-archived-customer-' . $tag . '@example.test', 'Dashboard Archived ' . $tag],
    );
    $customerActor = ['customer_id' => $customerId, 'role' => 'customer'];

    $customerDenied = false;
    try {
        $service->overview($customerActor);
    } catch (DomainException) {
        $customerDenied = true;
    }
    $check($customerDenied, 'Dashboard refuses a database-backed customer role.');
    $forgedRoleDenied = false;
    try {
        $service->overview(['customer_id' => $customerId, 'role' => 'admin']);
    } catch (DomainException) {
        $forgedRoleDenied = true;
    }
    $check($forgedRoleDenied, 'Dashboard rejects a forged admin role in the actor data.');

    $categoryId = $insert('INSERT INTO categories(name) VALUES(?)', ['Dashboard fixture ' . $tag]);
    $productId = $insert(
        "INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor) VALUES(?,?,?,'Fixture','indirect','weekly','non_toxic',1,1,1250,250)",
        [$categoryId, 'DASH-' . strtoupper($tag), 'Dashboard Fixture ' . $tag],
    );
    $archivedProductId = $insert(
        "INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor,archived_at) VALUES(?,?,?,'Fixture','indirect','weekly','non_toxic',1,1,1250,250,UTC_TIMESTAMP())",
        [$categoryId, 'DASH-ARCH-' . strtoupper($tag), 'Dashboard Archived ' . $tag],
    );

    $insertOrder = static function (string $suffix, ?string $archivedAt = null) use ($insert, $customerId, $tag): int {
        return $archivedAt === null
            ? $insert('INSERT INTO sales_orders(customer_id,checkout_key,state) VALUES(?,?,\'confirmed\')',
                [$customerId, 'dashboard-' . $tag . '-' . $suffix])
            : $insert('INSERT INTO sales_orders(customer_id,checkout_key,state,archived_at) VALUES(?,?,\'confirmed\',UTC_TIMESTAMP())',
                [$customerId, 'dashboard-' . $tag . '-' . $suffix]);
    };
    $orderIds = [];
    for ($index = 1; $index <= 7; ++$index) {
        $orderIds[] = $insertOrder('order-' . $index);
    }
    $archivedOrderId = $insertOrder('archived-order', 'now');
    $anchorOrderId = $insertOrder('payment-anchor');
    $definitionId = $insert(
        "INSERT INTO composition_definitions(definition_code,name,arrangement_kind,strategy) VALUES(?,?,'plant_arrangement','fixed')",
        ['dashboard-' . $tag, 'Dashboard fixture ' . $tag],
    );
    $insert(
        'INSERT INTO composition_checkouts(customer_id,definition_id,definition_code_snapshot,definition_name_snapshot,checkout_key,selection_sha256,order_id,order_role,state,total_minor) VALUES(?,?,?,?,?,?,?,\'payment_anchor\',\'confirmed\',0)',
        [$customerId, $definitionId, 'dashboard-' . $tag, 'Dashboard fixture ' . $tag,
            'dashboard-anchor-' . $tag, str_repeat('a', 64), $anchorOrderId],
    );

    $rentalIds = [];
    for ($index = 1; $index <= 7; ++$index) {
        $rentalIds[] = $insert(
            "INSERT INTO rentals(customer_id,checkout_key,state) VALUES(?,?,'confirmed')",
            [$customerId, 'dashboard-rental-' . $tag . '-' . $index],
        );
    }
    $archivedRentalId = $insert(
        "INSERT INTO rentals(customer_id,checkout_key,state,archived_at) VALUES(?,?,'confirmed',UTC_TIMESTAMP())",
        [$customerId, 'dashboard-archived-rental-' . $tag],
    );

    $today = new DateTimeImmutable('today', new DateTimeZone('Africa/Johannesburg'));
    $startDate = $today->format('Y-m-d');
    $endDate = $today->modify('+5 days')->format('Y-m-d');
    $itemId = $insert(
        'INSERT INTO rental_items(rental_id,product_id,product_name_snapshot,sku_snapshot,quantity,start_date,end_date_exclusive,daily_rate_minor) VALUES(?,?,?,?,?,?,?,250)',
        [$rentalIds[0], $productId, 'Dashboard fixture plant', 'DASH-FIXTURE', 4, $startDate, $endDate],
    );
    $insert("INSERT INTO rental_allocations(rental_item_id,quantity,state) VALUES(?,4,'issued')", [$itemId]);
    $insert('INSERT INTO rental_issues(rental_item_id,quantity,issued_at,actor_id) VALUES(?,4,UTC_TIMESTAMP(),?)', [$itemId, $adminId]);
    $insert('INSERT INTO rental_returns(rental_item_id,quantity,returned_at,request_key,actor_id) VALUES(?,1,UTC_TIMESTAMP(),?,?)',
        [$itemId, 'dashboard-return-' . $tag, $adminId]);
    $laterItemId = $insert(
        'INSERT INTO rental_items(rental_id,product_id,product_name_snapshot,sku_snapshot,quantity,start_date,end_date_exclusive,daily_rate_minor) VALUES(?,?,?,?,?,?,?,250)',
        [$rentalIds[0], $productId, 'Dashboard later due plant', 'DASH-LATER', 1, $startDate,
            $today->modify('+10 days')->format('Y-m-d')],
    );
    $insert("INSERT INTO rental_allocations(rental_item_id,quantity,state) VALUES(?,1,'closed')", [$laterItemId]);
    $insert('INSERT INTO rental_issues(rental_item_id,quantity,issued_at,actor_id) VALUES(?,1,UTC_TIMESTAMP(),?)', [$laterItemId, $adminId]);
    $insert('INSERT INTO rental_returns(rental_item_id,quantity,returned_at,request_key,actor_id) VALUES(?,1,UTC_TIMESTAMP(),?,?)',
        [$laterItemId, 'dashboard-later-return-' . $tag, $adminId]);

    $overview = $service->overview($adminActor);
    $counts = $overview['counts'];
    $check($counts['product_count'] === $initial['counts']['product_count'] + 1,
        'Product count includes the active fixture and excludes its archived product.');
    $check($counts['customer_count'] === $initial['counts']['customer_count'] + 1,
        'Customer count includes only the active customer fixture, not admin or archived accounts.');
    $check($counts['order_count'] === $initial['counts']['order_count'] + 7,
        'Retail order count excludes the archived order and composition payment anchor.');
    $check($counts['rental_count'] === $initial['counts']['rental_count'] + 7,
        'Rental count excludes the archived rental.');
    $check($counts['outstanding_rental_count'] === $initial['counts']['outstanding_rental_count'] + 1,
        'Outstanding rental count recognizes the partially returned issued rental.');

    $recentOrderIds = array_map(static fn (array $row): int => (int) $row['order_id'], $overview['recentOrders']);
    $check(count($recentOrderIds) === 5 && in_array($orderIds[6], $recentOrderIds, true)
        && !in_array($orderIds[0], $recentOrderIds, true), 'Recent order list is capped at five newest eligible rows.');
    $check(!in_array($anchorOrderId, $recentOrderIds, true) && !in_array($archivedOrderId, $recentOrderIds, true),
        'Recent orders omit composition payment anchors and archived rows.');

    $recentRentalIds = array_map(static fn (array $row): int => (int) $row['rental_id'], $overview['recentRentals']);
    $check(count($recentRentalIds) === 5 && in_array($rentalIds[6], $recentRentalIds, true)
        && !in_array($rentalIds[0], $recentRentalIds, true), 'Recent rental list is capped at five newest rows.');
    $check(!in_array($archivedRentalId, $recentRentalIds, true), 'Recent rentals omit archived rows.');

    $attention = $overview['outstandingReturns'];
    $attentionRow = null;
    foreach ($attention as $row) {
        if ((int) $row['rental_id'] === $rentalIds[0]) {
            $attentionRow = $row;
            break;
        }
    }
    $check($attentionRow !== null && (int) $attentionRow['outstanding_quantity'] === 3,
        'Outstanding return summary reports issued quantity minus persisted partial returns.');
    $check($attentionRow !== null && $attentionRow['end_date_exclusive'] === $endDate,
        'Outstanding return summary uses the due date of an item that still has quantity outstanding.');

    echo "Admin dashboard checks: $checks passed, 0 failed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Admin dashboard checks failed after ' . $checks . ' checks: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    $db->rollback();
    $db->close();
}
