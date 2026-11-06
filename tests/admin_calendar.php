<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Admin\AdminAccess;
use Verdant\Admin\RentalCalendarRepository;
use Verdant\Admin\RentalCalendarService;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;

$databaseName = getenv('DB_NAME') ?: '';
if (PHP_SAPI !== 'cli' || (getenv('APP_ENV') ?: '') !== 'local'
    || preg_match('/^verdant_test_[a-z0-9_]+$/', $databaseName) !== 1) {
    fwrite(STDERR, "Use APP_ENV=local and an isolated verdant_test_ database.\n");
    exit(2);
}

$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    ++$checks;
    echo "PASS: $label\n";
};
$insert = static function (string $sql, array $parameters = []) use ($db): int {
    $statement = $db->prepare($sql);
    try {
        $statement->execute($parameters);
        return (int) $db->insert_id;
    } finally {
        $statement->close();
    }
};

$db->begin_transaction();
try {
    $tag = bin2hex(random_bytes(8));
    $service = new RentalCalendarService(new RentalCalendarRepository($db), new AdminAccess($db));
    $adminId = $insert(
        "INSERT INTO customers(email,password_hash,name,role,account_state) VALUES(?, 'unused fixture hash', ?, 'admin', 'active')",
        ['calendar-admin-' . $tag . '@example.test', 'Calendar Admin ' . $tag],
    );
    $insert('INSERT INTO admin_allowlist (email) VALUES (?)', ['calendar-admin-' . $tag . '@example.test']);
    $customerId = $insert(
        "INSERT INTO customers(email,password_hash,name,role,account_state) VALUES(?, 'unused fixture hash', ?, 'customer', 'active')",
        ['calendar-customer-' . $tag . '@example.test', 'Calendar Customer ' . $tag],
    );
    $actor = ['customer_id' => $adminId, 'role' => 'admin'];
    $customerActor = ['customer_id' => $customerId, 'role' => 'customer'];

    $categoryId = $insert('INSERT INTO categories(name) VALUES(?)', ['Calendar fixture ' . $tag]);
    $productId = $insert(
        "INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor) VALUES(?,?,?,'Fixture','indirect','weekly','non_toxic',1,1,1000,250)",
        [$categoryId, 'CAL-' . strtoupper($tag), 'Calendar fixture plant ' . $tag],
    );
    $newRental = static function (string $suffix, string $state = 'confirmed', bool $archived = false) use ($insert, $customerId, $tag): int {
        return $insert(
            'INSERT INTO rentals(customer_id,checkout_key,state,archived_at) VALUES(?,?,?,?)',
            [$customerId, 'calendar-' . $tag . '-' . $suffix, $state, $archived ? '2026-01-01 00:00:00' : null],
        );
    };
    $newItem = static function (int $rentalId, string $suffix, string $start, string $end, string $allocation,
                                int $quantity = 1, int $issued = 0, int $returned = 0) use ($insert, $productId, $adminId, $tag): int {
        $itemId = $insert(
            'INSERT INTO rental_items(rental_id,product_id,product_name_snapshot,sku_snapshot,quantity,start_date,end_date_exclusive,daily_rate_minor) VALUES(?,?,?,?,?,?,?,250)',
            [$rentalId, $productId, 'Calendar item ' . $suffix, 'CAL-' . strtoupper($tag . $suffix), $quantity, $start, $end],
        );
        $insert('INSERT INTO rental_allocations(rental_item_id,quantity,state) VALUES(?,?,?)', [$itemId, $quantity, $allocation]);
        if ($issued > 0) {
            $insert('INSERT INTO rental_issues(rental_item_id,quantity,actor_id,issued_at) VALUES(?,?,?,?)',
                [$itemId, $issued, $adminId, $start . ' 09:00:00']);
            if ($returned > 0) {
                $insert('INSERT INTO rental_returns(rental_item_id,quantity,returned_at,request_key,actor_id) VALUES(?,?,?,?,?)',
                    [$itemId, $returned, $end . ' 10:00:00', 'cal-' . bin2hex(random_bytes(16)), $adminId]);
            }
        }
        return $itemId;
    };

    $unauthorizedDenied = false;
    try {
        $service->month($customerActor, '2098-02');
    } catch (DomainException) {
        $unauthorizedDenied = true;
    }
    $check($unauthorizedDenied, 'Customer accounts cannot read rental calendar data.');
    $forgedDenied = false;
    try {
        $service->month(['customer_id' => $customerId, 'role' => 'admin'], '2098-02');
    } catch (DomainException) {
        $forgedDenied = true;
    }
    $check($forgedDenied, 'A forged admin role fails the database role check.');

    foreach (['2026-1', '1999-12', '2101-01', '2024-00', '2024-13', ['2026-01']] as $invalidMonth) {
        $rejected = false;
        try {
            $service->month($actor, $invalidMonth);
        } catch (InvalidArgumentException) {
            $rejected = true;
        }
        $check($rejected, 'Invalid or out-of-range month is rejected: ' . (is_string($invalidMonth) ? $invalidMonth : 'array input'));
    }
    $defaultMonth = $service->month($actor);
    $expectedCurrentMonth = (new DateTimeImmutable('now', new DateTimeZone('Africa/Johannesburg')))->format('Y-m');
    $check($defaultMonth['month'] === $expectedCurrentMonth, 'Omitted month defaults to the Johannesburg current month.');
    $minimumMonth = $service->month($actor, '2000-01');
    $maximumMonth = $service->month($actor, '2100-12');
    $check($minimumMonth['previous_month'] === null && $maximumMonth['next_month'] === null,
        'Month navigation respects the supported 2000–2100 bounds.');

    $olderMonth = (new DateTimeImmutable('first day of this month', new DateTimeZone('Africa/Johannesburg')))->modify('-2 months');
    $oldStart = $olderMonth->format('Y-m-01');
    $oldEnd = $olderMonth->modify('+1 month')->format('Y-m-01');
    $baselineOverdue = $service->month($actor, $olderMonth->format('Y-m'))['overdue_summary'];
    $partialRental = $newRental('partial');
    $partialStart = $olderMonth->modify('+3 days')->format('Y-m-d');
    $partialEnd = $olderMonth->modify('+5 days')->format('Y-m-d');
    $partialItem = $newItem($partialRental, 'partial', $partialStart, $partialEnd, 'issued', 3, 3, 1);

    $returnedRental = $newRental('returned');
    $returnedItem = $newItem($returnedRental, 'returned', $olderMonth->modify('+6 days')->format('Y-m-d'),
        $olderMonth->modify('+8 days')->format('Y-m-d'), 'closed', 2, 2, 2);

    $cancelledRental = $newRental('cancelled', 'cancelled');
    $cancelledItem = $newItem($cancelledRental, 'cancelled', $olderMonth->modify('+9 days')->format('Y-m-d'),
        $olderMonth->modify('+10 days')->format('Y-m-d'), 'released');

    $pendingRental = $newRental('pending', 'pending_payment');
    $pendingItem = $newItem($pendingRental, 'pending', $olderMonth->modify('+11 days')->format('Y-m-d'),
        $olderMonth->modify('+12 days')->format('Y-m-d'), 'held');

    $archivedRental = $newRental('archived', 'confirmed', true);
    $archivedItem = $newItem($archivedRental, 'archived', $olderMonth->modify('+13 days')->format('Y-m-d'),
        $olderMonth->modify('+14 days')->format('Y-m-d'), 'issued', 4, 4, 0);

    $monthData = $service->month($actor, $olderMonth->format('Y-m'));
    $byItem = [];
    foreach ($monthData['events'] as $event) {
        $byItem[$event['rental_item_id']] = $event;
    }
    $check(isset($byItem[$partialItem]) && $byItem[$partialItem]['status_label'] === 'Overdue return'
        && $byItem[$partialItem]['returned_quantity'] === 1 && $byItem[$partialItem]['outstanding_quantity'] === 2,
        'Partial return projection reports actual issued, returned and outstanding quantities.');
    $check(isset($byItem[$returnedItem]) && $byItem[$returnedItem]['status_label'] === 'Returned',
        'Fully returned issued quantity is labelled Returned.');
    $check(isset($byItem[$cancelledItem]) && $byItem[$cancelledItem]['status_label'] === 'Cancelled',
        'Cancelled rental items remain visible with their actual state.');
    $check(isset($byItem[$pendingItem]) && $byItem[$pendingItem]['status_label'] === 'Pending payment',
        'Held allocation is labelled Pending payment.');
    $check(!isset($byItem[$archivedItem]), 'Archived rental records are excluded from the calendar.');
    $check($monthData['overdue_summary']['item_count'] === $baselineOverdue['item_count'] + 1
        && $monthData['overdue_summary']['outstanding_quantity'] === $baselineOverdue['outstanding_quantity'] + 2,
        'Current overdue summary includes outstanding issued quantity from outside the current month.');
    $expectedDays = (int) $olderMonth->format('t');
    $dayCells = array_merge(...array_column($monthData['weeks'], 'days'));
    $check(count(array_filter($dayCells, static fn (array $cell): bool => $cell['in_month'])) === $expectedDays,
        'Calendar weeks include every date cell in the selected month.');

    $rangeRental = $newRental('range-lanes');
    $rangeStart = $olderMonth->modify('+2 days');
    $rangeItem = $newItem($rangeRental, 'range-lane', $rangeStart->format('Y-m-d'),
        $rangeStart->modify('+10 days')->format('Y-m-d'), 'confirmed');
    $laneMonth = $service->month($actor, $olderMonth->format('Y-m'));
    $rangeSegments = [];
    foreach ($laneMonth['weeks'] as $weekIndex => $week) {
        foreach ($week['segments'] as $segment) {
            if ($segment['rental_item_id'] === $rangeItem) {
                $rangeSegments[] = ['week' => $weekIndex, 'segment' => $segment];
            }
        }
    }
    $check(count($rangeSegments) >= 2
        && $rangeSegments[0]['segment']['lane'] === $rangeSegments[1]['segment']['lane']
        && !$rangeSegments[0]['segment']['continues_before']
        && $rangeSegments[0]['segment']['continues_after']
        && $rangeSegments[1]['segment']['continues_before'],
        'A multiweek item keeps one stable lane and exposes continuation markers across the week boundary.');

    $denseRental = $newRental('dense-day');
    $denseItems = [];
    for ($index = 0; $index < RentalCalendarService::VISIBLE_LANES + 2; ++$index) {
        $denseItems[] = $newItem($denseRental, 'dense-' . $index,
            $olderMonth->modify('+1 day')->format('Y-m-d'), $olderMonth->modify('+2 days')->format('Y-m-d'), 'confirmed');
    }
    $denseMonth = $service->month($actor, $olderMonth->format('Y-m'));
    $denseDayIndex = (int) $olderMonth->modify('+1 day')->format('N') - 1;
    $denseWeek = array_values(array_filter($denseMonth['weeks'], static fn (array $week): bool =>
        $week['days'][$denseDayIndex]['date'] === $olderMonth->modify('+1 day')->format('Y-m-d')))[0];
    $check($denseWeek['visible_lane_count'] === RentalCalendarService::VISIBLE_LANES
        && $denseWeek['overflow'][$denseDayIndex] >= 2,
        'Dense dates have a bounded visible lane count and an explicit overflow count.');

    $boundaryRental = $newRental('boundary');
    $boundaryIncluded = $newItem($boundaryRental, 'boundary-in', '2100-11-30', '2100-12-02', 'confirmed');
    $boundaryEndedBefore = $newItem($boundaryRental, 'boundary-out-before', '2100-11-29', '2100-12-01', 'confirmed');
    $boundaryStartsAfter = $newItem($boundaryRental, 'boundary-out-after', '2101-01-01', '2101-01-02', 'confirmed');
    $december = $service->month($actor, '2100-12');
    $boundaryIds = array_column($december['events'], 'rental_item_id');
    $check(in_array($boundaryIncluded, $boundaryIds, true)
        && !in_array($boundaryEndedBefore, $boundaryIds, true)
        && !in_array($boundaryStartsAfter, $boundaryIds, true),
        'Month query includes partial overlap and respects the exclusive end-date boundary.');

    $fullRental = $newRental('cap');
    $capItems = [];
    for ($index = 0; $index < 201; ++$index) {
        $suffix = 'cap-' . $index;
        $capItems[] = $newItem($fullRental, $suffix, '2099-06-01', '2099-06-02', 'confirmed');
    }
    $capped = $service->month($actor, '2099-06');
    $check(count($capped['events']) === RentalCalendarRepository::MAX_ITEMS && $capped['truncated'],
        'Calendar reads at most 201 rows to identify and report a truncated 200-item projection.');

    echo "Admin calendar checks: $checks passed, 0 failed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Admin calendar checks failed after ' . $checks . ' checks: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    $db->rollback();
    $db->close();
}
