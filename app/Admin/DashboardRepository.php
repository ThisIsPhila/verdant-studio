<?php

declare(strict_types=1);

namespace Verdant\Admin;

use Verdant\Persistence\Repository;

final class DashboardRepository extends Repository
{
    /** @return array{product_count:int,customer_count:int,order_count:int,rental_count:int,outstanding_rental_count:int} */
    public function counts(): array
    {
        $row = $this->select(
            'SELECT
                (SELECT COUNT(*) FROM products WHERE archived_at IS NULL) AS product_count,
                (SELECT COUNT(*) FROM customers WHERE role = \'customer\' AND account_state = \'active\') AS customer_count,
                (SELECT COUNT(*) FROM sales_orders o
                 WHERE o.archived_at IS NULL AND NOT EXISTS (
                    SELECT 1 FROM composition_checkouts cc
                    WHERE cc.order_id = o.order_id AND cc.order_role = \'payment_anchor\'
                 )) AS order_count,
                (SELECT COUNT(*) FROM rentals WHERE archived_at IS NULL) AS rental_count,
                (SELECT COUNT(DISTINCT r.rental_id)
                 FROM rentals r
                 JOIN rental_items i ON i.rental_id = r.rental_id
                 JOIN rental_issues issued ON issued.rental_item_id = i.rental_item_id
                 LEFT JOIN (
                    SELECT rental_item_id, SUM(quantity) AS returned_quantity
                    FROM rental_returns GROUP BY rental_item_id
                 ) returned ON returned.rental_item_id = i.rental_item_id
                 WHERE r.archived_at IS NULL AND r.state = \'confirmed\'
                   AND issued.quantity > COALESCE(returned.returned_quantity, 0)
                ) AS outstanding_rental_count',
        )[0] ?? [];

        return [
            'product_count' => (int) ($row['product_count'] ?? 0),
            'customer_count' => (int) ($row['customer_count'] ?? 0),
            'order_count' => (int) ($row['order_count'] ?? 0),
            'rental_count' => (int) ($row['rental_count'] ?? 0),
            'outstanding_rental_count' => (int) ($row['outstanding_rental_count'] ?? 0),
        ];
    }

    /** @return list<array{order_id:int,customer_name:string,state:string,created_at:string,total_minor:int}> */
    public function recentOrders(int $limit): array
    {
        return $this->select(
            'SELECT o.order_id, c.name AS customer_name, o.state, o.created_at,
                    COALESCE(SUM(i.quantity * i.unit_price_minor), 0) AS total_minor
             FROM sales_orders o
             JOIN customers c ON c.customer_id = o.customer_id
             LEFT JOIN order_items i ON i.order_id = o.order_id
             WHERE o.archived_at IS NULL AND NOT EXISTS (
                SELECT 1 FROM composition_checkouts cc
                WHERE cc.order_id = o.order_id AND cc.order_role = \'payment_anchor\'
             )
             GROUP BY o.order_id, c.name, o.state, o.created_at
             ORDER BY o.created_at DESC, o.order_id DESC LIMIT ?',
            [$limit],
        );
    }

    /** @return list<array{rental_id:int,customer_name:string,state:string,created_at:string,start_date:?string,end_date_exclusive:?string}> */
    public function recentRentals(int $limit): array
    {
        return $this->select(
            'SELECT r.rental_id, c.name AS customer_name, r.state, r.created_at,
                    MIN(i.start_date) AS start_date, MAX(i.end_date_exclusive) AS end_date_exclusive
             FROM rentals r
             JOIN customers c ON c.customer_id = r.customer_id
             LEFT JOIN rental_items i ON i.rental_id = r.rental_id
             WHERE r.archived_at IS NULL
             GROUP BY r.rental_id, c.name, r.state, r.created_at
             ORDER BY r.created_at DESC, r.rental_id DESC LIMIT ?',
            [$limit],
        );
    }

    /** @return list<array{rental_id:int,customer_name:string,outstanding_quantity:int,end_date_exclusive:string}> */
    public function outstandingReturns(int $limit): array
    {
        return $this->select(
            'SELECT r.rental_id, c.name AS customer_name,
                    SUM(GREATEST(issued.quantity - COALESCE(returned.returned_quantity, 0), 0)) AS outstanding_quantity,
                    MIN(CASE WHEN issued.quantity > COALESCE(returned.returned_quantity, 0)
                             THEN i.end_date_exclusive END) AS end_date_exclusive
             FROM rentals r
             JOIN customers c ON c.customer_id = r.customer_id
             JOIN rental_items i ON i.rental_id = r.rental_id
             JOIN rental_issues issued ON issued.rental_item_id = i.rental_item_id
             LEFT JOIN (
                SELECT rental_item_id, SUM(quantity) AS returned_quantity
                FROM rental_returns GROUP BY rental_item_id
             ) returned ON returned.rental_item_id = i.rental_item_id
             WHERE r.archived_at IS NULL AND r.state = \'confirmed\'
             GROUP BY r.rental_id, c.name
             HAVING SUM(GREATEST(issued.quantity - COALESCE(returned.returned_quantity, 0), 0)) > 0
             ORDER BY end_date_exclusive, r.rental_id
             LIMIT ?',
            [$limit],
        );
    }
}
