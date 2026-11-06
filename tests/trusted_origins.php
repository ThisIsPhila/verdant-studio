<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Config\TrustedOrigins;
use Verdant\Notifications\EmailRenderer;
use Verdant\Payments\PaystackConfiguration;
use Verdant\Payments\PaystackException;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
};

$oldEnv = [];
foreach (['APP_ENV', 'PAYSTACK_SECRET_KEY', 'PAYSTACK_CALLBACK_URL'] as $key) {
    $value = getenv($key);
    $oldEnv[$key] = $value === false ? null : $value;
}

try {
    putenv('APP_ENV=local');
    putenv('PAYSTACK_SECRET_KEY=sk_test_syntheticplaceholdervalue');
    $_SERVER['HTTP_HOST'] = 'attacker.example';
    $origins = TrustedOrigins::local();
    $check($origins->store === 'http://verdant.localhost:8088'
        && $origins->admin === 'http://admin.verdant.localhost:8088', 'Local origins match the configured website addresses.');

    $customer = EmailRenderer::render([
        'template_key' => 'purchase_confirmed', 'recipient_role' => 'customer',
        'context' => ['record_type' => 'order', 'record_id' => 41, 'customer_name' => 'Test Customer', 'items' => []],
    ], 'https://attacker.example', $origins);
    $check(str_contains($customer['text'], 'http://verdant.localhost:8088/account/order?id=41')
        && !str_contains($customer['text'], 'attacker.example'), 'Customer transaction link ignores an untrusted supplied origin.');

    $admin = EmailRenderer::render([
        'template_key' => 'purchase_confirmed', 'recipient_role' => 'admin',
        'context' => ['record_type' => 'order', 'record_id' => 41, 'customer_name' => 'Test Customer', 'items' => []],
    ], 'https://attacker.example', $origins);
    $check(str_contains($admin['text'], 'http://admin.verdant.localhost:8088/admin/order?id=41')
        && !str_contains($admin['text'], 'attacker.example'), 'Admin notification link ignores an untrusted supplied origin.');

    $support = EmailRenderer::render([
        'template_key' => 'support_admin_new_message',
        'context' => ['enquiry_id' => 9],
    ], 'https://attacker.example', $origins);
    $check(str_contains($support['text'], 'http://admin.verdant.localhost:8088/admin/enquiry?id=9')
        && !str_contains($support['text'], 'attacker.example'), 'Admin support notification link uses admin origin.');

    foreach ([
        'http://verdant.localhost:8088/payment/return',
        'http://127.0.0.1:8088/payment/return',
        'http://localhost:8088/payment/return',
        'http://[::1]:8088/payment/return',
    ] as $callback) {
        putenv('PAYSTACK_CALLBACK_URL=' . $callback);
        $configuration = PaystackConfiguration::fromEnvironment();
        $check($configuration->callbackUrl === 'http://verdant.localhost:8088/payment/return', 'Allowed local callback is normalized to the store origin.');
    }

    foreach ([
        'https://attacker.example/payment/return',
        'http://admin.verdant.localhost:8088/payment/return',
        'http://verdant.localhost:8088/redirect?next=https://attacker.example',
        'http://verdant.localhost.evil.test:8088/payment/return',
        'http://user@verdant.localhost:8088/payment/return',
        '//verdant.localhost:8088/payment/return',
        'http://verdant.localhost:9999/payment/return',
        'http://verdant.localhost:8088/not-a-callback',
    ] as $callback) {
        putenv('PAYSTACK_CALLBACK_URL=' . $callback);
        try {
            PaystackConfiguration::fromEnvironment();
            throw new RuntimeException('Untrusted callback was accepted: ' . $callback);
        } catch (PaystackException) {
            ++$checks;
        }
    }

    putenv('APP_ENV=production');
    putenv('EMAIL_BASE_URL=https://verdant.thisisphila.co.za');
    putenv('ADMIN_BASE_URL=https://admin.verdant.thisisphila.co.za');
    $hostedOrigins = TrustedOrigins::hosted();
    $check($hostedOrigins !== null && $hostedOrigins->store === 'https://verdant.thisisphila.co.za'
        && $hostedOrigins->admin === 'https://admin.verdant.thisisphila.co.za',
        'Hosted origins come from the configured public base URLs.');
    $hostedMail = EmailRenderer::render([
        'template_key' => 'purchase_confirmed', 'recipient_role' => 'customer',
        'context' => ['record_type' => 'order', 'record_id' => 42, 'customer_name' => 'Test Customer', 'items' => []],
    ], 'https://attacker.example', $hostedOrigins);
    $check(str_contains($hostedMail['text'], 'https://verdant.thisisphila.co.za/account/order?id=42'),
        'Hosted mail links use the public store origin.');
    putenv('ADMIN_BASE_URL');
    putenv('PAYSTACK_CALLBACK_URL=https://verdant.thisisphila.co.za/payment/return');
    $hostedPaystack = PaystackConfiguration::fromEnvironment();
    $check($hostedPaystack->callbackUrl === 'https://verdant.thisisphila.co.za/payment/return',
        'Hosted sandbox payments use the configured store callback.');
    putenv('PAYSTACK_CALLBACK_URL=https://payments.verdant.example.test/callback');
    try {
        PaystackConfiguration::fromEnvironment();
        throw new RuntimeException('Sandbox payments accepted a foreign hosted callback.');
    } catch (PaystackException) {
        ++$checks;
    }
    putenv('EMAIL_BASE_URL');
} finally {
    foreach ($oldEnv as $key => $value) {
        if ($value === null) {
            putenv($key);
        } else {
            putenv($key . '=' . $value);
        }
    }
}

fwrite(STDOUT, "Trusted origin checks passed: {$checks}\n");
