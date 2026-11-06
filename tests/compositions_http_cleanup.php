<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;

$email = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'local' || getenv('DB_NAME') !== 'verdant_test_phase012'
    || preg_match('/^variants-http-customer-[a-f0-9]{16}@example\.test$/D', $email) !== 1) {
    throw new RuntimeException('Cleanup only supports generated Phase012 HTTP fixtures.');
}
$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$query = static function (string $sql, array $values = []) use ($db): array {
    $statement = $db->prepare($sql);
    $statement->execute($values);
    $result = $statement->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $statement->close();
    return $rows;
};
$db->begin_transaction();
try {
    $customer = $query("SELECT customer_id FROM customers WHERE email = ? AND role = 'customer' FOR UPDATE", [$email])[0] ?? null;
    if ($customer !== null) {
        $id = (int) $customer['customer_id'];
        $rows = $query('SELECT cl.product_id FROM composition_checkout_lines cl JOIN composition_checkouts cc
            ON cc.composition_checkout_id = cl.composition_checkout_id WHERE cc.customer_id = ? ORDER BY cl.product_id', [$id]);
        foreach (array_unique(array_column($rows, 'product_id')) as $productId) {
            $query('SELECT product_id FROM inventory WHERE product_id = ? FOR UPDATE', [$productId]);
        }
        foreach ($query('SELECT composition_checkout_id, order_id, rental_id, state FROM composition_checkouts WHERE customer_id = ? FOR UPDATE', [$id]) as $checkout) {
            if ($checkout['state'] === 'cancelled') { continue; }
            if ($checkout['state'] !== 'pending_payment'
                || $query('SELECT order_payment_id FROM order_payments WHERE order_id = ?', [$checkout['order_id']]) !== []
                || ($checkout['rental_id'] !== null && $query('SELECT rental_payment_id FROM rental_payments WHERE rental_id = ?', [$checkout['rental_id']]) !== [])) {
                throw new RuntimeException('HTTP fixture cleanup refuses paid or unresolved reservations.');
            }
            $query("UPDATE retail_holds h JOIN order_items i ON i.order_item_id = h.order_item_id
                SET h.state = 'released' WHERE i.order_id = ? AND h.state = 'active'", [$checkout['order_id']]);
            $query("UPDATE sales_orders SET state = 'cancelled' WHERE order_id = ? AND state = 'pending_payment'", [$checkout['order_id']]);
            if ($checkout['rental_id'] !== null) {
                $query("UPDATE rental_allocations a JOIN rental_items i ON i.rental_item_id = a.rental_item_id
                    SET a.state = 'released' WHERE i.rental_id = ? AND a.state = 'held'", [$checkout['rental_id']]);
                $query("UPDATE rentals SET state = 'cancelled' WHERE rental_id = ? AND state = 'pending_payment'", [$checkout['rental_id']]);
            }
            $query("UPDATE composition_checkouts SET state = 'cancelled' WHERE composition_checkout_id = ?", [$checkout['composition_checkout_id']]);
        }
        $query("UPDATE customers SET account_state = 'archived', archived_at = UTC_TIMESTAMP() WHERE customer_id = ?", [$id]);
    }
    $db->commit();
} catch (Throwable $error) {
    $db->rollback();
    throw $error;
} finally {
    $db->close();
}
