<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Admin\AdminAccess;
use Verdant\Admin\OperationsRepository;
use Verdant\Admin\OperationsService;
use Verdant\Inventory\CapacityRepository;
use Verdant\Inventory\InventoryRepository;
use Verdant\Payments\PaystackException;
use Verdant\Payments\PaystackGateway;
use Verdant\Payments\PaymentRepository;
use Verdant\Payments\PaymentService;
use Verdant\Payments\RentalPaymentRepository;
use Verdant\Payments\RentalPaymentService;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Persistence\TransactionManager;
use Verdant\Rentals\RentalRepository;
use Verdant\Rentals\RentalService;
use Verdant\Retail\OrderRepository;
use Verdant\Retail\OrderService;
use Verdant\Retail\RetailAvailabilityRepository;
use Verdant\Support\ValidationException;

if (PHP_SAPI !== 'cli' || preg_match('/^verdant_test_[a-z0-9_]+$/', getenv('DB_NAME') ?: '') !== 1) {
    fwrite(STDERR, "Use an isolated verdant_test_ database.\n");
    exit(2);
}

final class IntegrationFailureGateway implements PaystackGateway
{
    public bool $timeoutOnInitialize = false;
    /** @var array<string, array<string, mixed>> */
    public array $verification = [];

    public function initialize(string $email, int $amountMinor, string $reference, string $callbackUrl): array
    {
        if ($this->timeoutOnInitialize) {
            throw new PaystackException('synthetic timeout', true);
        }
        return ['status' => true, 'data' => [
            'reference' => $reference,
            'authorization_url' => 'https://checkout.paystack.com/integration-' . $reference,
        ]];
    }

    public function verify(string $reference): array
    {
        return $this->verification[$reference] ?? throw new PaystackException('Missing synthetic verification.', false);
    }
}

$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$tag = bin2hex(random_bytes(6));
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    ++$checks;
    echo "PASS: $label\n";
};
$scalar = static function (string $sql, array $parameters = []) use ($db): mixed {
    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    $value = $statement->get_result()->fetch_row()[0] ?? null;
    $statement->close();
    return $value;
};
$customer = static function (string $suffix, string $role = 'customer') use ($db, $tag): int {
    $statement = $db->prepare("INSERT INTO customers(email,password_hash,name,account_state,role) VALUES(?, 'synthetic-test-hash', ?, 'active', ?)");
    $statement->execute(['failure-' . $tag . '-' . $suffix . '@example.test', 'Failure ' . $suffix, $role]);
    $id = (int) $db->insert_id;
    $statement->close();
    if ($role === 'admin') {
        $allow = $db->prepare('INSERT INTO admin_allowlist (email) VALUES (?)');
        $allow->execute(['failure-' . $tag . '-' . $suffix . '@example.test']);
        $allow->close();
    }
    return $id;
};
$product = static function (string $suffix, int $stock = 2) use ($db, $tag): int {
    $category = $db->prepare('INSERT INTO categories(name) VALUES(?)');
    $category->execute(['Failure synthetic category ' . $tag . '-' . $suffix]);
    $categoryId = (int) $db->insert_id;
    $category->close();
    $statement = $db->prepare("INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor) VALUES(?,?,?,'Synthetic failure fixture','indirect','weekly','non_toxic',1,1,1000,250)");
    $statement->execute([$categoryId, 'FAIL-' . strtoupper($tag . $suffix), 'Failure plant ' . $suffix]);
    $productId = (int) $db->insert_id;
    $statement->close();
    $stockStatement = $db->prepare('INSERT INTO inventory(product_id,on_hand) VALUES(?,?)');
    $stockStatement->execute([$productId, $stock]);
    $stockStatement->close();
    return $productId;
};

try {
    $customerId = $customer('customer');
    $adminId = $customer('admin', 'admin');
    $actor = ['customer_id' => $adminId, 'role' => 'admin'];
    $transactions = new TransactionManager($db);
    $inventory = new InventoryRepository($db);
    $capacity = new CapacityRepository($db);
    $orders = new OrderRepository($db);
    $rentals = new RentalRepository($db);
    $orderService = new OrderService($orders, $inventory, new RetailAvailabilityRepository($db), $transactions);
    $rentalService = new RentalService($rentals, $inventory, $capacity, $transactions);
    $gateway = new IntegrationFailureGateway();
    $paymentRepository = new PaymentRepository($db);
    $paymentService = new PaymentService($paymentRepository, $inventory, $transactions, $gateway, 'http://127.0.0.1:8088/payment/return');
    $rentalPaymentRepository = new RentalPaymentRepository($db);
    $rentalPaymentService = new RentalPaymentService($rentalPaymentRepository, $inventory, $capacity, $transactions, $gateway, 'http://127.0.0.1:8088/rental/payment/return');
    $operations = new OperationsService(new OperationsRepository($db), $inventory, $transactions, new AdminAccess($db));
    $businessDate = (new DateTimeImmutable('now', new DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
    $rentalDate = (new DateTimeImmutable($businessDate))->modify('+12 days')->format('Y-m-d');
    $rentalEnd = (new DateTimeImmutable($rentalDate))->modify('+1 day')->format('Y-m-d');
    $email = 'failure-' . $tag . '-customer@example.test';

    // A held rental consumes the common availability budget and rejects an oversized retail basket atomically.
    $sharedProduct = $product('rental-held');
    $heldRental = $rentalService->createPending($customerId, $sharedProduct, 2, $rentalDate, $rentalDate, bin2hex(random_bytes(24)));
    $check($capacity->retailAvailable($sharedProduct) === 0, 'Pending rental hold protects all shared units from retail checkout.');
    $beforeOrders = (int) $scalar('SELECT COUNT(*) FROM sales_orders WHERE customer_id=?', [$customerId]);
    $beforeHolds = (int) $scalar('SELECT COUNT(*) FROM retail_holds h JOIN order_items i ON i.order_item_id=h.order_item_id WHERE i.product_id=?', [$sharedProduct]);
    try {
        $orderService->createPending($customerId, [$sharedProduct => 1], bin2hex(random_bytes(32)));
        throw new RuntimeException('Retail checkout exceeded capacity reserved by a rental.');
    } catch (ValidationException) {
        $check((int) $scalar('SELECT COUNT(*) FROM sales_orders WHERE customer_id=?', [$customerId]) === $beforeOrders
            && (int) $scalar('SELECT COUNT(*) FROM retail_holds h JOIN order_items i ON i.order_item_id=h.order_item_id WHERE i.product_id=?', [$sharedProduct]) === $beforeHolds,
            'Rejected retail basket creates neither an order nor a stock hold.');
    }

    // An authoritative retail failure releases the hold so rental capacity can be booked.
    $retailFailProduct = $product('retail-failed');
    $failedOrder = $orderService->createPending($customerId, [$retailFailProduct => 2], bin2hex(random_bytes(32)));
    $paymentService->start($customerId, (int) $failedOrder['order_id'], $email);
    $failedPayment = $paymentRepository->latestForCustomerOrder($customerId, (int) $failedOrder['order_id']);
    $failedReference = (string) $failedPayment['provider_reference'];
    $gateway->verification[$failedReference] = ['status' => true, 'data' => [
        'reference' => $failedReference, 'status' => 'failed', 'amount' => 2000, 'currency' => 'ZAR', 'domain' => 'test',
    ]];
    $check($paymentService->verifyForCustomer($customerId, $failedReference) === 'failed'
        && $scalar('SELECT state FROM retail_holds h JOIN order_items i ON i.order_item_id=h.order_item_id WHERE i.order_id=?', [(int) $failedOrder['order_id']]) === 'released',
        'Definitive retail payment failure cancels the order and releases its shared-stock hold.');
    $replacementRental = $rentalService->createPending($customerId, $retailFailProduct, 2, $rentalDate, $rentalDate, bin2hex(random_bytes(24)));
    $check((int) $replacementRental['rental_id'] > 0 && $capacity->availableForPeriod($retailFailProduct, $rentalDate, $rentalEnd) === 0,
        'Released retail stock can be reserved by rental checkout.');

    // An authoritative rental failure releases its allocation for retail checkout.
    $rentalFailProduct = $product('rental-failed');
    $failedRental = $rentalService->createPending($customerId, $rentalFailProduct, 2, $rentalDate, $rentalDate, bin2hex(random_bytes(24)));
    $failedRentalId = (int) $failedRental['rental_id'];
    $rentalPaymentService->start($customerId, $failedRentalId, $email);
    $failedRentalPayment = $rentalPaymentRepository->latestForRental($failedRentalId);
    $failedRentalReference = (string) $failedRentalPayment['provider_reference'];
    $gateway->verification[$failedRentalReference] = ['status' => true, 'data' => [
        'reference' => $failedRentalReference, 'status' => 'failed', 'amount' => 500, 'currency' => 'ZAR', 'domain' => 'test',
    ]];
    $check($rentalPaymentService->verifyForCustomer($customerId, $failedRentalReference) === 'failed'
        && $scalar('SELECT state FROM rental_allocations a JOIN rental_items i ON i.rental_item_id=a.rental_item_id WHERE i.rental_id=?', [$failedRentalId]) === 'released',
        'Definitive rental payment failure cancels the booking and releases date capacity.');
    $replacementOrder = $orderService->createPending($customerId, [$rentalFailProduct => 2], bin2hex(random_bytes(32)));
    $check($replacementOrder['state'] === 'pending_payment' && $capacity->retailAvailable($rentalFailProduct) === 0,
        'Released rental capacity can be held by a retail order.');

    // An uncertain initialization keeps a rental protected; later owned verification and signed callback delivery converge once.
    $uncertainProduct = $product('rental-uncertain');
    $uncertainRental = $rentalService->createPending($customerId, $uncertainProduct, 2, $rentalDate, $rentalDate, bin2hex(random_bytes(24)));
    $uncertainRentalId = (int) $uncertainRental['rental_id'];
    $gateway->timeoutOnInitialize = true;
    try {
        $rentalPaymentService->start($customerId, $uncertainRentalId, $email);
        throw new RuntimeException('Synthetic provider timeout was not surfaced.');
    } catch (PaystackException $error) {
        $check($error->uncertain, 'Fake gateway initialization timeout is reported as uncertain.');
    }
    $gateway->timeoutOnInitialize = false;
    $uncertainPayment = $rentalPaymentRepository->latestForRental($uncertainRentalId);
    $uncertainReference = (string) $uncertainPayment['provider_reference'];
    $check($uncertainPayment['state'] === 'reconciliation_required'
        && $scalar('SELECT state FROM rental_allocations a JOIN rental_items i ON i.rental_item_id=a.rental_item_id WHERE i.rental_id=?', [$uncertainRentalId]) === 'reconciliation_required'
        && $capacity->retailAvailable($uncertainProduct) === 0,
        'Uncertain rental outcome retains reconciliation state and blocks competing retail checkout.');
    $gateway->verification[$uncertainReference] = ['status' => true, 'data' => [
        'reference' => $uncertainReference, 'status' => 'success', 'amount' => 500, 'currency' => 'ZAR', 'domain' => 'test',
    ]];
    $check($rentalPaymentService->verifyForCustomer($customerId, $uncertainReference) === 'succeeded',
        'Owned late verification resolves the uncertain rental as success.');
    $raw = json_encode(['event' => 'charge.success', 'data' => ['reference' => $uncertainReference]], JSON_THROW_ON_ERROR);
    $secret = 'integration-fake-gateway-webhook-secret';
    $signature = hash_hmac('sha512', $raw, $secret);
    $callback = $rentalPaymentService->handleWebhook($raw, $signature, $secret);
    $duplicateCallback = $rentalPaymentService->handleWebhook($raw, $signature, $secret);
    $uncertainItemId = (int) $uncertainRental['items'][0]['rental_item_id'];
    $check($callback['accepted'] && $callback['state'] === 'succeeded' && $duplicateCallback['state'] === 'duplicate'
        && (int) $scalar('SELECT COUNT(*) FROM rental_issues WHERE rental_item_id=?', [$uncertainItemId]) === 0
        && $capacity->outstandingIssued($uncertainProduct) === 0,
        'Same signed callback is idempotent after owned verification and does not create issue or duplicate physical stock.');
    $check($scalar('SELECT state FROM rentals WHERE rental_id=?', [$uncertainRentalId]) === 'confirmed'
        && $scalar('SELECT state FROM rental_allocations WHERE rental_item_id=?', [$uncertainItemId]) === 'confirmed'
        && (int) $scalar('SELECT on_hand FROM inventory WHERE product_id=?', [$uncertainProduct]) === 2,
        'Late success confirms exactly one rental allocation while preserving physical on-hand stock.');

    // Wrong amount, currency, or provider domain must leave retail funds/stock unresolved and unfulfillable.
    foreach ([
        'amount' => ['amount' => 999, 'currency' => 'ZAR', 'domain' => 'test'],
        'currency' => ['amount' => 1000, 'currency' => 'USD', 'domain' => 'test'],
        'domain' => ['amount' => 1000, 'currency' => 'ZAR', 'domain' => 'live'],
    ] as $kind => $details) {
        $mismatchProduct = $product('mismatch-' . $kind);
        $mismatchOrder = $orderService->createPending($customerId, [$mismatchProduct => 1], bin2hex(random_bytes(32)));
        $mismatchOrderId = (int) $mismatchOrder['order_id'];
        $paymentService->start($customerId, $mismatchOrderId, $email);
        $mismatchPayment = $paymentRepository->latestForCustomerOrder($customerId, $mismatchOrderId);
        $mismatchReference = (string) $mismatchPayment['provider_reference'];
        $gateway->verification[$mismatchReference] = ['status' => true, 'data' => [
            'reference' => $mismatchReference, 'status' => 'success',
            'amount' => $details['amount'], 'currency' => $details['currency'], 'domain' => $details['domain'],
        ]];
        $outcome = $paymentService->verifyForCustomer($customerId, $mismatchReference);
        $check($outcome === 'reconciliation_required'
            && $scalar('SELECT state FROM sales_orders WHERE order_id=?', [$mismatchOrderId]) === 'reconciliation_required'
            && $scalar('SELECT state FROM order_payments WHERE order_id=?', [$mismatchOrderId]) === 'reconciliation_required'
            && $scalar('SELECT state FROM retail_holds h JOIN order_items i ON i.order_item_id=h.order_item_id WHERE i.order_id=?', [$mismatchOrderId]) === 'reconciliation_required',
            ucfirst($kind) . ' mismatch retains the retail order and shared-stock hold for reconciliation.');
        try {
            $operations->fulfilOrder($actor, $mismatchOrderId);
            throw new RuntimeException('A mismatched provider payment was fulfilled.');
        } catch (ValidationException) {
            $check($scalar('SELECT fulfilled_at IS NULL FROM sales_orders WHERE order_id=?', [$mismatchOrderId]) == 1
                && (int) $scalar('SELECT on_hand FROM inventory WHERE product_id=?', [$mismatchProduct]) === 2,
                ucfirst($kind) . ' mismatch cannot be fulfilled or consume physical stock.');
        }
    }

    echo "Integration failure checks: $checks passed, 0 failed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Integration failure checks failed after ' . $checks . ' checks: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    $db->close();
}
