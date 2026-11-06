<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Inventory\CapacityRepository;
use Verdant\Inventory\InventoryRepository;
use Verdant\Notifications\CommerceNotifier;
use Verdant\Payments\PaystackException;
use Verdant\Payments\PaystackGateway;
use Verdant\Payments\RentalPaymentRepository;
use Verdant\Payments\RentalPaymentService;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Persistence\TransactionManager;
use Verdant\Rentals\RentalRepository;
use Verdant\Rentals\RentalService;

if (PHP_SAPI !== 'cli' || preg_match('/^verdant_test_[a-z0-9_]+$/', getenv('DB_NAME') ?: '') !== 1) {
    fwrite(STDERR, "Use an isolated verdant_test_ database.\n"); exit(2);
}

final class FakeRentalPaystack implements PaystackGateway
{
    public int $initializeCalls = 0;
    public bool $uncertainInitialize = false;
    public bool $definitiveInitializeFailure = false;
    public array $verification = [];

    public function initialize(string $email, int $amountMinor, string $reference, string $callbackUrl): array
    {
        ++$this->initializeCalls;
        if ($this->uncertainInitialize) { throw new PaystackException('timeout', true); }
        if ($this->definitiveInitializeFailure) { throw new PaystackException('rejected', false); }
        return ['status' => true, 'data' => ['reference' => $reference, 'authorization_url' => 'https://checkout.paystack.com/' . $reference]];
    }

    public function verify(string $reference): array
    {
        return $this->verification[$reference] ?? ['status' => true, 'data' => [
            'reference' => $reference, 'status' => 'pending', 'amount' => 250, 'currency' => 'ZAR', 'domain' => 'test',
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
$reject = static function (callable $action, string $label) use ($check): void {
    try { $action(); } catch (DomainException|InvalidArgumentException) { $check(true, $label); return; }
    throw new RuntimeException($label);
};
$scalar = static function (string $sql, array $params = []) use ($db): mixed {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $value = $stmt->get_result()->fetch_row()[0] ?? null;
    $stmt->close();
    return $value;
};
$fixture = static function (string $suffix, int $stock = 3) use ($db, $tag): array {
    $stmt = $db->prepare("INSERT INTO customers(email,password_hash,name,account_state,role) VALUES(?, 'test-hash','Payment fixture','active','customer')");
    $stmt->execute(['rpay-' . $tag . '-' . $suffix . '@example.test']);
    $customerId = (int) $db->insert_id; $stmt->close();
    $stmt = $db->prepare('INSERT INTO categories(name) VALUES (?)');
    $stmt->execute(['Rental pay category ' . $tag . '-' . $suffix]);
    $categoryId = (int) $db->insert_id; $stmt->close();
    $stmt = $db->prepare("INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor) VALUES(?,?,?,'Synthetic','indirect','weekly','non_toxic',1,1,1000,250)");
    $stmt->execute([$categoryId, 'RPAY-' . strtoupper($tag . $suffix), 'Rental payment plant ' . $suffix]);
    $productId = (int) $db->insert_id; $stmt->close();
    $stmt = $db->prepare('INSERT INTO inventory(product_id,on_hand) VALUES(?,?)');
    $stmt->execute([$productId, $stock]); $stmt->close();
    return ['customer' => $customerId, 'product' => $productId, 'email' => 'rpay-' . $tag . '-' . $suffix . '@example.test'];
};

try {
    $gateway = new FakeRentalPaystack();
    $inventory = new InventoryRepository($db);
    $capacity = new CapacityRepository($db);
    $transaction = new TransactionManager($db);
    $notifier = new CommerceNotifier($db);
    $rentals = new RentalRepository($db);
    $rentalService = new RentalService($rentals, $inventory, $capacity, $transaction, $notifier);
    $payments = new RentalPaymentRepository($db);
    $paymentService = new RentalPaymentService($payments, $inventory, $capacity, $transaction, $gateway, 'http://127.0.0.1:8088/rental/payment/return', $notifier);
    $adminStatement = $db->prepare("INSERT INTO customers(email,password_hash,name,account_state,role) VALUES(?, 'test-hash','Rental admin','active','admin')");
    $adminStatement->execute(['rpay-' . $tag . '-admin@example.test']);
    $adminId = (int) $db->insert_id;
    $adminStatement->close();
    $future = (new DateTimeImmutable('now', new DateTimeZone('Africa/Johannesburg')))->modify('+10 days')->format('Y-m-d');
    $makeRental = static function (string $name, int $stock = 3) use ($fixture, $rentalService, $future): array {
        $f = $fixture($name, $stock);
        $r = $rentalService->createPending($f['customer'], $f['product'], 1, $future, $future, bin2hex(random_bytes(16)));
        return $f + ['rental' => $r];
    };

    $success = $makeRental('success');
    $started = $paymentService->start($success['customer'], (int) $success['rental']['rental_id'], $success['email']);
    $stored = $payments->latestForRental((int) $success['rental']['rental_id']);
    $check($started['state'] === 'ready' && str_starts_with($started['authorization_url'], 'https://checkout.paystack.com/'),
        'Rental checkout initializes a validated test-mode hosted session.');
    $check($stored['provider_checkout_url'] === $started['authorization_url'], 'Rental checkout URL is persisted for safe retry.');
    $retry = $paymentService->start($success['customer'], (int) $success['rental']['rental_id'], $success['email']);
    $check($retry['authorization_url'] === $started['authorization_url'] && $gateway->initializeCalls === 1,
        'Retry resumes the same hosted payment session.');
    $reference = $stored['provider_reference'];
    $gateway->verification[$reference] = ['status' => true, 'data' => [
        'reference' => $reference, 'status' => 'success', 'amount' => 250, 'currency' => 'ZAR', 'domain' => 'test',
    ]];
    $raw = json_encode(['event' => 'charge.success', 'data' => ['reference' => $reference]], JSON_THROW_ON_ERROR);
    $secret = 'synthetic-test-webhook-secret';
    $signature = hash_hmac('sha512', $raw, $secret);
    $webhook = $paymentService->handleWebhook($raw, $signature, $secret);
    $check($webhook['accepted'] && $webhook['state'] === 'succeeded', 'Signed provider event is independently verified before confirming rental.');
    $rentalId = (int) $success['rental']['rental_id'];
    $eventId = $scalar('SELECT commerce_event_id FROM commerce_notification_events WHERE event_key=? AND event_kind=? AND rental_id=?',
        ['rental-confirmed:' . $rentalId, 'rental_confirmed', $rentalId]);
    $eventContext = $eventId === null ? [] : json_decode(
        (string) $scalar('SELECT context_json FROM commerce_notification_events WHERE commerce_event_id=?', [$eventId]),
        true, 32, JSON_THROW_ON_ERROR,
    );
    $check($eventId !== null && $eventContext['status'] === 'confirmed'
        && $eventContext['items'][0]['name'] === 'Rental payment plant success'
        && (int) $scalar('SELECT COUNT(*) FROM email_outbox WHERE commerce_event_id=? AND recipient_customer_id IN (?,?) AND recipient_role IN (?,?)',
            [$eventId, $success['customer'], $adminId, 'customer', 'admin']) === 2,
        'Verified rental payment snapshots the confirmed reservation and trusted customer/admin recipients.');
    $confirmed = $payments->byReference($reference);
    $allocation = $db->query('SELECT state FROM rental_allocations WHERE rental_item_id=' . (int) $success['rental']['items'][0]['rental_item_id'])->fetch_assoc();
    $stock = $db->query('SELECT on_hand FROM inventory WHERE product_id=' . $success['product'])->fetch_assoc();
    $check($confirmed['payment_state'] === 'succeeded' && $confirmed['rental_state'] === 'confirmed' && $allocation['state'] === 'confirmed',
        'Verified amount, currency, reference, and test domain atomically confirm allocation once.');
    $check((int) $stock['on_hand'] === 3, 'Rental confirmation reserves dates without decrementing physical stock.');
    $duplicate = $paymentService->handleWebhook($raw, $signature, $secret);
    $check($duplicate['state'] === 'duplicate', 'Duplicate signed rental webhook cannot confirm twice.');
    $check((int) $scalar('SELECT COUNT(*) FROM commerce_notification_events WHERE event_key=?', ['rental-confirmed:' . $rentalId]) === 1,
        'Repeated provider callback retains one rental confirmation event.');
    $failedLate = ['status' => true, 'data' => ['reference' => $reference, 'status' => 'failed', 'amount' => 250, 'currency' => 'ZAR', 'domain' => 'test']];
    $gateway->verification[$reference] = $failedLate;
    $check($paymentService->verifyForCustomer($success['customer'], $reference) === 'succeeded', 'Late failure cannot downgrade a verified success.');
    $otherOwner = $fixture('otherowner');
    $check($paymentService->verifyForCustomer($otherOwner['customer'], $reference) === 'unavailable', 'A different customer cannot verify the rental payment.');

    $mismatch = $makeRental('mismatch');
    $mismatchStart = $paymentService->start($mismatch['customer'], (int) $mismatch['rental']['rental_id'], $mismatch['email']);
    $mismatchPayment = $payments->latestForRental((int) $mismatch['rental']['rental_id']);
    $gateway->verification[$mismatchPayment['provider_reference']] = ['status' => true, 'data' => [
        'reference' => $mismatchPayment['provider_reference'], 'status' => 'success', 'amount' => 249, 'currency' => 'ZAR', 'domain' => 'test',
    ]];
    $check($paymentService->verifyForCustomer($mismatch['customer'], $mismatchPayment['provider_reference']) === 'reconciliation_required',
        'Amount mismatch retains a visible rental reconciliation hold.');
    $check($payments->byReference($mismatchPayment['provider_reference'])['rental_state'] === 'reconciliation_required',
        'Mismatched provider outcome cannot confirm a reservation.');

    foreach ([['currency' => 'USD', 'domain' => 'test'], ['currency' => 'ZAR', 'domain' => 'live']] as $index => $details) {
        $wrong = $makeRental('wrong' . $index);
        $paymentService->start($wrong['customer'], (int) $wrong['rental']['rental_id'], $wrong['email']);
        $wrongPayment = $payments->latestForRental((int) $wrong['rental']['rental_id']);
        $gateway->verification[$wrongPayment['provider_reference']] = ['status' => true, 'data' => [
            'reference' => $wrongPayment['provider_reference'], 'status' => 'success', 'amount' => 250,
            'currency' => $details['currency'], 'domain' => $details['domain'],
        ]];
        $check($paymentService->verifyForCustomer($wrong['customer'], $wrongPayment['provider_reference']) === 'reconciliation_required',
            'Wrong currency or non-test provider domain cannot confirm rental.');
    }

    $failure = $makeRental('failure');
    $paymentService->start($failure['customer'], (int) $failure['rental']['rental_id'], $failure['email']);
    $failurePayment = $payments->latestForRental((int) $failure['rental']['rental_id']);
    $gateway->verification[$failurePayment['provider_reference']] = ['status' => true, 'data' => [
        'reference' => $failurePayment['provider_reference'], 'status' => 'failed', 'amount' => 250, 'currency' => 'ZAR', 'domain' => 'test',
    ]];
    $check($paymentService->verifyForCustomer($failure['customer'], $failurePayment['provider_reference']) === 'failed',
        'Authoritative failure cancels the pending rental.');
    $check((int) $scalar('SELECT COUNT(*) FROM commerce_notification_events WHERE rental_id=?', [(int) $failure['rental']['rental_id']]) === 0,
        'Definitive payment failure does not emit a rental confirmation event.');
    $released = $db->query('SELECT state FROM rental_allocations WHERE rental_item_id=' . (int) $failure['rental']['items'][0]['rental_item_id'])->fetch_assoc();
    $check($released['state'] === 'released', 'Definitive payment failure releases rental capacity.');
    $gateway->verification[$failurePayment['provider_reference']]['data']['status'] = 'success';
    $check($paymentService->verifyForCustomer($failure['customer'], $failurePayment['provider_reference']) === 'succeeded',
        'Late verified success reacquires capacity atomically when still available.');
    $check((int) $scalar('SELECT COUNT(*) FROM commerce_notification_events WHERE event_key=?', ['rental-confirmed:' . (int) $failure['rental']['rental_id']]) === 1,
        'Late verified success emits one event only when rental confirmation commits.');

    $lateFull = $makeRental('latefull', 1);
    $paymentService->start($lateFull['customer'], (int) $lateFull['rental']['rental_id'], $lateFull['email']);
    $lateFullPayment = $payments->latestForRental((int) $lateFull['rental']['rental_id']);
    $gateway->verification[$lateFullPayment['provider_reference']] = ['status' => true, 'data' => [
        'reference' => $lateFullPayment['provider_reference'], 'status' => 'failed', 'amount' => 250, 'currency' => 'ZAR', 'domain' => 'test',
    ]];
    $paymentService->verifyForCustomer($lateFull['customer'], $lateFullPayment['provider_reference']);
    $otherCustomerStatement = $db->prepare("INSERT INTO customers(email,password_hash,name,account_state,role) VALUES(?, 'test-hash','Competing customer','active','customer')");
    $otherCustomerStatement->execute(['rpay-' . $tag . '-latecompetitor@example.test']);
    $otherCustomer = (int) $db->insert_id; $otherCustomerStatement->close();
    $competitor = $rentalService->createPending($otherCustomer, $lateFull['product'], 1, $future, $future, bin2hex(random_bytes(16)));
    $gateway->verification[$lateFullPayment['provider_reference']]['data']['status'] = 'success';
    $check($paymentService->verifyForCustomer($lateFull['customer'], $lateFullPayment['provider_reference']) === 'reconciliation_required',
        'Late success cannot silently overbook capacity already promised to another rental.');
    $check($rentals->owned($otherCustomer, (int) $competitor['rental_id'])['state'] === 'pending_payment',
        'Competing held reservation remains intact after late-success reconciliation.');

    $uncertain = $makeRental('uncertain');
    $gateway->uncertainInitialize = true;
    try { $paymentService->start($uncertain['customer'], (int) $uncertain['rental']['rental_id'], $uncertain['email']); }
    catch (PaystackException $error) { $check($error->uncertain, 'Initialization timeout is marked uncertain.'); }
    $uncertainPayment = $payments->latestForRental((int) $uncertain['rental']['rental_id']);
    $uncertainStored = $payments->byReference($uncertainPayment['provider_reference']);
    $uncertainAllocation = $db->query('SELECT state FROM rental_allocations WHERE rental_item_id=' . (int) $uncertain['rental']['items'][0]['rental_item_id'])->fetch_assoc();
    $check($uncertainStored['payment_state'] === 'reconciliation_required' && $uncertainAllocation['state'] === 'reconciliation_required',
        'Unknown initialization outcome retains the reservation for reconciliation.');
    $gateway->uncertainInitialize = false;

    $initFail = $makeRental('initfail');
    $gateway->definitiveInitializeFailure = true;
    try { $paymentService->start($initFail['customer'], (int) $initFail['rental']['rental_id'], $initFail['email']); }
    catch (PaystackException $error) { $check(!$error->uncertain, 'Definitive initialization rejection is reported as a failure.'); }
    $gateway->definitiveInitializeFailure = false;
    $initFailPayment = $payments->latestForRental((int) $initFail['rental']['rental_id']);
    $check($payments->byReference($initFailPayment['provider_reference'])['rental_state'] === 'cancelled',
        'Definitive pre-checkout rejection cancels and releases the pending reservation.');

    $malformed = $makeRental('malformed');
    $paymentService->start($malformed['customer'], (int) $malformed['rental']['rental_id'], $malformed['email']);
    $malformedPayment = $payments->latestForRental((int) $malformed['rental']['rental_id']);
    $gateway->verification[$malformedPayment['provider_reference']] = ['status' => false];
    $check($paymentService->verifyForCustomer($malformed['customer'], $malformedPayment['provider_reference']) === 'reconciliation_required',
        'Malformed provider verification is persisted as reconciliation-required.');
    $check($payments->byReference($malformedPayment['provider_reference'])['payment_state'] === 'reconciliation_required',
        'Malformed provider result remains visible in stored payment state.');

    $cancelledPaid = $makeRental('cancelledpaid');
    $paymentService->start($cancelledPaid['customer'], (int) $cancelledPaid['rental']['rental_id'], $cancelledPaid['email']);
    $cancelledPaidPayment = $payments->latestForRental((int) $cancelledPaid['rental']['rental_id']);
    $gateway->verification[$cancelledPaidPayment['provider_reference']] = ['status' => true, 'data' => [
        'reference' => $cancelledPaidPayment['provider_reference'], 'status' => 'success', 'amount' => 250, 'currency' => 'ZAR', 'domain' => 'test',
    ]];
    $paymentService->verifyForCustomer($cancelledPaid['customer'], $cancelledPaidPayment['provider_reference']);
    $cancelledPaidId = (int) $cancelledPaid['rental']['rental_id'];
    $rentalService->cancel($adminId, 'admin', $cancelledPaidId);
    $cancelEventId = $scalar("SELECT commerce_event_id FROM commerce_notification_events WHERE event_key=? AND event_kind='rental_cancelled' AND rental_id=?",
        ['rental-cancelled:' . $cancelledPaidId, $cancelledPaidId]);
    $check($cancelEventId !== null
        && (int) $scalar('SELECT COUNT(*) FROM email_outbox WHERE commerce_event_id=? AND recipient_customer_id IN (?,?)',
            [$cancelEventId, $cancelledPaid['customer'], $adminId]) === 2,
        'Operational cancellation of a confirmed unissued rental records one event with customer/admin recipients.');
    $rentalService->cancel($adminId, 'admin', $cancelledPaidId);
    $check((int) $scalar('SELECT COUNT(*) FROM commerce_notification_events WHERE event_key=?', ['rental-cancelled:' . $cancelledPaidId]) === 1,
        'Repeated rental cancellation does not create a duplicate event.');
    $gateway->verification[$cancelledPaidPayment['provider_reference']]['data']['status'] = 'failed';
    $check($paymentService->verifyForCustomer($cancelledPaid['customer'], $cancelledPaidPayment['provider_reference']) === 'cancelled_paid',
        'Late failure cannot revive a cancelled paid rental or relabel the payment as failed.');

    $past = $makeRental('past');
    $paymentService->start($past['customer'], (int) $past['rental']['rental_id'], $past['email']);
    $pastPayment = $payments->latestForRental((int) $past['rental']['rental_id']);
    $db->query("UPDATE rental_items SET start_date='2020-01-01',end_date_exclusive='2020-01-02' WHERE rental_id=" . (int) $past['rental']['rental_id']);
    $gateway->verification[$pastPayment['provider_reference']] = ['status' => true, 'data' => [
        'reference' => $pastPayment['provider_reference'], 'status' => 'success', 'amount' => 250, 'currency' => 'ZAR', 'domain' => 'test',
    ]];
    $check($paymentService->verifyForCustomer($past['customer'], $pastPayment['provider_reference']) === 'reconciliation_required',
        'Success received after an unissued rental period cannot create an expired booking.');

    $badSignature = $paymentService->handleWebhook($raw, str_repeat('0', 128), $secret);
    $check(!$badSignature['accepted'] && $badSignature['state'] === 'invalid_signature', 'Invalid signature is rejected before provider verification.');
    $check($paymentService->verifyForCustomer($failure['customer'], 'unknown-reference') === 'unavailable',
        'Unknown payment references are not exposed as owned transactions.');

    echo "Rental payment checks: $checks passed, 0 failed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Rental payment checks failed after ' . $checks . ' checks: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    $db->close();
}
