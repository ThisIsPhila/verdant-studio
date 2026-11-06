<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Inventory\CapacityRepository;
use Verdant\Inventory\InventoryRepository;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Persistence\TransactionManager;
use Verdant\Rentals\RentalRepository;
use Verdant\Rentals\RentalService;
use Verdant\Retail\OrderRepository;
use Verdant\Retail\OrderService;
use Verdant\Retail\RetailAvailabilityRepository;
use Verdant\Support\ValidationException;

if (PHP_SAPI !== 'cli' || !function_exists('pcntl_fork')
    || preg_match('/^verdant_test_[a-z0-9_]+$/D', getenv('DB_NAME') ?: '') !== 1) {
    fwrite(STDERR, "Use CLI with process support and an isolated verdant_test_* database.\n");
    exit(2);
}

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    ++$checks;
    echo "PASS: $label\n";
};
$insert = static function (mysqli $db, string $sql, array $values): int {
    $statement = $db->prepare($sql);
    $statement->execute($values);
    $id = (int) $db->insert_id;
    $statement->close();
    return $id;
};
$waitForFiles = static function (array $paths, float $timeoutSeconds): bool {
    $deadline = microtime(true) + $timeoutSeconds;
    do {
        foreach ($paths as $path) {
            if (!is_file($path)) {
                usleep(1000);
                continue 2;
            }
        }
        return true;
    } while (microtime(true) < $deadline);
    return false;
};

$db = null;
$children = [];
$temporaryPaths = [];
try {
    $tag = bin2hex(random_bytes(8));
    $start = (new DateTimeImmutable('today', new DateTimeZone('Africa/Johannesburg')))
        ->modify('+10 days')->format('Y-m-d');
    $endExclusive = (new DateTimeImmutable($start))->modify('+1 day')->format('Y-m-d');

    $db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
    $categoryId = $insert($db, 'INSERT INTO categories(name) VALUES(?)', ['Integration race ' . $tag]);
    $customers = [];
    foreach ([0, 1] as $slot) {
        $customers[] = $insert($db,
            "INSERT INTO customers(email,password_hash,name,account_state) VALUES(?,'test-hash','Integration race fixture','active')",
            ['integration-race-' . $tag . '-' . $slot . '@example.test']);
    }
    $productId = $insert($db,
        "INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor)
         VALUES(?,?,'Dual use race plant','Synthetic fixture','indirect','weekly','non_toxic',1,1,1450,300)",
        [$categoryId, 'IR-' . strtoupper($tag)]);
    $insert($db, 'INSERT INTO inventory(product_id,on_hand) VALUES(?,1)', [$productId]);
    $db->close();
    $db = null;

    $keys = [bin2hex(random_bytes(32)), bin2hex(random_bytes(32))];
    $gate = sys_get_temp_dir() . '/verdant-integration-race-' . $tag . '-release';
    $ready = [$gate . '-ready-0', $gate . '-ready-1'];
    $results = [$gate . '-result-0', $gate . '-result-1'];
    $temporaryPaths = [$gate, ...$ready, ...$results];

    foreach ([0, 1] as $slot) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Could not start both competing checkout processes.');
        }
        if ($pid === 0) {
            $childDb = null;
            try {
                $childDb = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
                if (file_put_contents($ready[$slot], 'ready', LOCK_EX) === false) {
                    throw new RuntimeException('Could not signal readiness to the race coordinator.');
                }
                $deadline = microtime(true) + 15.0;
                while (!is_file($gate) && microtime(true) < $deadline) {
                    usleep(1000);
                }
                if (!is_file($gate)) {
                    throw new RuntimeException('Timed out waiting for the synchronized race launch.');
                }

                $inventory = new InventoryRepository($childDb);
                $transactions = new TransactionManager($childDb);
                if ($slot === 0) {
                    $service = new OrderService(new OrderRepository($childDb), $inventory,
                        new RetailAvailabilityRepository($childDb), $transactions);
                    $record = $service->createPending($customers[$slot], [$productId => 1], $keys[$slot]);
                    $result = ['state' => 'created', 'kind' => 'retail', 'id' => (int) $record['order_id']];
                } else {
                    $service = new RentalService(new RentalRepository($childDb), $inventory,
                        new CapacityRepository($childDb), $transactions);
                    $record = $service->createPending($customers[$slot], $productId, 1, $start, $start, $keys[$slot]);
                    $result = ['state' => 'created', 'kind' => 'rental', 'id' => (int) $record['rental_id']];
                }
                file_put_contents($results[$slot], json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX);
                $childDb->close();
                exit(0);
            } catch (ValidationException|DomainException $error) {
                file_put_contents($results[$slot], json_encode([
                    'state' => 'unavailable', 'kind' => $slot === 0 ? 'retail' : 'rental',
                    'type' => get_class($error),
                ], JSON_THROW_ON_ERROR), LOCK_EX);
                if ($childDb instanceof mysqli) {
                    $childDb->close();
                }
                exit(0);
            } catch (Throwable $error) {
                file_put_contents($results[$slot], json_encode([
                    'state' => 'error', 'kind' => $slot === 0 ? 'retail' : 'rental',
                    'type' => get_class($error), 'code' => $error->getCode(),
                ], JSON_THROW_ON_ERROR), LOCK_EX);
                if ($childDb instanceof mysqli) {
                    $childDb->close();
                }
                exit(1);
            }
        }
        $children[$slot] = $pid;
    }

    if (!$waitForFiles($ready, 15.0)) {
        throw new RuntimeException('Both child processes did not reach the launch barrier.');
    }
    touch($gate);
    $statuses = [];
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        $statuses[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 255;
    }
    $children = [];
    if (!$waitForFiles($results, 2.0)) {
        throw new RuntimeException('A competing process did not record its outcome.');
    }
    $outcomes = array_map(static fn (string $path): array => json_decode(
        (string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR), $results);
    $check($statuses === [0, 0], 'Both forked service requests completed without an unhandled process error.');
    $created = array_values(array_filter($outcomes, static fn (array $outcome): bool => $outcome['state'] === 'created'));
    $rejected = array_values(array_filter($outcomes, static fn (array $outcome): bool => $outcome['state'] === 'unavailable'));
    $check(count($created) === 1 && count($rejected) === 1,
        'A synchronized retail checkout and rental reservation produce exactly one winner: ' . json_encode($outcomes));

    $db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
    $orderCountRow = $db->prepare('SELECT COUNT(*) AS count FROM sales_orders WHERE customer_id=? AND checkout_key=?');
    $rentalCountRow = $db->prepare('SELECT COUNT(*) AS count FROM rentals WHERE customer_id=? AND checkout_key=?');
    $activeRetailRow = $db->prepare(
        "SELECT COALESCE(SUM(h.quantity),0) AS quantity FROM retail_holds h
         JOIN order_items oi ON oi.order_item_id=h.order_item_id JOIN sales_orders so ON so.order_id=oi.order_id
         WHERE so.customer_id=? AND so.checkout_key=? AND so.state IN ('pending_payment','reconciliation_required')
           AND h.state IN ('active','reconciliation_required')");
    $heldRentalRow = $db->prepare(
        "SELECT COALESCE(SUM(a.quantity),0) AS quantity FROM rental_allocations a
         JOIN rental_items ri ON ri.rental_item_id=a.rental_item_id JOIN rentals r ON r.rental_id=ri.rental_id
         WHERE r.customer_id=? AND r.checkout_key=? AND r.state IN ('pending_payment','confirmed','reconciliation_required')
           AND a.state IN ('held','confirmed','issued','reconciliation_required')");
    $winningSlot = $created[0]['kind'] === 'retail' ? 0 : 1;
    $winnerKey = $keys[$winningSlot];
    $winnerCustomer = $customers[$winningSlot];

    if ($winningSlot === 0) {
        $service = new OrderService(new OrderRepository($db), new InventoryRepository($db),
            new RetailAvailabilityRepository($db), new TransactionManager($db));
        $retry = $service->createPending($winnerCustomer, [$productId => 1], $winnerKey);
        $check((int) $retry['order_id'] === $created[0]['id'] && $retry['existing'] === true,
            'Retrying the winning retail checkout returns its original order.');
        $retryAgain = $service->createPending($winnerCustomer, [$productId => 1], $winnerKey);
        $check((int) $retryAgain['order_id'] === $created[0]['id'] && $retryAgain['existing'] === true,
            'A second retail retry still returns the original order.');
    } else {
        $service = new RentalService(new RentalRepository($db), new InventoryRepository($db),
            new CapacityRepository($db), new TransactionManager($db));
        $retry = $service->createPending($winnerCustomer, $productId, 1, $start, $start, $winnerKey);
        $check((int) $retry['rental_id'] === $created[0]['id'],
            'Retrying the winning rental reservation returns its original rental.');
        $retryAgain = $service->createPending($winnerCustomer, $productId, 1, $start, $start, $winnerKey);
        $check((int) $retryAgain['rental_id'] === $created[0]['id'],
            'A second rental retry still returns the original reservation.');
    }

    $orderCountRow->execute([$winnerCustomer, $winnerKey]);
    $orderCount = (int) $orderCountRow->get_result()->fetch_assoc()['count'];
    $rentalCountRow->execute([$winnerCustomer, $winnerKey]);
    $rentalCount = (int) $rentalCountRow->get_result()->fetch_assoc()['count'];
    $check($orderCount + $rentalCount === 1,
        'The winning checkout key has exactly one persisted workflow record after retry.');
    $orderCountRow->execute([$customers[0], $keys[0]]);
    $retailRecordCount = (int) $orderCountRow->get_result()->fetch_assoc()['count'];
    $rentalCountRow->execute([$customers[1], $keys[1]]);
    $rentalRecordCount = (int) $rentalCountRow->get_result()->fetch_assoc()['count'];
    $check($retailRecordCount + $rentalRecordCount === 1,
        'Exactly one workflow record exists across both competing checkout keys.');

    $activeRetailRow->execute([$customers[0], $keys[0]]);
    $retailClaim = (int) $activeRetailRow->get_result()->fetch_assoc()['quantity'];
    $heldRentalRow->execute([$customers[1], $keys[1]]);
    $rentalClaim = (int) $heldRentalRow->get_result()->fetch_assoc()['quantity'];
    $combined = $retailClaim + $rentalClaim;
    $stockRow = $db->prepare('SELECT on_hand FROM inventory WHERE product_id=?');
    $stockRow->execute([$productId]);
    $onHand = (int) $stockRow->get_result()->fetch_assoc()['on_hand'];
    $check($combined === 1 && $combined <= $onHand,
        "Committed retail and rental allocation totals $combined against on_hand=$onHand (at most one unit may be claimed).");

    $capacity = new CapacityRepository($db);
    $check($capacity->availableForPeriod($productId, $start, $endExclusive) === 0,
        'The final dual-use unit is unavailable to both workflows after the race.');
    $check($rejected[0]['kind'] !== $created[0]['kind'],
        'The service that lost the final-unit race reports its own capacity rejection.');

    foreach ([$orderCountRow, $rentalCountRow, $activeRetailRow, $heldRentalRow, $stockRow] as $statement) {
        $statement->close();
    }
    $db->close();
    $db = null;
    echo "Mixed retail/rental concurrency checks: $checks passed, 0 failed.\n";
} catch (Throwable $error) {
    if ($db instanceof mysqli) {
        try {
            $db->rollback();
        } catch (Throwable) {
        }
        $db->close();
    }
    foreach ($children as $pid) {
        pcntl_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $status);
    }
    fwrite(STDERR, 'Mixed retail/rental concurrency checks failed: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    foreach ($temporaryPaths as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
