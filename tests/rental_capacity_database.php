<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Inventory\CapacityRepository;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;

if (PHP_SAPI !== 'cli' || preg_match('/^verdant_test_[a-z0-9_]+$/', getenv('DB_NAME') ?: '') !== 1) {
    fwrite(STDERR, "Use a disposable Verdant test database and CLI.\n");
    exit(2);
}

$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$tag = bin2hex(random_bytes(6));
$insert = static function (mysqli $connection, string $sql, array $values): int {
    $statement = $connection->prepare($sql);
    $statement->execute($values);
    $id = (int) $connection->insert_id;
    $statement->close();
    return $id;
};
$categoryId = $insert($db, 'INSERT INTO categories(name) VALUES (?)', ['Capacity ' . $tag]);
$customerId = $insert($db,
    "INSERT INTO customers(email,password_hash,name,account_state) VALUES(?, 'test-hash', 'Capacity fixture', 'active')",
    ['capacity-' . $tag . '@example.test']);
$productId = $insert($db,
    "INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor)
     VALUES(?,?, 'Capacity plant','Synthetic fixture','indirect','weekly','non_toxic',1,1,1000,125)",
    [$categoryId, 'CAP-' . strtoupper($tag)]);
$insert($db, 'INSERT INTO inventory(product_id,on_hand) VALUES(?,10)', [$productId]);
$heldProductId = $insert($db,
    "INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor)
     VALUES(?,?, 'Held plant','Synthetic fixture','indirect','weekly','non_toxic',1,1,1000,125)",
    [$categoryId, 'HLD-' . strtoupper($tag)]);
$insert($db, 'INSERT INTO inventory(product_id,on_hand) VALUES(?,4)', [$heldProductId]);
$peakProductId = $insert($db,
    "INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor)
     VALUES(?,?, 'Peak plant','Synthetic fixture','indirect','weekly','non_toxic',1,1,1000,125)",
    [$categoryId, 'PEK-' . strtoupper($tag)]);
$insert($db, 'INSERT INTO inventory(product_id,on_hand) VALUES(?,10)', [$peakProductId]);
$reconciliationProductId = $insert($db,
    "INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor)
     VALUES(?,?, 'Reconciliation plant','Synthetic fixture','indirect','weekly','non_toxic',1,1,1000,125)",
    [$categoryId, 'REC-' . strtoupper($tag)]);
$insert($db, 'INSERT INTO inventory(product_id,on_hand) VALUES(?,4)', [$reconciliationProductId]);

$base = new DateTimeImmutable('today', new DateTimeZone('Africa/Johannesburg'));
$day = static fn (int $offset): string => $base->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');
$addRental = static function (int $quantity, string $start, string $end, string $state = 'confirmed', ?int $targetProductId = null,
    string $allocationState = 'confirmed', ?string $expiresAt = null) use ($db, $insert, $customerId, $productId, $tag): int {
    static $sequence = 0;
    $sequence++;
    $rentalId = $insert($db, 'INSERT INTO rentals(customer_id,checkout_key,state) VALUES(?,?,?)',
        [$customerId, substr(hash('sha256', $tag . $sequence), 0, 64), $state]);
    $itemId = $insert($db,
        "INSERT INTO rental_items(rental_id,product_id,product_name_snapshot,sku_snapshot,quantity,start_date,end_date_exclusive,daily_rate_minor)
         VALUES(?,?,'Capacity plant','CAP',?,?,?,125)",
        [$rentalId, $targetProductId ?? $productId, $quantity, $start, $end]);
    $statement = $db->prepare('INSERT INTO rental_allocations(rental_item_id,quantity,state,expires_at) VALUES(?,?,?,?)');
    $statement->execute([$itemId, $quantity, $allocationState, $expiresAt]);
    $statement->close();
    return $itemId;
};

$addRental(3, $day(1), $day(3));
$addRental(4, $day(3), $day(5));
$addRental(2, $day(2), $day(4));
$issuedItem = $addRental(5, $day(-5), $day(-2));
$db->query("UPDATE rental_allocations SET state='issued' WHERE rental_item_id=" . $issuedItem);
$insert($db, "INSERT INTO rental_issues(rental_item_id,quantity,issued_at) VALUES(?,5,UTC_TIMESTAMP())", [$issuedItem]);
$insert($db, "INSERT INTO rental_returns(rental_item_id,quantity,returned_at) VALUES(?,2,UTC_TIMESTAMP())", [$issuedItem]);

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    ++$checks;
    echo "PASS: $label\n";
};
try {
    $capacity = new CapacityRepository($db, $day(0));
    $check($capacity->availableForPeriod($productId, $day(1), $day(5)) === 1,
        'Database claims combine overdue issued units with the peak simultaneous future quantity, without summing disjoint bookings.');
    $check($capacity->outstandingIssued($productId) === 3,
        'Partial actual returns reduce physical issued quantity by the recorded return amount.');
    $check($capacity->availableForPeriod($productId, $day(5), $day(6)) === 7,
        'An overdue issued remainder stays blocked after its scheduled end.');
    $check($capacity->retailAvailable($productId) === 1,
        'Retail availability protects the greatest future peak and current issued balance without summing disjoint bookings.');
    $addRental(3, $day(1), $day(3), 'confirmed', $peakProductId);
    $addRental(4, $day(3), $day(5), 'confirmed', $peakProductId);
    $addRental(2, $day(2), $day(4), 'confirmed', $peakProductId);
    $check($capacity->availableForPeriod($peakProductId, $day(1), $day(5)) === 4,
        'Without overdue issues, disjoint bookings reserve only the peak simultaneous quantity of six.');
    $check($capacity->retailAvailable($peakProductId) === 4,
        'Retail sales subtract the peak of six rather than summing all three touching bookings.');

    $orderId = $insert($db, "INSERT INTO sales_orders(customer_id,checkout_key,state) VALUES(?,?,'pending_payment')",
        [$customerId, substr(hash('sha256', $tag . 'retail-hold'), 0, 64)]);
    $orderItemId = $insert($db,
        "INSERT INTO order_items(order_id,product_id,product_name_snapshot,sku_snapshot,quantity,unit_price_minor)
         VALUES(?,?,'Capacity plant','CAP',2,1000)", [$orderId, $productId]);
    $insert($db, "INSERT INTO retail_holds(order_item_id,quantity,state) VALUES(?,2,'active')", [$orderItemId]);
    $check($capacity->retailAvailable($productId) === 0,
        'Active retail holds reduce sale availability while preserving rental commitments.');
    $check($capacity->availableForPeriod($productId, $day(1), $day(5)) === 0,
        'Retail holds protect capacity for rental checkout as well as retail checkout.');
    $addRental(2, $day(1), $day(3), 'pending_payment', $heldProductId, 'held', '2000-01-01 00:00:00');
    $heldOrderId = $insert($db, "INSERT INTO sales_orders(customer_id,checkout_key,state) VALUES(?,?,'pending_payment')",
        [$customerId, substr(hash('sha256', $tag . 'expired-retail-hold'), 0, 64)]);
    $heldOrderItemId = $insert($db,
        "INSERT INTO order_items(order_id,product_id,product_name_snapshot,sku_snapshot,quantity,unit_price_minor)
         VALUES(?,?,'Held plant','HLD',1,1000)", [$heldOrderId, $heldProductId]);
    $holdStatement = $db->prepare("INSERT INTO retail_holds(order_item_id,quantity,state,expires_at) VALUES(?,1,'active','2000-01-01 00:00:00')");
    $holdStatement->execute([$heldOrderItemId]);
    $holdStatement->close();
    $check($capacity->availableForPeriod($heldProductId, $day(1), $day(2)) === 1,
        'A rental allocation remains held until its explicit lifecycle state changes even after expires_at.');
    $check($capacity->retailAvailable($heldProductId) === 1,
        'A retail hold remains capacity consuming until its explicit lifecycle state changes even after expires_at.');
    $reconciliationItemId = $addRental(4, $day(-5), $day(-2), 'cancelled', $reconciliationProductId, 'reconciliation_required');
    $allocationStatement = $db->prepare('UPDATE rental_allocations SET state=\'closed\' WHERE rental_item_id=?');
    $allocationStatement->execute([$reconciliationItemId]);
    $allocationStatement->close();
    $insert($db, 'INSERT INTO rental_issues(rental_item_id,quantity,issued_at) VALUES(?,4,UTC_TIMESTAMP())', [$reconciliationItemId]);
    $insert($db, 'INSERT INTO rental_returns(rental_item_id,quantity,returned_at) VALUES(?,1,UTC_TIMESTAMP())', [$reconciliationItemId]);
    $check($capacity->outstandingIssued($reconciliationProductId) === 3,
        'Issued facts remain outstanding through partial returns despite reconciliation or cancelled header states.');
    $check($capacity->availableForPeriod($reconciliationProductId, $day(1), $day(2)) === 1,
        'Issued facts remain a physical capacity block after allocation state changes.');
    $archiveStatement = $db->prepare('UPDATE products SET archived_at=UTC_TIMESTAMP() WHERE product_id=?');
    $archiveStatement->execute([$reconciliationProductId]);
    $archiveStatement->close();
    $archiveStatement = $db->prepare('UPDATE categories SET archived_at=UTC_TIMESTAMP() WHERE category_id=?');
    $archiveStatement->execute([$categoryId]);
    $archiveStatement->close();
    $check($capacity->outstandingIssued($reconciliationProductId) === 3,
        'Archived product and category records do not release issued capacity claims.');
    try {
        $capacity->availableForPeriod($productId, '2026-02-30', '2026-03-01');
        throw new RuntimeException('Malformed dates were accepted by the repository.');
    } catch (InvalidArgumentException) {
        $check(true, 'Malformed repository date input is rejected before querying claims.');
    }
    echo "Rental capacity database checks: $checks passed, 0 failed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Rental capacity database checks failed: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    $db->close();
}
