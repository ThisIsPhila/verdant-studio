<?php

declare(strict_types=1);

namespace Verdant\Inventory;

/** Pure half-open interval capacity calculations for shared rental stock. */
final class RentalCapacity
{
    /**
     * Return the greatest simultaneous claim in [start, endExclusive).
     * Claims are arrays with start_date, end_date_exclusive (null means open-ended),
     * and quantity. End events sort before start events on the same date.
     */
    public static function peak(array $claims, string $start, string $endExclusive): int
    {
        RentalPeriod::validateRange($start, $endExclusive);
        $events = [];
        foreach ($claims as $claim) {
            $quantity = $claim['quantity'] ?? null;
            $claimStart = $claim['start_date'] ?? null;
            $claimEnd = $claim['end_date_exclusive'] ?? null;
            if (!is_int($quantity) || $quantity < 1 || !is_string($claimStart)) {
                throw new \InvalidArgumentException('Capacity claims require a positive integer quantity and valid dates.');
            }
            RentalPeriod::validateDate($claimStart);
            if ($claimEnd !== null) {
                if (!is_string($claimEnd)) {
                    throw new \InvalidArgumentException('Capacity claim end must be a date or null.');
                }
                RentalPeriod::validateRange($claimStart, $claimEnd);
            }
            $from = $claimStart > $start ? $claimStart : $start;
            $to = $claimEnd === null || $claimEnd > $endExclusive ? $endExclusive : $claimEnd;
            if ($from >= $to) {
                continue;
            }
            $events[$from][] = $quantity;
            $events[$to][] = -$quantity;
        }

        ksort($events, SORT_STRING);
        $current = 0;
        $peak = 0;
        foreach ($events as $deltas) {
            // At a shared boundary, a claim ending that day no longer overlaps one starting that day.
            sort($deltas, SORT_NUMERIC);
            foreach ($deltas as $delta) {
                if ($delta > 0 && $current > PHP_INT_MAX - $delta) {
                    throw new \OverflowException('Simultaneous rental quantity exceeds the supported integer range.');
                }
                $current += $delta;
            }
            $peak = max($peak, $current);
        }
        return $peak;
    }

}
