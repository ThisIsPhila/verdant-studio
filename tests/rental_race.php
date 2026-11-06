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
    fwrite(STDERR, "Use a disposable test database with CLI process support.\n"); exit(2);
}
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($label); }
    ++$checks; echo "PASS: $label\n";
};
$insert = static function (mysqli $db, string $sql, array $args): int {
    $stmt = $db->prepare($sql); $stmt->execute($args); $id = (int) $db->insert_id; $stmt->close(); return $id;
};
$start = (new DateTimeImmutable('today', new DateTimeZone('Africa/Johannesburg')))->modify('+10 days')->format('Y-m-d');
try {
    foreach (['rental-rental', 'rental-retail', 'duplicate-rental'] as $scenario) {
        $db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
        $tag = bin2hex(random_bytes(6));
        $category = $insert($db, 'INSERT INTO categories(name) VALUES(?)', ['Rental race ' . $tag]);
        $customers = [];
        foreach ([0, 1] as $slot) {
            $customers[] = $insert($db, "INSERT INTO customers(email,password_hash,name,account_state) VALUES(?,'test-hash','Race fixture','active')", ['race-' . $tag . '-' . $slot . '@example.test']);
        }
        $product = $insert($db, "INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor) VALUES(?,?,'Race plant','Synthetic','indirect','weekly','non_toxic',1,1,1000,250)", [$category, 'RR-' . strtoupper($tag)]);
        $insert($db, 'INSERT INTO inventory(product_id,on_hand) VALUES(?,1)', [$product]);
        $db->close();
        $sharedKey = bin2hex(random_bytes(32));
        $keys = [$sharedKey, $scenario === 'duplicate-rental' ? $sharedKey : bin2hex(random_bytes(32))];
        $files = []; $children = [];
        $gate = sys_get_temp_dir() . '/verdant-rental-race-' . $tag;
        for ($slot = 0; $slot < 2; ++$slot) {
            $files[$slot] = $gate . '-' . $slot;
            $pid = pcntl_fork();
            if ($pid === -1) { throw new RuntimeException('Could not start a competing request.'); }
            if ($pid === 0) {
                while (!is_file($gate)) { usleep(1000); }
                try {
                    $db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
                    $inventory = new InventoryRepository($db); $tx = new TransactionManager($db);
                    if ($scenario === 'rental-retail' && $slot === 1) {
                        $service = new OrderService(new OrderRepository($db), $inventory, new RetailAvailabilityRepository($db), $tx);
                        $record = $service->createPending($customers[$slot], [$product => 1], $keys[$slot]);
                        $id = $record['order_id'];
                    } else {
                        $service = new RentalService(new RentalRepository($db), $inventory, new CapacityRepository($db), $tx);
                        $actor = $scenario === 'duplicate-rental' ? $customers[0] : $customers[$slot];
                        $record = $service->createPending($actor, $product, 1, $start, $start, $keys[$slot]);
                        $id = $record['rental_id'];
                    }
                    file_put_contents($files[$slot], json_encode(['state' => 'created', 'id' => $id], JSON_THROW_ON_ERROR));
                    $db->close(); exit(0);
                } catch (DomainException|ValidationException) {
                    file_put_contents($files[$slot], '{"state":"unavailable"}'); exit(0);
                } catch (Throwable $error) {
                    file_put_contents($files[$slot], json_encode(['state' => 'error', 'code' => $error->getCode(), 'type' => get_class($error)], JSON_THROW_ON_ERROR)); exit(1);
                }
            }
            $children[] = $pid;
        }
        touch($gate);
        foreach ($children as $pid) { pcntl_waitpid($pid, $exitStatus); }
        $outcomes = array_map(static fn (string $file): array => json_decode((string) file_get_contents($file), true, 8, JSON_THROW_ON_ERROR), $files);
        foreach ([...$files, $gate] as $file) { unlink($file); }
        $created = array_filter($outcomes, static fn (array $result): bool => $result['state'] === 'created');
        if ($scenario === 'duplicate-rental') {
            $check(count($created) === 2 && $outcomes[0]['id'] === $outcomes[1]['id'], 'Simultaneous identical rental submissions resolve to one reservation: ' . json_encode($outcomes));
        } else {
            $check(count($created) === 1 && count(array_filter($outcomes, static fn (array $result): bool => $result['state'] === 'unavailable')) === 1,
                'Competing ' . $scenario . ' requests cannot both claim the final unit: ' . json_encode($outcomes));
        }
        $db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
        $capacity = new CapacityRepository($db);
        $check($capacity->availableForPeriod($product, $start, (new DateTimeImmutable($start))->modify('+1 day')->format('Y-m-d')) === 0,
            $scenario . ' leaves exactly one active capacity claim.');
        $db->close();
    }
    echo "Rental concurrency checks: $checks passed, 0 failed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Rental concurrency checks failed: ' . $error->getMessage() . "\n"); exit(1);
}
