<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Persistence\TransactionManager;

if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'local'
    || getenv('DB_NAME') !== 'verdant_test_phase009') {
    fwrite(STDERR, "Refusing security fixture outside verdant_test_phase009 in local mode.\n");
    exit(2);
}

$db = null;
try {
    $db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
    $insert = static function (string $sql, array $values) use ($db): int {
        $statement = $db->prepare($sql);
        $statement->execute($values);
        $id = (int) $db->insert_id;
        $statement->close();
        return $id;
    };
    $one = static function (string $sql, array $values) use ($db): array {
        $statement = $db->prepare($sql);
        $statement->execute($values);
        $row = $statement->get_result()->fetch_assoc();
        $statement->close();
        return is_array($row) ? $row : [];
    };
    $count = static function (string $sql, array $values) use ($db): int {
        $statement = $db->prepare($sql);
        if ($values === []) {
            $statement->execute();
        } else {
            $statement->execute($values);
        }
        $row = $statement->get_result()->fetch_row();
        $statement->close();
        return (int) ($row[0] ?? 0);
    };

    $action = $argv[1] ?? 'create';
    if ($action === 'create' && count($argv) === 2) {
        $tag = bin2hex(random_bytes(8));
        $password = 'Phase009 Security fixture!';
        $productName = '<script>alert("phase009-product")</script>';
        $customerName = '<script>alert("phase009-customer")</script>';
        $customerEmail = 'phase009-' . $tag . '@example.test';
        $otherEmail = 'phase009-other-' . $tag . '@example.test';
        $tx = new TransactionManager($db);
        $fixture = $tx->run(static function () use ($insert, $tag, $password, $productName, $customerName, $customerEmail, $otherEmail): array {
            $category = $insert('INSERT INTO categories(name) VALUES(?)', ['Phase009 security ' . $tag]);
            $sku = 'SEC-' . strtoupper($tag);
            $product = $insert(
                'INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor)
                 VALUES(?,?,?,\'Synthetic security fixture\',\'indirect\',\'weekly\',\'non_toxic\',1,1,1000,250)',
                [$category, $sku, $productName]);
            $insert('INSERT INTO inventory(product_id,on_hand) VALUES(?,3)', [$product]);

            $customer = $insert(
                "INSERT INTO customers(email,password_hash,name,account_state,role) VALUES(?,?,?,'active','customer')",
                [$customerEmail, password_hash($password, PASSWORD_DEFAULT), $customerName]);
            $otherCustomer = $insert(
                "INSERT INTO customers(email,password_hash,name,account_state,role) VALUES(?,?,?,'active','customer')",
                [$otherEmail, password_hash($password, PASSWORD_DEFAULT), 'Phase009 Other Customer']);

            // A separate owner's pending records support direct ownership and mutation checks.
            $foreignOrder = $insert("INSERT INTO sales_orders(customer_id,checkout_key,state) VALUES(?,?,'pending_payment')",
                [$otherCustomer, 'phase009-order-' . $tag]);
            $line = $insert('INSERT INTO order_items(order_id,product_id,product_name_snapshot,sku_snapshot,quantity,unit_price_minor) VALUES(?,?,?,?,1,1000)',
                [$foreignOrder, $product, $productName, $sku]);
            $insert("INSERT INTO retail_holds(order_item_id,quantity,state) VALUES(?,1,'active')", [$line]);

            $start = (new DateTimeImmutable('today', new DateTimeZone('Africa/Johannesburg')))
                ->modify('+30 days')->format('Y-m-d');
            $end = (new DateTimeImmutable($start))->modify('+1 day')->format('Y-m-d');
            $foreignRental = $insert("INSERT INTO rentals(customer_id,checkout_key,state) VALUES(?,?,'pending_payment')",
                [$otherCustomer, 'phase009-rental-' . $tag]);
            $rentalItem = $insert(
                'INSERT INTO rental_items(rental_id,product_id,product_name_snapshot,sku_snapshot,quantity,start_date,end_date_exclusive,daily_rate_minor) VALUES(?,?,?,?,1,?,?,250)',
                [$foreignRental, $product, $productName, $sku, $start, $end]);
            $insert("INSERT INTO rental_allocations(rental_item_id,quantity,state) VALUES(?,1,'held')", [$rentalItem]);

            return ['tag' => $tag, 'customer_id' => $customer, 'customer_email' => $customerEmail,
                'other_customer_id' => $otherCustomer, 'product_id' => $product, 'sku' => $sku,
                'product_name' => $productName, 'customer_name' => $customerName,
                'password' => $password, 'foreign_order_id' => $foreignOrder,
                'foreign_rental_id' => $foreignRental];
        });
        echo json_encode($fixture, JSON_THROW_ON_ERROR);
    } elseif ($action === 'inspect' && count($argv) === 7
        && ctype_digit($argv[2]) && ctype_digit($argv[3]) && ctype_digit($argv[4])
        && ctype_digit($argv[5]) && ctype_digit($argv[6])) {
        [$customer, $product, $order, $rental, $otherCustomer] = array_map('intval', array_slice($argv, 2));
        $result = [
            'inventory_on_hand' => (int) ($one('SELECT on_hand FROM inventory WHERE product_id=?', [$product])['on_hand'] ?? 0),
            'customer_role' => (string) ($one('SELECT role FROM customers WHERE customer_id=?', [$customer])['role'] ?? ''),
            'other_customer_role' => (string) ($one('SELECT role FROM customers WHERE customer_id=?', [$otherCustomer])['role'] ?? ''),
            'other_customer_state' => (string) ($one('SELECT account_state FROM customers WHERE customer_id=?', [$otherCustomer])['account_state'] ?? ''),
            'foreign_order_state' => (string) ($one('SELECT state FROM sales_orders WHERE order_id=?', [$order])['state'] ?? ''),
            'foreign_hold_state' => (string) ($one('SELECT h.state FROM retail_holds h JOIN order_items i ON i.order_item_id=h.order_item_id WHERE i.order_id=? AND i.product_id=?', [$order, $product])['state'] ?? ''),
            'foreign_hold_quantity' => $count('SELECT COALESCE(SUM(h.quantity),0) FROM retail_holds h JOIN order_items i ON i.order_item_id=h.order_item_id WHERE i.order_id=? AND i.product_id=?', [$order, $product]),
            'foreign_rental_state' => (string) ($one('SELECT state FROM rentals WHERE rental_id=?', [$rental])['state'] ?? ''),
            'foreign_allocation_state' => (string) ($one('SELECT a.state FROM rental_allocations a JOIN rental_items i ON i.rental_item_id=a.rental_item_id WHERE i.rental_id=? AND i.product_id=?', [$rental, $product])['state'] ?? ''),
            'foreign_allocation_quantity' => $count('SELECT COALESCE(SUM(a.quantity),0) FROM rental_allocations a JOIN rental_items i ON i.rental_item_id=a.rental_item_id WHERE i.rental_id=? AND i.product_id=?', [$rental, $product]),
            'provider_event_count' => $count("SELECT COUNT(*) FROM provider_events WHERE provider_code='paystack'", []),
        ];
        echo json_encode($result, JSON_THROW_ON_ERROR);
    } elseif ($action === 'identity' && count($argv) === 3
        && preg_match('/^phase009-[a-z0-9-]+@example\.test$/D', $argv[2]) === 1) {
        $row = $one('SELECT customer_id,role,account_state FROM customers WHERE email=?', [$argv[2]]);
        echo json_encode(['count' => $row === [] ? 0 : 1, 'role' => (string) ($row['role'] ?? ''),
            'account_state' => (string) ($row['account_state'] ?? '')], JSON_THROW_ON_ERROR);
    } else {
        fwrite(STDERR, "Invalid security fixture action or arguments.\n");
        exit(2);
    }
} catch (Throwable) {
    fwrite(STDERR, "Phase009 security fixture failed; private details suppressed.\n");
    exit(1);
} finally {
    $db?->close();
}
