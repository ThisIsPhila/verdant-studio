<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Admin\AdminAccess;
use Verdant\Admin\OperationsRepository;
use Verdant\Admin\OperationsService;
use Verdant\Inventory\InventoryRepository;
use Verdant\Payments\PaystackException;
use Verdant\Payments\PaystackGateway;
use Verdant\Payments\PaymentRepository;
use Verdant\Payments\PaymentService;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Persistence\TransactionManager;
use Verdant\Retail\OrderRepository;
use Verdant\Retail\OrderService;
use Verdant\Retail\RetailAvailabilityRepository;
use Verdant\Support\ValidationException;

if (PHP_SAPI !== 'cli' || preg_match('/^verdant_test_[a-z0-9_]+$/D', getenv('DB_NAME') ?: '') !== 1) {
    fwrite(STDERR, "Use an isolated verdant_test_ database.\n");
    exit(2);
}

final class SecurityPaymentGateway implements PaystackGateway
{
    /** @var array<string, array<string, mixed>> */
    public array $verification = [];
    /** @var array<string, PaystackException> */
    public array $verificationErrors = [];
    public bool $definitiveInitializeFailure = false;
    /** @var null|Closure(string):void */
    public ?Closure $duringInitialize = null;

    public function initialize(string $email, int $amountMinor, string $reference, string $callbackUrl): array
    {
        if ($this->duringInitialize !== null) {
            $callback = $this->duringInitialize;
            $this->duringInitialize = null;
            $callback($reference);
        }
        if ($this->definitiveInitializeFailure) {
            throw new PaystackException('synthetic definitive initialization rejection', false);
        }
        return ['status' => true, 'data' => [
            'reference' => $reference,
            'authorization_url' => 'https://checkout.paystack.com/security-' . $reference,
        ]];
    }

    public function verify(string $reference): array
    {
        if (isset($this->verificationErrors[$reference])) {
            throw $this->verificationErrors[$reference];
        }
        return $this->verification[$reference] ?? ['status' => true, 'data' => [
            'reference' => $reference, 'status' => 'success', 'amount' => 1000, 'currency' => 'ZAR', 'domain' => 'test',
        ]];
    }
}

$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$tag = bin2hex(random_bytes(6));
$checks = 0;
$failures = 0;
$check = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    ++$checks;
    if ($condition) {
        echo "PASS: $label\n";
    } else {
        ++$failures;
        fwrite(STDERR, "FAIL: $label\n");
    }
};

$gateway = new SecurityPaymentGateway();
$inventory = new InventoryRepository($db);
$orderRepository = new OrderRepository($db);
$paymentRepository = new PaymentRepository($db);
$transactions = new TransactionManager($db);
$orderService = new OrderService($orderRepository, $inventory, new RetailAvailabilityRepository($db), $transactions);
$paymentService = new PaymentService($paymentRepository, $inventory, $transactions, $gateway, 'http://127.0.0.1:8088/payment/return');

$makeOrder = static function (int $sequence) use ($db, $tag, $orderService): array {
    $suffix = $tag . '-' . $sequence;
    $statement = $db->prepare("INSERT INTO customers(email,password_hash,name,account_state) VALUES(?, 'synthetic-hash', 'Payment security fixture', 'active')");
    $statement->execute(['security-' . $suffix . '@example.test']);
    $customerId = (int) $db->insert_id;
    $statement->close();

    $statement = $db->prepare('INSERT INTO categories(name) VALUES (?)');
    $statement->execute(['Security category ' . $suffix]);
    $categoryId = (int) $db->insert_id;
    $statement->close();

    $statement = $db->prepare("INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor) VALUES(?,?,?, 'Synthetic','indirect','weekly','non_toxic',1,1,1000,250)");
    $statement->execute([$categoryId, 'SEC-' . strtoupper($suffix), 'Security plant ' . $sequence]);
    $productId = (int) $db->insert_id;
    $statement->close();

    $statement = $db->prepare('INSERT INTO inventory(product_id,on_hand) VALUES(?,2)');
    $statement->execute([$productId]);
    $statement->close();

    $order = $orderService->createPending($customerId, [$productId => 1], bin2hex(random_bytes(32)));
    return ['customer' => $customerId, 'product' => $productId, 'order' => (int) $order['order_id']];
};

$successResponse = static function (string $reference, array $changes = []): array {
    return ['status' => true, 'data' => array_replace([
        'reference' => $reference, 'status' => 'success', 'amount' => 1000, 'currency' => 'ZAR', 'domain' => 'test',
    ], $changes)];
};

try {
    putenv('APP_ENV=local');
    $secret = 'sk_test_security_fixture_key';
    putenv('PAYSTACK_SECRET_KEY=' . $secret);

    $paidFixture = $makeOrder(1);
    $paymentService->start($paidFixture['customer'], $paidFixture['order'], 'security-' . $tag . '-1@example.test');
    $paid = $paymentRepository->latestForCustomerOrder($paidFixture['customer'], $paidFixture['order']);
    $reference = (string) $paid['provider_reference'];
    $gateway->verification[$reference] = $successResponse($reference);
    $check($paymentService->verifyForCustomer($paidFixture['customer'], $reference) === 'succeeded',
        'Authoritative success confirms the synthetic order.');
    $paidBefore = $paymentRepository->byReference($reference);
    $stockBefore = (int) $db->query('SELECT on_hand FROM inventory WHERE product_id=' . $paidFixture['product'])->fetch_row()[0];
    $check($paidBefore['payment_state'] === 'succeeded' && $paidBefore['order_state'] === 'confirmed' && $stockBefore === 1,
        'Success consumes the single held unit exactly once.');

    $gateway->verification[$reference] = $successResponse($reference, ['amount' => 999]);
    $mismatchState = $paymentService->verifyForCustomer($paidFixture['customer'], $reference);
    $paidAfterMismatch = $paymentRepository->byReference($reference);
    $stockAfterMismatch = (int) $db->query('SELECT on_hand FROM inventory WHERE product_id=' . $paidFixture['product'])->fetch_row()[0];
    $check($mismatchState === 'succeeded' && $paidAfterMismatch['payment_state'] === 'succeeded'
        && $paidAfterMismatch['order_state'] === 'confirmed' && $stockAfterMismatch === 1,
        'A later amount mismatch cannot downgrade a paid order or consume stock again.');

    $gateway->verification[$reference] = $successResponse($reference);
    $recoveredState = $paymentService->verifyForCustomer($paidFixture['customer'], $reference);
    $paidAfterRecovery = $paymentRepository->byReference($reference);
    $check($recoveredState === 'succeeded' && $paidAfterRecovery['payment_state'] === 'succeeded'
        && $paidAfterRecovery['order_state'] === 'confirmed',
        'A later matching success preserves the confirmed paid order.');

    $failureFixture = $makeOrder(2);
    $paymentService->start($failureFixture['customer'], $failureFixture['order'], 'security-' . $tag . '-2@example.test');
    $failedPayment = $paymentRepository->latestForCustomerOrder($failureFixture['customer'], $failureFixture['order']);
    $failedReference = (string) $failedPayment['provider_reference'];
    $gateway->verification[$failedReference] = $successResponse($failedReference);
    $paymentService->verifyForCustomer($failureFixture['customer'], $failedReference);
    $gateway->verification[$failedReference] = $successResponse($failedReference, ['status' => 'failed']);
    $paymentService->verifyForCustomer($failureFixture['customer'], $failedReference);
    $failedConflict = $paymentRepository->byReference($failedReference);
    $failureStock = (int) $db->query('SELECT on_hand FROM inventory WHERE product_id=' . $failureFixture['product'])->fetch_row()[0];
    $check($failedConflict['payment_state'] === 'succeeded' && $failedConflict['order_state'] === 'confirmed' && $failureStock === 1,
        'A later failure result cannot unpay a confirmed order or restore its consumed stock.');

    $uncertainFixture = $makeOrder(3);
    $paymentService->start($uncertainFixture['customer'], $uncertainFixture['order'], 'security-' . $tag . '-3@example.test');
    $uncertainPayment = $paymentRepository->latestForCustomerOrder($uncertainFixture['customer'], $uncertainFixture['order']);
    $uncertainReference = (string) $uncertainPayment['provider_reference'];
    $gateway->verification[$uncertainReference] = $successResponse($uncertainReference);
    $paymentService->verifyForCustomer($uncertainFixture['customer'], $uncertainReference);
    $gateway->verificationErrors[$uncertainReference] = new PaystackException('synthetic verification timeout', true);
    try {
        $paymentService->verifyForCustomer($uncertainFixture['customer'], $uncertainReference);
    } catch (PaystackException) {
        // The result is uncertain; the persisted successful payment must remain immutable.
    }
    unset($gateway->verificationErrors[$uncertainReference]);
    $uncertainStored = $paymentRepository->byReference($uncertainReference);
    $uncertainStock = (int) $db->query('SELECT on_hand FROM inventory WHERE product_id=' . $uncertainFixture['product'])->fetch_row()[0];
    $check($uncertainStored['payment_state'] === 'succeeded' && $uncertainStored['order_state'] === 'confirmed' && $uncertainStock === 1,
        'A later verification timeout cannot downgrade a confirmed payment or alter consumed stock.');

    $raceFixture = $makeOrder(4);
    $gateway->duringInitialize = static function (string $raceReference) use ($gateway, $paymentService, $raceFixture, $successResponse): void {
        $gateway->verification[$raceReference] = $successResponse($raceReference);
        $paymentService->verifyForCustomer($raceFixture['customer'], $raceReference);
    };
    $gateway->definitiveInitializeFailure = true;
    $raceStartResult = null;
    try {
        $raceStartResult = $paymentService->start($raceFixture['customer'], $raceFixture['order'], 'security-' . $tag . '-4@example.test');
    } catch (PaystackException) {
        // Provider initialization fails after an authoritative success callback raced it.
    }
    $gateway->definitiveInitializeFailure = false;
    $racePayment = $paymentRepository->latestForCustomerOrder($raceFixture['customer'], $raceFixture['order']);
    $raceStored = $paymentRepository->byReference((string) $racePayment['provider_reference']);
    $raceStock = (int) $db->query('SELECT on_hand FROM inventory WHERE product_id=' . $raceFixture['product'])->fetch_row()[0];
    $check(($raceStartResult['state'] ?? null) === 'succeeded' && $raceStored['payment_state'] === 'succeeded'
        && $raceStored['order_state'] === 'confirmed' && $raceStock === 1,
        'A late initialization rejection cannot cancel an order confirmed by a concurrent verified callback.');

    $statement = $db->prepare("INSERT INTO customers(email,password_hash,name,role,account_state) VALUES(?, 'synthetic-hash', 'Security admin', 'admin', 'active')");
    $statement->execute(['security-admin-' . $tag . '@example.test']);
    $adminId = (int) $db->insert_id;
    $statement->close();
    $admin = ['customer_id' => $adminId, 'role' => 'admin'];
    $operations = new OperationsService(new OperationsRepository($db), $inventory, $transactions, new AdminAccess($db));
    $fulfilSucceeded = true;
    try {
        $operations->fulfilOrder($admin, $paidFixture['order']);
    } catch (ValidationException) {
        $fulfilSucceeded = false;
    }
    $check($fulfilSucceeded, 'A matching paid order remains eligible for administrator fulfilment.');

    $forgedFixture = $makeOrder(5);
    $paymentService->start($forgedFixture['customer'], $forgedFixture['order'], 'security-' . $tag . '-5@example.test');
    $forgedPayment = $paymentRepository->latestForCustomerOrder($forgedFixture['customer'], $forgedFixture['order']);
    $forgedReference = (string) $forgedPayment['provider_reference'];
    $gateway->verification[$forgedReference] = $successResponse($forgedReference, ['amount' => 999]);
    $raw = json_encode(['event' => 'charge.success', 'data' => ['reference' => $forgedReference]], JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha512', $raw, $secret);
    $forgedResult = $paymentService->handleWebhook($raw, $signature, $secret);
    $forgedStored = $paymentRepository->byReference($forgedReference);
    $forgedStock = (int) $db->query('SELECT on_hand FROM inventory WHERE product_id=' . $forgedFixture['product'])->fetch_row()[0];
    $forgedHold = $db->query('SELECT h.state FROM retail_holds h JOIN order_items i ON i.order_item_id=h.order_item_id WHERE i.order_id=' . $forgedFixture['order'])->fetch_row()[0];
    $check($forgedResult['accepted'] && $forgedResult['state'] === 'reconciliation_required'
        && $forgedStored['payment_state'] !== 'succeeded' && $forgedStored['order_state'] !== 'confirmed'
        && $forgedStock === 2 && $forgedHold === 'reconciliation_required',
        'A correctly signed event with a provider amount mismatch cannot confirm an unpaid order or consume stock.');

    echo "Security payment checks: $checks passed, $failures failed.\n";
    if ($failures > 0) {
        exit(1);
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Security payment checks aborted after ' . $checks . ' checks: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    $db->close();
}
