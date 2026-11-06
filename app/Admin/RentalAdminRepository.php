<?php

declare(strict_types=1);

namespace Verdant\Admin;

use Verdant\Persistence\Repository;

final class RentalAdminRepository extends Repository
{
    private const OUTSTANDING_RETURNS = '
        FROM rental_items i
        JOIN rentals r ON r.rental_id = i.rental_id
        JOIN customers c ON c.customer_id = r.customer_id
        JOIN rental_issues iss ON iss.rental_item_id = i.rental_item_id
        LEFT JOIN (
            SELECT rental_item_id, SUM(quantity) AS quantity
            FROM rental_returns GROUP BY rental_item_id
        ) ret ON ret.rental_item_id = i.rental_item_id
        WHERE iss.quantity > COALESCE(ret.quantity, 0)';

    /** Archived reservations still owe their physically issued items. */
    public function outstandingReturns(int $limit, int $offset): array
    {
        return $this->select(
            'SELECT r.rental_id, r.archived_at, c.name AS customer_name,
                    i.rental_item_id, i.product_name_snapshot, i.end_date_exclusive,
                    iss.quantity AS issued_quantity, COALESCE(ret.quantity, 0) AS returned_quantity,
                    iss.quantity - COALESCE(ret.quantity, 0) AS outstanding_quantity '
                . self::OUTSTANDING_RETURNS
                . ' ORDER BY i.end_date_exclusive, r.rental_id, i.rental_item_id LIMIT ? OFFSET ?',
            [$limit, $offset],
        );
    }

    public function outstandingReturnCount(): int
    {
        return (int) ($this->select('SELECT COUNT(*) AS total ' . self::OUTSTANDING_RETURNS)[0]['total'] ?? 0);
    }

    public function rentals(int $limit, int $offset): array
    {
        return $this->select(
            "SELECT r.rental_id, r.state, r.archived_at, r.created_at, c.name AS customer_name, c.email AS customer_email,
                    (SELECT MIN(i.start_date) FROM rental_items i WHERE i.rental_id=r.rental_id) AS start_date,
                    (SELECT MAX(i.end_date_exclusive) FROM rental_items i WHERE i.rental_id=r.rental_id) AS end_date_exclusive,
                    (SELECT COALESCE(SUM(i.quantity),0) FROM rental_items i WHERE i.rental_id=r.rental_id) AS booked_quantity,
                    (SELECT COALESCE(SUM(GREATEST(COALESCE(iss.quantity,0)-COALESCE(ret.quantity,0),0)),0)
                     FROM rental_items i LEFT JOIN rental_issues iss ON iss.rental_item_id=i.rental_item_id
                     LEFT JOIN (SELECT rental_item_id,SUM(quantity) AS quantity FROM rental_returns GROUP BY rental_item_id) ret
                       ON ret.rental_item_id=i.rental_item_id WHERE i.rental_id=r.rental_id) AS outstanding_quantity
             FROM rentals r JOIN customers c ON c.customer_id=r.customer_id
             ORDER BY (r.archived_at IS NOT NULL), r.created_at DESC, r.rental_id DESC LIMIT ? OFFSET ?",
            [$limit, $offset],
        );
    }

    /** @return list<array<string,mixed>> One row per rental item, ordered for a stable projection. */
    public function rentalTimingRows(): array
    {
        return $this->select(
            "SELECT r.rental_id, r.state, r.archived_at, r.created_at,
                    c.name AS customer_name, c.email AS customer_email,
                    i.rental_item_id, i.product_name_snapshot, i.sku_snapshot, i.quantity,
                    i.start_date, i.end_date_exclusive, a.state AS allocation_state,
                    COALESCE(iss.quantity, 0) AS issued_quantity,
                    COALESCE(ret.returned_quantity, 0) AS returned_quantity
             FROM rentals r
             JOIN customers c ON c.customer_id = r.customer_id
             LEFT JOIN rental_items i ON i.rental_id = r.rental_id
             LEFT JOIN rental_allocations a ON a.rental_item_id = i.rental_item_id
             LEFT JOIN rental_issues iss ON iss.rental_item_id = i.rental_item_id
             LEFT JOIN (
                 SELECT rental_item_id, SUM(quantity) AS returned_quantity
                 FROM rental_returns GROUP BY rental_item_id
             ) ret ON ret.rental_item_id = i.rental_item_id
             ORDER BY r.rental_id DESC, i.start_date, i.rental_item_id"
        );
    }

    public function rentalCount(): int
    {
        return (int) ($this->select('SELECT COUNT(*) AS total FROM rentals')[0]['total'] ?? 0);
    }

    public function rental(int $rentalId): ?array
    {
        $rental = $this->select(
            'SELECT r.rental_id,r.customer_id,r.state,r.archived_at,r.created_at,r.updated_at,c.name AS customer_name,c.email AS customer_email
             FROM rentals r JOIN customers c ON c.customer_id=r.customer_id WHERE r.rental_id=?',
            [$rentalId],
        )[0] ?? null;
        if ($rental === null) {
            return null;
        }
        $rental['items'] = $this->select(
            'SELECT i.rental_item_id,i.product_id,i.product_name_snapshot,i.sku_snapshot,i.quantity,i.start_date,i.end_date_exclusive,
                    i.daily_rate_minor,a.state AS allocation_state,COALESCE(iss.quantity,0) AS issued_quantity,iss.issued_at,
                    iss.actor_id AS issued_by_id,issued_by.name AS issued_by_name,
                    COALESCE(ret.returned_quantity,0) AS returned_quantity,
                    GREATEST(COALESCE(iss.quantity,0)-COALESCE(ret.returned_quantity,0),0) AS outstanding_quantity,
                    i.quantity * DATEDIFF(i.end_date_exclusive,i.start_date) * i.daily_rate_minor AS total_minor
             FROM rental_items i JOIN rental_allocations a ON a.rental_item_id=i.rental_item_id
             LEFT JOIN rental_issues iss ON iss.rental_item_id=i.rental_item_id
             LEFT JOIN customers issued_by ON issued_by.customer_id=iss.actor_id
             LEFT JOIN (SELECT rental_item_id,SUM(quantity) AS returned_quantity FROM rental_returns GROUP BY rental_item_id) ret
               ON ret.rental_item_id=i.rental_item_id
             WHERE i.rental_id=? ORDER BY i.rental_item_id',
            [$rentalId],
        );
        foreach ($rental['items'] as &$item) {
            $item['returns'] = $this->select(
                'SELECT rr.rental_return_id,rr.quantity,rr.returned_at,rr.actor_id,actor.name AS actor_name
                 FROM rental_returns rr LEFT JOIN customers actor ON actor.customer_id=rr.actor_id
                 WHERE rr.rental_item_id=? ORDER BY rr.returned_at,rr.rental_return_id',
                [(int) $item['rental_item_id']],
            );
        }
        unset($item);
        return $rental;
    }

    public function setArchived(int $rentalId, bool $archived): void
    {
        $this->execute(
            $archived
                ? 'UPDATE rentals SET archived_at=UTC_TIMESTAMP() WHERE rental_id=? AND archived_at IS NULL'
                : 'UPDATE rentals SET archived_at=NULL WHERE rental_id=? AND archived_at IS NOT NULL',
            [$rentalId],
        );
    }
}
