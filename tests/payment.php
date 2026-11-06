<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Inventory\InventoryRepository;
use Verdant\Notifications\CommerceNotifier;
use Verdant\Payments\PaystackConfiguration;
use Verdant\Payments\PaystackException;
use Verdant\Payments\PaystackGateway;
use Verdant\Payments\PaystackHttpClient;
use Verdant\Payments\PaymentRepository;
use Verdant\Payments\PaymentService;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Persistence\TransactionManager;
use Verdant\Retail\OrderRepository;
use Verdant\Retail\OrderService;
use Verdant\Retail\RetailAvailabilityRepository;

if (PHP_SAPI !== 'cli' || preg_match('/^verdant_test_[a-z0-9_]+$/', getenv('DB_NAME') ?: '') !== 1) {
    fwrite(STDERR, "Use an isolated verdant_test_ database.\n"); exit(2);
}

final class FakePaystack implements PaystackGateway
{
    public int $initializeCalls = 0;
    public bool $uncertainInitialize = false;
    public bool $definitiveInitializeFailure = false;
    /** @var array<string, array<string, mixed>> */
    public array $verification = [];

    public function initialize(string $email, int $amountMinor, string $reference, string $callbackUrl): array
    {
        ++$this->initializeCalls;
        if ($this->uncertainInitialize) { throw new PaystackException('timeout', true); }
        if ($this->definitiveInitializeFailure) { throw new PaystackException('rejected', false); }
        return ['status' => true, 'data' => [
            'reference' => $reference,
            'authorization_url' => 'https://checkout.paystack.com/test-' . $reference,
        ]];
    }

    public function verify(string $reference): array
    {
        return $this->verification[$reference] ?? ['status' => true, 'data' => [
            'reference' => $reference, 'status' => 'pending', 'amount' => 1000, 'currency' => 'ZAR', 'domain' => 'test',
        ]];
    }
}

$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$tag = bin2hex(random_bytes(6));
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($label); }
    ++$checks;
    echo "PASS: $label\n";
};
$gateway = new FakePaystack();
$inventory = new InventoryRepository($db);
$orders = new OrderRepository($db);
$availability = new RetailAvailabilityRepository($db);
$paymentRepository = new PaymentRepository($db);
$transaction = new TransactionManager($db);
$notifier = new CommerceNotifier($db);
$orderService = new OrderService($orders, $inventory, $availability, $transaction);
$paymentService = new PaymentService($paymentRepository, $inventory, $transaction, $gateway, 'http://127.0.0.1:8088/payment/return', $notifier);
$scalar = static function (string $sql, array $params = []) use ($db): mixed {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $value = $stmt->get_result()->fetch_row()[0] ?? null;
    $stmt->close();
    return $value;
};

$makeOrder = static function (int $stock = 5) use ($db, $tag, $orderService): array {
    static $sequence = 0;
    ++$sequence;
    $suffix = $tag . '-' . $sequence;
    $stmt = $db->prepare("INSERT INTO customers(email,password_hash,name,account_state) VALUES(?, 'test-hash', 'Payment fixture', 'active')");
    $stmt->execute(['pay-' . $suffix . '@example.test']);
    $customerId = (int) $db->insert_id;
    $stmt->close();
    $stmt = $db->prepare('INSERT INTO categories(name) VALUES (?)');
    $stmt->execute(['Payment category ' . $suffix]);
    $categoryId = (int) $db->insert_id;
    $stmt->close();
    $stmt = $db->prepare("INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor) VALUES(?,?,?, 'Synthetic','indirect','weekly','non_toxic',1,1,1000,250)");
    $stmt->execute([$categoryId, 'PAY-' . strtoupper($suffix), 'Payment plant ' . $sequence]);
    $productId = (int) $db->insert_id;
    $stmt->close();
    $stmt = $db->prepare('INSERT INTO inventory(product_id,on_hand) VALUES(?,?)');
    $stmt->execute([$productId, $stock]);
    $stmt->close();
    $checkoutKey = bin2hex(random_bytes(32));
    $order = $orderService->createPending($customerId, [$productId => 1], $checkoutKey);
    return ['customer' => $customerId, 'product' => $productId, 'order' => (int) $order['order_id'], 'key' => $checkoutKey];
};

try {
    $adminStmt = $db->prepare("INSERT INTO customers(email,password_hash,name,account_state,role) VALUES(?, 'test-hash','Payment admin','active','admin')");
    $adminStmt->execute(['pay-admin-' . $tag . '@example.test']);
    $adminId = (int) $db->insert_id;
    $adminStmt->close();
    putenv('APP_ENV=local');
    putenv('PAYSTACK_SECRET_KEY=sk_test_syntheticplaceholdervalue');
    $configuration = PaystackConfiguration::fromEnvironment();
    $check(str_starts_with($configuration->secretKey, 'sk_test_') && $configuration->callbackUrl === 'http://verdant.localhost:8088/payment/return',
        'Configuration accepts a test key and local callback only.');
    putenv('APP_ENV=production');
    putenv('EMAIL_BASE_URL=https://verdant.thisisphila.co.za');
    putenv('PAYSTACK_CALLBACK_URL=https://verdant.thisisphila.co.za/payment/return');
    $hostedConfiguration = PaystackConfiguration::fromEnvironment();
    $check($hostedConfiguration->callbackUrl === 'https://verdant.thisisphila.co.za/payment/return',
        'Hosted configuration accepts a test key with the public store callback.');
    putenv('PAYSTACK_CALLBACK_URL=https://evil.example/payment/return');
    try { PaystackConfiguration::fromEnvironment(); throw new RuntimeException('Foreign hosted callback was accepted.'); }
    catch (PaystackException) { $check(true, 'Hosted configuration refuses a callback outside the store origin.'); }
    putenv('APP_ENV=local');
    putenv('PAYSTACK_CALLBACK_URL');
    try { new PaystackHttpClient('sk_live_synthetic-live-key-value'); throw new RuntimeException('Live key was accepted.'); }
    catch (PaystackException) { $check(true, 'Provider client refuses live secret keys.'); }
    putenv('PAYSTACK_SECRET_KEY=sk_live_synthetic-live-key-value');
    try { PaystackConfiguration::fromEnvironment(); throw new RuntimeException('Live config was accepted.'); }
    catch (PaystackException) { $check(true, 'Environment configuration refuses live mode.'); }
    putenv('PAYSTACK_SECRET_KEY=sk_test_syntheticplaceholdervalue');

    $successFixture = $makeOrder();
    $started = $paymentService->start($successFixture['customer'], $successFixture['order'], 'pay-' . $tag . '-1@example.test');
    $payment = $paymentRepository->latestForCustomerOrder($successFixture['customer'], $successFixture['order']);
    $check($started['state'] === 'ready' && str_starts_with($started['authorization_url'], 'https://checkout.paystack.com/'),
        'Test-mode transaction creates a validated hosted checkout redirect.');
    $check($payment !== null && $payment['provider_checkout_url'] === $started['authorization_url'],
        'Hosted URL and unique reference are stored for safe retry.');
    $retry = $paymentService->start($successFixture['customer'], $successFixture['order'], 'pay-' . $tag . '-1@example.test');
    $check($retry['authorization_url'] === $started['authorization_url'] && $gateway->initializeCalls === 1,
        'Retry resumes the existing provider checkout instead of initializing another transaction.');

    $reference = (string) $payment['provider_reference'];
    $gateway->verification[$reference] = ['status' => true, 'data' => [
        'reference' => $reference, 'status' => 'success', 'amount' => 1000, 'currency' => 'ZAR', 'domain' => 'test',
    ]];
    $raw = json_encode(['event' => 'charge.success', 'data' => ['reference' => $reference]], JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha512', $raw, $configuration->secretKey);
    $result = $paymentService->handleWebhook($raw, $signature, $configuration->secretKey);
    $check($result['accepted'] && $result['state'] === 'succeeded', 'Signed success event is server-verified and confirms the order.');
    $commerceEventId = $scalar('SELECT commerce_event_id FROM commerce_notification_events WHERE event_key=? AND event_kind=? AND order_id=?',
        ['purchase-confirmed:' . $successFixture['order'], 'purchase_confirmed', $successFixture['order']]);
    $eventContext = $commerceEventId === null ? [] : json_decode(
        (string) $scalar('SELECT context_json FROM commerce_notification_events WHERE commerce_event_id=?', [$commerceEventId]),
        true, 32, JSON_THROW_ON_ERROR,
    );
    $check($commerceEventId !== null && $eventContext['status'] === 'confirmed'
        && $eventContext['items'][0]['name'] === 'Payment plant 1'
        && (int) $eventContext['total_minor'] === 1000
        && (int) $scalar('SELECT COUNT(*) FROM email_outbox WHERE commerce_event_id=? AND recipient_customer_id IN (?,?) AND recipient_role IN (?,?)',
            [$commerceEventId, $successFixture['customer'], $adminId, 'customer', 'admin']) === 2,
        'Successful payment snapshots the confirmed purchase and trusted customer/admin recipients.');
    $db->query('SELECT 1');
    $confirmed = $paymentRepository->byReference($reference);
    $stock = $db->query('SELECT on_hand FROM inventory WHERE product_id=' . $successFixture['product'])->fetch_assoc();
    $check($confirmed['payment_state'] === 'succeeded' && $confirmed['order_state'] === 'confirmed' && (int) $stock['on_hand'] === 4,
        'Successful payment consumes the hold and decrements stock once.');
    $duplicate = $paymentService->handleWebhook($raw, $signature, $configuration->secretKey);
    $stockAfterDuplicate = $db->query('SELECT on_hand FROM inventory WHERE product_id=' . $successFixture['product'])->fetch_assoc();
    $check($duplicate['state'] === 'duplicate' && (int) $stockAfterDuplicate['on_hand'] === 4,
        'Duplicate signed webhook does not decrement stock a second time.');
    $check((int) $scalar('SELECT COUNT(*) FROM commerce_notification_events WHERE event_key=?', ['purchase-confirmed:' . $successFixture['order']]) === 1,
        'Repeated provider callback retains exactly one purchase notification event.');
    $invalid = $paymentService->handleWebhook($raw, str_repeat('0', 128), $configuration->secretKey);
    $check(!$invalid['accepted'] && $invalid['state'] === 'invalid_signature', 'Invalid webhook signature is rejected before provider verification.');

    $failedFixture = $makeOrder(2);
    $failedStart = $paymentService->start($failedFixture['customer'], $failedFixture['order'], 'pay-' . $tag . '-2@example.test');
    $failedPayment = $paymentRepository->latestForCustomerOrder($failedFixture['customer'], $failedFixture['order']);
    $gateway->verification[$failedPayment['provider_reference']] = ['status' => true, 'data' => [
        'reference' => $failedPayment['provider_reference'], 'status' => 'failed', 'amount' => 1000, 'currency' => 'ZAR', 'domain' => 'test',
    ]];
    $failedState = $paymentService->verifyForCustomer($failedFixture['customer'], $failedPayment['provider_reference']);
    $failedStored = $paymentRepository->byReference($failedPayment['provider_reference']);
    $check($failedState === 'failed' && $failedStored['order_state'] === 'cancelled' && $failedStored['payment_state'] === 'failed',
        'Authoritatively failed payment cancels the order.');
    $check((int) $scalar('SELECT COUNT(*) FROM commerce_notification_events WHERE order_id=?', [$failedFixture['order']]) === 0,
        'Definitive payment failure creates no purchase confirmation event.');
    $failedHold = $db->query('SELECT state FROM retail_holds h JOIN order_items i ON i.order_item_id=h.order_item_id WHERE i.order_id=' . $failedFixture['order'])->fetch_assoc();
    $check($failedHold['state'] === 'released', 'Only definitive failure releases reserved stock.');

    $pendingFixture = $makeOrder(2);
    $paymentService->start($pendingFixture['customer'], $pendingFixture['order'], 'pay-' . $tag . '-3@example.test');
    $pendingPayment = $paymentRepository->latestForCustomerOrder($pendingFixture['customer'], $pendingFixture['order']);
    $gateway->verification[$pendingPayment['provider_reference']] = ['status' => true, 'data' => [
        'reference' => $pendingPayment['provider_reference'], 'status' => 'pending', 'amount' => 1000, 'currency' => 'ZAR', 'domain' => 'test',
    ]];
    $check($paymentService->verifyForCustomer($pendingFixture['customer'], $pendingPayment['provider_reference']) === 'reconciliation_required',
        'Pending provider result remains unresolved and retains its stock hold.');
    $check((int) $scalar('SELECT COUNT(*) FROM commerce_notification_events WHERE order_id=?', [$pendingFixture['order']]) === 0,
        'Unverified pending provider status creates no purchase notification.');
    $pendingHold = $db->query('SELECT state FROM retail_holds h JOIN order_items i ON i.order_item_id=h.order_item_id WHERE i.order_id=' . $pendingFixture['order'])->fetch_assoc();
    $check($pendingHold['state'] === 'reconciliation_required', 'Uncertain result is visibly marked for reconciliation.');
    $gateway->verification[$pendingPayment['provider_reference']]['data']['status'] = 'success';
    $check($paymentService->verifyForCustomer($pendingFixture['customer'], $pendingPayment['provider_reference']) === 'succeeded',
        'A delayed verified success can consume the still-held stock.');
    $check((int) $scalar('SELECT COUNT(*) FROM commerce_notification_events WHERE event_key=?', ['purchase-confirmed:' . $pendingFixture['order']]) === 1,
        'Delayed success creates one purchase event when confirmation commits.');

    $mismatchFixture = $makeOrder(2);
    $paymentService->start($mismatchFixture['customer'], $mismatchFixture['order'], 'pay-' . $tag . '-4@example.test');
    $mismatchPayment = $paymentRepository->latestForCustomerOrder($mismatchFixture['customer'], $mismatchFixture['order']);
    $gateway->verification[$mismatchPayment['provider_reference']] = ['status' => true, 'data' => [
        'reference' => $mismatchPayment['provider_reference'], 'status' => 'success', 'amount' => 999, 'currency' => 'ZAR', 'domain' => 'test',
    ]];
    $check($paymentService->verifyForCustomer($mismatchFixture['customer'], $mismatchPayment['provider_reference']) === 'reconciliation_required',
        'Amount mismatch never confirms an order.');

    $timeoutFixture = $makeOrder(2);
    $gateway->uncertainInitialize = true;
    try { $paymentService->start($timeoutFixture['customer'], $timeoutFixture['order'], 'pay-' . $tag . '-5@example.test'); }
    catch (PaystackException $error) { $check($error->uncertain, 'Initialization timeout is reported as uncertain.'); }
    $timeoutPayment = $paymentRepository->latestForCustomerOrder($timeoutFixture['customer'], $timeoutFixture['order']);
    $timeoutStored = $paymentRepository->byReference($timeoutPayment['provider_reference']);
    $timeoutHold = $db->query('SELECT h.state FROM retail_holds h JOIN order_items i ON i.order_item_id=h.order_item_id WHERE i.order_id=' . $timeoutFixture['order'])->fetch_assoc();
    $check($timeoutStored['payment_state'] === 'reconciliation_required' && $timeoutHold['state'] === 'reconciliation_required',
        'Unknown initialization result keeps stock reserved for reconciliation.');
    $gateway->uncertainInitialize = false;

    $initFailureFixture = $makeOrder(2);
    $gateway->definitiveInitializeFailure = true;
    try { $paymentService->start($initFailureFixture['customer'], $initFailureFixture['order'], 'pay-' . $tag . '-6@example.test'); }
    catch (PaystackException $error) { $check(!$error->uncertain, 'Definitive initialization rejection is distinguished from a timeout.'); }
    $initFailurePayment = $paymentRepository->latestForCustomerOrder($initFailureFixture['customer'], $initFailureFixture['order']);
    $initFailureState = $paymentRepository->byReference($initFailurePayment['provider_reference']);
    $initFailureHold = $db->query('SELECT h.state FROM retail_holds h JOIN order_items i ON i.order_item_id=h.order_item_id WHERE i.order_id=' . $initFailureFixture['order'])->fetch_assoc();
    $check($initFailureState['payment_state'] === 'failed' && $initFailureState['order_state'] === 'cancelled'
        && $initFailureHold['state'] === 'released', 'Definitive pre-checkout rejection safely cancels the order and releases its hold.');
    $gateway->definitiveInitializeFailure = false;

    $unknownRaw = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'unknown-' . $tag]], JSON_THROW_ON_ERROR);
    $unknownSignature = hash_hmac('sha512', $unknownRaw, $configuration->secretKey);
    $unknown = $paymentService->handleWebhook($unknownRaw, $unknownSignature, $configuration->secretKey);
    $check($unknown['accepted'] && $unknown['state'] === 'unknown_reference', 'Valid signed unknown provider event is quarantined without attaching it to an order.');
    $quarantine = $db->query("SELECT processing_state FROM provider_events WHERE provider_code='paystack' AND event_reference='charge.success:unknown-$tag'")->fetch_assoc();
    $check($quarantine['processing_state'] === 'quarantined', 'Unknown signed event leaves an auditable hash-only quarantine record.');

    echo "Payment checks: $checks passed, 0 failed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Payment checks failed after ' . $checks . ' checks: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    $db->close();
}
