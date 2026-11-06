<?php

declare(strict_types=1);

use Verdant\Catalogue\ProductRepository;
use Verdant\Identity\CustomerRepository;
use Verdant\Inventory\InventoryRepository;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Persistence\DatabaseException;
use Verdant\Persistence\TransactionManager;

require dirname(__DIR__) . '/app/bootstrap.php';

$checks = 0;
$failures = [];

$assert = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    ++$checks;
    if (!$condition) {
        $failures[] = $message;
    }
};

$expectFailure = static function (callable $operation, string $message, int $expectedCode) use ($assert): void {
    try {
        $operation();
        $assert(false, $message);
    } catch (mysqli_sql_exception|DatabaseException $error) {
        $assert($error->getCode() === $expectedCode, $message);
    }
};

$execute = static function (mysqli $connection, string $sql, array $parameters = []): mysqli_stmt {
    $statement = $connection->prepare($sql);
    $statement->execute($parameters);
    return $statement;
};

$insert = static function (mysqli $connection, string $sql, array $parameters = []) use ($execute): int {
    $statement = $execute($connection, $sql, $parameters);
    $statement->close();
    return (int) $connection->insert_id;
};

$one = static function (mysqli $connection, string $sql, array $parameters = []) use ($execute): ?array {
    $statement = $execute($connection, $sql, $parameters);
    try {
        $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        return $rows[0] ?? null;
    } finally {
        $statement->close();
    }
};

$restoreEnvironment = static function (array $values): void {
    foreach ($values as $key => $value) {
        if ($value === false) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
};

$requireSafeDatabaseName = static function () use ($assert): void {
    $name = getenv('DB_NAME');
    if ($name === false || preg_match('/^verdant_test_[a-z0-9_]+$/', $name) !== 1) {
        fwrite(STDERR, "Refusing to connect: DB_NAME must match ^verdant_test_[a-z0-9_]+$.\n");
        exit(2);
    }
};

$requireSafeDatabaseName();

try {
    $config = DatabaseConfig::fromEnvironment();
    $connection = ConnectionFactory::connect($config);
} catch (Throwable) {
    fwrite(STDERR, "FAIL: database connection/configuration unavailable; details suppressed.\n");
    exit(2);
}

try {
    $versionRow = $one($connection, 'SELECT VERSION() AS version');
    $version = (string) ($versionRow['version'] ?? '');
    $assert(!str_contains(strtolower($version), 'mariadb') && version_compare($version, '8.0.16', '>='), 'The configured MySQL server is version 8 or newer.');
    $engineRow = $one($connection,
        "SELECT COUNT(*) AS table_count, SUM(engine = 'InnoDB') AS innodb_count
         FROM information_schema.tables WHERE table_schema = DATABASE()
           AND table_name IN ('customers','categories','products','inventory','sales_orders','order_items',
             'retail_holds','rentals','rental_items','rental_allocations','rental_issues','rental_returns',
             'order_payments','rental_payments','provider_events')",
    );
    $assert((int) ($engineRow['table_count'] ?? 0) === 15, 'All 15 business tables exist in the selected database.');
    $assert((int) ($engineRow['innodb_count'] ?? 0) === 15, 'All business tables use transactional InnoDB storage.');

    // A failed connection/configuration must never copy credentials into errors.
    $environmentKeys = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_SOCKET'];
    $originalEnvironment = [];
    foreach ($environmentKeys as $key) {
        $originalEnvironment[$key] = getenv($key);
    }
    $canaryUser = 'verdant_canary_user_71';
    $canaryPassword = 'verdant_canary_password_92';
    putenv('DB_HOST=127.0.0.1');
    putenv('DB_PORT=1');
    putenv('DB_NAME=verdant_test_connection_probe');
    putenv('DB_USER=' . $canaryUser);
    putenv('DB_PASSWORD=' . $canaryPassword);
    putenv('DB_SOCKET');
    $configuration = DatabaseConfig::fromEnvironment();
    try {
        ConnectionFactory::connect($configuration);
        $assert(false, 'A refused connection is reported as an error.');
    } catch (DatabaseException $error) {
        $assert(
            !str_contains($error->getMessage(), $canaryUser) && !str_contains($error->getMessage(), $canaryPassword),
            'Connection errors do not expose canary credentials.',
        );
    } catch (Throwable) {
        $assert(false, 'Connection failure is reported through the safe database exception.');
    }
    putenv('DB_PORT=not-a-port');
    try {
        DatabaseConfig::fromEnvironment();
        $assert(false, 'Malformed connection configuration is rejected.');
    } catch (DatabaseException $error) {
        $assert(
            !str_contains($error->getMessage(), $canaryUser) && !str_contains($error->getMessage(), $canaryPassword),
            'Configuration errors do not expose canary credentials.',
        );
    }
    $restoreEnvironment($originalEnvironment);

    $expectFailure(static fn () => $connection->query('DELETE FROM inventory WHERE product_id = 0'),
        'Application credentials cannot delete inventory history.', 1142);
    $expectFailure(static fn () => $connection->query('CREATE TABLE inventory (unexpected_column INT)'),
        'Application credentials cannot change schema.', 1142);

    $manager = new TransactionManager($connection);
    $sentinel = new RuntimeException('test rollback sentinel');
    $suffix = bin2hex(random_bytes(6));
    try {
        $manager->run(static function () use (
            $connection, $assert, $expectFailure, $insert, $one, $execute, $manager, $sentinel, $suffix,
        ): void {
            $email = 'db-test-' . $suffix . '@example.test';
            $categoryName = 'Integration ' . $suffix . ' · 植物';
            $sku = 'IT-' . strtoupper($suffix);
            $password = 'synthetic-test-password';
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $customerId = (new CustomerRepository($connection))->createCustomer(
                strtoupper($email), $passwordHash, 'Integration Customer',
            );
            $categoryId = $insert($connection, 'INSERT INTO categories (name) VALUES (?)', [$categoryName]);

            $productId = $insert($connection,
                "INSERT INTO products (category_id, sku, name, description, light_level, watering_level,
                    toxicity, buy_enabled, rent_enabled, retail_price_minor, rental_daily_rate_minor)
                 VALUES (?, ?, ?, ?, 'indirect', 'weekly', 'non-toxic', 1, 1, 1250, 300)",
                [$categoryId, $sku, 'Monstera · 植物', 'UTF-8 fixture 🌿'],
            );
            $insert($connection, 'INSERT INTO inventory (product_id, on_hand) VALUES (?, 5)', [$productId]);

            $assert(password_verify($password, $passwordHash), 'Synthetic customer password hash verifies.');
            $assert(
                $one($connection, 'SELECT name FROM products WHERE product_id = ?', [$productId])['name'] === 'Monstera · 植物',
                'The schema stores and returns UTF-8 product data.',
            );
            $assert(
                (new ProductRepository($connection))->findActive($productId) !== null,
                'Product repository reads the active fixture.',
            );
            $assert(
                (new CustomerRepository($connection))->findForAuthentication($email) !== null,
                'Customer repository reads its normalized active authentication fixture.',
            );
            $assert(
                (new ProductRepository($connection))->findActive(0) === null,
                'Prepared product lookup rejects a nonexistent product identifier.',
            );
            $assert(
                (new CustomerRepository($connection))->findForAuthentication("' OR 1=1 --") === null,
                'Prepared authentication lookup does not match SQL-injection input.',
            );

            $expectFailure(static fn () => $insert($connection,
                "INSERT INTO customers (email, password_hash, name, role, account_state)
                 VALUES (?, ?, ?, 'customer', 'active')",
                [$email, $passwordHash, 'Duplicate Email'],
            ), 'Duplicate customer email is rejected.', 1062);
            $expectFailure(static fn () => $insert($connection,
                'INSERT INTO products (category_id, sku, name, description, light_level, watering_level, toxicity, buy_enabled, rent_enabled)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0)',
                [$categoryId, 'BAD-' . $suffix, 'Invalid capability', 'desc', 'indirect', 'weekly', 'unknown'],
            ), 'Products with no enabled capability are rejected.', 3819);
            $expectFailure(static fn () => $insert($connection,
                'INSERT INTO products (category_id, sku, name, description, light_level, watering_level, toxicity, buy_enabled, rent_enabled)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0)',
                [$categoryId, 'BADPRICE-' . $suffix, 'Missing price', 'desc', 'indirect', 'weekly', 'unknown'],
            ), 'An enabled retail capability requires a price.', 3819);
            $expectFailure(static fn () => $insert($connection,
                'INSERT INTO inventory (product_id, on_hand) VALUES (999999999, 1)',
            ), 'Inventory rejects a missing product foreign key.', 1452);
            $expectFailure(static fn () => $insert($connection,
                'INSERT INTO sales_orders (customer_id, checkout_key, state) VALUES (999999999, ?, ?)',
                ['fk-' . $suffix, 'pending_payment'],
            ), 'Sales orders reject a missing customer foreign key.', 1452);

            $orderId = $insert($connection,
                "INSERT INTO sales_orders (customer_id, checkout_key, state) VALUES (?, ?, 'pending_payment')",
                [$customerId, 'checkout-' . $suffix],
            );
            $expectFailure(static fn () => $insert($connection,
                "INSERT INTO sales_orders (customer_id, checkout_key, state) VALUES (?, ?, 'pending_payment')",
                [$customerId, 'checkout-' . $suffix],
            ), 'Duplicate customer checkout key is rejected.', 1062);
            $expectFailure(static fn () => $insert($connection,
                'INSERT INTO order_items (order_id, product_id, product_name_snapshot, sku_snapshot, quantity, unit_price_minor)
                 VALUES (?, ?, ?, ?, 0, 0)',
                [$orderId, $productId, 'Zero quantity', $sku],
            ), 'Order item quantities must be positive.', 3819);
            $orderItemId = $insert($connection,
                'INSERT INTO order_items (order_id, product_id, product_name_snapshot, sku_snapshot, quantity, unit_price_minor)
                 VALUES (?, ?, ?, ?, 2, 1250)',
                [$orderId, $productId, 'Monstera snapshot', $sku],
            );
            $insert($connection,
                "INSERT INTO retail_holds (order_item_id, quantity, state) VALUES (?, 2, 'active')",
                [$orderItemId],
            );
            $orderPaymentId = $insert($connection,
                "INSERT INTO order_payments (order_id, provider_code, provider_reference, amount_minor, state)
                 VALUES (?, 'sandbox', ?, 2500, 'pending')",
                [$orderId, 'pay-' . $suffix],
            );
            $expectFailure(static fn () => $insert($connection,
                "INSERT INTO order_payments (order_id, provider_code, provider_reference, amount_minor, state)
                 VALUES (?, 'sandbox', ?, 2500, 'pending')",
                [$orderId, 'pay-' . $suffix],
            ), 'Duplicate provider payment reference is rejected.', 1062);

            $rentalId = $insert($connection,
                "INSERT INTO rentals (customer_id, checkout_key, state) VALUES (?, ?, 'pending_payment')",
                [$customerId, 'rental-' . $suffix],
            );
            $rentalItemId = $insert($connection,
                'INSERT INTO rental_items (rental_id, product_id, product_name_snapshot, sku_snapshot, quantity,
                    start_date, end_date_exclusive, daily_rate_minor) VALUES (?, ?, ?, ?, 1, ?, ?, 300)',
                [$rentalId, $productId, 'Monstera snapshot', $sku, '2026-10-12', '2026-10-13'],
            );
            $expectFailure(static fn () => $insert($connection,
                'INSERT INTO rental_items (rental_id, product_id, product_name_snapshot, sku_snapshot, quantity,
                    start_date, end_date_exclusive, daily_rate_minor) VALUES (?, ?, ?, ?, 1, ?, ?, 300)',
                [$rentalId, $productId, 'Bad range', $sku, '2026-10-13', '2026-10-13'],
            ), 'Rental dates require an exclusive end later than the start.', 3819);
            $allocationId = $rentalItemId;
            $insert($connection,
                "INSERT INTO rental_allocations (rental_item_id, quantity, state) VALUES (?, 1, 'confirmed')",
                [$allocationId],
            );
            $expectFailure(static fn () => $execute($connection,
                "UPDATE rental_allocations SET quantity = 0 WHERE rental_item_id = ?",
                [$rentalItemId],
            ), 'Rental allocation quantities must be positive.', 3819);
            $rentalPaymentId = $insert($connection,
                "INSERT INTO rental_payments (rental_id, provider_code, provider_reference, amount_minor, state)
                 VALUES (?, 'sandbox', ?, 300, 'pending')",
                [$rentalId, 'rental-pay-' . $suffix],
            );

            $eventRef = 'event-' . $suffix;
            $insert($connection,
                "INSERT INTO provider_events (provider_code, event_reference, payment_kind, order_payment_id,
                    payload_hash, received_at, processing_state) VALUES ('sandbox', ?, 'order', ?, ?, UTC_TIMESTAMP(), 'received')",
                [$eventRef, $orderPaymentId, hash('sha256', 'synthetic-event')],
            );
            $expectFailure(static fn () => $insert($connection,
                "INSERT INTO provider_events (provider_code, event_reference, payment_kind, order_payment_id,
                    payload_hash, received_at, processing_state) VALUES ('sandbox', ?, 'order', ?, ?, UTC_TIMESTAMP(), 'received')",
                [$eventRef, $orderPaymentId, hash('sha256', 'synthetic-event')],
            ), 'Duplicate provider event identity is rejected.', 1062);
            $expectFailure(static fn () => $insert($connection,
                "INSERT INTO provider_events (provider_code, event_reference, payment_kind, order_payment_id,
                    rental_payment_id, payload_hash, received_at, processing_state)
                 VALUES ('sandbox', ?, 'order', ?, ?, ?, UTC_TIMESTAMP(), 'received')",
                ['both-' . $suffix, $orderPaymentId, $rentalPaymentId, hash('sha256', 'bad-event')],
            ), 'Provider events cannot belong to both order and rental payments.', 3819);
            $expectFailure(static fn () => $insert($connection,
                "INSERT INTO provider_events (provider_code, event_reference, payment_kind, payload_hash,
                    received_at, processing_state) VALUES ('sandbox', ?, 'order', ?, UTC_TIMESTAMP(), 'received')",
                ['unowned-' . $suffix, hash('sha256', 'unowned')],
            ), 'Unmatched provider events cannot be marked received.', 3819);
            $insert($connection,
                "INSERT INTO provider_events (provider_code, event_reference, payload_hash, received_at, processing_state)
                 VALUES ('sandbox', ?, ?, UTC_TIMESTAMP(), 'quarantined')",
                ['quarantined-' . $suffix, hash('sha256', 'quarantined')],
            );

            // Archival hides customer-facing records while FK-linked history remains intact.
            $execute($connection, "UPDATE customers SET account_state = 'archived', archived_at = UTC_TIMESTAMP() WHERE customer_id = ?", [$customerId])->close();
            $execute($connection, 'UPDATE categories SET archived_at = UTC_TIMESTAMP() WHERE category_id = ?', [$categoryId])->close();
            $assert((new ProductRepository($connection))->findActive($productId) === null,
                'An archived category hides an otherwise active product.');
            $execute($connection, 'UPDATE products SET archived_at = UTC_TIMESTAMP() WHERE product_id = ?', [$productId])->close();
            $assert((new ProductRepository($connection))->findActive($productId) === null, 'Archived catalogue items are hidden by the repository.');
            $assert((new CustomerRepository($connection))->findForAuthentication($email) === null, 'Archived accounts cannot authenticate.');
            $assert(
                $one($connection, 'SELECT order_id FROM sales_orders WHERE order_id = ?', [$orderId]) !== null
                && $one($connection, 'SELECT rental_id FROM rentals WHERE rental_id = ?', [$rentalId]) !== null
                && $one($connection, 'SELECT product_id FROM inventory WHERE product_id = ?', [$productId]) !== null,
                'Archiving preserves order, rental, and stock history.',
            );
            // Cross-domain writes roll back as a unit. The nested transaction is intentionally unsupported;
            // this probe uses direct writes inside the single coordinator-owned transaction.
            $atomicEmail = 'atomic-' . $suffix . '@example.test';
            $atomicCustomerId = (new CustomerRepository($connection))->createCustomer(
                $atomicEmail, password_hash('atomic-password', PASSWORD_DEFAULT), 'Atomic Customer',
            );
            $atomicCategoryId = $insert($connection, 'INSERT INTO categories (name) VALUES (?)', ['Atomic ' . $suffix]);
            $atomicSku = 'AT-' . strtoupper($suffix);
            $atomicProductId = $insert($connection,
                "INSERT INTO products (category_id, sku, name, description, light_level, watering_level, toxicity,
                    buy_enabled, rent_enabled, retail_price_minor) VALUES (?, ?, 'Atomic', 'desc', 'indirect',
                    'weekly', 'unknown', 1, 0, 100)",
                [$atomicCategoryId, $atomicSku],
            );
            $insert($connection, 'INSERT INTO inventory (product_id, on_hand) VALUES (?, 3)', [$atomicProductId]);
            $execute($connection, 'UPDATE inventory SET on_hand = on_hand - 1, version = version + 1 WHERE product_id = ?', [$atomicProductId])->close();
            $assert((int) $one($connection, 'SELECT on_hand FROM inventory WHERE product_id = ?', [$atomicProductId])['on_hand'] === 2, 'Inventory mutation is visible inside the transaction.');
            if ($atomicCustomerId < 1) {
                throw new LogicException('Fixture insert did not return a key.');
            }

            // The new uncommitted inventory row is locked by its insert. A second connection must
            // time out waiting for the repository's SELECT FOR UPDATE and cannot make a partial write.
            $secondary = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
            try {
                $secondary->query('SET SESSION innodb_lock_wait_timeout = 1');
                $lockError = null;
                try {
                    (new InventoryRepository($secondary))->lockProducts([$atomicProductId]);
                } catch (DatabaseException $error) {
                    $lockError = $error;
                }
                $assert($lockError !== null && $lockError->getCode() === 1205, 'A second connection cannot acquire the held inventory row lock.');
                $visibleStock = $one($secondary, 'SELECT on_hand FROM inventory WHERE product_id = ?', [$atomicProductId]);
                $assert($visibleStock === null, 'The second connection sees no uncommitted inventory write.');
            } finally {
                $secondary->close();
            }

            // Force the enclosing coordinator transaction to roll back every synthetic row and stock mutation.
            throw $sentinel;
        });
    } catch (RuntimeException $error) {
        if ($error !== $sentinel) {
            throw $error;
        }
    }

    $assert($one($connection, 'SELECT customer_id FROM customers WHERE email = ?', ['db-test-' . $suffix . '@example.test']) === null, 'Rollback removes the customer fixture.');
    $assert($one($connection, 'SELECT product_id FROM products WHERE sku = ?', ['IT-' . strtoupper($suffix)]) === null, 'Rollback removes the catalogue fixture.');
    $assert($one($connection, 'SELECT customer_id FROM customers WHERE email = ?', ['atomic-' . $suffix . '@example.test']) === null, 'Cross-domain rollback removes the customer write.');
    $assert($one($connection, 'SELECT product_id FROM products WHERE sku = ?', ['AT-' . strtoupper($suffix)]) === null, 'Cross-domain rollback removes product and inventory writes.');

    printf("Database integration checks: %d passed, %d failed.\n", $checks - count($failures), count($failures));
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . "\n");
    }
    if ($failures !== []) {
        exit(1);
    }
    fwrite(STDOUT, "PASS: schema, repository, transaction, locking, archive, and credential-hygiene checks.\n");
} catch (Throwable) {
    fwrite(STDERR, "FAIL: database integration check aborted; details suppressed.\n");
    exit(1);
} finally {
    $connection->close();
}
