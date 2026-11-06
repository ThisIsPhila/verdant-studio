<?php

declare(strict_types=1);

namespace Verdant\Inventory;

/** Strict calendar-date validation and inclusive customer date conversion. */
final class RentalPeriod
{
    public static function validateDate(string $date): void
    {
        if (preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $date) !== 1) {
            throw new \InvalidArgumentException('Dates must be supported ISO calendar dates (YYYY-MM-DD).');
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Africa/Johannesburg'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Dates must be valid ISO calendar dates (YYYY-MM-DD).');
        }
    }

    public static function validateRange(string $start, string $endExclusive): void
    {
        self::validateDate($start);
        self::validateDate($endExclusive);
        if ($endExclusive <= $start) {
            throw new \InvalidArgumentException('The exclusive end date must be after the start date.');
        }
    }

    /** Convert customer-selected inclusive end date into the persisted exclusive boundary. */
    public static function fromInclusive(string $start, string $endInclusive): array
    {
        self::validateDate($start);
        self::validateDate($endInclusive);
        if ($endInclusive < $start) {
            throw new \InvalidArgumentException('The end date cannot be before the start date.');
        }
        $exclusive = self::addDays($endInclusive, 1);
        self::validateRange($start, $exclusive);
        return ['start_date' => $start, 'end_date_exclusive' => $exclusive];
    }

    public static function addDays(string $date, int $days): string
    {
        self::validateDate($date);
        if ($days < 0) {
            throw new \InvalidArgumentException('Day increment must be nonnegative.');
        }
        $result = (new \DateTimeImmutable($date, new \DateTimeZone('Africa/Johannesburg')))->modify('+' . $days . ' days');
        if ($result === false) {
            throw new \InvalidArgumentException('Date is outside the supported calendar range.');
        }
        $formatted = $result->format('Y-m-d');
        self::validateDate($formatted);
        return $formatted;
    }

    /** Checked rental total in integer minor currency units. */
    public static function totalMinor(int $quantity, string $start, string $endExclusive, int $dailyRateMinor): int
    {
        self::validateRange($start, $endExclusive);
        if ($quantity < 1 || $dailyRateMinor < 0) {
            throw new \InvalidArgumentException('Quantity must be positive and daily rate nonnegative.');
        }
        $days = (int) (new \DateTimeImmutable($start, new \DateTimeZone('Africa/Johannesburg')))
            ->diff(new \DateTimeImmutable($endExclusive, new \DateTimeZone('Africa/Johannesburg')))->days;
        if ($dailyRateMinor !== 0 && $quantity > intdiv(PHP_INT_MAX, $dailyRateMinor)) {
            throw new \OverflowException('Rental amount exceeds the supported integer range.');
        }
        $perDay = $quantity * $dailyRateMinor;
        if ($days !== 0 && $perDay > intdiv(PHP_INT_MAX, $days)) {
            throw new \OverflowException('Rental amount exceeds the supported integer range.');
        }
        return $perDay * $days;
    }
}
