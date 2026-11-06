<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Admin\AdminAccess;
use Verdant\Admin\OperationsRepository;
use Verdant\Admin\OperationsService;
use Verdant\Inventory\InventoryRepository;
use Verdant\Notifications\CommerceNotifier;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Persistence\TransactionManager;
use Verdant\Support\ValidationException;

if (PHP_SAPI !== 'cli' || preg_match('/^verdant_test_[a-z0-9_]+$/', getenv('DB_NAME') ?: '') !== 1) {
    fwrite(STDERR, "Use a disposable verdant_test_ database.\n"); exit(2);
}

$connection = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$tag = bin2hex(random_bytes(6));
$insertCustomer = static function (string $email, string $role = 'customer') use ($connection, $tag): int {
    $statement = $connection->prepare('INSERT INTO customers(email,password_hash,name,phone,role,account_state) VALUES(?,\'unused fixture hash\',\'Operations fixture\',\'555-0100\',?,\'active\')');
    $statement->execute([$email . '-' . $tag . '@example.test', $role]);
    $id = (int) $connection->insert_id;
    $statement->close();
    if ($role === 'admin') {
        $allowlist = $connection->prepare('INSERT INTO admin_allowlist (email) VALUES (?)');
        $allowlist->execute([$email . '-' . $tag . '@example.test']);
        $allowlist->close();
    }
    return $id;
};
$adminId = $insertCustomer('operations-admin', 'admin');
$customerId = $insertCustomer('operations-customer');
$otherCustomerId = $insertCustomer('operations-other');
$category = $connection->prepare('INSERT INTO categories(name) VALUES(?)');
$category->execute(['Operations fixture ' . $tag]);
$categoryId = (int) $connection->insert_id;
$category->close();
$product = $connection->prepare("INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor) VALUES(?,?, 'Operations plant','Synthetic fixture','indirect','weekly','non_toxic',1,0,1250,NULL)");
$product->execute([$categoryId, 'OPS-' . strtoupper($tag)]);
$productId = (int) $connection->insert_id;
$product->close();
$stock = $connection->prepare('INSERT INTO inventory(product_id,on_hand) VALUES(?,20)');
$stock->execute([$productId]);
$stock->close();

$insertOrder = static function (int $owner, string $state, string $suffix, bool $payment = false, string $paymentState = 'succeeded') use ($connection, $productId): int {
    $statement = $connection->prepare('INSERT INTO sales_orders(customer_id,checkout_key,state) VALUES(?,?,?)');
    $statement->execute([$owner, $suffix . bin2hex(random_bytes(12)), $state]);
    $id = (int) $connection->insert_id;
    $statement->close();
    $line = $connection->prepare('INSERT INTO order_items(order_id,product_id,product_name_snapshot,sku_snapshot,quantity,unit_price_minor) VALUES(?,? ,\'Snapshot plant\',\'SNAP\',2,1250)');
    $line->execute([$id, $productId]);
    $itemId = (int) $connection->insert_id;
    $line->close();
    $hold = $connection->prepare("INSERT INTO retail_holds(order_item_id,quantity,state) VALUES(?,2,'active')");
    $hold->execute([$itemId]);
    $hold->close();
    if ($payment) {
        $pay = $connection->prepare('INSERT INTO order_payments(order_id,provider_code,provider_reference,amount_minor,currency,state) VALUES(?,?,NULL,2500,\'ZAR\',?)');
        $pay->execute([$id, 'demo', $paymentState]);
        $pay->close();
    }
    return $id;
};
$paidOrder = $insertOrder($customerId, 'confirmed', 'paid-', true);
$unpaidOrder = $insertOrder($customerId, 'pending_payment', 'unpaid-');
$attemptedOrder = $insertOrder($customerId, 'pending_payment', 'attempted-', true, 'failed');
$unmatchedOrder = $insertOrder($customerId, 'confirmed', 'mismatch-', true, 'failed');
$activePaidOrder = $insertOrder($customerId, 'confirmed', 'active-paid-', true, 'succeeded');
$connection->query("UPDATE retail_holds h JOIN order_items i ON i.order_item_id=h.order_item_id SET h.state='consumed' WHERE i.order_id=" . $paidOrder);
$connection->query('UPDATE inventory SET on_hand=on_hand-2 WHERE product_id=' . $productId);
$adminActor = ['customer_id' => $adminId, 'role' => 'admin'];
$customerActor = ['customer_id' => $customerId, 'role' => 'customer'];
$service = new OperationsService(new OperationsRepository($connection), new InventoryRepository($connection), new TransactionManager($connection), new AdminAccess($connection), new CommerceNotifier($connection));
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($label); }
    ++$checks;
    echo "PASS: $label\n";
};
$scalar = static function (string $sql, array $params = []) use ($connection): mixed {
    $statement = $connection->prepare($sql);
    $statement->execute($params);
    $value = $statement->get_result()->fetch_row()[0] ?? null;
    $statement->close();
    return $value;
};

try {
    $page = $service->customers($adminActor);
    $check(count($page['items']) <= 25 && $page['page'] === 1 && array_key_exists('has_next', $page), 'Customer lists use the bounded pagination contract.');
    $service->setCustomerArchived($adminActor, $customerId, true);
    $check($scalar('SELECT account_state FROM customers WHERE customer_id=?', [$customerId]) === 'archived'
        && $scalar('SELECT archived_at IS NOT NULL FROM customers WHERE customer_id=?', [$customerId]) == 1,
        'Customer archival updates account state and timestamp together.');
    $service->setCustomerArchived($adminActor, $customerId, false);
    $check($scalar('SELECT account_state FROM customers WHERE customer_id=?', [$customerId]) === 'active'
        && $scalar('SELECT archived_at IS NULL FROM customers WHERE customer_id=?', [$customerId]) == 1,
        'Customer restoration clears archive state and timestamp together.');
    try {
        $service->setCustomerArchived($adminActor, $adminId, true);
        throw new RuntimeException('Administrator account was archived.');
    } catch (ValidationException) {
        $check($scalar('SELECT account_state FROM customers WHERE customer_id=?', [$adminId]) === 'active', 'Administrator archival is rejected.');
    }
    $customerDenied = false;
    try {
        $service->orders($customerActor);
    } catch (Throwable) {
        $customerDenied = true;
    }
    $check($customerDenied, 'Customer role cannot read the admin order list.');
    $forgedActorDenied = false;
    try {
        $service->orders(['customer_id' => $otherCustomerId, 'role' => 'admin']);
    } catch (Throwable) {
        $forgedActorDenied = true;
    }
    $check($forgedActorDenied, 'A forged admin role in the session actor cannot authorize a customer ID.');

    $initialStock = (int) $scalar('SELECT on_hand FROM inventory WHERE product_id=?', [$productId]);
    $service->fulfilOrder($adminActor, $paidOrder);
    $check((int) $scalar('SELECT on_hand FROM inventory WHERE product_id=?', [$productId]) === $initialStock
        && $scalar('SELECT fulfilled_at IS NOT NULL FROM sales_orders WHERE order_id=?', [$paidOrder]) == 1
        && $scalar("SELECT state FROM retail_holds h JOIN order_items i ON i.order_item_id=h.order_item_id WHERE i.order_id=?", [$paidOrder]) === 'consumed',
        'A matching succeeded payment permits fulfilment without changing stock already consumed at payment.');
    $fulfilledEventId = $scalar("SELECT commerce_event_id FROM commerce_notification_events WHERE event_key=? AND event_kind='order_fulfilled' AND order_id=?", ['order-fulfilled:' . $paidOrder, $paidOrder]);
    $check($fulfilledEventId !== null
        && (int) $scalar('SELECT COUNT(*) FROM email_outbox WHERE commerce_event_id=?', [$fulfilledEventId]) >= 2,
        'First fulfilment records one order event and snapshots customer plus active-admin recipients in the same transaction.');
    $service->fulfilOrder($adminActor, $paidOrder);
    $check((int) $scalar('SELECT on_hand FROM inventory WHERE product_id=?', [$productId]) === $initialStock
        && (int) $scalar("SELECT COUNT(*) FROM commerce_notification_events WHERE event_key=?", ['order-fulfilled:' . $paidOrder]) === 1,
        'Repeated fulfilment preserves stock and does not duplicate its event.');
    try {
        $service->fulfilOrder($adminActor, $activePaidOrder);
        throw new RuntimeException('Fulfilment accepted an active hold after payment.');
    } catch (ValidationException) {
        $check($scalar('SELECT fulfilled_at IS NULL FROM sales_orders WHERE order_id=?', [$activePaidOrder]) == 1
            && $scalar("SELECT state FROM retail_holds h JOIN order_items i ON i.order_item_id=h.order_item_id WHERE i.order_id=?", [$activePaidOrder]) === 'active'
            && (int) $scalar('SELECT on_hand FROM inventory WHERE product_id=?', [$productId]) === $initialStock,
            'A confirmed order with an unconsumed hold is rejected for review.');
    }
    try {
        $service->fulfilOrder($adminActor, $unmatchedOrder);
        throw new RuntimeException('Order without a successful matching payment was fulfilled.');
    } catch (ValidationException) {
        $check($scalar('SELECT fulfilled_at IS NULL FROM sales_orders WHERE order_id=?', [$unmatchedOrder]) == 1
            && (int) $scalar("SELECT COUNT(*) FROM commerce_notification_events WHERE event_key=?", ['order-fulfilled:' . $unmatchedOrder]) === 0,
            'Unpaid order fulfilment is rejected without a notification.');
    }

    $beforeCancel = (int) $scalar('SELECT on_hand FROM inventory WHERE product_id=?', [$productId]);
    $service->cancelOrder($adminActor, $unpaidOrder);
    $check($scalar('SELECT state FROM sales_orders WHERE order_id=?', [$unpaidOrder]) === 'cancelled'
        && $scalar("SELECT state FROM retail_holds h JOIN order_items i ON i.order_item_id=h.order_item_id WHERE i.order_id=?", [$unpaidOrder]) === 'released'
        && (int) $scalar('SELECT on_hand FROM inventory WHERE product_id=?', [$productId]) === $beforeCancel
        && (int) $scalar("SELECT COUNT(*) FROM commerce_notification_events WHERE event_key=? AND event_kind='order_cancelled' AND order_id=?", ['order-cancelled:' . $unpaidOrder, $unpaidOrder]) === 1,
        'Eligible cancellation releases its hold, preserves physical stock, and records one order event.');
    $service->cancelOrder($adminActor, $unpaidOrder);
    $check((int) $scalar('SELECT on_hand FROM inventory WHERE product_id=?', [$productId]) === $beforeCancel
        && (int) $scalar("SELECT COUNT(*) FROM commerce_notification_events WHERE event_key=?", ['order-cancelled:' . $unpaidOrder]) === 1,
        'Repeated cancellation is harmless and does not duplicate its event.');
    try {
        $service->cancelOrder($adminActor, $attemptedOrder);
        throw new RuntimeException('Order with a payment attempt was cancelled.');
    } catch (ValidationException) {
        $check($scalar('SELECT state FROM sales_orders WHERE order_id=?', [$attemptedOrder]) === 'pending_payment'
            && (int) $scalar("SELECT COUNT(*) FROM commerce_notification_events WHERE event_key=?", ['order-cancelled:' . $attemptedOrder]) === 0,
            'Any provider attempt blocks cancellation without a notification.');
    }
    try {
        $service->cancelOrder($adminActor, $paidOrder);
        throw new RuntimeException('Confirmed paid order was cancelled.');
    } catch (ValidationException) {
        $check($scalar('SELECT state FROM sales_orders WHERE order_id=?', [$paidOrder]) === 'confirmed', 'Paid order cancellation is rejected.');
    }
    $beforeArchive = (string) $scalar('SELECT state FROM retail_holds h JOIN order_items i ON i.order_item_id=h.order_item_id WHERE i.order_id=?', [$attemptedOrder]);
    $stockBeforeArchive = (int) $scalar('SELECT on_hand FROM inventory WHERE product_id=?', [$productId]);
    $service->setOrderArchived($adminActor, $attemptedOrder, true);
    $check($scalar('SELECT archived_at IS NOT NULL FROM sales_orders WHERE order_id=?', [$attemptedOrder]) == 1
        && $scalar('SELECT state FROM retail_holds h JOIN order_items i ON i.order_item_id=h.order_item_id WHERE i.order_id=?', [$attemptedOrder]) === $beforeArchive
        && (int) $scalar('SELECT on_hand FROM inventory WHERE product_id=?', [$productId]) === $stockBeforeArchive,
        'Order archival retains payment history, stock hold, and physical inventory.');
    $service->setOrderArchived($adminActor, $attemptedOrder, false);
    $check($scalar('SELECT archived_at IS NULL FROM sales_orders WHERE order_id=?', [$attemptedOrder]) == 1, 'Archived order history can be restored.');

    $connection->query("UPDATE customers SET account_state='archived', archived_at=UTC_TIMESTAMP() WHERE customer_id=" . $adminId);
    $staleActorDenied = false;
    try {
        $service->orders($adminActor);
    } catch (Throwable) {
        $staleActorDenied = true;
    }
    $check($staleActorDenied, 'An admin session is denied after its database account is archived.');

    echo "Admin operations checks: $checks passed, 0 failed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Admin operations checks failed: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    $connection->close();
}
