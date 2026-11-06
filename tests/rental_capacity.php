<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Inventory\RentalCapacity;
use Verdant\Inventory\RentalPeriod;

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    ++$checks;
    echo "PASS: $label\n";
};
$rejects = static function (callable $action, string $label) use ($check): void {
    try {
        $action();
    } catch (InvalidArgumentException|OverflowException) {
        $check(true, $label);
        return;
    }
    throw new RuntimeException($label);
};

$claims = [
    ['start_date' => '2026-11-01', 'end_date_exclusive' => '2026-11-03', 'quantity' => 2],
    ['start_date' => '2026-11-03', 'end_date_exclusive' => '2026-11-05', 'quantity' => 3],
    ['start_date' => '2026-11-02', 'end_date_exclusive' => '2026-11-04', 'quantity' => 1],
];
$check(RentalCapacity::peak($claims, '2026-11-01', '2026-11-05') === 4,
    'Sweep-line peak sums simultaneous claims without adding disjoint bookings.');
$check(RentalCapacity::peak($claims, '2026-11-01', '2026-11-03') === 3,
    'Half-open adjacency releases the ending claim before the next interval begins.');
$check(RentalCapacity::peak([
    ['start_date' => '2026-11-01', 'end_date_exclusive' => '2026-11-02', 'quantity' => 2],
    ['start_date' => '2026-11-01', 'end_date_exclusive' => '2026-11-02', 'quantity' => 3],
], '2026-11-01', '2026-11-02') === 5, 'Same-period quantities add concurrently.');
$check(RentalCapacity::peak([
    ['start_date' => '2026-11-02', 'end_date_exclusive' => null, 'quantity' => 2],
], '2026-11-04', '2026-11-05') === 2, 'Open-ended overdue claims remain unavailable.');
$check(RentalPeriod::fromInclusive('2026-11-01', '2026-11-01') === [
    'start_date' => '2026-11-01', 'end_date_exclusive' => '2026-11-02',
], 'A same-day inclusive rental becomes a one-day half-open period.');
$check(RentalPeriod::totalMinor(3, '2026-11-01', '2026-11-04', 125) === 1125,
    'Rate calculation multiplies quantity, calendar days, and integer minor units.');
$rejects(static fn () => RentalPeriod::validateDate('2026-02-30'), 'Impossible calendar dates are rejected.');
$rejects(static fn () => RentalPeriod::validateDate('2026-1-01'), 'Noncanonical date strings are rejected.');
$rejects(static fn () => RentalPeriod::validateRange('2026-11-02', '2026-11-02'), 'Empty/reversed half-open periods are rejected.');
$rejects(static fn () => RentalPeriod::fromInclusive('2026-11-03', '2026-11-02'), 'Inclusive end before start is rejected.');
$rejects(static fn () => RentalCapacity::peak([
    ['start_date' => '2026-11-01', 'end_date_exclusive' => '2026-11-02', 'quantity' => 0],
], '2026-11-01', '2026-11-02'), 'Nonpositive claim quantities are rejected.');
$rejects(static fn () => RentalCapacity::peak([
    ['start_date' => '2026-11-01', 'end_date_exclusive' => '2026-11-02', 'quantity' => PHP_INT_MAX],
    ['start_date' => '2026-11-01', 'end_date_exclusive' => '2026-11-02', 'quantity' => 1],
], '2026-11-01', '2026-11-02'), 'Simultaneous claim quantity overflow is rejected.');
$rejects(static fn () => RentalPeriod::totalMinor(PHP_INT_MAX, '2026-11-01', '2026-11-03', 2), 'Rental amount overflow is rejected.');

echo "Rental capacity checks: $checks passed, 0 failed.\n";
