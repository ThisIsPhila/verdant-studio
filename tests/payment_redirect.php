<?php
require dirname(__DIR__) . '/app/bootstrap.php';
putenv('APP_ENV=local');
$checks=0;
foreach (['http://checkout.paystack.com/x','https://checkout.paystack.com.evil.test/x','https://user@checkout.paystack.com/x','https://checkout.paystack.com:444/x','javascript:alert(1)','//checkout.paystack.com/x'] as $url) {
    try { \Verdant\Http\PaymentRedirect::toCheckout($url, []); throw new \RuntimeException('Unsafe destination accepted.'); }
    catch (\DomainException) { ++$checks; }
}
ob_start();\Verdant\Http\PaymentRedirect::toCheckout('https://checkout.paystack.com/test?value=%22', []);$html=ob_get_clean();
foreach ([str_contains($html,'id="paystack-checkout-link"'),str_contains($html,'/assets/js/payment-forward.js'),!str_contains($html,'<form'),str_contains($html,'Continue to Paystack TEST')] as $ok) { if(!$ok)throw new \RuntimeException('Safe fallback missing.'); ++$checks; }
echo "Payment handoff checks: $checks passed, 0 failed.\n";
