<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Admin\AdminAccess;
use Verdant\Admin\OperationsRepository;
use Verdant\Admin\OperationsService;
use Verdant\Admin\RentalAdminRepository;
use Verdant\Admin\RentalAdminService;
use Verdant\Identity\AccountService;
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
use Verdant\Retail\CartService;
use Verdant\Retail\OrderRepository;
use Verdant\Retail\OrderService;
use Verdant\Retail\RetailAvailabilityRepository;

if (PHP_SAPI !== 'cli' || preg_match('/^verdant_test_[a-z0-9_]+$/', getenv('DB_NAME') ?: '') !== 1) {
    fwrite(STDERR, "Use an isolated verdant_test_ database.\n");
    exit(2);
}

final class IntegrationFakeGateway implements PaystackGateway
{
    /** @var array<string, array<string, mixed>> */
    public array $verification = [];

    public function initialize(string $email, int $amountMinor, string $reference, string $callbackUrl): array
    {
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
    $statement->execute(['integration-' . $tag . '-' . $suffix . '@example.test', 'Integration ' . $suffix, $role]);
    $id = (int) $db->insert_id;
    $statement->close();
    if ($role === 'admin') {
        $allow = $db->prepare('INSERT INTO admin_allowlist (email) VALUES (?)');
        $allow->execute(['integration-' . $tag . '-' . $suffix . '@example.test']);
        $allow->close();
    }
    return $id;
};

try {
    $customerId = $customer('customer');
    $adminId = $customer('admin', 'admin');
    $category = $db->prepare('INSERT INTO categories(name) VALUES(?)');
    $category->execute(['Integration synthetic category ' . $tag]);
    $categoryId = (int) $db->insert_id;
    $category->close();
    $product = $db->prepare("INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor) VALUES(?,?,?,'Synthetic integration fixture','indirect','weekly','non_toxic',1,1,1000,250)");
    $product->execute([$categoryId, 'INT-' . strtoupper($tag), 'Integration plant ' . $tag]);
    $productId = (int) $db->insert_id;
    $product->close();
    $stockInsert = $db->prepare('INSERT INTO inventory(product_id,on_hand) VALUES(?,4)');
    $stockInsert->execute([$productId]);
    $stockInsert->close();

    $transactions = new TransactionManager($db);
    $inventory = new InventoryRepository($db);
    $orders = new OrderRepository($db);
    $rentalRepository = new RentalRepository($db);
    $capacity = new CapacityRepository($db);
    $orderService = new OrderService($orders, $inventory, new RetailAvailabilityRepository($db), $transactions);
    $rentalService = new RentalService($rentalRepository, $inventory, $capacity, $transactions);
    $account = new AccountService($orders, $rentalRepository);
    $gateway = new IntegrationFakeGateway();
    $payments = new PaymentRepository($db);
    $paymentService = new PaymentService($payments, $inventory, $transactions, $gateway, 'http://127.0.0.1:8088/payment/return');
    $rentalPayments = new RentalPaymentRepository($db);
    $rentalPaymentService = new RentalPaymentService($rentalPayments, $inventory, $capacity, $transactions, $gateway, 'http://127.0.0.1:8088/rental/payment/return');
    $adminActor = ['customer_id' => $adminId, 'role' => 'admin'];
    $customerActor = ['customer_id' => $customerId];
    $operations = new OperationsService(new OperationsRepository($db), $inventory, $transactions, new AdminAccess($db));
    $rentalAdmin = new RentalAdminService(new RentalAdminRepository($db), $rentalService, $transactions, new AdminAccess($db));

    // Retail: real pending order, verified synthetic provider success, shared customer/admin views and fulfilment.
    $cart = new CartService(new RetailAvailabilityRepository($db));
    $basket = $cart->add([], (string) $productId, '1');
    $summary = $cart->summary($basket);
    $check($summary['valid'] && $summary['total_minor'] === 1000, 'Basket validates the shared product and server-calculated retail total.');
    $order = $orderService->createPending($customerId, $basket, bin2hex(random_bytes(32)));
    $orderId = (int) $order['order_id'];
    $paymentService->start($customerId, $orderId, 'integration-' . $tag . '-customer@example.test');
    $payment = $payments->latestForCustomerOrder($customerId, $orderId);
    $reference = (string) $payment['provider_reference'];
    $gateway->verification[$reference] = ['status' => true, 'data' => [
        'reference' => $reference, 'status' => 'success', 'amount' => 1000, 'currency' => 'ZAR', 'domain' => 'test',
    ]];
    $raw = json_encode(['event' => 'charge.success', 'data' => ['reference' => $reference]], JSON_THROW_ON_ERROR);
    $secret = 'integration-synthetic-webhook-secret';
    $signature = hash_hmac('sha512', $raw, $secret);
    $paid = $paymentService->handleWebhook($raw, $signature, $secret);
    $check($paid['accepted'] && $paid['state'] === 'succeeded', 'Retail fake-gateway success confirms a service-created customer order.');
    $check((int) $scalar('SELECT on_hand FROM inventory WHERE product_id=?', [$productId]) === 3,
        'Verified retail payment consumes one unit of shared physical stock.');
    $customerOrder = $account->order($customerActor, $orderId);
    $adminOrder = $operations->order($adminActor, $orderId);
    $adminListIds = array_map(static fn (array $row): int => (int) $row['order_id'], $operations->orders($adminActor)['items']);
    $check($customerOrder !== null && $customerOrder['state'] === 'confirmed' && $customerOrder['payments'][0]['state'] === 'succeeded',
        'Customer order history exposes the confirmed purchase and successful payment.');
    $check($adminOrder !== null && $adminOrder['state'] === 'confirmed' && in_array($orderId, $adminListIds, true),
        'Admin order list and detail expose the same confirmed customer purchase.');
    $operations->fulfilOrder($adminActor, $orderId);
    $operations->fulfilOrder($adminActor, $orderId);
    $check($operations->order($adminActor, $orderId)['fulfilled_at'] !== null
        && (int) $scalar('SELECT on_hand FROM inventory WHERE product_id=?', [$productId]) === 3,
        'Admin fulfilment is idempotent and does not consume the already-paid stock twice.');
    $operations->setOrderArchived($adminActor, $orderId, true);
    $check($account->order($customerActor, $orderId) !== null
        && $operations->order($adminActor, $orderId)['archived_at'] !== null,
        'Archiving a retail order retains customer history and admin access to the record.');

    // Rentals use today's Johannesburg business date so the same admin issue/return APIs exercise a real lifecycle.
    $today = (new DateTimeImmutable('now', new DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
    $rental = $rentalService->createPending($customerId, $productId, 2, $today, $today, bin2hex(random_bytes(24)));
    $rentalId = (int) $rental['rental_id'];
    $rentalPaymentService->start($customerId, $rentalId, 'integration-' . $tag . '-customer@example.test');
    $rentalPayment = $rentalPayments->latestForRental($rentalId);
    $rentalReference = (string) $rentalPayment['provider_reference'];
    $gateway->verification[$rentalReference] = ['status' => true, 'data' => [
        'reference' => $rentalReference, 'status' => 'success', 'amount' => 500, 'currency' => 'ZAR', 'domain' => 'test',
    ]];
    $rentalRaw = json_encode(['event' => 'charge.success', 'data' => ['reference' => $rentalReference]], JSON_THROW_ON_ERROR);
    $rentalSignature = hash_hmac('sha512', $rentalRaw, $secret);
    $rentalPaid = $rentalPaymentService->handleWebhook($rentalRaw, $rentalSignature, $secret);
    $check($rentalPaid['accepted'] && $rentalPaid['state'] === 'succeeded', 'Rental fake-gateway success confirms a service-created reservation.');
    $customerRental = $account->rental($customerActor, $rentalId);
    $rentalAdminList = array_map(static fn (array $row): int => (int) $row['rental_id'], $rentalAdmin->rentals($adminActor)['rentals']);
    $check($customerRental !== null && $customerRental['state'] === 'confirmed'
        && $rentalAdmin->rental($adminActor, $rentalId)['state'] === 'confirmed'
        && in_array($rentalId, $rentalAdminList, true),
        'Customer rental history and admin rental list/detail show the same confirmed booking.');
    $check($capacity->availableForPeriod($productId, $today, (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d')) === 1,
        'Confirmed rental reserves two of the three remaining units for the booked date.');
    $rentalAdmin->issue($adminActor, $rentalId);
    $rentalItemId = (int) $customerRental['items'][0]['rental_item_id'];
    $check($capacity->outstandingIssued($productId) === 2, 'Admin issue records two physical units as outstanding.');
    $partialKey = bin2hex(random_bytes(24));
    $partial = $rentalAdmin->recordReturn($adminActor, $rentalId, $rentalItemId, 1, $partialKey);
    $check($partial['state'] === 'partially_returned' && $capacity->outstandingIssued($productId) === 1
        && $capacity->availableForPeriod($productId, $today, (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d')) === 2,
        'Partial return releases one unit of current and dated availability.');
    $full = $rentalAdmin->recordReturn($adminActor, $rentalId, $rentalItemId, 1, bin2hex(random_bytes(24)));
    $check($full['state'] === 'returned' && $capacity->outstandingIssued($productId) === 0
        && $capacity->availableForPeriod($productId, $today, (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d')) === 3,
        'Full return releases the remaining unit and all booked-date availability.');
    $check((int) $scalar('SELECT on_hand FROM inventory WHERE product_id=?', [$productId]) === 3,
        'Rental issue and returns do not alter inventory already consumed by the retail purchase.');
    $rentalAdmin->setArchived($adminActor, $rentalId, true);
    $check($account->rental($customerActor, $rentalId) !== null
        && $rentalAdmin->rental($adminActor, $rentalId)['archived_at'] !== null,
        'Archiving a returned rental retains customer history and admin access to the record.');

    // Cancellation must release a pending reservation and restore date availability.
    $cancelled = $rentalService->createPending($customerId, $productId, 2, $today, $today, bin2hex(random_bytes(24)));
    $cancelledId = (int) $cancelled['rental_id'];
    $periodEnd = (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
    $check($capacity->availableForPeriod($productId, $today, $periodEnd) === 1,
        'A second pending rental temporarily reserves its requested date capacity.');
    $rentalAdmin->cancel($adminActor, $cancelledId);
    $check($rentalRepository->owned($customerId, $cancelledId)['state'] === 'cancelled'
        && $capacity->availableForPeriod($productId, $today, $periodEnd) === 3,
        'Admin cancellation releases the pending allocation and restores date availability.');

    echo "Integration workflow checks: $checks passed, 0 failed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Integration workflow checks failed after ' . $checks . ' checks: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    $db->close();
}
