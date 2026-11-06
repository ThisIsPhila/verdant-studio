<?php

declare(strict_types=1);

namespace Verdant\Admin;

use Verdant\Persistence\TransactionManager;
use Verdant\Rentals\RentalService;

final class RentalAdminService
{
    public function __construct(
        private readonly RentalAdminRepository $repository,
        private readonly RentalService $rentals,
        private readonly TransactionManager $transactions,
        private readonly AdminAccess $access,
    ) {
    }

    public function rentals(array $actor, int $page = 1, string $group = ''): array
    {
        $this->access->require($actor);
        if ($page < 1) {
            $page = 1;
        }
        $pageSize = 20;
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
        $groupKeys = ['overdue', 'current', 'upcoming', 'history'];
        $rows = $this->repository->rentalTimingRows();
        $records = [];
        foreach ($rows as $row) {
            $rentalId = (int) $row['rental_id'];
            if (!isset($records[$rentalId])) {
                $records[$rentalId] = [
                    'rental_id' => $rentalId,
                    'state' => $row['state'],
                    'archived_at' => $row['archived_at'],
                    'created_at' => $row['created_at'],
                    'customer_name' => $row['customer_name'],
                    'customer_email' => $row['customer_email'],
                    'items' => [],
                ];
            }
            if ($row['rental_item_id'] === null) {
                continue;
            }
            $row['rental_id'] = $rentalId;
            foreach (['rental_item_id', 'quantity', 'issued_quantity', 'returned_quantity'] as $number) {
                $row[$number] = (int) $row[$number];
            }
            $row['outstanding_quantity'] = max(0, $row['issued_quantity'] - $row['returned_quantity']);
            $row['overdue'] = $row['end_date_exclusive'] <= $today && $row['outstanding_quantity'] > 0
                && $row['state'] !== 'cancelled' && $row['archived_at'] === null;
            $records[$rentalId]['items'][] = $row;
        }

        $groups = array_fill_keys($groupKeys, []);
        foreach ($records as $record) {
            $active = $record['archived_at'] === null && $record['state'] !== 'cancelled';
            $overdueItems = array_values(array_filter($record['items'], static fn (array $item): bool => $item['overdue']));
            $currentItems = array_values(array_filter($record['items'], static fn (array $item): bool =>
                $item['start_date'] <= $today && $item['end_date_exclusive'] > $today
                && in_array($item['allocation_state'], ['confirmed', 'issued'], true)));
            $upcomingItems = array_values(array_filter($record['items'], static fn (array $item): bool =>
                $item['start_date'] > $today && !in_array($item['allocation_state'], ['closed', 'released'], true)));
            if (!$active) {
                $groupKey = 'history';
            } elseif ($overdueItems !== []) {
                $groupKey = 'overdue';
            } elseif ($currentItems !== []) {
                $groupKey = 'current';
            } elseif ($upcomingItems !== []) {
                $groupKey = 'upcoming';
            } else {
                $groupKey = 'history';
            }
            $record['group'] = $groupKey;
            $record['overdue_item_count'] = count($overdueItems);
            $record['overdue_quantity'] = array_sum(array_column($overdueItems, 'outstanding_quantity'));
            $record['overdue_deadline'] = $overdueItems === [] ? null : min(array_column($overdueItems, 'end_date_exclusive'));
            $record['start_date'] = $record['items'] === [] ? null : min(array_column($record['items'], 'start_date'));
            $record['end_date_exclusive'] = $record['items'] === [] ? null : max(array_column($record['items'], 'end_date_exclusive'));
            $record['booked_quantity'] = array_sum(array_column($record['items'], 'quantity'));
            $record['outstanding_quantity'] = array_sum(array_column($record['items'], 'outstanding_quantity'));
            foreach ($record['items'] as &$item) {
                $item['timing_label'] = match (true) {
                    $item['overdue'] => 'Overdue return',
                    $record['state'] === 'cancelled' => 'Cancelled',
                    $item['outstanding_quantity'] === 0 && $item['issued_quantity'] > 0 => 'Returned',
                    $item['allocation_state'] === 'released' => 'Released',
                    $item['allocation_state'] === 'closed' => 'Closed',
                    $item['start_date'] <= $today && $item['end_date_exclusive'] > $today => 'Current',
                    $item['start_date'] > $today => $item['allocation_state'] === 'held' ? 'Awaiting payment' : 'Upcoming',
                    default => 'Past booking',
                };
            }
            unset($item);
            $record['sort_date'] = match ($groupKey) {
                'overdue' => $record['overdue_deadline'],
                'current' => min(array_column($currentItems, 'end_date_exclusive')),
                'upcoming' => min(array_column($upcomingItems, 'start_date')),
                default => $record['end_date_exclusive'] ?? $record['created_at'],
            };
            $groups[$groupKey][] = $record;
        }

        foreach ($groups as $key => &$groupRows) {
            usort($groupRows, static function (array $left, array $right) use ($key): int {
                $dateOrder = $key === 'history'
                    ? strcmp((string) $right['sort_date'], (string) $left['sort_date'])
                    : strcmp((string) $left['sort_date'], (string) $right['sort_date']);
                return $dateOrder ?: ((int) $left['rental_id'] <=> (int) $right['rental_id']);
            });
        }
        unset($groupRows);
        if (!in_array($group, $groupKeys, true)) {
            $group = '';
        }
        if ($group === '') {
            foreach ($groupKeys as $candidate) {
                if ($groups[$candidate] !== []) { $group = $candidate; break; }
            }
            $group = $group ?: 'history';
        }
        $total = count($groups[$group]);
        $pages = max(1, (int) ceil($total / $pageSize));
        $page = min($page, $pages);
        $items = array_slice($groups[$group], ($page - 1) * $pageSize, $pageSize);
        return [
            'items' => $items,
            'rentals' => $items,
            'groups' => array_map('count', $groups),
            'group' => $group,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'has_next' => $page < $pages,
            'today' => $today,
        ];
    }

    public function returns(array $actor, int $page = 1): array
    {
        $this->access->require($actor);
        $total = $this->repository->outstandingReturnCount();
        $pages = max(1, (int) ceil($total / 20));
        $page = min(max(1, $page), $pages);
        return [
            'items' => $this->repository->outstandingReturns(20, ($page - 1) * 20),
            'page' => $page, 'pages' => $pages, 'total' => $total,
            'today' => (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Johannesburg')))->format('Y-m-d'),
        ];
    }

    public function rental(array $actor, int $rentalId): ?array
    {
        $this->access->require($actor);
        if ($rentalId < 1) {
            return null;
        }
        $rental = $this->repository->rental($rentalId);
        if ($rental === null) {
            return null;
        }
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
        foreach ($rental['items'] as &$item) {
            $item['overdue'] = $item['end_date_exclusive'] <= $today && (int) $item['outstanding_quantity'] > 0;
        }
        unset($item);
        $rental['today'] = $today;
        return $rental;
    }

    public function issue(array $actor, int $rentalId): void
    {
        $this->access->require($actor);
        $this->rentals->issue((int) ($actor['customer_id'] ?? 0), (string) ($actor['role'] ?? ''), $rentalId);
    }

    public function recordReturn(array $actor, int $rentalId, int $rentalItemId, int $quantity, string $requestKey): array
    {
        $this->access->require($actor);
        if (preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $requestKey) !== 1) {
            throw new \InvalidArgumentException('Return details are invalid.');
        }
        return $this->rentals->return(
            (int) ($actor['customer_id'] ?? 0), (string) ($actor['role'] ?? ''),
            $rentalId, $rentalItemId, $quantity, $requestKey,
        );
    }

    public function cancel(array $actor, int $rentalId): void
    {
        $this->access->require($actor);
        $this->rentals->cancel((int) ($actor['customer_id'] ?? 0), (string) ($actor['role'] ?? ''), $rentalId);
    }

    public function setArchived(array $actor, int $rentalId, bool $archived): void
    {
        $this->access->require($actor);
        if ($rentalId < 1) {
            throw new \InvalidArgumentException('Choose a valid rental.');
        }
        $this->transactions->run(function () use ($actor, $rentalId, $archived): void {
            $this->access->require($actor, true);
            if ($this->repository->rental($rentalId) === null) {
                throw new \DomainException('Rental is unavailable.');
            }
            $this->repository->setArchived($rentalId, $archived);
        });
    }
}
