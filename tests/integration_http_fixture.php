<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Persistence\TransactionManager;

if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'local'
    || getenv('DB_NAME') !== 'verdant_test_phase008') {
    fwrite(STDERR, "Refusing Phase008 HTTP fixture outside verdant_test_phase008 in local mode.\n");
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
    $scalar = static function (string $sql, array $values) use ($db): int {
        $statement = $db->prepare($sql);
        $statement->execute($values);
        $value = (int) $statement->get_result()->fetch_row()[0];
        $statement->close();
        return $value;
    };

    $action = $argv[1] ?? 'create';
    if ($action === 'create' && count($argv) === 2) {
        $tag = bin2hex(random_bytes(8));
        $password = 'Phase008 HTTP fixture only!';
        $tx = new TransactionManager($db);
        $fixture = $tx->run(static function () use ($insert, $tag, $password): array {
            $category = $insert('INSERT INTO categories(name) VALUES(?)', ['Phase008 HTTP ' . $tag]);
            $sku = 'P8-' . strtoupper($tag);
            $product = $insert(
                "INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor)
                 VALUES(?,?, 'Phase008 dual-use fixture','Synthetic integration fixture','indirect','weekly','non_toxic',1,1,1250,250)",
                [$category, $sku]);
            $insert('INSERT INTO inventory(product_id,on_hand) VALUES(?,3)', [$product]);

            $customerEmail = 'phase008-' . $tag . '@example.test';
            $customer = $insert(
                "INSERT INTO customers(email,password_hash,name,account_state,role) VALUES(?,?,?,'active','customer')",
                [$customerEmail, password_hash($password, PASSWORD_DEFAULT), 'Phase008 HTTP Customer']);
            $otherEmail = 'phase008-owner-' . $tag . '@example.test';
            $otherCustomer = $insert(
                "INSERT INTO customers(email,password_hash,name,account_state,role) VALUES(?,?,?,'active','customer')",
                [$otherEmail, password_hash($password, PASSWORD_DEFAULT), 'Phase008 Other Customer']);

            // Empty synthetic records provide real, foreign-owned callback references. Callback
            // ownership checks must reject these before attempting provider verification.
            $foreignOrder = $insert("INSERT INTO sales_orders(customer_id,checkout_key,state) VALUES(?,?,'pending_payment')",
                [$otherCustomer, 'phase008-foreign-order-' . $tag]);
            $orderReference = 'verdant-retail-foreign-' . $tag;
            $insert("INSERT INTO order_payments(order_id,provider_code,provider_reference,amount_minor,currency,state)
                     VALUES(?,'paystack',?,0,'ZAR','pending')", [$foreignOrder, $orderReference]);

            $foreignRental = $insert("INSERT INTO rentals(customer_id,checkout_key,state) VALUES(?,?,'pending_payment')",
                [$otherCustomer, 'phase008-foreign-rental-' . $tag]);
            $rentalReference = 'verdant-rental-foreign-' . $tag;
            $insert("INSERT INTO rental_payments(rental_id,provider_code,provider_reference,amount_minor,currency,state)
                     VALUES(?,'paystack',?,0,'ZAR','pending')", [$foreignRental, $rentalReference]);

            return ['tag' => $tag, 'category_id' => $category, 'product_id' => $product, 'sku' => $sku,
                'customer_id' => $customer, 'email' => $customerEmail, 'password' => $password,
                'foreign_order_reference' => $orderReference, 'foreign_rental_reference' => $rentalReference];
        });
        echo json_encode($fixture, JSON_THROW_ON_ERROR);
    } elseif ($action === 'inspect' && count($argv) === 4
        && ctype_digit($argv[2]) && ctype_digit($argv[3])
        && (int) $argv[2] > 0 && (int) $argv[3] > 0) {
        $customer = (int) $argv[2];
        $product = (int) $argv[3];
        $result = [
            'inventory_on_hand' => $scalar('SELECT on_hand FROM inventory WHERE product_id=?', [$product]),
            'order_count' => $scalar('SELECT COUNT(*) FROM sales_orders WHERE customer_id=? AND order_id IN (SELECT order_id FROM order_items WHERE product_id=?)', [$customer, $product]),
            'pending_order_count' => $scalar("SELECT COUNT(*) FROM sales_orders o WHERE o.customer_id=? AND o.state='pending_payment' AND EXISTS (SELECT 1 FROM order_items i WHERE i.order_id=o.order_id AND i.product_id=?)", [$customer, $product]),
            'cancelled_order_count' => $scalar("SELECT COUNT(*) FROM sales_orders o WHERE o.customer_id=? AND o.state='cancelled' AND EXISTS (SELECT 1 FROM order_items i WHERE i.order_id=o.order_id AND i.product_id=?)", [$customer, $product]),
            'order_item_count' => $scalar('SELECT COUNT(*) FROM order_items i JOIN sales_orders o ON o.order_id=i.order_id WHERE o.customer_id=? AND i.product_id=?', [$customer, $product]),
            'active_hold_quantity' => $scalar("SELECT COALESCE(SUM(h.quantity),0) FROM retail_holds h JOIN order_items i ON i.order_item_id=h.order_item_id JOIN sales_orders o ON o.order_id=i.order_id WHERE o.customer_id=? AND i.product_id=? AND o.state IN ('pending_payment','reconciliation_required') AND h.state IN ('active','reconciliation_required')", [$customer, $product]),
            'released_hold_quantity' => $scalar("SELECT COALESCE(SUM(h.quantity),0) FROM retail_holds h JOIN order_items i ON i.order_item_id=h.order_item_id JOIN sales_orders o ON o.order_id=i.order_id WHERE o.customer_id=? AND i.product_id=? AND h.state='released'", [$customer, $product]),
            'rental_count' => $scalar('SELECT COUNT(*) FROM rentals r WHERE r.customer_id=? AND EXISTS (SELECT 1 FROM rental_items i WHERE i.rental_id=r.rental_id AND i.product_id=?)', [$customer, $product]),
            'rental_item_count' => $scalar('SELECT COUNT(*) FROM rental_items i JOIN rentals r ON r.rental_id=i.rental_id WHERE r.customer_id=? AND i.product_id=?', [$customer, $product]),
            'active_rental_quantity' => $scalar("SELECT COALESCE(SUM(a.quantity),0) FROM rental_allocations a JOIN rental_items i ON i.rental_item_id=a.rental_item_id JOIN rentals r ON r.rental_id=i.rental_id WHERE r.customer_id=? AND i.product_id=? AND r.state IN ('pending_payment','confirmed','reconciliation_required') AND a.state IN ('held','confirmed','issued','reconciliation_required')", [$customer, $product]),
            'provider_event_count' => $scalar("SELECT COUNT(*) FROM provider_events WHERE provider_code='paystack'" , []),
        ];
        echo json_encode($result, JSON_THROW_ON_ERROR);
    } elseif ($action === 'settle-order' && count($argv) === 5
        && ctype_digit($argv[2]) && ctype_digit($argv[3]) && in_array($argv[4], ['success', 'failed'], true)) {
        $customer = (int) $argv[2];
        $orderId = (int) $argv[3];
        $statement = $db->prepare("SELECT email FROM customers WHERE customer_id=? AND email LIKE 'phase008-%@example.test'");
        $statement->execute([$customer]);
        $email = $statement->get_result()->fetch_assoc()['email'] ?? null;
        $statement->close();
        $order = (new Verdant\Retail\OrderRepository($db))->owned($customer, $orderId);
        if ($email === null || $order === null) { throw new RuntimeException('Missing synthetic checkout.'); }
        $gateway = new class((int) $order['total_minor'], $argv[4]) implements Verdant\Payments\PaystackGateway {
            public function __construct(private int $amount, private string $status) {}
            public function initialize(string $email, int $amountMinor, string $reference, string $callbackUrl): array
            {
                return ['status'=>true,'data'=>['reference'=>$reference,'authorization_url'=>'https://checkout.paystack.com/synthetic-' . $reference]];
            }
            public function verify(string $reference): array
            {
                return ['status'=>true,'data'=>['reference'=>$reference,'status'=>$this->status,'amount'=>$this->amount,'currency'=>'ZAR','domain'=>'test']];
            }
        };
        $repository = new Verdant\Payments\PaymentRepository($db);
        $service = new Verdant\Payments\PaymentService($repository, new Verdant\Inventory\InventoryRepository($db),
            new TransactionManager($db), $gateway, 'http://127.0.0.1:8093/payment/return');
        $service->start($customer, $orderId, $email);
        $payment = $repository->latestForCustomerOrder($customer, $orderId);
        if ($argv[4] === 'success') {
            $raw = json_encode(['event'=>'charge.success','data'=>['reference'=>$payment['provider_reference']]], JSON_THROW_ON_ERROR);
            $secret = 'phase008-synthetic-event-secret';
            $result = $service->handleWebhook($raw, hash_hmac('sha512', $raw, $secret), $secret);
            echo json_encode($result, JSON_THROW_ON_ERROR);
        } else {
            echo json_encode(['state'=>$service->verifyForCustomer($customer, $payment['provider_reference'])], JSON_THROW_ON_ERROR);
        }
    } elseif ($action === 'archive-customer' && count($argv) === 3 && ctype_digit($argv[2]) && (int) $argv[2] > 0) {
        $statement = $db->prepare("UPDATE customers SET account_state='archived', archived_at=UTC_TIMESTAMP() WHERE customer_id=? AND email LIKE 'phase008-%@example.test'");
        $statement->execute([(int) $argv[2]]);
        $changed = $statement->affected_rows;
        $statement->close();
        echo json_encode(['changed' => $changed], JSON_THROW_ON_ERROR);
    } else {
        fwrite(STDERR, "Invalid fixture action or arguments.\n");
        exit(2);
    }
} catch (Throwable) {
    fwrite(STDERR, "Phase008 HTTP fixture failed; private details suppressed.\n");
    exit(1);
} finally {
    $db?->close();
}
