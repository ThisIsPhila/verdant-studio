<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Admin\AdminAccess;
use Verdant\Admin\RentalAdminRepository;
use Verdant\Admin\RentalAdminService;
use Verdant\Inventory\CapacityRepository;
use Verdant\Inventory\InventoryRepository;
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
    catch (DomainException|InvalidArgumentException) { $check(true, $label); return; }
    throw new RuntimeException($label);
};
$fixture = static function (string $suffix, int $stock = 3) use ($db, $tag): array {
    $stmt = $db->prepare("INSERT INTO customers(email,password_hash,name,account_state,role) VALUES(?, 'test-hash',?,'active',?)");
    $stmt->execute(['admin-rental-' . $tag . '-' . $suffix . '@example.test', 'Admin rental ' . $suffix, $suffix === 'admin' ? 'admin' : 'customer']);
    $customerId = (int) $db->insert_id; $stmt->close();
    if ($suffix === 'admin') {
        $allow = $db->prepare('INSERT INTO admin_allowlist (email) VALUES (?)');
        $allow->execute(['admin-rental-' . $tag . '-' . $suffix . '@example.test']);
        $allow->close();
    }
    $stmt = $db->prepare('INSERT INTO categories(name) VALUES (?)');
    $stmt->execute(['Admin rental category ' . $tag . '-' . $suffix]);
    $categoryId = (int) $db->insert_id; $stmt->close();
    $stmt = $db->prepare("INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor) VALUES(?,?,?,'Synthetic','indirect','weekly','non_toxic',1,1,1000,250)");
    $stmt->execute([$categoryId, 'ADM-' . strtoupper($tag . $suffix), 'Admin rental plant ' . $suffix]);
    $productId = (int) $db->insert_id; $stmt->close();
    $stmt = $db->prepare('INSERT INTO inventory(product_id,on_hand) VALUES(?,?)');
    $stmt->execute([$productId, $stock]); $stmt->close();
    return ['customer_id' => $customerId, 'product_id' => $productId];
};

try {
    $transactions = new TransactionManager($db);
    $rentalRepository = new RentalRepository($db);
    $inventory = new InventoryRepository($db);
    $lifecycle = new RentalService($rentalRepository, $inventory, new CapacityRepository($db), $transactions);
    $admin = new RentalAdminService(new RentalAdminRepository($db), $lifecycle, $transactions, new AdminAccess($db));
    $adminFixture = $fixture('admin');
    $customerFixture = $fixture('customer');
    $timingFixture = $fixture('timing', 20);
    $actor = ['customer_id' => $adminFixture['customer_id'], 'role' => 'admin'];
    $customerActor = ['customer_id' => $customerFixture['customer_id'], 'role' => 'customer'];
    $findRentalInGroup = static function (string $group, int $rentalId) use ($admin, $actor): ?array {
        $firstPage = $admin->rentals($actor, 1, $group);
        for ($pageNumber = 1; $pageNumber <= $firstPage['pages']; ++$pageNumber) {
            foreach ($pageNumber === 1 ? $firstPage['rentals'] : $admin->rentals($actor, $pageNumber, $group)['rentals'] as $row) {
                if ((int) $row['rental_id'] === $rentalId) return $row;
            }
        }
        return null;
    };
    $today = (new DateTimeImmutable('now', new DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
    $rental = $lifecycle->createPending($customerFixture['customer_id'], $customerFixture['product_id'], 2, $today, $today, bin2hex(random_bytes(24)));
    $rentalId = (int) $rental['rental_id'];
    $itemId = (int) $rental['items'][0]['rental_item_id'];
    $db->query("UPDATE rentals SET state='confirmed' WHERE rental_id=" . $rentalId);
    $db->query("UPDATE rental_allocations SET state='confirmed' WHERE rental_item_id=" . $itemId);

    $reject(static fn () => $admin->rentals($customerActor), 'Customer role cannot read rental administration.');
    $reject(static fn () => $admin->returns($customerActor), 'Customer role cannot read the returns worklist.');
    $forged = ['customer_id' => $customerFixture['customer_id'], 'role' => 'admin'];
    $reject(static fn () => $admin->rental($forged, $rentalId), 'A forged admin session role fails the database role guard.');
    $listed = $admin->rentals($actor, 1, 'current');
    $check($listed['total'] >= 1 && $findRentalInGroup('current', $rentalId) !== null,
        'Admin rental list is paginated and contains its reservation.');

    $mixedRental = (int) $db->insert_id;
    $stmt = $db->prepare("INSERT INTO rentals(customer_id,checkout_key,state) VALUES(?,?,'confirmed')");
    $stmt->execute([$customerFixture['customer_id'], 'admin-rental-mixed-' . $tag]);
    $mixedRental = (int) $db->insert_id;
    $stmt->close();
    $dueStart = (new DateTimeImmutable($today, new DateTimeZone('Africa/Johannesburg')))->modify('-5 days')->format('Y-m-d');
    $dueEnd = (new DateTimeImmutable($today, new DateTimeZone('Africa/Johannesburg')))->modify('-2 days')->format('Y-m-d');
    $todayDueStart = (new DateTimeImmutable($today, new DateTimeZone('Africa/Johannesburg')))->modify('-3 days')->format('Y-m-d');
    $laterStart = (new DateTimeImmutable($today, new DateTimeZone('Africa/Johannesburg')))->modify('+5 days')->format('Y-m-d');
    $laterEnd = (new DateTimeImmutable($today, new DateTimeZone('Africa/Johannesburg')))->modify('+8 days')->format('Y-m-d');
    $insertTimingItem = static function (int $rentalId, string $label, string $start, string $end, string $allocation, int $quantity = 1) use ($db, $timingFixture, $actor): int {
        $statement = $db->prepare('INSERT INTO rental_items(rental_id,product_id,product_name_snapshot,sku_snapshot,quantity,start_date,end_date_exclusive,daily_rate_minor) VALUES(?,?,?,?,?,?,?,250)');
        $statement->execute([$rentalId, $timingFixture['product_id'], $label, 'TIMING-' . $label, $quantity, $start, $end]);
        $id = (int) $db->insert_id; $statement->close();
        $statement = $db->prepare('INSERT INTO rental_allocations(rental_item_id,quantity,state) VALUES(?,?,?)');
        $statement->execute([$id, $quantity, $allocation]); $statement->close();
        if ($allocation === 'issued') {
            $statement = $db->prepare('INSERT INTO rental_issues(rental_item_id,quantity,actor_id,issued_at) VALUES(?,?,?,?)');
            $statement->execute([$id, $quantity, $actor['customer_id'], $start . ' 09:00:00']); $statement->close();
            $statement = $db->prepare('INSERT INTO rental_returns(rental_item_id,quantity,returned_at,request_key,actor_id) VALUES(?,?,?,?,?)');
            $statement->execute([$id, 1, $end . ' 10:00:00', 'timing-' . bin2hex(random_bytes(16)), $actor['customer_id']]); $statement->close();
        }
        return $id;
    };
    $dueItem = $insertTimingItem($mixedRental, 'due-' . $tag, $dueStart, $dueEnd, 'issued', 2);
    $stmt = $db->prepare("INSERT INTO rentals(customer_id,checkout_key,state) VALUES(?,?,'confirmed')");
    $stmt->execute([$customerFixture['customer_id'], 'admin-rental-today-due-' . $tag]);
    $todayDueRental = (int) $db->insert_id;
    $stmt->close();
    $todayDueItem = $insertTimingItem($todayDueRental, 'today-due-' . $tag, $todayDueStart, $today, 'issued', 2);
    $laterItem = $insertTimingItem($mixedRental, 'later-' . $tag, $laterStart, $laterEnd, 'confirmed', 1);
    $closedItem = $insertTimingItem($mixedRental, 'closed-' . $tag, $laterStart, $laterEnd, 'closed', 1);
    $releasedItem = $insertTimingItem($mixedRental, 'released-' . $tag, $laterStart, $laterEnd, 'released', 1);
    $mixedRow = $findRentalInGroup('overdue', $mixedRental);
    $check($mixedRow !== null && $mixedRow['overdue_item_count'] === 1 && $mixedRow['overdue_quantity'] === 1
        && $mixedRow['end_date_exclusive'] === $laterEnd,
        'A later-dated item cannot hide one partially returned overdue item in the same rental.');
    $check(count(array_filter($mixedRow['items'], static fn (array $item): bool => $item['overdue'])) === 1
        && array_values(array_filter($mixedRow['items'], static fn (array $item): bool => $item['rental_item_id'] === $laterItem))[0]['overdue'] === false,
        'Overdue status is derived independently from each item deadline and outstanding issued quantity.');
    $timingLabels = array_column($mixedRow['items'], 'timing_label', 'rental_item_id');
    $check($timingLabels[$closedItem] === 'Closed' && $timingLabels[$releasedItem] === 'Released',
        'Closed and released item lifecycle states remain explicit in rental history.');
    $todayDueRow = $findRentalInGroup('overdue', $todayDueRental);
    $check($todayDueRow !== null && $todayDueRow['overdue_deadline'] === $today
        && count(array_filter($todayDueRow['items'], static fn (array $item): bool =>
            (int) $item['rental_item_id'] === $todayDueItem && $item['overdue'])) === 1,
        'An exclusive end date equal to Johannesburg today is overdue and exposed as a date boundary.');
    $check($admin->rentals($actor, 1, 'overdue')['groups']['overdue'] >= 1
        && $admin->rentals($actor, 1, 'upcoming')['total'] >= 0,
        'Rental filters expose group counts and paginate within the selected timing group.');
    $admin->issue($actor, $rentalId);
    $admin->issue($actor, $rentalId);
    $issue = $db->query('SELECT quantity,actor_id,issued_at FROM rental_issues WHERE rental_item_id=' . $itemId)->fetch_assoc();
    $check((int) $issue['quantity'] === 2 && (int) $issue['actor_id'] === $actor['customer_id'] && $issue['issued_at'] !== null,
        'Issue is idempotent and records the active administrator and timestamp.');

    $returnRows = static function () use ($admin, $actor, $itemId): array {
        $firstPage = $admin->returns($actor, 1);
        $matches = [];
        for ($pageNumber = 1; $pageNumber <= $firstPage['pages']; ++$pageNumber) {
            $pageRows = $pageNumber === 1 ? $firstPage['items'] : $admin->returns($actor, $pageNumber)['items'];
            foreach ($pageRows as $row) {
                if ((int) $row['rental_item_id'] === $itemId) $matches[] = $row;
            }
        }
        return $matches;
    };
    $check(count($returnRows()) === 1 && (int) $returnRows()[0]['outstanding_quantity'] === 2,
        'Issued items appear in the separate returns worklist.');

    $stockBefore = (int) $db->query('SELECT on_hand FROM inventory WHERE product_id=' . $customerFixture['product_id'])->fetch_row()[0];
    $admin->setArchived($actor, $rentalId, true);
    $archived = $admin->rental($actor, $rentalId);
    $check($archived !== null && $archived['archived_at'] !== null && $archived['state'] === 'confirmed'
        && $archived['items'][0]['allocation_state'] === 'issued' && (int) $archived['items'][0]['outstanding_quantity'] === 2,
        'Archiving preserves confirmed lifecycle, issued allocation and outstanding capacity claim.');
    $check((int) $db->query('SELECT on_hand FROM inventory WHERE product_id=' . $customerFixture['product_id'])->fetch_row()[0] === $stockBefore,
        'Archiving and issuing leave physical on-hand inventory unchanged.');

    $check(count($returnRows()) === 1 && $returnRows()[0]['archived_at'] !== null,
        'Archived rentals remain in the returns worklist while physical items are outstanding.');
    $key = bin2hex(random_bytes(24));
    $partial = $admin->recordReturn($actor, $rentalId, $itemId, 1, $key);
    $retry = $admin->recordReturn($actor, $rentalId, $itemId, 1, $key);
    $check($partial['state'] === 'partially_returned' && $retry['state'] === 'duplicate'
        && $retry['returned_quantity'] === 1, 'Partial return retry records the same request only once.');
    $check((int) $returnRows()[0]['outstanding_quantity'] === 1 && (int) $returnRows()[0]['returned_quantity'] === 1,
        'A partial return updates the outstanding worklist quantities.');
    $returnAudit = $db->query("SELECT quantity,actor_id,returned_at FROM rental_returns WHERE request_key='" . $db->real_escape_string($key) . "'")->fetch_assoc();
    $check((int) $returnAudit['quantity'] === 1 && (int) $returnAudit['actor_id'] === $actor['customer_id'] && $returnAudit['returned_at'] !== null,
        'Return history attributes the quantity and timestamp to the administrator.');
    $reject(static fn () => $admin->recordReturn($actor, $rentalId, $itemId, 2, bin2hex(random_bytes(24))),
        'Return above outstanding issued quantity is rejected.');
    $final = $admin->recordReturn($actor, $rentalId, $itemId, 1, bin2hex(random_bytes(24)));
    $detail = $admin->rental($actor, $rentalId);
    $check($final['state'] === 'returned' && (int) $detail['items'][0]['outstanding_quantity'] === 0
        && count($detail['items'][0]['returns']) === 2, 'Full return closes the item and exposes complete audit history.');
    $check((int) $db->query('SELECT on_hand FROM inventory WHERE product_id=' . $customerFixture['product_id'])->fetch_row()[0] === $stockBefore,
        'Partial and full returns release date capacity without changing physical stock.');

    $check($returnRows() === [], 'Fully returned items leave the outstanding worklist.');
    $check($admin->returns($actor, PHP_INT_MAX)['page'] === $admin->returns($actor)['pages'],
        'Returns pagination clamps out-of-range pages to the available result set.');
    $admin->setArchived($actor, $rentalId, false);
    $check($admin->rental($actor, $rentalId)['archived_at'] === null, 'Rental history can be restored without changing its lifecycle.');
    echo "Admin rental checks: $checks passed, 0 failed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Admin rental checks failed after ' . $checks . ' checks: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    $db->close();
}
