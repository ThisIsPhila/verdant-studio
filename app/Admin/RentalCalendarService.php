<?php

declare(strict_types=1);

namespace Verdant\Admin;

use DateTimeImmutable;
use DateTimeZone;

final class RentalCalendarService
{
    private const ZONE = 'Africa/Johannesburg';
    private const MIN_MONTH = '2000-01';
    private const MAX_MONTH = '2100-12';
    public const VISIBLE_LANES = 4;

    public function __construct(
        private readonly RentalCalendarRepository $calendar,
        private readonly AdminAccess $access,
    ) {
    }

    /**
     * Build a bounded, read-only month projection from persisted rental records.
     *
     * @return array{
     *   month:string,month_label:string,today:string,previous_month:?string,next_month:?string,
     *   weeks:list<list<array{date:string,day:int,in_month:bool,is_today:bool,events:list<array<string,mixed>>}>>,
     *   events:list<array<string,mixed>>,truncated:bool,overdue_summary:array{item_count:int,outstanding_quantity:int}
     * }
     */
    public function month(array $actor, mixed $requestedMonth = null): array
    {
        $this->access->require($actor);

        $zone = new DateTimeZone(self::ZONE);
        $today = new DateTimeImmutable('today', $zone);
        if ($requestedMonth !== null && !is_string($requestedMonth)) {
            throw new \InvalidArgumentException('Choose a valid calendar month.');
        }
        $month = $requestedMonth ?? $today->format('Y-m');
        if (!$this->validMonth($month)) {
            throw new \InvalidArgumentException('Choose a month from January 2000 through December 2100.');
        }

        $monthStart = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', $zone);
        if ($monthStart === false) {
            throw new \InvalidArgumentException('Choose a valid calendar month.');
        }
        $monthEnd = $monthStart->modify('first day of next month');
        $monthEndExclusive = $monthEnd->format('Y-m-d');
        $rows = $this->calendar->itemsIntersecting($monthStart->format('Y-m-d'), $monthEndExclusive);
        $truncated = count($rows) > RentalCalendarRepository::MAX_ITEMS;
        if ($truncated) {
            $rows = array_slice($rows, 0, RentalCalendarRepository::MAX_ITEMS);
        }

        $events = [];
        foreach ($rows as $row) {
            $row['rental_item_id'] = (int) $row['rental_item_id'];
            $row['rental_id'] = (int) $row['rental_id'];
            $row['quantity'] = (int) $row['quantity'];
            $row['issued_quantity'] = (int) $row['issued_quantity'];
            $row['returned_quantity'] = (int) $row['returned_quantity'];
            $row['outstanding_quantity'] = (int) $row['outstanding_quantity'];
            $row['status_label'] = $this->statusLabel($row, $today->format('Y-m-d'));
            $row['status_key'] = $this->statusKey($row, $today->format('Y-m-d'));
            $events[] = $row;
        }

        $firstCell = $monthStart->modify('-' . ((int) $monthStart->format('N') - 1) . ' days');
        $lastMonthDay = $monthEnd->modify('-1 day');
        $lastCell = $lastMonthDay->modify('+' . (7 - (int) $lastMonthDay->format('N')) . ' days');
        $weeks = [];
        $week = [];
        for ($day = $firstCell; $day <= $lastCell; $day = $day->modify('+1 day')) {
            $isoDate = $day->format('Y-m-d');
            $dayEvents = [];
            if ($day >= $monthStart && $day < $monthEnd) {
                foreach ($events as $event) {
                    if ($event['start_date'] <= $isoDate && $event['end_date_exclusive'] > $isoDate) {
                        $dayEvents[] = $event;
                    }
                }
            }
            $week[] = [
                'date' => $isoDate,
                'day' => (int) $day->format('j'),
                'in_month' => $day->format('Y-m') === $month,
                'is_today' => $isoDate === $today->format('Y-m-d'),
                'events' => $dayEvents,
            ];
            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }
        }

        // Assign one deterministic lane per item for the whole visible month. The same
        // item therefore stays on the same lane when its range continues into another week.
        $laneEnds = [];
        $eventLanes = [];
        foreach ($events as $eventIndex => $event) {
            $lane = 0;
            while (isset($laneEnds[$lane]) && $laneEnds[$lane] > $event['start_date']) {
                ++$lane;
            }
            $laneEnds[$lane] = $event['end_date_exclusive'];
            $eventLanes[$eventIndex] = $lane;
        }
        foreach ($weeks as $weekIndex => $weekCells) {
            $weekStart = $weekCells[0]['date'];
            $weekEnd = (new DateTimeImmutable($weekCells[6]['date'], $zone))->modify('+1 day')->format('Y-m-d');
            $segments = [];
            $overflow = array_fill(0, 7, 0);
            foreach ($events as $eventIndex => $event) {
                if ($event['start_date'] >= $weekEnd || $event['end_date_exclusive'] <= $weekStart) {
                    continue;
                }
                $segmentStart = max($event['start_date'], $weekStart);
                $segmentEnd = min($event['end_date_exclusive'], $weekEnd);
                $startColumn = (int) (new DateTimeImmutable($segmentStart, $zone))->format('N');
                $endColumn = (int) (new DateTimeImmutable($segmentEnd, $zone))->modify('-1 day')->format('N');
                $lane = $eventLanes[$eventIndex];
                $segments[] = $event + [
                    'lane' => $lane,
                    'start_column' => $startColumn,
                    'span' => $endColumn - $startColumn + 1,
                    'continues_before' => $event['start_date'] < $weekStart,
                    'continues_after' => $event['end_date_exclusive'] > $weekEnd,
                ];
                if ($lane >= self::VISIBLE_LANES) {
                    for ($column = $startColumn; $column <= $endColumn; ++$column) {
                        if ($weekCells[$column - 1]['in_month']) {
                            ++$overflow[$column - 1];
                        }
                    }
                }
            }
            usort($segments, static fn (array $left, array $right): int =>
                ($left['lane'] <=> $right['lane']) ?: (($left['start_column'] <=> $right['start_column'])
                    ?: ($left['rental_item_id'] <=> $right['rental_item_id'])));
            $weeks[$weekIndex] = [
                'days' => $weekCells,
                'segments' => $segments,
                'overflow' => $overflow,
                'visible_lane_count' => self::VISIBLE_LANES,
            ];
        }

        return [
            'month' => $month,
            'month_label' => $monthStart->format('F Y'),
            'today' => $today->format('Y-m-d'),
            'previous_month' => $month > self::MIN_MONTH ? $monthStart->modify('-1 month')->format('Y-m') : null,
            'next_month' => $month < self::MAX_MONTH ? $monthStart->modify('+1 month')->format('Y-m') : null,
            'weeks' => $weeks,
            'events' => $events,
            'truncated' => $truncated,
            'overdue_summary' => $this->calendar->overdueSummary($today->format('Y-m-d')),
        ];
    }

    private function validMonth(string $month): bool
    {
        if (preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/D', $month) !== 1
            || $month < self::MIN_MONTH || $month > self::MAX_MONTH) {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m', $month, new DateTimeZone(self::ZONE));
        return $parsed !== false && $parsed->format('Y-m') === $month;
    }

    /** @param array<string,mixed> $row */
    private function statusLabel(array $row, string $today): string
    {
        if ($row['rental_state'] === 'cancelled') {
            return 'Cancelled';
        }
        if ($row['rental_state'] === 'reconciliation_required' || $row['allocation_state'] === 'reconciliation_required') {
            return 'Reconciliation required';
        }
        if ($row['issued_quantity'] > 0) {
            if ($row['outstanding_quantity'] === 0) {
                return 'Returned';
            }
            return $row['end_date_exclusive'] <= $today ? 'Overdue return' : 'Issued';
        }
        return match ($row['allocation_state']) {
            'confirmed' => 'Booked',
            'held' => 'Pending payment',
            'closed' => 'Closed',
            'released' => 'Released',
            default => 'Pending payment',
        };
    }

    /** @param array<string,mixed> $row */
    private function statusKey(array $row, string $today): string
    {
        $label = $this->statusLabel($row, $today);
        return match ($label) {
            'Cancelled' => 'cancelled',
            'Reconciliation required' => 'reconciliation',
            'Returned' => 'returned',
            'Overdue return' => 'overdue',
            'Issued' => 'issued',
            'Booked' => 'booked',
            'Closed' => 'closed',
            'Released' => 'released',
            default => 'pending',
        };
    }
}
