<?php

declare(strict_types=1);

namespace Verdant\Admin;

use Verdant\Persistence\Repository;

/** Bounded, read-only rental calendar projection. */
final class RentalCalendarRepository extends Repository
{
    public const MAX_ITEMS = 200;

    /** @return list<array<string, mixed>> */
    public function itemsIntersecting(string $monthStart, string $monthEndExclusive): array
    {
        return $this->select(
            "SELECT i.rental_item_id, r.rental_id, r.state AS rental_state,
                    c.name AS customer_name, i.product_name_snapshot, i.sku_snapshot,
                    i.quantity, i.start_date, i.end_date_exclusive,
                    a.state AS allocation_state,
                    COALESCE(iss.quantity, 0) AS issued_quantity,
                    COALESCE(ret.returned_quantity, 0) AS returned_quantity,
                    GREATEST(COALESCE(iss.quantity, 0) - COALESCE(ret.returned_quantity, 0), 0) AS outstanding_quantity
             FROM rental_items i
             JOIN rentals r ON r.rental_id = i.rental_id
             JOIN customers c ON c.customer_id = r.customer_id
             JOIN rental_allocations a ON a.rental_item_id = i.rental_item_id
             LEFT JOIN rental_issues iss ON iss.rental_item_id = i.rental_item_id
             LEFT JOIN (
                 SELECT rental_item_id, SUM(quantity) AS returned_quantity
                 FROM rental_returns GROUP BY rental_item_id
             ) ret ON ret.rental_item_id = i.rental_item_id
             WHERE r.archived_at IS NULL
               AND i.start_date < ?
               AND i.end_date_exclusive > ?
             ORDER BY i.start_date, r.rental_id, i.rental_item_id
             LIMIT 201",
            [$monthEndExclusive, $monthStart],
        );
    }

    /** @return array{item_count:int,outstanding_quantity:int} */
    public function overdueSummary(string $today): array
    {
        $row = $this->select(
            "SELECT COUNT(*) AS item_count,
                    COALESCE(SUM(GREATEST(iss.quantity - COALESCE(ret.returned_quantity, 0), 0)), 0) AS outstanding_quantity
             FROM rental_items i
             JOIN rentals r ON r.rental_id = i.rental_id
             JOIN rental_allocations a ON a.rental_item_id = i.rental_item_id
             JOIN rental_issues iss ON iss.rental_item_id = i.rental_item_id
             LEFT JOIN (
                 SELECT rental_item_id, SUM(quantity) AS returned_quantity
                 FROM rental_returns GROUP BY rental_item_id
             ) ret ON ret.rental_item_id = i.rental_item_id
             WHERE r.archived_at IS NULL
               AND r.state <> 'cancelled'
               AND a.state = 'issued'
               AND i.end_date_exclusive <= ?
               AND iss.quantity > COALESCE(ret.returned_quantity, 0)",
            [$today],
        )[0] ?? [];

        return [
            'item_count' => (int) ($row['item_count'] ?? 0),
            'outstanding_quantity' => (int) ($row['outstanding_quantity'] ?? 0),
        ];
    }
}
