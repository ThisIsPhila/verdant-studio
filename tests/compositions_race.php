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

if (PHP_SAPI !== 'cli' || !function_exists('pcntl_fork')
    || preg_match('/^verdant_test_[a-z0-9_]+$/', getenv('DB_NAME') ?: '') !== 1) {
    fwrite(STDERR, "Use an isolated verdant_test_ database and CLI process support.\n");
    exit(2);
}

$config = DatabaseConfig::fromEnvironment();
$setup = ConnectionFactory::connect($config);
$tag = strtoupper(bin2hex(random_bytes(5)));
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
    echo "PASS: $message\n";
};
$insert = static function (mysqli $connection, string $sql, array $parameters = []): int {
    $statement = $connection->prepare($sql);
    $statement->execute($parameters);
    $statement->close();
    return (int) $connection->insert_id;
};

$categoryId = $insert($setup, 'INSERT INTO categories(name) VALUES(?)', ['Composition race ' . $tag]);
$productId = $insert($setup,
    "INSERT INTO products(category_id,sku,name,description,product_kind,accessory_subtype,selling_unit,
         light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor)
     VALUES(?,?,?,'Synthetic race product','plant','other','Each','unknown','unknown','unknown',1,1,1550,300)",
    [$categoryId, 'CMPR-' . $tag, 'Synthetic shared last plant'],
);
$insert($setup, 'INSERT INTO inventory(product_id,on_hand) VALUES(?,1)', [$productId]);
$customers = [];
foreach ([1, 2] as $number) {
    $customers[] = $insert($setup,
        "INSERT INTO customers(email,password_hash,name,role,account_state) VALUES(?, 'test-hash', 'Composition race customer', 'customer', 'active')",
        ['composition-race-' . strtolower($tag) . '-' . $number . '@example.test'],
    );
}
$definitionCodes = ['race-' . strtolower($tag) . '-a', 'race-' . strtolower($tag) . '-b'];
foreach ($definitionCodes as $code) {
    $definitionId = $insert($setup,
        "INSERT INTO composition_definitions(definition_code,name,arrangement_kind,strategy)
         VALUES(?, 'Synthetic race plant', 'plant_arrangement', 'custom')",
        [$code],
    );
    $componentId = $insert($setup,
        "INSERT INTO composition_definition_components
            (definition_id,component_key,label,component_role,source_type,required,min_selections,max_selections,
             min_quantity,max_quantity,permitted_mode,sort_order)
         VALUES(?, 'plant', 'Plant', 'plant', 'product', 1,1,1,1,1,'sale',10)",
        [$definitionId],
    );
    $insert($setup, 'INSERT INTO composition_component_product_allowlist(component_id,product_id) VALUES(?,?)', [$componentId, $productId]);
}
$setup->close();

$ready = [sys_get_temp_dir() . '/verdant-comp-race-' . getmypid() . '-0.ready', sys_get_temp_dir() . '/verdant-comp-race-' . getmypid() . '-1.ready'];
$results = [sys_get_temp_dir() . '/verdant-comp-race-' . getmypid() . '-0.result', sys_get_temp_dir() . '/verdant-comp-race-' . getmypid() . '-1.result'];
$gate = sys_get_temp_dir() . '/verdant-comp-race-' . getmypid() . '.go';
$inventoryLockReady = sys_get_temp_dir() . '/verdant-comp-race-' . getmypid() . '.lock-ready';
$releaseInventoryLock = sys_get_temp_dir() . '/verdant-comp-race-' . getmypid() . '.release-lock';
$children = [];
$lockerPid = null;
try {
    // A separate lock-holder process ensures the two request workers do not inherit its DB socket across fork.
    $lockerPid = pcntl_fork();
    if ($lockerPid === -1) {
        throw new RuntimeException('Unable to start the composition inventory lock fixture.');
    }
    if ($lockerPid === 0) {
        $connection = null;
        try {
            $connection = ConnectionFactory::connect($config);
            $connection->begin_transaction();
            $statement = $connection->prepare('SELECT product_id FROM inventory WHERE product_id=? FOR UPDATE');
            $statement->execute([$productId]);
            $statement->get_result()->fetch_assoc();
            $statement->close();
            touch($inventoryLockReady);
            $deadline = microtime(true) + 20.0;
            while (!is_file($releaseInventoryLock) && microtime(true) < $deadline) {
                usleep(10000);
            }
            if (!is_file($releaseInventoryLock)) {
                throw new RuntimeException('Composition inventory lock release timed out.');
            }
            $connection->commit();
            $connection->close();
            exit(0);
        } catch (Throwable) {
            if ($connection instanceof mysqli) {
                try { $connection->rollback(); } catch (Throwable) {}
                $connection->close();
            }
            exit(1);
        }
    }
    $deadline = microtime(true) + 15.0;
    while (!is_file($inventoryLockReady) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (!is_file($inventoryLockReady)) {
        throw new RuntimeException('The composition inventory lock was not acquired.');
    }

    foreach ([0, 1] as $slot) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to start concurrent composition fixture.');
        }
        if ($pid === 0) {
            try {
                file_put_contents($ready[$slot], 'ready', LOCK_EX);
                $deadline = microtime(true) + 15.0;
                while (!is_file($gate) && microtime(true) < $deadline) {
                    usleep(10000);
                }
                if (!is_file($gate)) {
                    throw new RuntimeException('Composition race launch barrier timed out.');
                }
                $connection = ConnectionFactory::connect($config);
                $service = new CompositionService(
                    new CompositionRepository($connection), new InventoryRepository($connection),
                    new CapacityRepository($connection), new TransactionManager($connection),
                );
                $key = 'race-' . strtolower($tag) . '-checkout-' . $slot;
                $result = $service->createPending($customers[$slot], $definitionCodes[$slot], [[
                    'component_key' => 'plant', 'product_id' => $productId, 'quantity' => 1, 'mode' => 'sale',
                ]], $key);
                file_put_contents($results[$slot], json_encode(['state' => 'created', 'id' => $result['composition_checkout_id']], JSON_THROW_ON_ERROR), LOCK_EX);
                $connection->close();
                exit(0);
            } catch (DomainException) {
                file_put_contents($results[$slot], '{"state":"unavailable"}', LOCK_EX);
                exit(0);
            } catch (Throwable $error) {
                file_put_contents($results[$slot], json_encode(['state' => 'error', 'type' => get_class($error), 'code' => $error->getCode()], JSON_THROW_ON_ERROR), LOCK_EX);
                exit(1);
            }
        }
        $children[$slot] = $pid;
    }

    $deadline = microtime(true) + 15.0;
    while ((!is_file($ready[0]) || !is_file($ready[1])) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (!is_file($ready[0]) || !is_file($ready[1])) {
        throw new RuntimeException('Concurrent composition workers did not reach the launch barrier.');
    }
    touch($gate);
    // Keep the inventory row locked while both workers enter createPending and queue for that SKU.
    usleep(800000);
    touch($releaseInventoryLock);

    $statuses = [];
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        $statuses[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 255;
    }
    $children = [];
    pcntl_waitpid($lockerPid, $lockStatus);
    $lockerPid = null;
    $check(pcntl_wifexited($lockStatus) && pcntl_wexitstatus($lockStatus) === 0,
        'A separate process held and released the inventory row around the competing requests.');
    if (!is_file($results[0]) || !is_file($results[1])) {
        throw new RuntimeException('A concurrent composition worker did not report its outcome.');
    }
    $outcomes = array_map(static fn (string $path): array => json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR), $results);
    $created = count(array_filter($outcomes, static fn (array $row): bool => $row['state'] === 'created'));
    $unavailable = count(array_filter($outcomes, static fn (array $row): bool => $row['state'] === 'unavailable'));
    $check($statuses === [0, 0] && $created === 1 && $unavailable === 1,
        'Two independent customers waiting on the same locked final SKU produce one reservation and one capacity rejection.');

    $verify = ConnectionFactory::connect($config);
    $statement = $verify->prepare(
        "SELECT COUNT(*) AS checkouts,
                (SELECT COALESCE(SUM(h.quantity),0) FROM retail_holds h
                 JOIN order_items oi ON oi.order_item_id=h.order_item_id
                 JOIN composition_checkouts cc ON cc.order_id=oi.order_id
                 WHERE oi.product_id=? AND h.state='active') AS held
         FROM composition_checkouts cc
         JOIN composition_checkout_lines cl ON cl.composition_checkout_id=cc.composition_checkout_id
         WHERE cl.product_id=? AND cl.mode='sale'",
    );
    $statement->execute([$productId, $productId]);
    $totals = $statement->get_result()->fetch_assoc();
    $statement->close();
    $stockStatement = $verify->prepare('SELECT on_hand FROM inventory WHERE product_id=?');
    $stockStatement->execute([$productId]);
    $stock = $stockStatement->get_result()->fetch_assoc();
    $stockStatement->close();
    $check((int) $totals['checkouts'] === 1 && (int) $totals['held'] === 1 && (int) $stock['on_hand'] === 1,
        'The winning checkout keeps one active sale hold and never decrements on-hand before payment.');
    $verify->close();
    echo "Composition concurrency checks: $checks passed, 0 failed.\n";
} finally {
    if ($lockerPid !== null) {
        touch($releaseInventoryLock);
        pcntl_waitpid($lockerPid, $lockStatus);
    }
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }
    foreach (array_merge($ready, $results, [$gate, $inventoryLockReady, $releaseInventoryLock]) as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
