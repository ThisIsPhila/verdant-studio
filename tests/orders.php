<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Inventory\InventoryRepository;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Persistence\TransactionManager;
use Verdant\Retail\OrderRepository;
use Verdant\Retail\OrderService;
use Verdant\Retail\RetailAvailabilityRepository;
use Verdant\Support\ValidationException;

if (PHP_SAPI !== 'cli' || !function_exists('pcntl_fork')
    || preg_match('/^verdant_test_[a-z0-9_]+$/', getenv('DB_NAME') ?: '') !== 1) {
    fwrite(STDERR, "Use a disposable test database and CLI with process support.\n"); exit(2);
}
$admin = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$tag = bin2hex(random_bytes(6));
$categoryStmt = $admin->prepare("INSERT INTO categories(name) VALUES (?)");
$categoryStmt->execute(['Order race ' . $tag]);
$categoryId = (int) $admin->insert_id;
$categoryStmt->close();

$customerIds = [];
foreach ([1, 2] as $number) {
    $statement = $admin->prepare("INSERT INTO customers(email,password_hash,name,account_state) VALUES(?, 'test-hash', 'Order race fixture', 'active')");
    $statement->execute(["order-$tag-$number@example.test"]);
    $customerIds[] = (int) $admin->insert_id;
    $statement->close();
}
$productStmt = $admin->prepare("INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor) VALUES(?,?, 'Order race plant','Synthetic','indirect','weekly','non_toxic',1,1,1450,300)");
$productStmt->execute([$categoryId, 'ORD-' . strtoupper($tag)]);
$productId = (int) $admin->insert_id;
$productStmt->close();
$inventoryStmt = $admin->prepare('INSERT INTO inventory(product_id,on_hand) VALUES(?,1)');
$inventoryStmt->execute([$productId]);
$inventoryStmt->close();
$admin->close();

$keys = [bin2hex(random_bytes(32)), bin2hex(random_bytes(32))];
$paths = [sys_get_temp_dir() . '/verdant-order-' . getmypid() . '-0', sys_get_temp_dir() . '/verdant-order-' . getmypid() . '-1'];
$children = [];
foreach ([0, 1] as $slot) {
    $pid = pcntl_fork();
    if ($pid === -1) { throw new RuntimeException('Unable to launch concurrent checkout fixture.'); }
    if ($pid === 0) {
        try {
            usleep(random_int(0, 100000));
            $connection = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
            $service = new OrderService(new OrderRepository($connection), new InventoryRepository($connection),
                new RetailAvailabilityRepository($connection), new TransactionManager($connection));
            $service->createPending($customerIds[$slot], [$productId => 1], $keys[$slot]);
            file_put_contents($paths[$slot], 'created');
            $connection->close();
            exit(0);
        } catch (ValidationException) {
            file_put_contents($paths[$slot], 'unavailable');
            exit(0);
        } catch (Throwable $error) {
            // Keep concurrency diagnostics safe: record the exception class/code, never its message.
            file_put_contents($paths[$slot], 'error:' . $error::class . ':' . (string) $error->getCode());
            exit(1);
        }
    }
    $children[$slot] = $pid;
}
foreach ($children as $pid) { pcntl_waitpid($pid, $status); }
$outcomes = array_map(static fn (string $path): string => is_file($path) ? (string) file_get_contents($path) : 'missing', $paths);
foreach ($paths as $path) { @unlink($path); }

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($label); }
    ++$checks;
    echo "PASS: $label\n";
};
try {
    $check(count(array_filter($outcomes, static fn (string $value): bool => $value === 'created')) === 1
        && count(array_filter($outcomes, static fn (string $value): bool => $value === 'unavailable')) === 1,
        'Concurrent customers cannot reserve the same final physical unit (outcomes: ' . implode(',', $outcomes) . ').');
    $connection = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
    $repository = new OrderRepository($connection);
    $availability = new RetailAvailabilityRepository($connection);
    $winner = array_search('created', $outcomes, true);
    $winnerOrder = $repository->forCheckout($customerIds[$winner], $keys[$winner]);
    $check($winnerOrder !== null && $winnerOrder['state'] === 'pending_payment' && (int) $winnerOrder['total_minor'] === 1450,
        'Winning checkout stores one pending order with server-calculated amount.');
    $check((int) $availability->find($productId)['available_quantity'] === 0,
        'Committed order hold makes the final unit unavailable without decrementing on-hand.');
    $holdCount = $connection->query("SELECT COUNT(*) AS count FROM retail_holds h JOIN order_items i ON i.order_item_id=h.order_item_id WHERE i.order_id=" . (int) $winnerOrder['order_id'])->fetch_assoc();
    $check((int) $holdCount['count'] === 1, 'One explicit active hold is recorded for the order line.');

    $service = new OrderService($repository, new InventoryRepository($connection), $availability, new TransactionManager($connection));
    $retry = $service->createPending($customerIds[$winner], [$productId => 1], $keys[$winner]);
    $check((int) $retry['order_id'] === (int) $winnerOrder['order_id'] && $retry['existing'] === true,
        'Retry with the same server checkout key returns the existing order.');
    $orderCount = $connection->query("SELECT COUNT(*) AS count FROM sales_orders WHERE customer_id=" . (int) $customerIds[$winner] . " AND checkout_key='" . $connection->real_escape_string($keys[$winner]) . "'")->fetch_assoc();
    $check((int) $orderCount['count'] === 1, 'Idempotent retry does not duplicate the order.');

    $connection->query('UPDATE products SET retail_price_minor=1999 WHERE product_id=' . $productId);
    $owned = $repository->owned($customerIds[$winner], (int) $winnerOrder['order_id']);
    $check((int) $owned['items'][0]['unit_price_minor'] === 1450,
        'Later catalogue price changes do not alter the immutable order snapshot.');

    try {
        $service->createPending($customerIds[1 - $winner], [$productId => 1], bin2hex(random_bytes(32)));
        throw new RuntimeException('A second customer reserved sold-out stock.');
    } catch (ValidationException) {
        $check(true, 'A later checkout is rejected while the prior payment hold remains active.');
    }
    foreach ([0, -1, 'one', 100] as $badQuantity) {
        try {
            $service->createPending($customerIds[$winner], [$productId => $badQuantity], bin2hex(random_bytes(32)));
            throw new RuntimeException('Invalid quantity accepted.');
        } catch (ValidationException) { ++$checks; }
    }
    try {
        $service->createPending($customerIds[$winner], [$productId => 1], 'bad-key');
        throw new RuntimeException('Invalid checkout key accepted.');
    } catch (ValidationException) { ++$checks; }
    echo "Order lifecycle checks: $checks passed, 0 failed.\n";
    $connection->close();
} catch (Throwable $error) {
    fwrite(STDERR, 'Order lifecycle checks failed: ' . $error->getMessage() . "\n");
    exit(1);
}
