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
use Verdant\Storefront\RentalDiscoveryService;

if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'local' || getenv('DB_NAME') !== 'verdant_test_setup_phase012') {
    fwrite(STDERR, "Use only the isolated verdant_test_setup_phase012 schema.\n");
    exit(2);
}

$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$db->begin_transaction();
$tag = strtolower(bin2hex(random_bytes(5)));
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
};
$insert = static function (mysqli $connection, string $sql, array $parameters = []): int {
    $statement = $connection->prepare($sql);
    $statement->execute($parameters);
    $statement->close();
    return (int) $connection->insert_id;
};
$one = static function (mysqli $connection, string $sql): int {
    $result = $connection->query($sql)->fetch_assoc();
    return (int) ($result['n'] ?? 0);
};

try {
    $categoryId = $insert($db, 'INSERT INTO categories(name) VALUES(?)', ['Rental discovery ' . $tag]);
    $makeProduct = static function (string $sku, string $name, int $quantity, int $rate) use ($db, $insert, $categoryId): int {
        $productId = $insert($db,
            "INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,product_kind,
                 buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor)
             VALUES(?,?,?,'Synthetic rental discovery fixture','unknown','unknown','unknown','plant',0,1,NULL,?)",
            [$categoryId, $sku, $name, $rate],
        );
        $insert($db, 'INSERT INTO inventory(product_id,on_hand) VALUES(?,?)', [$productId, $quantity]);
        return $productId;
    };
    $plantId = $makeProduct('RD-' . strtoupper($tag) . '-PLANT', 'Available rental plant', 3, 500);
    $emptyId = $makeProduct('RD-' . strtoupper($tag) . '-EMPTY', 'Unavailable rental plant', 0, 200);

    try {
        $insert($db,
            "INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,product_kind,
                 buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor)
             VALUES(?,?,?,'Synthetic flower must remain sale-only','unknown','unknown','unknown','cut_flower',1,1,1000,100)",
            [$categoryId, 'RD-' . strtoupper($tag) . '-FLOWER', 'Forbidden rental flower'],
        );
        throw new RuntimeException('The database accepted a sale-only flower rental row.');
    } catch (mysqli_sql_exception $error) {
        $check($error->getCode() === 3819, 'The active database check constraint rejects floral rental offers.');
    }

    $today = new DateTimeImmutable('today', new DateTimeZone('Africa/Johannesburg'));
    $start = $today->modify('+30 days')->format('Y-m-d');
    $end = $today->modify('+31 days')->format('Y-m-d');
    $service = new RentalDiscoveryService(new RentalService(
        new RentalRepository($db), new InventoryRepository($db), new CapacityRepository($db), new TransactionManager($db),
    ));
    $beforeRentals = $one($db, 'SELECT COUNT(*) AS n FROM rentals');
    $beforeAllocations = $one($db, 'SELECT COUNT(*) AS n FROM rental_allocations');
    $quotes = $service->quotes([
        ['product_id' => $plantId, 'product_kind' => 'plant', 'rent_enabled' => 1, 'rental_daily_rate_minor' => 500],
        ['product_id' => $emptyId, 'product_kind' => 'plant', 'rent_enabled' => 1, 'rental_daily_rate_minor' => 200],
        ['product_id' => $plantId, 'product_kind' => 'cut_flower', 'rent_enabled' => 1, 'rental_daily_rate_minor' => 500],
        ['product_id' => $plantId, 'product_kind' => 'plant', 'rent_enabled' => 0, 'rental_daily_rate_minor' => 500],
    ], $start, $end);
    $check(array_keys($quotes) === [$plantId, $emptyId], 'Only eligible non-floral rentable candidates are quoted.');
    $check($quotes[$plantId]['available'] && $quotes[$plantId]['available_quantity'] === 3
        && $quotes[$plantId]['days'] === 2 && $quotes[$plantId]['total_minor'] === 1000,
        'Dated availability and estimate come from RentalService for the inclusive period.');
    $check(!$quotes[$emptyId]['available'] && $quotes[$emptyId]['total_minor'] === 400,
        'Unavailable dates remain visible with the authoritative rate-based estimate.');
    $check($one($db, 'SELECT COUNT(*) AS n FROM rentals') === $beforeRentals
        && $one($db, 'SELECT COUNT(*) AS n FROM rental_allocations') === $beforeAllocations,
        'Rental discovery quotes do not create rental records or stock holds.');
    echo "Rental discovery checks: {$checks} passed, 0 failed.\n";
} finally {
    $db->rollback();
    $db->close();
}
