<?php

declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
use Verdant\Persistence\{ConnectionFactory,DatabaseConfig};
use Verdant\Notifications\CommerceNotifier;
use Verdant\Support\Mail\EmailConfig;
if (PHP_SAPI !== 'cli' || !preg_match('/^verdant_test_[a-z0-9_]+$/D', getenv('DB_NAME') ?: '')) exit('Isolated test database required.');
$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$checks=0;
$check=static function(bool $ok,string $why) use (&$checks):void {if(!$ok) throw new RuntimeException($why); $checks++;};
$one=static function(string $sql,array $params=[]) use($db):?array {$s=$db->prepare($sql);$s->execute($params);try{return $s->get_result()->fetch_assoc() ?: null;}finally{$s->close();}};
$put=static function(string $sql,array $params=[]) use($db):int {$s=$db->prepare($sql);$s->execute($params);$s->close();return(int)$db->insert_id;};
$tag=bin2hex(random_bytes(6));
$db->begin_transaction();
try {
    $customer=$put("INSERT INTO customers(email,password_hash,name,role,account_state) VALUES(?,'unused','Mail Customer','customer','active')",["mail-$tag@example.com"]);
    $category=$put('INSERT INTO categories(name) VALUES(?)',["Mail $tag"]);
    $product=$put("INSERT INTO products(category_id,sku,name,description,product_kind,accessory_subtype,selling_unit,light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor) VALUES(?,?,?,'Mail test','plant','other','Each','unknown','unknown','unknown',1,0,1000,NULL)",[$category,"MAIL-$tag",'Test plant']);
    $order=$put("INSERT INTO sales_orders(customer_id,checkout_key,state) VALUES(?,?,'confirmed')",[$customer,$tag]);
    $put('INSERT INTO order_items(order_id,product_id,product_name_snapshot,sku_snapshot,quantity,unit_price_minor) VALUES(?,?,?,?,2,1000)',[$order,$product,'Original plant',"MAIL-$tag"]);
    $notifier=new CommerceNotifier($db);
    $notifier->record('purchase_confirmed',"purchase-confirmed:$order",'order',$order);
    $event=$one('SELECT commerce_event_id,context_json FROM commerce_notification_events WHERE event_key=?',["purchase-confirmed:$order"]);
    $check($event!==null,'Event persisted');
    $context=json_decode($event['context_json'],true,flags:JSON_THROW_ON_ERROR);
    $check($context['total_minor']===2000 && $context['items'][0]['name']==='Original plant','Frozen price/name summary');
    $rows=$one('SELECT COUNT(*) AS n FROM email_outbox WHERE commerce_event_id=?',[$event['commerce_event_id']]);
    $admins=(int)$one("SELECT COUNT(*) AS n FROM customers WHERE role='admin' AND account_state='active'")['n'];
    $check((int)$rows['n']===1+$admins,'Customer and active admin recipients');
    $check((int)$one("SELECT COUNT(*) AS n FROM email_outbox WHERE commerce_event_id=? AND delivery_state='held'",[$event['commerce_event_id']])['n']===1+$admins,'No automatic sending');
    $notifier->record('purchase_confirmed',"purchase-confirmed:$order",'order',$order);
    $check((int)$one('SELECT COUNT(*) AS n FROM email_outbox WHERE commerce_event_id=?',[$event['commerce_event_id']])['n']===1+$admins,'Duplicate callback preserves recipient count');
    $put('UPDATE products SET name=? WHERE product_id=?',['Changed name',$product]);
    $check(json_decode($one('SELECT context_json FROM commerce_notification_events WHERE commerce_event_id=?',[$event['commerce_event_id']])['context_json'],true)['items'][0]['name']==='Original plant','Historical summary immutable after product edit');
    $pending=$put("INSERT INTO sales_orders(customer_id,checkout_key,state) VALUES(?,?,'pending_payment')",[$customer,$tag.'-pending']);
    try {$notifier->record('purchase_confirmed',"purchase-confirmed:$pending",'order',$pending);throw new RuntimeException('Unconfirmed accepted');}catch(LogicException){$checks++;}
    foreach([
        "INSERT INTO email_outbox(recipient_email,template_key,context_json) VALUES('x@example.com','x','{}')",
        "INSERT INTO commerce_notification_events(event_key,event_kind,order_id,rental_id,context_json) VALUES('bad-$tag','purchase_confirmed',$order,999999,'{}')"
    ] as $sql){try{$db->query($sql);throw new RuntimeException('Invalid source accepted');}catch(mysqli_sql_exception){$checks++;}}
    $before=(int)$one('SELECT COUNT(*) AS n FROM email_outbox WHERE commerce_event_id=?',[$event['commerce_event_id']])['n'];
    $check($before>0,'Events visible inside caller transaction');
    $adminBuyer=$one("SELECT customer_id FROM customers WHERE role='admin' AND account_state='active' LIMIT 1");
    $adminOrder=$put("INSERT INTO sales_orders(customer_id,checkout_key,state) VALUES(?,?,'confirmed')",[$adminBuyer['customer_id'],$tag.'-admin']);
    $put('INSERT INTO order_items(order_id,product_id,product_name_snapshot,sku_snapshot,quantity,unit_price_minor) VALUES(?,?,?,?,1,1000)',[$adminOrder,$product,'Admin plant',"MAIL-$tag"]);
    $notifier->record('purchase_confirmed',"purchase-confirmed:$adminOrder",'order',$adminOrder);
    $check((int)$one('SELECT COUNT(*) AS n FROM email_outbox o JOIN commerce_notification_events e ON e.commerce_event_id=o.commerce_event_id WHERE e.order_id=?',[$adminOrder])['n']===$admins,'An administrator purchase does not duplicate the recipient or break confirmation');

    $emailKeys=['EMAIL_AUTO_QUEUE','EMAIL_TRANSPORT','EMAIL_FROM_ADDRESS','EMAIL_FROM_NAME','EMAIL_REPLY_TO','EMAIL_BASE_URL','EMAIL_TEST_RECIPIENTS'];
    $previousEmailEnv=[];
    foreach($emailKeys as $key)$previousEmailEnv[$key]=getenv($key);
    try {
        putenv('EMAIL_AUTO_QUEUE=1');
        putenv('EMAIL_TRANSPORT=local_capture');
        putenv('EMAIL_FROM_ADDRESS=owner@example.test');
        putenv('EMAIL_FROM_NAME=Verdant Test');
        putenv('EMAIL_REPLY_TO=reply@example.test');
        putenv('EMAIL_BASE_URL=http://127.0.0.1:8088');
        putenv('EMAIL_TEST_RECIPIENTS='.implode(',',EmailConfig::TEST_RECIPIENTS));

        $autoCustomer=$one('SELECT customer_id,role,account_state FROM customers WHERE LOWER(email)=? LIMIT 1',['customer@thisisphila.co.za']);
        if($autoCustomer===null){
            $autoCustomerId=$put("INSERT INTO customers(email,password_hash,name,role,account_state) VALUES('customer@thisisphila.co.za','unused','Mail Auto Queue Customer','customer','active')");
        }else{
            if($autoCustomer['role']!=='customer')throw new RuntimeException('Designated auto-queue address is not a customer in the isolated fixture.');
            $autoCustomerId=(int)$autoCustomer['customer_id'];
            if($autoCustomer['account_state']!=='active'){
                $statement=$db->prepare("UPDATE customers SET account_state='active' WHERE customer_id=?");
                $statement->execute([$autoCustomerId]);
                $statement->close();
            }
        }
        $autoOrder=$put("INSERT INTO sales_orders(customer_id,checkout_key,state) VALUES(?,?,'confirmed')",[$autoCustomerId,$tag.'-auto-queue']);
        $put('INSERT INTO order_items(order_id,product_id,product_name_snapshot,sku_snapshot,quantity,unit_price_minor) VALUES(?,?,?,?,1,1000)',[$autoOrder,$product,'Auto queue plant',"MAIL-$tag"]);
        (new CommerceNotifier($db))->record('purchase_confirmed',"purchase-confirmed:$autoOrder",'order',$autoOrder);
        $autoEvent=$one('SELECT commerce_event_id FROM commerce_notification_events WHERE event_key=?',["purchase-confirmed:$autoOrder"]);
        $autoQuery=$db->prepare('SELECT recipient_email,delivery_state FROM email_outbox WHERE commerce_event_id=?');
        $autoQuery->execute([$autoEvent['commerce_event_id']]);
        $autoRows=$autoQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        $autoQuery->close();
        $queued=0;$held=0;$validQueueStates=true;
        foreach($autoRows as $row){
            $shouldQueue=(new EmailConfig('local_capture','owner@example.test','Verdant Test','reply@example.test','http://127.0.0.1:8088'))->permits($row['recipient_email']);
            if($row['delivery_state']==='queued')$queued++;
            elseif($row['delivery_state']==='held')$held++;
            else $validQueueStates=false;
            if(($row['delivery_state']==='queued')!==$shouldQueue)$validQueueStates=false;
        }
        $check($validQueueStates && $queued>0 && $held>0,'Complete local auto-queue configuration queues only allowlisted recipients and holds all other active admins.');
    } finally {
        foreach($previousEmailEnv as $key=>$value)putenv($value===false?$key:$key.'='.$value);
    }

    $db->rollback();
    $check($one('SELECT commerce_event_id FROM commerce_notification_events WHERE event_key=?',["purchase-confirmed:$order"])===null,'Rollback removes event and outbox together');
    echo "Commerce notification checks: $checks passed\n";
} catch(Throwable $e) {$db->rollback();throw $e;}
