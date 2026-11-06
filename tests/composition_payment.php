<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Compositions\CompositionPaymentRepository;
use Verdant\Compositions\CompositionPaymentService;
use Verdant\Compositions\CompositionLinkRepository;
use Verdant\Compositions\CompositionRepository;
use Verdant\Compositions\CompositionService;
use Verdant\Admin\AdminAccess;
use Verdant\Admin\OperationsRepository;
use Verdant\Admin\OperationsService;
use Verdant\Inventory\CapacityRepository;
use Verdant\Inventory\InventoryRepository;
use Verdant\Notifications\CommerceNotifier;
use Verdant\Http\CompositionPaymentController;
use Verdant\Payments\PaystackException;
use Verdant\Payments\PaystackGateway;
use Verdant\Payments\PaymentRepository;
use Verdant\Payments\PaymentService;
use Verdant\Payments\RentalPaymentRepository;
use Verdant\Payments\RentalPaymentService;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Persistence\TransactionManager;
use Verdant\Retail\OrderRepository;
use Verdant\Retail\OrderService;
use Verdant\Retail\RetailAvailabilityRepository;
use Verdant\Rentals\RentalRepository;
use Verdant\Rentals\RentalService;

if (PHP_SAPI !== 'cli' || preg_match('/^verdant_test_[a-z0-9_]+$/', getenv('DB_NAME') ?: '') !== 1) {
    fwrite(STDERR, "Use an isolated verdant_test_ database.\n");
    exit(2);
}

final class FakeCompositionPaymentGateway implements PaystackGateway
{
    public int $initializeCalls = 0;
    public int $verifyCalls = 0;
    public bool $uncertainInitialize = false;
    public bool $definitiveInitializeFailure = false;
    public bool $uncertainVerify = false;
    public int $lastAmount = 0;
    /** @var array<string,array<string,mixed>> */
    public array $verification = [];

    public function initialize(string $email, int $amountMinor, string $reference, string $callbackUrl): array
    {
        ++$this->initializeCalls;
        $this->lastAmount = $amountMinor;
        if ($this->uncertainInitialize) { throw new PaystackException('synthetic timeout', true); }
        if ($this->definitiveInitializeFailure) { throw new PaystackException('synthetic rejection', false); }
        return ['status' => true, 'data' => [
            'reference' => $reference,
            'authorization_url' => 'https://checkout.paystack.com/test-' . $reference,
        ]];
    }

    public function verify(string $reference): array
    {
        ++$this->verifyCalls;
        if ($this->uncertainVerify) { throw new PaystackException('synthetic verify timeout', true); }
        return $this->verification[$reference] ?? ['status' => true, 'data' => [
            'reference' => $reference, 'status' => 'pending', 'amount' => 0, 'currency' => 'ZAR', 'domain' => 'test',
        ]];
    }
}

$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$tag = strtoupper(bin2hex(random_bytes(5)));
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
    echo "PASS: $message\n";
};
$one = static function (mysqli $connection, string $sql, array $parameters = []): ?array {
    $statement = $connection->prepare($sql);
    $statement->execute($parameters);
    try { return $statement->get_result()->fetch_assoc() ?: null; }
    finally { $statement->close(); }
};
$insert = static function (mysqli $connection, string $sql, array $parameters = []): int {
    $statement = $connection->prepare($sql);
    $statement->execute($parameters);
    $statement->close();
    return (int) $connection->insert_id;
};
$customer = $insert($db,
    "INSERT INTO customers(email,password_hash,name,role,account_state) VALUES(?, 'test-hash', 'Payment owner', 'customer', 'active')",
    ['composition-payment-' . strtolower($tag) . '@example.test'],
);
$otherCustomer = $insert($db,
    "INSERT INTO customers(email,password_hash,name,role,account_state) VALUES(?, 'test-hash', 'Other owner', 'customer', 'active')",
    ['composition-payment-other-' . strtolower($tag) . '@example.test'],
);
$adminId = $insert($db,
    "INSERT INTO customers(email,password_hash,name,role,account_state) VALUES(?, 'test-hash', 'Payment administrator', 'admin', 'active')",
    ['composition-payment-admin-' . strtolower($tag) . '@example.test'],
);
$category = $insert($db, 'INSERT INTO categories(name) VALUES(?)', ['Composition payment ' . $tag]);
$product = static function (string $suffix, bool $buy, bool $rent, int $stock, int $retail, int $daily,
    string $kind = 'plant', string $subtype = 'other') use ($db, $insert, $category, $tag): int {
    $id = $insert($db,
        "INSERT INTO products(category_id,sku,name,description,product_kind,accessory_subtype,selling_unit,
             light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor)
         VALUES(?,?,?,'Payment test leaf',?,?,'Each','unknown','unknown','unknown',?,?,?,?)",
        [$category, 'CMP-PAY-' . $tag . '-' . $suffix, 'Payment ' . $suffix, $kind, $subtype, (int) $buy, (int) $rent,
            $buy ? $retail : null, $rent ? $daily : null],
    );
    $insert($db, 'INSERT INTO inventory(product_id,on_hand) VALUES(?,?)', [$id, $stock]);
    return $id;
};
$makeDefinition = static function (string $code, array $rules) use ($db, $insert): void {
    $definitionId = $insert($db,
        "INSERT INTO composition_definitions(definition_code,name,arrangement_kind,strategy)
         VALUES(?,?,'plant_arrangement','custom')",
        [$code, 'Payment ' . $code],
    );
    foreach ($rules as $index => $rule) {
        $componentId = $insert($db,
            "INSERT INTO composition_definition_components
                (definition_id,component_key,label,component_role,source_type,required,min_selections,max_selections,
                 min_quantity,max_quantity,permitted_mode,sort_order)
             VALUES(?,?,?,?,'product',1,1,1,1,5,?,?)",
            [$definitionId, $rule['key'], $rule['label'], $rule['role'], $rule['mode'], ($index + 1) * 10],
        );
        $insert($db, 'INSERT INTO composition_component_product_allowlist(component_id,product_id) VALUES(?,?)',
            [$componentId, $rule['product_id']]);
    }
};
$service = new CompositionService(
    new CompositionRepository($db), new InventoryRepository($db), new CapacityRepository($db), new TransactionManager($db),
);
$notifier = new CommerceNotifier($db);
$gateway = new FakeCompositionPaymentGateway();
$paymentService = new CompositionPaymentService(
    new CompositionPaymentRepository($db), new InventoryRepository($db), new CapacityRepository($db),
    new TransactionManager($db), $gateway, 'http://127.0.0.1:8088/payment/return', $notifier,
);
$futureStart = (new DateTimeImmutable('now', new DateTimeZone('Africa/Johannesburg')))->modify('+10 days')->format('Y-m-d');
$futureEnd = (new DateTimeImmutable($futureStart))->modify('+1 day')->format('Y-m-d');
$definitionPrefix = 'test-payment-' . strtolower($tag) . '-';
$plant = $product('SALE', true, false, 30, 2100, 0);
$pot = $product('RENT', false, true, 30, 0, 500, 'accessory', 'pot');
$saleOnly = $product('BUY', true, false, 3, 1500, 0);
$rentalOnly = $product('RENTONLY', false, true, 3, 0, 400, 'accessory', 'pot');
$lateSale = $product('LATE', true, false, 1, 900, 0);
$makeDefinition($definitionPrefix . 'mixed', [
    ['key' => 'plant', 'label' => 'Plant', 'role' => 'plant', 'mode' => 'sale', 'product_id' => $plant],
    ['key' => 'pot', 'label' => 'Pot', 'role' => 'pot', 'mode' => 'rent', 'product_id' => $pot],
]);
$makeDefinition($definitionPrefix . 'sale', [
    ['key' => 'plant', 'label' => 'Plant', 'role' => 'plant', 'mode' => 'sale', 'product_id' => $saleOnly],
]);
$makeDefinition($definitionPrefix . 'rental', [
    ['key' => 'pot', 'label' => 'Pot', 'role' => 'pot', 'mode' => 'rent', 'product_id' => $rentalOnly],
]);
$makeDefinition($definitionPrefix . 'late', [
    ['key' => 'plant', 'label' => 'Plant', 'role' => 'plant', 'mode' => 'sale', 'product_id' => $lateSale],
    ['key' => 'pot', 'label' => 'Pot', 'role' => 'pot', 'mode' => 'rent', 'product_id' => $pot],
]);
$mixedSelection = [
    ['component_key' => 'plant', 'product_id' => $plant, 'quantity' => 1, 'mode' => 'sale'],
    ['component_key' => 'pot', 'product_id' => $pot, 'quantity' => 1, 'mode' => 'rent',
        'start_date' => $futureStart, 'end_date_inclusive' => $futureStart],
];
$readState = static function (int $orderId) use ($db, $one): array {
    return $one($db,
        'SELECT cc.state AS checkout_state, so.state AS order_state, r.state AS rental_state,
                op.state AS payment_state, op.amount_minor, cc.total_minor
         FROM composition_checkouts cc JOIN sales_orders so ON so.order_id=cc.order_id
         LEFT JOIN rentals r ON r.rental_id=cc.rental_id LEFT JOIN order_payments op ON op.order_payment_id=cc.order_payment_id
         WHERE cc.order_id=?', [$orderId],
    ) ?? [];
};

try {
    $mixed = $service->createPending($customer, $definitionPrefix . 'mixed', $mixedSelection, 'mixed-' . strtolower($tag) . '-key');
    $expectedTotal = (int) $mixed['total_minor'];
    $ready = $paymentService->start($customer, (int) $mixed['order_id'], 'payment-owner@example.test');
    $check($ready['state'] === 'ready' && $gateway->lastAmount === $expectedTotal,
        'Mixed checkout initializes one TEST attempt for the saved aggregate total.');
    $paymentRow = $one($db, 'SELECT order_payment_id,amount_minor FROM order_payments WHERE order_id=?', [(int) $mixed['order_id']]);
    $check((int) $paymentRow['amount_minor'] === $expectedTotal
        && (int) ($one($db, 'SELECT COUNT(*) AS n FROM rental_payments WHERE rental_id=?', [(int) $mixed['rental_id']])['n'] ?? 0) === 0,
        'Composition links exactly one aggregate order payment and creates no child rental payment.');
    $sameReady = $paymentService->start($customer, (int) $mixed['order_id'], 'payment-owner@example.test');
    $check($sameReady['authorization_url'] === $ready['authorization_url'] && $gateway->initializeCalls === 1,
        'Repeated start returns the same hosted checkout without initializing a second provider attempt.');
    $reference = (string) $ready['reference'];
    $compositionPaymentController = new CompositionPaymentController($service,
        new CompositionPaymentRepository($db), new InventoryRepository($db), new CapacityRepository($db), new TransactionManager($db));
    $check($compositionPaymentController->handlesReference($reference)
        && !$compositionPaymentController->handlesReference('verdant-composition-not-persisted'),
        'Shared payment callback routing requires an existing persisted aggregate reference.');
    $gateway->verification[$reference] = ['status' => true, 'data' => [
        'reference' => $reference, 'status' => 'success', 'amount' => $expectedTotal, 'currency' => 'ZAR', 'domain' => 'test',
    ]];
    $check($paymentService->verifyForCustomer($customer, $reference) === 'succeeded',
        'Matching TEST reference, amount, currency and domain confirms the aggregate payment.');
    $lineStates = $db->prepare(
        'SELECT cl.mode, h.state AS hold_state, a.state AS allocation_state
         FROM composition_checkout_lines cl LEFT JOIN retail_holds h ON h.order_item_id=cl.order_item_id
         LEFT JOIN rental_allocations a ON a.rental_item_id=cl.rental_item_id
         WHERE cl.composition_checkout_id=? ORDER BY cl.mode',
    );
    $lineStates->execute([(int) $mixed['composition_checkout_id']]);
    $rows = $lineStates->get_result()->fetch_all(MYSQLI_ASSOC);
    $lineStates->close();
    $state = $readState((int) $mixed['order_id']);
    $stock = (int) $one($db, 'SELECT on_hand FROM inventory WHERE product_id=?', [$plant])['on_hand'];
    $check($state['checkout_state'] === 'confirmed' && $state['order_state'] === 'confirmed'
        && $state['rental_state'] === 'confirmed' && $state['payment_state'] === 'succeeded'
        && $stock === 29 && count($rows) === 2
        && in_array('consumed', array_column($rows, 'hold_state'), true)
        && in_array('confirmed', array_column($rows, 'allocation_state'), true),
        'Success consumes purchased stock and confirms the dated rental in the same parent outcome.');
    $aggregateEvent = $one($db,
        "SELECT commerce_event_id,context_json FROM commerce_notification_events WHERE event_key=? AND event_kind='composition_confirmed' AND composition_checkout_id=?",
        ['composition-confirmed:' . (int) $mixed['composition_checkout_id'], (int) $mixed['composition_checkout_id']],
    );
    $aggregateContext = $aggregateEvent === null ? [] : json_decode($aggregateEvent['context_json'], true, 32, JSON_THROW_ON_ERROR);
    $check($aggregateEvent !== null && $aggregateContext['status'] === 'confirmed'
        && (int) $aggregateContext['record_id'] === (int) $mixed['composition_checkout_id']
        && count($aggregateContext['items']) === 2
        && (int) $one($db, 'SELECT COUNT(*) AS n FROM email_outbox WHERE commerce_event_id=? AND recipient_customer_id IN (?,?)',
            [(int) $aggregateEvent['commerce_event_id'], $customer, $adminId])['n'] === 2
        && (int) $one($db, "SELECT COUNT(*) AS n FROM commerce_notification_events WHERE order_id=? AND event_kind='purchase_confirmed'", [(int) $mixed['order_id']])['n'] === 0
        && (int) $one($db, "SELECT COUNT(*) AS n FROM commerce_notification_events WHERE rental_id=? AND event_kind='rental_confirmed'", [(int) $mixed['rental_id']])['n'] === 0,
        'Mixed checkout records one aggregate snapshot for the customer and admin without child order/rental confirmations.');
    $check($paymentService->verifyForCustomer($customer, $reference) === 'succeeded'
        && (int) $one($db, 'SELECT on_hand FROM inventory WHERE product_id=?', [$plant])['on_hand'] === 29
        && (int) $one($db, 'SELECT COUNT(*) AS n FROM commerce_notification_events WHERE event_key=?',
            ['composition-confirmed:' . (int) $mixed['composition_checkout_id']])['n'] === 1,
        'Repeated confirmation is idempotent and never consumes sale stock twice.');
    $eventBody = json_encode(['event' => 'charge.success', 'data' => ['reference' => $reference]], JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha512', $eventBody, 'composition-test-secret');
    $webhookFirst = $paymentService->handleWebhook($eventBody, $signature, 'composition-test-secret');
    $webhookRepeat = $paymentService->handleWebhook($eventBody, $signature, 'composition-test-secret');
    $check(($webhookFirst['state'] ?? null) === 'succeeded' && ($webhookRepeat['state'] ?? null) === 'duplicate'
        && (int) $one($db, "SELECT COUNT(*) AS n FROM provider_events WHERE provider_code='paystack' AND event_reference=?", ['charge.success:' . $reference])['n'] === 1,
        'Duplicate signed webhook is deduplicated through the existing provider event ledger.');
    $check((int) $one($db, 'SELECT COUNT(*) AS n FROM commerce_notification_events WHERE event_key=?',
        ['composition-confirmed:' . (int) $mixed['composition_checkout_id']])['n'] === 1,
        'Repeated signed callbacks retain exactly one aggregate commerce event.');
    $gateway->verification[$reference]['data']['status'] = 'failed';
    $check($paymentService->verifyForCustomer($customer, $reference) === 'succeeded'
        && $readState((int) $mixed['order_id'])['payment_state'] === 'succeeded',
        'A later conflicting provider failure cannot reverse an applied aggregate success.');
    $check($paymentService->verifyForCustomer($customer + 1000, $reference) === 'unavailable',
        'A different customer cannot verify the composition payment.');

    foreach (['reference', 'amount', 'currency', 'domain'] as $mismatch) {
        $checkout = $service->createPending($customer, $definitionPrefix . 'mixed', $mixedSelection,
            'mismatch-' . $mismatch . '-' . strtolower($tag));
        $attempt = $paymentService->start($customer, (int) $checkout['order_id'], 'payment-owner@example.test');
        $attemptReference = (string) $attempt['reference'];
        $providerData = ['reference' => $attemptReference, 'status' => 'success',
            'amount' => (int) $checkout['total_minor'], 'currency' => 'ZAR', 'domain' => 'test'];
        if ($mismatch === 'reference') { $providerData['reference'] = 'unrelated-reference'; }
        if ($mismatch === 'amount') { ++$providerData['amount']; }
        if ($mismatch === 'currency') { $providerData['currency'] = 'USD'; }
        if ($mismatch === 'domain') { $providerData['domain'] = 'live'; }
        $gateway->verification[$attemptReference] = ['status' => true, 'data' => $providerData];
        $outcome = $paymentService->verifyForCustomer($customer, $attemptReference);
        $hold = $one($db, 'SELECT h.state FROM retail_holds h JOIN order_items i ON i.order_item_id=h.order_item_id WHERE i.order_id=?', [(int) $checkout['order_id']]);
        $allocation = $one($db, 'SELECT a.state FROM rental_allocations a JOIN rental_items i ON i.rental_item_id=a.rental_item_id WHERE i.rental_id=?', [(int) $checkout['rental_id']]);
        $check($outcome === 'reconciliation_required'
            && $readState((int) $checkout['order_id'])['checkout_state'] === 'reconciliation_required'
            && $hold['state'] === 'reconciliation_required' && $allocation['state'] === 'reconciliation_required',
            'A mismatched provider ' . $mismatch . ' keeps both child reservations for reconciliation.');
        $check((int) $one($db, 'SELECT COUNT(*) AS n FROM commerce_notification_events WHERE composition_checkout_id=?',
            [(int) $checkout['composition_checkout_id']])['n'] === 0,
            'A mismatched provider ' . $mismatch . ' creates no composition confirmation event.');
    }

    $incoherent = $service->createPending($customer, $definitionPrefix . 'mixed', $mixedSelection,
        'incoherent-' . strtolower($tag) . '-key');
    $incoherentReady = $paymentService->start($customer, (int) $incoherent['order_id'], 'payment-owner@example.test');
    $incoherentReference = (string) $incoherentReady['reference'];
    $gateway->verification[$incoherentReference] = ['status' => true, 'data' => [
        'reference' => $incoherentReference, 'status' => 'success', 'amount' => (int) $incoherent['total_minor'], 'currency' => 'ZAR', 'domain' => 'test',
    ]];
    $beforeIncoherentStock = (int) $one($db, 'SELECT on_hand FROM inventory WHERE product_id=?', [$plant])['on_hand'];
    $db->query('UPDATE rentals SET state = \'cancelled\' WHERE rental_id = ' . (int) $incoherent['rental_id']);
    $incoherentOutcome = $paymentService->verifyForCustomer($customer, $incoherentReference);
    $check($incoherentOutcome === 'reconciliation_required'
        && $readState((int) $incoherent['order_id'])['payment_state'] === 'reconciliation_required'
        && (int) $one($db, 'SELECT on_hand FROM inventory WHERE product_id=?', [$plant])['on_hand'] === $beforeIncoherentStock,
        'A success response cannot consume active holds when parent and child headers disagree.');

    $sale = $service->createPending($customer, $definitionPrefix . 'sale', [
        ['component_key' => 'plant', 'product_id' => $saleOnly, 'quantity' => 1, 'mode' => 'sale'],
    ], 'buyonly-' . strtolower($tag) . '-key');
    $saleReady = $paymentService->start($customer, (int) $sale['order_id'], 'payment-owner@example.test');
    $saleReference = (string) $saleReady['reference'];
    $gateway->verification[$saleReference] = ['status' => true, 'data' => [
        'reference' => $saleReference, 'status' => 'success', 'amount' => (int) $sale['total_minor'], 'currency' => 'ZAR', 'domain' => 'test',
    ]];
    $check($paymentService->verifyForCustomer($customer, $saleReference) === 'succeeded'
        && $readState((int) $sale['order_id'])['rental_state'] === null,
        'Buy-only composition settles without creating a rental leg.');

    $rent = $service->createPending($customer, $definitionPrefix . 'rental', [
        ['component_key' => 'pot', 'product_id' => $rentalOnly, 'quantity' => 1, 'mode' => 'rent',
            'start_date' => $futureStart, 'end_date_inclusive' => $futureStart],
    ], 'rentonly-' . strtolower($tag) . '-key');
    $rentReady = $paymentService->start($customer, (int) $rent['order_id'], 'payment-owner@example.test');
    $rentReference = (string) $rentReady['reference'];
    $gateway->verification[$rentReference] = ['status' => true, 'data' => [
        'reference' => $rentReference, 'status' => 'success', 'amount' => (int) $rent['total_minor'], 'currency' => 'ZAR', 'domain' => 'test',
    ]];
    $check($rent['order_role'] === 'payment_anchor'
        && (int) $one($db, 'SELECT COUNT(*) AS n FROM order_items WHERE order_id=?', [(int) $rent['order_id']])['n'] === 0
        && $paymentService->verifyForCustomer($customer, $rentReference) === 'succeeded'
        && (int) $one($db, 'SELECT on_hand FROM inventory WHERE product_id=?', [$rentalOnly])['on_hand'] === 3,
        'Rent-only anchor uses the same aggregate payment while leaving physical stock unchanged.');
    $anchorOperations = new OperationsService(new OperationsRepository($db), new InventoryRepository($db),
        new TransactionManager($db), new AdminAccess($db));
    try {
        $anchorOperations->fulfilOrder(['customer_id' => $adminId], (int) $rent['order_id']);
        $anchorFulfilRejected = false;
    } catch (Throwable) { $anchorFulfilRejected = true; }
    $check($anchorFulfilRejected && $one($db, 'SELECT fulfilled_at FROM sales_orders WHERE order_id=?', [(int) $rent['order_id']])['fulfilled_at'] === null,
        'A rent-only payment anchor cannot be fulfilled as a zero-value retail order.');
    $customerOrderRepository = new OrderRepository($db);
    $adminOrderRepository = new OperationsRepository($db);
    $check($customerOrderRepository->owned($customer, (int) $rent['order_id']) === null
        && !in_array((int) $rent['order_id'], array_map('intval', array_column($customerOrderRepository->forCustomer($customer), 'order_id')), true)
        && $adminOrderRepository->order((int) $rent['order_id']) === null
        && !in_array((int) $rent['order_id'], array_map('intval', array_column($adminOrderRepository->orders(0, 1000), 'order_id')), true),
        'Retail account and admin histories filter rent-only payment anchors from zero-value order records.');

    $lifecycleStart = (new DateTimeImmutable('now', new DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
    $lifecycle = $service->createPending($customer, $definitionPrefix . 'mixed', [
        ['component_key' => 'plant', 'product_id' => $plant, 'quantity' => 1, 'mode' => 'sale'],
        ['component_key' => 'pot', 'product_id' => $pot, 'quantity' => 2, 'mode' => 'rent',
            'start_date' => $lifecycleStart, 'end_date_inclusive' => $lifecycleStart],
    ], 'lifecycle-' . strtolower($tag) . '-key');
    $lifecycleReady = $paymentService->start($customer, (int) $lifecycle['order_id'], 'payment-owner@example.test');
    $lifecycleReference = (string) $lifecycleReady['reference'];
    $gateway->verification[$lifecycleReference] = ['status' => true, 'data' => [
        'reference' => $lifecycleReference, 'status' => 'success', 'amount' => (int) $lifecycle['total_minor'], 'currency' => 'ZAR', 'domain' => 'test',
    ]];
    $check($paymentService->verifyForCustomer($customer, $lifecycleReference) === 'succeeded',
        'Lifecycle fixture confirms its aggregate payment before issuing rental goods.');
    $lifecycleEvent = $one($db,
        'SELECT commerce_event_id,context_json FROM commerce_notification_events WHERE event_key=?',
        ['composition-confirmed:' . (int) $lifecycle['composition_checkout_id']],
    );
    $lifecycleSnapshot = $lifecycleEvent['context_json'] ?? null;
    $check($lifecycleEvent !== null
        && (int) $one($db, 'SELECT COUNT(*) AS n FROM commerce_notification_events WHERE order_id=? AND event_kind=?',
            [(int) $lifecycle['order_id'], 'purchase_confirmed'])['n'] === 0
        && (int) $one($db, 'SELECT COUNT(*) AS n FROM commerce_notification_events WHERE rental_id=? AND event_kind=?',
            [(int) $lifecycle['rental_id'], 'rental_confirmed'])['n'] === 0,
        'Mixed lifecycle checkout creates only its aggregate confirmation event.');
    $rentalService = new RentalService(new RentalRepository($db), new InventoryRepository($db),
        new CapacityRepository($db), new TransactionManager($db), $notifier);
    $rentalService->issue($adminId, 'admin', (int) $lifecycle['rental_id']);
    $check($paymentService->verifyForCustomer($customer, $lifecycleReference) === 'succeeded'
        && $one($db, 'SELECT state FROM rental_allocations WHERE rental_item_id=(SELECT rental_item_id FROM composition_checkout_lines WHERE composition_checkout_id=? AND mode=\'rent\')',
            [(int) $lifecycle['composition_checkout_id']])['state'] === 'issued',
        'A duplicate verified success after rental issue preserves the issued obligation.');
    $lifecycleBody = json_encode(['event' => 'charge.success', 'data' => ['reference' => $lifecycleReference]], JSON_THROW_ON_ERROR);
    $lifecycleSignature = hash_hmac('sha512', $lifecycleBody, 'composition-test-secret');
    $lifecycleWebhook = $paymentService->handleWebhook($lifecycleBody, $lifecycleSignature, 'composition-test-secret');
    $check(($lifecycleWebhook['state'] ?? null) === 'succeeded'
        && $one($db, 'SELECT state FROM rental_allocations WHERE rental_item_id=(SELECT rental_item_id FROM composition_checkout_lines WHERE composition_checkout_id=? AND mode=\'rent\')',
            [(int) $lifecycle['composition_checkout_id']])['state'] === 'issued',
        'A signed duplicate success webhook after issue remains idempotent.');
    $lifecycleRentalItem = (int) $one($db,
        'SELECT rental_item_id FROM composition_checkout_lines WHERE composition_checkout_id=? AND mode=\'rent\'',
        [(int) $lifecycle['composition_checkout_id']])['rental_item_id'];
    $rentalService->return($adminId, 'admin', (int) $lifecycle['rental_id'], $lifecycleRentalItem, 1,
        'composition-return-a-' . strtolower($tag));
    $check($paymentService->verifyForCustomer($customer, $lifecycleReference) === 'succeeded'
        && $one($db, 'SELECT state FROM rental_allocations WHERE rental_item_id=?', [$lifecycleRentalItem])['state'] === 'issued',
        'A repeated success after partial return preserves the outstanding issued rental quantity.');
    $rentalService->return($adminId, 'admin', (int) $lifecycle['rental_id'], $lifecycleRentalItem, 1,
        'composition-return-b-' . strtolower($tag));
    $lifecycleOperations = new OperationsService(new OperationsRepository($db), new InventoryRepository($db),
        new TransactionManager($db), new AdminAccess($db), $notifier);
    $lifecycleOperations->fulfilOrder(['customer_id' => $adminId], (int) $lifecycle['order_id']);
    $check($paymentService->verifyForCustomer($customer, $lifecycleReference) === 'succeeded'
        && $one($db, 'SELECT fulfilled_at FROM sales_orders WHERE order_id=?', [(int) $lifecycle['order_id']])['fulfilled_at'] !== null
        && $lifecycleSnapshot !== null
        && (string) $one($db, 'SELECT context_json FROM commerce_notification_events WHERE commerce_event_id=?',
            [(int) $lifecycleEvent['commerce_event_id']])['context_json'] === $lifecycleSnapshot,
        'The verified sale leg can be fulfilled after rental issue and full return; later callback does not unwind it.');

    $standaloneRetail = new PaymentService(new PaymentRepository($db), new InventoryRepository($db),
        new TransactionManager($db), $gateway, 'http://127.0.0.1:8088/payment/return');
    $standaloneRental = new RentalPaymentService(new RentalPaymentRepository($db), new InventoryRepository($db),
        new CapacityRepository($db), new TransactionManager($db), $gateway, 'http://127.0.0.1:8088/rental/payment/return');
    try { $standaloneRetail->start($customer, (int) $lifecycle['order_id'], 'payment-owner@example.test'); $retailChildGuarded = false; }
    catch (DomainException) { $retailChildGuarded = true; }
    try { $standaloneRental->start($customer, (int) $lifecycle['rental_id'], 'payment-owner@example.test'); $rentalChildGuarded = false; }
    catch (DomainException) { $rentalChildGuarded = true; }
    $check($retailChildGuarded && $rentalChildGuarded,
        'Standalone payment services reject composition-linked sale and rental children.');

    $syntheticLink = [
        'composition_state' => 'confirmed', 'order_state' => 'confirmed', 'rental_id' => 1,
        'rental_state' => 'confirmed', 'payment_state' => 'succeeded', 'currency' => 'ZAR',
        'payment_currency' => 'ZAR', 'payment_amount_minor' => 500, 'total_minor' => 500,
        'customer_id' => 1, 'order_customer_id' => 1, 'rental_customer_id' => 1,
    ];
    $check(CompositionLinkRepository::hasConfirmedPayment($syntheticLink),
        'Paid parent proof remains valid while the rental header is confirmed through issue and return lifecycle.');
    foreach (['pending_payment', 'reconciliation_required', 'cancelled', 'issued', 'partial_return', 'returned'] as $rentalState) {
        $check(!CompositionLinkRepository::hasConfirmedPayment(array_merge($syntheticLink, ['rental_state' => $rentalState])),
            'Paid parent proof rejects impossible or unresolved rental header state ' . $rentalState . '.');
    }

    $uncertain = $service->createPending($customer, $definitionPrefix . 'mixed', $mixedSelection, 'uncertain-' . strtolower($tag) . '-key');
    $gateway->uncertainInitialize = true;
    try { $paymentService->start($customer, (int) $uncertain['order_id'], 'payment-owner@example.test'); throw new RuntimeException('Uncertain init unexpectedly returned.'); }
    catch (PaystackException $error) { $check($error->uncertain, 'An uncertain provider initialization remains an explicit uncertain result.'); }
    finally { $gateway->uncertainInitialize = false; }
    $uncertainState = $readState((int) $uncertain['order_id']);
    $uncertainHold = $one($db, 'SELECT h.state FROM retail_holds h JOIN order_items i ON i.order_item_id=h.order_item_id WHERE i.order_id=?', [(int) $uncertain['order_id']]);
    $uncertainAllocation = $one($db, 'SELECT a.state FROM rental_allocations a JOIN rental_items i ON i.rental_item_id=a.rental_item_id WHERE i.rental_id=?', [(int) $uncertain['rental_id']]);
    $check($uncertainState['checkout_state'] === 'reconciliation_required'
        && $uncertainState['order_state'] === 'reconciliation_required'
        && $uncertainState['rental_state'] === 'reconciliation_required'
        && $uncertainHold['state'] === 'reconciliation_required'
        && $uncertainAllocation['state'] === 'reconciliation_required',
        'Uncertain initialization retains both linked capacity reservations for reconciliation.');

    $verifyTimeout = $service->createPending($customer, $definitionPrefix . 'mixed', $mixedSelection,
        'verifytimeout-' . strtolower($tag) . '-key');
    $verifyAttempt = $paymentService->start($customer, (int) $verifyTimeout['order_id'], 'payment-owner@example.test');
    $gateway->uncertainVerify = true;
    try { $paymentService->verifyForCustomer($customer, (string) $verifyAttempt['reference']); throw new RuntimeException('Uncertain verify unexpectedly returned.'); }
    catch (PaystackException $error) { $check($error->uncertain, 'A verification timeout remains uncertain to the caller.'); }
    finally { $gateway->uncertainVerify = false; }
    $verifyHold = $one($db, 'SELECT h.state FROM retail_holds h JOIN order_items i ON i.order_item_id=h.order_item_id WHERE i.order_id=?', [(int) $verifyTimeout['order_id']]);
    $verifyAllocation = $one($db, 'SELECT a.state FROM rental_allocations a JOIN rental_items i ON i.rental_item_id=a.rental_item_id WHERE i.rental_id=?', [(int) $verifyTimeout['rental_id']]);
    $check($readState((int) $verifyTimeout['order_id'])['checkout_state'] === 'reconciliation_required'
        && $verifyHold['state'] === 'reconciliation_required' && $verifyAllocation['state'] === 'reconciliation_required',
        'A verification timeout also preserves both reservations.');

    $late = $service->createPending($customer, $definitionPrefix . 'late', [
        ['component_key' => 'plant', 'product_id' => $lateSale, 'quantity' => 1, 'mode' => 'sale'],
        ['component_key' => 'pot', 'product_id' => $pot, 'quantity' => 1, 'mode' => 'rent',
            'start_date' => $futureStart, 'end_date_inclusive' => $futureStart],
    ], 'late-' . strtolower($tag) . '-key');
    $gateway->definitiveInitializeFailure = true;
    try { $paymentService->start($customer, (int) $late['order_id'], 'payment-owner@example.test'); throw new RuntimeException('Definitive rejection unexpectedly returned.'); }
    catch (PaystackException $error) { $check(!$error->uncertain, 'Definitive initialization rejection is surfaced as failed.'); }
    finally { $gateway->definitiveInitializeFailure = false; }
    $released = $readState((int) $late['order_id']);
    $competing = new OrderService(
        new OrderRepository($db), new InventoryRepository($db), new RetailAvailabilityRepository($db), new TransactionManager($db),
    );
    $competitor = $competing->createPending($customer, [$lateSale => 1], hash('sha256', 'competing-' . strtolower($tag) . '-key'));
    $lateReference = (string) $one($db, 'SELECT provider_reference FROM order_payments WHERE order_id=?', [(int) $late['order_id']])['provider_reference'];
    $gateway->verification[$lateReference] = ['status' => true, 'data' => [
        'reference' => $lateReference, 'status' => 'success', 'amount' => (int) $late['total_minor'], 'currency' => 'ZAR', 'domain' => 'test',
    ]];
    $competitorState = $one($db, 'SELECT state FROM sales_orders WHERE order_id=?', [(int) $competitor['order_id']]);
    $lateOutcome = $paymentService->verifyForCustomer($customer, $lateReference);
    $check($released['checkout_state'] === 'cancelled' && $lateOutcome === 'reconciliation_required'
        && $readState((int) $late['order_id'])['checkout_state'] === 'reconciliation_required'
        && (string) $one($db, 'SELECT h.state FROM retail_holds h JOIN order_items i ON i.order_item_id=h.order_item_id WHERE i.order_id=?', [(int) $late['order_id']])['state'] === 'released'
        && ($competitorState['state'] ?? null) === 'pending_payment',
        'Late success after released capacity is not allowed to steal a later sale hold.');

    $expireDates = static function (int $checkoutId, int $rentalId) use ($db): void {
        $yesterday = (new DateTimeImmutable('now', new DateTimeZone('Africa/Johannesburg')))->modify('-1 day')->format('Y-m-d');
        $today = (new DateTimeImmutable('now', new DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
        $statement = $db->prepare("UPDATE composition_checkout_lines SET start_date=?, end_date_exclusive=? WHERE composition_checkout_id=? AND mode='rent'");
        $statement->execute([$yesterday, $today, $checkoutId]);
        $statement->close();
        $statement = $db->prepare('UPDATE rental_items SET start_date=?, end_date_exclusive=? WHERE rental_id=?');
        $statement->execute([$yesterday, $today, $rentalId]);
        $statement->close();
    };
    $expiredActive = $service->createPending($customer, $definitionPrefix . 'mixed', $mixedSelection,
        'expired-active-' . strtolower($tag));
    $expiredActiveReady = $paymentService->start($customer, (int) $expiredActive['order_id'], 'payment-owner@example.test');
    $expiredActiveReference = (string) $expiredActiveReady['reference'];
    $gateway->verification[$expiredActiveReference] = ['status' => true, 'data' => [
        'reference' => $expiredActiveReference, 'status' => 'success', 'amount' => (int) $expiredActive['total_minor'], 'currency' => 'ZAR', 'domain' => 'test',
    ]];
    $expireDates((int) $expiredActive['composition_checkout_id'], (int) $expiredActive['rental_id']);
    $expiredActiveStock = (int) $one($db, 'SELECT on_hand FROM inventory WHERE product_id=?', [$plant])['on_hand'];
    $check($paymentService->verifyForCustomer($customer, $expiredActiveReference) === 'reconciliation_required'
        && $readState((int) $expiredActive['order_id'])['checkout_state'] === 'reconciliation_required'
        && $readState((int) $expiredActive['order_id'])['payment_state'] === 'reconciliation_required'
        && (int) $one($db, 'SELECT on_hand FROM inventory WHERE product_id=?', [$plant])['on_hand'] === $expiredActiveStock,
        'A first success after the rental window expires preserves capacity and requires reconciliation.');

    $expiredReleased = $service->createPending($customer, $definitionPrefix . 'mixed', $mixedSelection,
        'expired-late-' . strtolower($tag));
    $gateway->definitiveInitializeFailure = true;
    try { $paymentService->start($customer, (int) $expiredReleased['order_id'], 'payment-owner@example.test'); }
    catch (PaystackException $error) { $check(!$error->uncertain, 'Expired late-success fixture releases on definitive initialization failure.'); }
    finally { $gateway->definitiveInitializeFailure = false; }
    $expiredReleasedReference = (string) $one($db, 'SELECT provider_reference FROM order_payments WHERE order_id=?', [(int) $expiredReleased['order_id']])['provider_reference'];
    $gateway->verification[$expiredReleasedReference] = ['status' => true, 'data' => [
        'reference' => $expiredReleasedReference, 'status' => 'success', 'amount' => (int) $expiredReleased['total_minor'], 'currency' => 'ZAR', 'domain' => 'test',
    ]];
    $expireDates((int) $expiredReleased['composition_checkout_id'], (int) $expiredReleased['rental_id']);
    $check($paymentService->verifyForCustomer($customer, $expiredReleasedReference) === 'reconciliation_required'
        && $readState((int) $expiredReleased['order_id'])['checkout_state'] === 'reconciliation_required'
        && $one($db, 'SELECT a.state FROM rental_allocations a JOIN rental_items i ON i.rental_item_id=a.rental_item_id WHERE i.rental_id=?',
            [(int) $expiredReleased['rental_id']])['state'] === 'released',
        'Late success cannot reacquire an expired rental reservation even when capacity is currently free.');

    $providerFailure = $service->createPending($customer, $definitionPrefix . 'mixed', $mixedSelection,
        'providerfailure-' . strtolower($tag) . '-key');
    $failureReady = $paymentService->start($customer, (int) $providerFailure['order_id'], 'payment-owner@example.test');
    $failureReference = (string) $failureReady['reference'];
    $gateway->verification[$failureReference] = ['status' => true, 'data' => [
        'reference' => $failureReference, 'status' => 'failed', 'amount' => (int) $providerFailure['total_minor'], 'currency' => 'ZAR', 'domain' => 'test',
    ]];
    $failureOutcome = $paymentService->verifyForCustomer($customer, $failureReference);
    $failureHold = $one($db, 'SELECT h.state FROM retail_holds h JOIN order_items i ON i.order_item_id=h.order_item_id WHERE i.order_id=?', [(int) $providerFailure['order_id']]);
    $failureAllocation = $one($db, 'SELECT a.state FROM rental_allocations a JOIN rental_items i ON i.rental_item_id=a.rental_item_id WHERE i.rental_id=?', [(int) $providerFailure['rental_id']]);
    $check($failureOutcome === 'failed'
        && $readState((int) $providerFailure['order_id'])['checkout_state'] === 'cancelled'
        && $failureHold['state'] === 'released' && $failureAllocation['state'] === 'released',
        'Verified failure cancels the parent and releases both child holds atomically.');
    $check((int) $one($db, 'SELECT COUNT(*) AS n FROM commerce_notification_events WHERE composition_checkout_id=?',
        [(int) $providerFailure['composition_checkout_id']])['n'] === 0,
        'Verified failed aggregate payment creates no composition confirmation event.');

    $cancel = $service->createPending($customer, $definitionPrefix . 'mixed', $mixedSelection, 'cancel-' . strtolower($tag) . '-key');
    $check(!$paymentService->cancelUnstarted($otherCustomer, (int) $cancel['order_id'])
        && $readState((int) $cancel['order_id'])['checkout_state'] === 'pending_payment',
        'Another customer cannot release the composition reservation.');
    $check($paymentService->cancelUnstarted($customer, (int) $cancel['order_id'])
        && $paymentService->cancelUnstarted($customer, (int) $cancel['order_id'])
        && $readState((int) $cancel['order_id'])['checkout_state'] === 'cancelled',
        'Owner-scoped pre-payment cancellation releases both children and is safely repeatable.');
    $started = $service->createPending($customer, $definitionPrefix . 'sale', [
        ['component_key' => 'plant', 'product_id' => $saleOnly, 'quantity' => 1, 'mode' => 'sale'],
    ], 'cancelstarted-' . strtolower($tag) . '-key');
    $startedReady = $paymentService->start($customer, (int) $started['order_id'], 'payment-owner@example.test');
    $check(!$paymentService->cancelUnstarted($customer, (int) $started['order_id'])
        && $startedReady['state'] === 'ready',
        'Cancellation is denied after an aggregate payment attempt exists.');

    $partial = $service->createPending($customer, $definitionPrefix . 'mixed', $mixedSelection, 'partial-' . strtolower($tag) . '-key');
    $db->query('UPDATE rentals SET state = \'cancelled\' WHERE rental_id = ' . (int) $partial['rental_id']);
    $partialResult = $paymentService->start($customer, (int) $partial['order_id'], 'payment-owner@example.test');
    $partialState = $readState((int) $partial['order_id']);
    $partialHold = $one($db, 'SELECT h.state FROM retail_holds h JOIN order_items i ON i.order_item_id=h.order_item_id WHERE i.order_id=?', [(int) $partial['order_id']]);
    $check($partialResult['state'] === 'reconciliation_required'
        && $partialState['checkout_state'] === 'reconciliation_required'
        && $partialState['order_state'] === 'reconciliation_required'
        && $partialState['rental_state'] === 'reconciliation_required'
        && $partialHold['state'] === 'reconciliation_required',
        'A partially cancelled child state is reconciled instead of reported as a fully released failure.');

    echo "Composition payment checks: $checks passed, 0 failed.\n";
} finally {
    // Runtime credentials intentionally retain no DELETE permission; synthetic rows stay in the isolated test schema.
    $db->close();
}
