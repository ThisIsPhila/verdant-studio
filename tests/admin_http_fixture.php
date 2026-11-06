<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'local' || !in_array(getenv('DB_NAME'), ['verdant_test_phase007_http', 'verdant_test_phase015'], true)) { exit(2); }
$db = Verdant\Persistence\ConnectionFactory::connect(Verdant\Persistence\DatabaseConfig::fromEnvironment());
$tx = new Verdant\Persistence\TransactionManager($db);
$tag = bin2hex(random_bytes(8));
$customerId = (int) $db->query("SELECT customer_id FROM customers WHERE email='customer@example.test' AND role='customer'")->fetch_assoc()['customer_id'];
$adminId = (int) $db->query("SELECT customer_id FROM customers WHERE email='admin@example.test' AND role='admin'")->fetch_assoc()['customer_id'];
$categoryId = (int) $db->query("SELECT category_id FROM categories WHERE archived_at IS NULL ORDER BY category_id LIMIT 1")->fetch_assoc()['category_id'];
if ($customerId < 1 || $adminId < 1 || $categoryId < 1) throw new RuntimeException('Named test accounts and a category are required.');
$insert = static function (string $sql, array $args) use ($db): int {
    $s=$db->prepare($sql);$s->execute($args);$id=(int)$db->insert_id;$s->close();return $id;
};
$result = $tx->run(function () use ($insert,$tag,$customerId,$adminId,$categoryId): array {
    $sku='ADMIN-HTTP-'.strtoupper($tag);
    $scratchAdminEmail='admin-http-'.$tag.'@example.test';
    $insert("INSERT INTO customers(email,password_hash,name,account_state,role) VALUES(?,?,'HTTP administrator','active','admin')",[$scratchAdminEmail,password_hash('VerdantDemo!2026',PASSWORD_DEFAULT)]);
    $product=$insert("INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor) VALUES(?,?,'HTTP fixture','Synthetic','indirect','weekly','non_toxic',1,1,1000,750)",[$categoryId,$sku]);
    $insert('INSERT INTO inventory(product_id,on_hand) VALUES(?,3)',[$product]);
    $order=$insert("INSERT INTO sales_orders(customer_id,checkout_key,state) VALUES(?,?,'confirmed')",[$customerId,'admin-http-order-'.$tag]);
    $line=$insert("INSERT INTO order_items(order_id,product_id,product_name_snapshot,sku_snapshot,quantity,unit_price_minor) VALUES(?,?,'HTTP fixture',?,1,1000)",[$order,$product,$sku]);
    $insert("INSERT INTO retail_holds(order_item_id,quantity,state) VALUES(?,1,'consumed')",[$line]);
    $insert("INSERT INTO order_payments(order_id,provider_code,provider_reference,amount_minor,currency,state) VALUES(?,'demo',?,1000,'ZAR','succeeded')",[$order,'admin-http-paid-'.$tag]);
    $rental=$insert("INSERT INTO rentals(customer_id,checkout_key,state) VALUES(?,?,'confirmed')",[$customerId,'admin-http-rental-'.$tag]);
    $today=(new DateTimeImmutable('today',new DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
    $end=(new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
    $item=$insert("INSERT INTO rental_items(rental_id,product_id,product_name_snapshot,sku_snapshot,quantity,start_date,end_date_exclusive,daily_rate_minor) VALUES(?,?,'HTTP fixture',?,2,?,?,750)",[$rental,$product,$sku,$today,$end]);
    $insert("INSERT INTO rental_allocations(rental_item_id,quantity,state) VALUES(?,2,'issued')",[$item]);
    $insert('INSERT INTO rental_issues(rental_item_id,quantity,issued_at,actor_id) VALUES(?,2,UTC_TIMESTAMP(),?)',[$item,$adminId]);
    return ['product'=>$product,'sku'=>$sku,'order'=>$order,'rental'=>$rental,'admin_email'=>$scratchAdminEmail];
});
echo json_encode($result,JSON_THROW_ON_ERROR);
$db->close();
