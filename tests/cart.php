<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Retail\CartService;
use Verdant\Retail\RetailAvailabilityRepository;
use Verdant\Support\ValidationException;

if (!preg_match('/^verdant_test_[a-z0-9_]+$/', getenv('DB_NAME') ?: '')) {
    fwrite(STDERR, "Use an isolated verdant_test_ database.\n"); exit(2);
}
$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$db->begin_transaction();
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    ++$checks;
};
try {
    $db->query("INSERT INTO categories(name) VALUES ('Cart verification')");
    $category = (int) $db->insert_id;
    $stmt = $db->prepare("INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor)
        VALUES(?,?,'Cart specimen','Synthetic test plant','indirect','weekly','non_toxic',1,1,1250,300)");
    $stmt->execute([$category, 'CART-' . bin2hex(random_bytes(6))]);
    $product = (int) $db->insert_id;
    $stmt->close();
    $stmt = $db->prepare('INSERT INTO inventory(product_id,on_hand) VALUES(?,3)');
    $stmt->execute([$product]);
    $stmt->close();
    $service = new CartService(new RetailAvailabilityRepository($db));

    $cart = $service->add([], (string) $product, '2');
    $check($cart[(string) $product] === 2, 'Eligible item added to the recoverable basket.');
    $summary = $service->summary($cart);
    $check($summary['valid'] && $summary['total_minor'] === 2500, 'Basket totals use the stored price in integer minor units.');
    $check($service->update($cart, (string) $product, '3')[(string) $product] === 3, 'Quantity update within stock succeeds.');
    $check($service->remove($cart, (string) $product) === [], 'Item can be removed.');

    try { $service->add($cart, (string) $product, '2'); throw new RuntimeException('Over-stock add was accepted.'); }
    catch (ValidationException) { $check($cart[(string) $product] === 2, 'Rejected add leaves the original basket unchanged.'); }
    foreach ([['product', '1'], [(string) $product, '0'], [(string) $product, '100'], [(string) $product, ['2']]] as [$id, $qty]) {
        try { $service->add([], $id, $qty); throw new RuntimeException('Malformed cart input was accepted.'); }
        catch (ValidationException) { ++$checks; }
    }

    $stmt = $db->prepare("INSERT INTO customers(email,password_hash,name,account_state) VALUES(?, 'test-hash', 'Synthetic customer', 'active')");
    $stmt->execute(['cart-' . bin2hex(random_bytes(6)) . '@example.test']);
    $customer = (int) $db->insert_id;
    $stmt->close();
    $stmt = $db->prepare("INSERT INTO sales_orders(customer_id,checkout_key,state) VALUES(?,?,'pending_payment')");
    $stmt->execute([$customer, 'cart-' . bin2hex(random_bytes(6))]);
    $order = (int) $db->insert_id;
    $stmt->close();
    $stmt = $db->prepare("INSERT INTO order_items(order_id,product_id,product_name_snapshot,sku_snapshot,quantity,unit_price_minor) VALUES(?,?,'Cart specimen','TEST',1,1250)");
    $stmt->execute([$order, $product]);
    $item = (int) $db->insert_id;
    $stmt->close();
    $stmt = $db->prepare("INSERT INTO retail_holds(order_item_id,quantity,state) VALUES(?,1,'active')");
    $stmt->execute([$item]);
    $stmt->close();
    $fresh = $service->summary([$product => 3]);
    $check(!$fresh['valid'] && str_contains($fresh['errors']['product_' . $product], 'Only 2'), 'Current retail holds reduce available cart stock.');

    $stmt = $db->prepare("INSERT INTO rentals(customer_id,checkout_key,state) VALUES(?,?,'confirmed')");
    $stmt->execute([$customer, 'rent-' . bin2hex(random_bytes(6))]);
    $rental = (int) $db->insert_id;
    $stmt->close();
    $stmt = $db->prepare("INSERT INTO rental_items(rental_id,product_id,product_name_snapshot,sku_snapshot,quantity,start_date,end_date_exclusive,daily_rate_minor) VALUES(?,?,'Cart specimen','TEST',1,CURRENT_DATE(),DATE_ADD(CURRENT_DATE(), INTERVAL 1 DAY),300)");
    $stmt->execute([$rental, $product]);
    $rentalItem = (int) $db->insert_id;
    $stmt->close();
    $stmt = $db->prepare("INSERT INTO rental_allocations(rental_item_id,quantity,state) VALUES(?,1,'confirmed')");
    $stmt->execute([$rentalItem]);
    $stmt->close();
    $fresh = $service->summary([$product => 2]);
    $check(!$fresh['valid'] && str_contains($fresh['errors']['product_' . $product], 'Only 1'), 'Confirmed rental allocation also reduces shared retail stock.');

    $db->query("UPDATE products SET archived_at=UTC_TIMESTAMP() WHERE product_id=$product");
    $check(!$service->summary([$product => 1])['valid'], 'Archived product cannot pass checkout validation.');
    echo "Cart checks: $checks passed, 0 failed.\n";
} finally { $db->rollback(); $db->close(); }
