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
    foreach (['stock-reduction'] as $scenario) {
        $db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
        $tag = bin2hex(random_bytes(6));
        $category = $insert($db, 'INSERT INTO categories(name) VALUES(?)', ['Rental race ' . $tag]);
        $customers = [];
        foreach ([0, 1] as $slot) {
            $customers[] = $insert($db, "INSERT INTO customers(email,password_hash,name,account_state) VALUES(?,'test-hash','Race fixture','active')", ['race-' . $tag . '-' . $slot . '@example.test']);
        }
        $product = $insert($db, "INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor) VALUES(?,?,'Race plant','Synthetic','indirect','weekly','non_toxic',1,1,1000,250)", [$category, 'RR-' . strtoupper($tag)]);
        $insert($db, 'INSERT INTO inventory(product_id,on_hand) VALUES(?,1)', [$product]);
        $admin = $insert($db, "INSERT INTO customers(email,password_hash,name,account_state,role) VALUES(?,'test-hash','Admin race fixture','active','admin')", ['admin-race-' . $tag . '@example.test']);
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
                    if ($slot === 1) {
                        $service = new Verdant\Admin\CatalogueAdminService(new Verdant\Admin\CatalogueAdminRepository($db), $inventory, new CapacityRepository($db), $tx, new Verdant\Admin\AdminAccess($db));
                        $id = $service->saveProduct(['customer_id' => $admin, 'role' => 'admin'], $product, [
                            'category_id' => (string) $category, 'sku' => 'RR-' . strtoupper($tag), 'name' => 'Race plant', 'description' => 'Synthetic',
                            'light_level' => 'indirect', 'watering_level' => 'weekly', 'toxicity' => 'non_toxic', 'image_path' => '',
                            'buy_enabled' => '1', 'rent_enabled' => '1', 'retail_price' => '10.00', 'rental_daily_rate' => '2.50', 'on_hand' => '0', 'version' => '0',
                        ]);
                    } else {
                        $service = new RentalService(new RentalRepository($db), $inventory, new CapacityRepository($db), $tx);
                        $record = $service->createPending($customers[0], $product, 1, $start, $start, $keys[0]);
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
        $check(count($created) === 1 && count(array_filter($outcomes, static fn (array $result): bool => $result['state'] === 'unavailable')) === 1,
            'Stock reduction and last-unit rental cannot both succeed: ' . json_encode($outcomes));
        $db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
        $capacity = new CapacityRepository($db);
        $check($capacity->availableForPeriod($product, $start, (new DateTimeImmutable($start))->modify('+1 day')->format('Y-m-d')) === 0,
            'Final capacity is zero with either a protected booking or reduced stock.');
        $db->close();
    }
    echo "Admin stock concurrency checks: $checks passed, 0 failed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Admin stock concurrency checks failed: ' . $error->getMessage() . "\n"); exit(1);
}
