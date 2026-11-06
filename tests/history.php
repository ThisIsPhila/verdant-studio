<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Identity\AccountService;
use Verdant\Retail\OrderRepository;
use Verdant\Rentals\RentalRepository;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;

if (!preg_match('/^verdant_test_[a-z0-9_]+$/', getenv('DB_NAME') ?: '')) { exit(2); }
$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$db->begin_transaction();
$checks=0;
$check=function(bool $ok,string $message) use (&$checks):void { if (!$ok) throw new RuntimeException($message);++$checks; };
try {
    $hash=password_hash('synthetic-history-password',PASSWORD_DEFAULT);
    $insert=$db->prepare("INSERT INTO customers(email,password_hash,name,account_state) VALUES(?,?,'History fixture','active')");
    $suffix=bin2hex(random_bytes(6));
    $insert->execute(['history-'.$suffix.'@example.test',$hash]);$owner=(int)$db->insert_id;
    $insert->execute(['other-'.$suffix.'@example.test',$hash]);$other=(int)$db->insert_id;
    $order=$db->prepare("INSERT INTO sales_orders(customer_id,checkout_key,state) VALUES(?,?,'confirmed')");
    $order->execute([$owner,'history-'.$suffix]);$orderId=(int)$db->insert_id;
    $rental=$db->prepare("INSERT INTO rentals(customer_id,checkout_key,state) VALUES(?,?,'confirmed')");
    $rental->execute([$owner,'history-'.$suffix]);$rentalId=(int)$db->insert_id;
    $service=new AccountService(new OrderRepository($db),new RentalRepository($db));
    $a=['customer_id'=>$owner];$b=['customer_id'=>$other];
    $check(count($service->overview($a)['orders'])===1,'Own orders visible.');
    $check(count($service->overview($a)['rentals'])===1,'Own rentals visible.');
    $check($service->order($a,$orderId)!==null,'Own order detail visible.');
    $check($service->rental($a,$rentalId)!==null,'Own rental detail visible.');
    $check($service->order($b,$orderId)===null,'Other customer order denied.');
    $check($service->rental($b,$rentalId)===null,'Other customer rental denied.');
    $check($service->overview($b)['orders']===[] && $service->overview($b)['rentals']===[],'New account empty history.');
    $check($service->order($a,0)===null,'Invalid record unavailable.');
    try { $service->overview([]);throw new RuntimeException('Missing actor accepted.'); }
    catch (DomainException) {++$checks;}
    echo "History checks: $checks passed, 0 failed.\n";
} finally {$db->rollback();$db->close();}
