<?php

declare(strict_types=1);

namespace Verdant\Inventory;

use Verdant\Persistence\Repository;

/** Read-only capacity previews shared by rental and retail availability. */
final class CapacityRepository extends Repository
{
    public function __construct(\mysqli $connection, private readonly ?string $businessDate = null)
    {
        parent::__construct($connection);
        if ($businessDate !== null) {
            RentalPeriod::validateDate($businessDate);
        }
    }

    /** Capacity left for the requested half-open rental period after retail holds and rental claims. */
    public function availableForPeriod(int $productId, string $start, string $endExclusive, ?int $excludeRentalId = null): int
    {
        $this->validateProductId($productId);
        RentalPeriod::validateRange($start, $endExclusive);
        if ($excludeRentalId !== null && $excludeRentalId < 1) {
            throw new \InvalidArgumentException('Excluded rental ID must be positive.');
        }
        $inventory = $this->select('SELECT on_hand FROM inventory WHERE product_id = ?', [$productId])[0] ?? null;
        if ($inventory === null) {
            return 0;
        }
        $claims = $this->claims($productId, $excludeRentalId);
        $peak = RentalCapacity::peak($claims['claims'], $start, $endExclusive);
        return max(0, (int) $inventory['on_hand'] - $this->retailHolds($productId) - max($peak, $claims['outstanding']));
    }

    /** Retail sale capacity: active retail holds plus the greatest future rental commitment. */
    public function retailAvailable(int $productId): int
    {
        $this->validateProductId($productId);
        $inventory = $this->select('SELECT on_hand FROM inventory WHERE product_id = ?', [$productId])[0] ?? null;
        if ($inventory === null) {
            return 0;
        }
        $claims = $this->claims($productId, null);
        $today = $this->businessDate ?? (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
        $futureClaims = [];
        $latestEnd = $today;
        $latestStart = $today;
        foreach ($claims['claims'] as $claim) {
            $end = $claim['end_date_exclusive'];
            if ($end !== null && $end <= $today) {
                continue;
            }
            $claim['start_date'] = max($today, $claim['start_date']);
            if ($claim['start_date'] >= ($end ?? '9999-12-31')) {
                continue;
            }
            $futureClaims[] = $claim;
            if ($claim['start_date'] > $latestStart) {
                $latestStart = $claim['start_date'];
            }
            if ($end !== null && $end > $latestEnd) {
                $latestEnd = $end;
            }
        }
        $latestDate = max($latestEnd, $latestStart);
        $horizonEnd = $latestDate === '9999-12-31' ? $latestDate : RentalPeriod::addDays($latestDate, 1);
        $futurePeak = $futureClaims === [] || $horizonEnd <= $today
            ? 0 : RentalCapacity::peak($futureClaims, $today, $horizonEnd);
        return max(0, (int) $inventory['on_hand'] - $this->retailHolds($productId)
            - max($futurePeak, $claims['outstanding']));
    }

    /** Current physically issued balance is deliberately independent of scheduled dates. */
    public function outstandingIssued(int $productId): int
    {
        $this->validateProductId($productId);
        return $this->claims($productId, null)['outstanding'];
    }

    private function claims(int $productId, ?int $excludeRentalId): array
    {
        $excludeSql = $excludeRentalId === null ? '' : ' AND r.rental_id <> ?';
        $parameters = $excludeRentalId === null ? [$productId] : [$productId, $excludeRentalId];
        $sql = "SELECT ri.start_date, ri.end_date_exclusive, a.quantity AS allocation_quantity, a.state AS allocation_state,
                       iss.quantity AS issued_quantity,
                       COALESCE((SELECT SUM(ret.quantity) FROM rental_returns ret WHERE ret.rental_item_id = ri.rental_item_id), 0) AS returned_quantity
                FROM rental_items ri
                JOIN rental_allocations a ON a.rental_item_id = ri.rental_item_id
                JOIN rentals r ON r.rental_id = ri.rental_id
                LEFT JOIN rental_issues iss ON iss.rental_item_id = ri.rental_item_id
                WHERE ri.product_id = ?
                  AND (r.state IN ('pending_payment', 'confirmed', 'reconciliation_required') OR iss.rental_item_id IS NOT NULL)
                  AND (a.state IN ('held', 'confirmed', 'issued', 'reconciliation_required') OR iss.rental_item_id IS NOT NULL)
                  $excludeSql";
        $rows = $this->select($sql, $parameters);
        $claims = [];
        $outstanding = 0;
        foreach ($rows as $row) {
            $allocated = (int) $row['allocation_quantity'];
            if ($row['issued_quantity'] !== null) {
                $issued = (int) ($row['issued_quantity'] ?? 0);
                $remaining = max(0, $issued - (int) $row['returned_quantity']);
                if ($remaining > PHP_INT_MAX - $outstanding) {
                    throw new \OverflowException('Outstanding rental quantity exceeds the supported integer range.');
                }
                $outstanding += $remaining;
                if ($remaining > 0) {
                    // The dated reservation and its overdue tail are one uninterrupted claim.
                    $claims[] = ['start_date' => $row['start_date'], 'end_date_exclusive' => null, 'quantity' => $remaining];
                }
            } elseif ($allocated > 0) {
                $claims[] = ['start_date' => $row['start_date'], 'end_date_exclusive' => $row['end_date_exclusive'], 'quantity' => $allocated];
            }
        }
        return ['claims' => $claims, 'outstanding' => $outstanding];
    }

    private function retailHolds(int $productId): int
    {
        $row = $this->select(
            "SELECT COALESCE(SUM(h.quantity), 0) AS quantity
             FROM retail_holds h JOIN order_items oi ON oi.order_item_id = h.order_item_id
             JOIN sales_orders so ON so.order_id = oi.order_id
             WHERE oi.product_id = ? AND so.state IN ('pending_payment', 'reconciliation_required')
               AND h.state IN ('active', 'reconciliation_required')",
            [$productId],
        )[0];
        return (int) $row['quantity'];
    }

    private function validateProductId(int $productId): void
    {
        if ($productId < 1) {
            throw new \InvalidArgumentException('Product ID must be positive.');
        }
    }
}
