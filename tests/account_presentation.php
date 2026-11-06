<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
putenv('APP_ENV=local');
$_SERVER['HTTP_HOST']='127.0.0.1:8088';
use Verdant\Http\View;
$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);++$checks;};
$actor=['name'=>'<script>account</script>','role'=>'customer'];
ob_start();View::render('account',['actor'=>$actor,'orders'=>[['order_id'=>7,'created_at'=>'2026-10-13 00:00:00','total_minor'=>12345,'state'=>'pending_payment']],'rentals'=>[['rental_id'=>8,'start_date'=>'2026-10-15','end_date_exclusive'=>'2026-10-18','total_minor'=>5000,'state'=>'confirmed']]]);$html=ob_get_clean();
$check(!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;account'),'Customer name escaped.');
$check(str_contains($html,'/account/order?id=7')&&str_contains($html,'/account/rental?id=8'),'Correct owned-history links.');
$check(str_contains($html,'17 Oct 2026'),'Rental end shown inclusive.');
$check(str_contains($html,'class="account-record"')&&!str_contains($html,'<table>'),'History uses mobile summary cards.');
$check(!str_contains($html,'password-recovery')&&!str_contains($html,'subscribe'),'No unimplemented account services.');
echo "Account presentation checks: $checks passed, 0 failed.\n";
