<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Inventory\CapacityRepository;
use Verdant\Inventory\InventoryRepository;
use Verdant\Inventory\RentalPeriod;
use Verdant\Notifications\CommerceNotifier;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Persistence\TransactionManager;
use Verdant\Rentals\RentalRepository;
use Verdant\Rentals\RentalService;

if (PHP_SAPI !== 'cli' || preg_match('/^verdant_test_[a-z0-9_]+$/', getenv('DB_NAME') ?: '') !== 1) {
    fwrite(STDERR, "Use an isolated verdant_test_ database.\n"); exit(2);
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
    try { $action(); }
    catch (DomainException|InvalidArgumentException|OverflowException) { $check(true, $label); return; }
    throw new RuntimeException($label);
};
$scalar = static function (string $sql, array $params = []) use ($db): mixed {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $value = $stmt->get_result()->fetch_row()[0] ?? null;
    $stmt->close();
    return $value;
};
$fixture = static function (string $suffix, int $stock = 4) use ($db, $tag): array {
    $stmt = $db->prepare("INSERT INTO customers(email,password_hash,name,account_state,role) VALUES(?, 'test-hash','Rental fixture','active',?)");
    $stmt->execute(['rent-' . $tag . '-' . $suffix . '@example.test', $suffix === 'admin' ? 'admin' : 'customer']);
    $customerId = (int) $db->insert_id; $stmt->close();
    $stmt = $db->prepare('INSERT INTO categories(name) VALUES (?)');
    $stmt->execute(['Rental category ' . $tag . '-' . $suffix]);
    $categoryId = (int) $db->insert_id; $stmt->close();
    $stmt = $db->prepare("INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor) VALUES(?,?,?,'Synthetic','indirect','weekly','non_toxic',1,1,1000,250)");
    $stmt->execute([$categoryId, 'REN-' . strtoupper($tag . $suffix), 'Rental plant ' . $suffix]);
    $productId = (int) $db->insert_id; $stmt->close();
    $stmt = $db->prepare('INSERT INTO inventory(product_id,on_hand) VALUES(?,?)');
    $stmt->execute([$productId, $stock]); $stmt->close();
    return ['actor' => $customerId, 'product' => $productId, 'category' => $categoryId];
};

try {
    $repository = new RentalRepository($db);
    $capacity = new CapacityRepository($db);
    $inventory = new InventoryRepository($db);
    $transactions = new TransactionManager($db);
    $notifier = new CommerceNotifier($db);
    $service = new RentalService($repository, $inventory, $capacity, $transactions, $notifier);
    $customer = $fixture('customer', 3);
    $admin = $fixture('admin', 3);
    $start = RentalPeriod::addDays((new DateTimeImmutable('now', new DateTimeZone('Africa/Johannesburg')))->format('Y-m-d'), 12);
    $end = RentalPeriod::addDays($start, 1);

    $quote = $service->quote($customer['product'], 2, $start, $start);
    $check($quote['days'] === 1 && $quote['total_minor'] === 500 && $quote['end_date_exclusive'] === RentalPeriod::addDays($start, 1),
        'Rental quote snapshots the daily rate over inclusive selected calendar days.');
    $reject(static fn () => $service->quote($customer['product'], 0, $start, $start), 'Zero quantity is rejected.');
    $reject(static fn () => $service->quote($customer['product'], 1, '2026-02-30', '2026-03-01'), 'Impossible calendar dates are rejected by the service.');
    $db->query('UPDATE products SET rent_enabled=0 WHERE product_id=' . $customer['product']);
    $reject(static fn () => $service->quote($customer['product'], 1, $start, $start), 'Rent-disabled products cannot be quoted.');
    $db->query('UPDATE products SET rent_enabled=1 WHERE product_id=' . $customer['product']);
    $db->query('UPDATE categories SET archived_at=UTC_TIMESTAMP() WHERE category_id=' . $customer['category']);
    $reject(static fn () => $service->quote($customer['product'], 1, $start, $start), 'Products in archived categories cannot be rented.');
    $db->query('UPDATE categories SET archived_at=NULL WHERE category_id=' . $customer['category']);
    $firstKey = bin2hex(random_bytes(16));
    $first = $service->createPending($customer['actor'], $customer['product'], 2, $start, $start, $firstKey);
    $check($first['state'] === 'pending_payment' && (int) $first['total_minor'] === 500 && count($first['items']) === 1,
        'Pending rental contains immutable item and amount facts.');
    $allocation = $db->query('SELECT quantity,state FROM rental_allocations WHERE rental_item_id=' . (int) $first['items'][0]['rental_item_id'])->fetch_assoc();
    $check((int) $allocation['quantity'] === 2 && $allocation['state'] === 'held', 'Pending checkout immediately holds its full quantity in the shared pool.');
    $repeat = $service->createPending($customer['actor'], $customer['product'], 2, $start, $start, $firstKey);
    $check((int) $repeat['rental_id'] === (int) $first['rental_id'], 'Duplicate checkout key returns the same logical rental.');
    $reject(static fn () => $service->createPending($customer['actor'], $customer['product'], 1, $start, $start, $firstKey),
        'A checkout key cannot be reused with changed rental details.');
    $reject(static fn () => $service->createPending($customer['actor'], $customer['product'], 2, $start, $start, bin2hex(random_bytes(16))),
        'Competing overlapping quantity is rejected after transactional capacity recheck.');
    $adjacent = RentalPeriod::addDays($start, 1);
    $backToBack = $service->createPending($customer['actor'], $customer['product'], 1, $adjacent, $adjacent, bin2hex(random_bytes(16)));
    $check($backToBack['state'] === 'pending_payment', 'Adjacent half-open rental period does not conflict with the first hold.');

    $unstartedFixture = $fixture('unstarted', 1);
    $unstarted = $service->createPending($unstartedFixture['actor'], $unstartedFixture['product'], 1, $start, $start, bin2hex(random_bytes(16)));
    $service->cancel($admin['actor'], 'admin', (int) $unstarted['rental_id']);
    $unstartedState = $repository->owned($unstartedFixture['actor'], (int) $unstarted['rental_id']);
    $check($unstartedState['state'] === 'cancelled'
        && (int) $scalar('SELECT COUNT(*) FROM commerce_notification_events WHERE rental_id=?', [(int) $unstarted['rental_id']]) === 0,
        'A pending checkout with no payment attempt can be cancelled safely without a confirmed-booking notice.');
    $released = $db->query('SELECT state FROM rental_allocations WHERE rental_item_id=' . (int) $unstarted['items'][0]['rental_item_id'])->fetch_assoc();
    $check($released['state'] === 'released', 'Unstarted cancellation releases only its allocation.');

    $issuedFixture = $fixture('issued', 2);
    $current = (new DateTimeImmutable('now', new DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
    $issuedRental = $service->createPending($issuedFixture['actor'], $issuedFixture['product'], 2, $current, $current, bin2hex(random_bytes(16)));
    $itemId = (int) $issuedRental['items'][0]['rental_item_id'];
    $issuedRentalId = (int) $issuedRental['rental_id'];
    $db->query("UPDATE rentals SET state='confirmed' WHERE rental_id=$issuedRentalId");
    $db->query("UPDATE rental_allocations SET state='confirmed' WHERE rental_item_id=$itemId");
    $reject(static fn () => $service->issue($customer['actor'], 'admin', (int) $issuedRental['rental_id']),
        'A forged administrator role is rejected against the database actor role.');
    $service->issue($admin['actor'], 'admin', (int) $issuedRental['rental_id']);
    $service->issue($admin['actor'], 'admin', (int) $issuedRental['rental_id']);
    $issueCount = $db->query("SELECT COUNT(*) AS count FROM rental_issues WHERE rental_item_id=$itemId")->fetch_assoc();
    $issueEventId = $scalar("SELECT commerce_event_id FROM commerce_notification_events WHERE event_key=? AND event_kind='rental_issued' AND rental_id=?",
        ['rental-issued:' . $issuedRentalId, $issuedRentalId]);
    $check((int) $issueCount['count'] === 1 && $issueEventId !== null
        && (int) $scalar('SELECT operation_id FROM commerce_notification_events WHERE commerce_event_id=?', [$issueEventId]) === $itemId
        && (int) $scalar('SELECT COUNT(*) FROM email_outbox WHERE commerce_event_id=? AND recipient_customer_id IN (?,?)',
            [$issueEventId, $issuedFixture['actor'], $admin['actor']]) === 2
        && (int) $scalar("SELECT COUNT(*) FROM commerce_notification_events WHERE event_key=?", ['rental-issued:' . $issuedRentalId]) === 1,
        'Repeated issue records one handover and one event with stable operation ID and customer/admin recipients.');
    $reject(static fn () => $service->cancel($admin['actor'], 'admin', (int) $issuedRental['rental_id']),
        'Issued goods cannot be released through cancellation.');
    $db->query('UPDATE customers SET archived_at=UTC_TIMESTAMP(),account_state="archived" WHERE customer_id=' . $admin['actor']);
    $reject(static fn () => $service->return($admin['actor'], 'admin', (int) $issuedRental['rental_id'], $itemId, 1),
        'An archived administrator cannot record a return.');
    $db->query('UPDATE customers SET archived_at=NULL,account_state="active" WHERE customer_id=' . $admin['actor']);
    $partKey = bin2hex(random_bytes(16));
    $partial = $service->return($admin['actor'], 'admin', (int) $issuedRental['rental_id'], $itemId, 1, $partKey);
    $check($partial['state'] === 'partially_returned' && $partial['returned_quantity'] === 1,
        'Partial physical return releases only the recorded quantity.');
    $partialEvent = $db->prepare("SELECT commerce_event_id,operation_id,context_json FROM commerce_notification_events WHERE event_kind='rental_returned' AND rental_id=? ORDER BY operation_id");
    $partialEvent->execute([$issuedRentalId]);
    $partialResult = $partialEvent->get_result()->fetch_assoc();
    $partialEvent->close();
    $partialContext = $partialResult === null ? [] : json_decode($partialResult['context_json'], true, 32, JSON_THROW_ON_ERROR);
    $check($partialResult !== null && (int) $partialResult['operation_id'] > 0
        && (int) $partialContext['newly_returned_quantity'] === 1
        && (int) $partialContext['items'][0]['outstanding_quantity'] === 1
        && (int) $scalar('SELECT COUNT(*) FROM email_outbox WHERE commerce_event_id=? AND recipient_customer_id IN (?,?)',
            [$partialResult['commerce_event_id'], $issuedFixture['actor'], $admin['actor']]) === 2,
        'Partial return snapshots its stable receipt, outstanding quantity, and trusted customer/admin recipients.');
    $duplicate = $service->return($admin['actor'], 'admin', (int) $issuedRental['rental_id'], $itemId, 1, $partKey);
    $check($duplicate['state'] === 'duplicate'
        && (int) $scalar("SELECT COUNT(*) FROM commerce_notification_events WHERE event_kind='rental_returned' AND rental_id=?", [$issuedRentalId]) === 1,
        'Same return request key and payload does not double-record a return or notification.');
    $reject(static fn () => $service->return($admin['actor'], 'admin', (int) $issuedRental['rental_id'], $itemId, 2, $partKey),
        'A return idempotency key cannot be reused with changed quantity.');
    $reject(static fn () => $service->return($admin['actor'], 'admin', (int) $issuedRental['rental_id'], $itemId, 2),
        'Return quantity cannot exceed outstanding issued units.');
    $final = $service->return($admin['actor'], 'admin', (int) $issuedRental['rental_id'], $itemId, 1, bin2hex(random_bytes(16)));
    $returnEvents = $db->prepare("SELECT operation_id,context_json FROM commerce_notification_events WHERE event_kind='rental_returned' AND rental_id=? ORDER BY operation_id");
    $returnEvents->execute([$issuedRentalId]);
    $returnRows = $returnEvents->get_result()->fetch_all(MYSQLI_ASSOC);
    $returnEvents->close();
    $finalContext = isset($returnRows[1]) ? json_decode($returnRows[1]['context_json'], true, 32, JSON_THROW_ON_ERROR) : [];
    $check($final['state'] === 'returned' && count($returnRows) === 2
        && (int) $finalContext['newly_returned_quantity'] === 1
        && (int) $finalContext['items'][0]['outstanding_quantity'] === 0,
        'A distinct full return creates a second operation event and closes the outstanding quantity snapshot.');
    $stock = $db->query('SELECT on_hand FROM inventory WHERE product_id=' . $issuedFixture['product'])->fetch_assoc();
    $check((int) $stock['on_hand'] === 2, 'Returns release rental capacity without manufacturing or decrementing stock.');

    $confirmedCancelFixture = $fixture('confirmedcancel', 1);
    $confirmedCancel = $service->createPending($confirmedCancelFixture['actor'], $confirmedCancelFixture['product'], 1, $start, $start, bin2hex(random_bytes(16)));
    $confirmedCancelId = (int) $confirmedCancel['rental_id'];
    $confirmedCancelItemId = (int) $confirmedCancel['items'][0]['rental_item_id'];
    $db->query("UPDATE rentals SET state='confirmed' WHERE rental_id=$confirmedCancelId");
    $db->query("UPDATE rental_allocations SET state='confirmed' WHERE rental_item_id=$confirmedCancelItemId");
    $service->cancel($admin['actor'], 'admin', $confirmedCancelId);
    $cancelEventId = $scalar("SELECT commerce_event_id FROM commerce_notification_events WHERE event_key=? AND event_kind='rental_cancelled' AND rental_id=?",
        ['rental-cancelled:' . $confirmedCancelId, $confirmedCancelId]);
    $cancelContext = $cancelEventId === null ? [] : json_decode(
        (string) $scalar('SELECT context_json FROM commerce_notification_events WHERE commerce_event_id=?', [$cancelEventId]),
        true, 32, JSON_THROW_ON_ERROR,
    );
    $service->cancel($admin['actor'], 'admin', $confirmedCancelId);
    $check($cancelEventId !== null && $cancelContext['status'] === 'cancelled'
        && (int) $scalar('SELECT COUNT(*) FROM email_outbox WHERE commerce_event_id=? AND recipient_customer_id IN (?,?)',
            [$cancelEventId, $confirmedCancelFixture['actor'], $admin['actor']]) === 2
        && (int) $scalar("SELECT COUNT(*) FROM commerce_notification_events WHERE event_key=?", ['rental-cancelled:' . $confirmedCancelId]) === 1,
        'Confirmed unissued cancellation records one cancelled snapshot for the customer and admin, even when repeated.');
    $reject(static fn () => $service->issue($customer['actor'], 'customer', (int) $issuedRental['rental_id']),
        'Customer role cannot invoke the administrator issue transition.');
    $reject(static fn () => $service->cancel($unstartedFixture['actor'], 'customer', (int) $first['rental_id']),
        'Customer cannot cancel another customer’s rental.');

    echo "Rental service checks: $checks passed, 0 failed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Rental service checks failed after ' . $checks . ' checks: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    $db->close();
}
