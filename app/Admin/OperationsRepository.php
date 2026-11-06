<?php

declare(strict_types=1);

namespace Verdant\Admin;

use Verdant\Persistence\Repository;

final class OperationsRepository extends Repository
{
    public function customers(int $offset, int $limit): array
    {
        return $this->select(
            'SELECT customer_id, email, name, phone, role, account_state, created_at, archived_at
             FROM customers ORDER BY customer_id DESC LIMIT ? OFFSET ?', [$limit, $offset],
        );
    }

    public function customer(int $id, bool $lock = false): ?array
    {
        $suffix = $lock ? ' FOR UPDATE' : '';
        return $this->select(
            'SELECT customer_id, email, name, phone, role, account_state, created_at, archived_at
             FROM customers WHERE customer_id = ?' . $suffix, [$id],
        )[0] ?? null;
    }

    public function compositionLink(int $orderId, bool $lock = false): ?array
    {
        return (new \Verdant\Compositions\CompositionLinkRepository($this->connection))->forOrder($orderId, $lock);
    }

    public function lockOrderHeader(int $id): ?array
    {
        return $this->select(
            'SELECT order_id, state, fulfilled_at FROM sales_orders WHERE order_id = ? FOR UPDATE', [$id],
        )[0] ?? null;
    }

    public function orders(int $offset, int $limit): array
    {
        return $this->select(
            'SELECT o.order_id, o.customer_id, c.name AS customer_name, c.email AS customer_email,
                    o.state, o.created_at, o.fulfilled_at, o.archived_at,
                    COALESCE((SELECT SUM(i.quantity * i.unit_price_minor) FROM order_items i WHERE i.order_id = o.order_id), 0) AS total_minor
             FROM sales_orders o JOIN customers c ON c.customer_id = o.customer_id
             WHERE NOT EXISTS (SELECT 1 FROM composition_checkouts cc
                               WHERE cc.order_id = o.order_id AND cc.order_role = \'payment_anchor\')
             ORDER BY o.order_id DESC LIMIT ? OFFSET ?', [$limit, $offset],
        );
    }

    public function order(int $id, bool $lock = false): ?array
    {
        $suffix = $lock ? ' FOR UPDATE' : '';
        $order = $this->select(
            'SELECT o.order_id, o.customer_id, c.name AS customer_name, c.email AS customer_email,
                    o.state, o.created_at, o.fulfilled_at, o.archived_at,
                    COALESCE((SELECT SUM(i.quantity * i.unit_price_minor) FROM order_items i WHERE i.order_id = o.order_id), 0) AS total_minor
             FROM sales_orders o JOIN customers c ON c.customer_id = o.customer_id
             WHERE o.order_id = ? AND NOT EXISTS (
                 SELECT 1 FROM composition_checkouts cc WHERE cc.order_id = o.order_id AND cc.order_role = \'payment_anchor\')' . $suffix, [$id],
        )[0] ?? null;
        if ($order === null) {
            return null;
        }
        $order['items'] = $this->select(
            'SELECT order_item_id, product_name_snapshot, sku_snapshot, quantity, unit_price_minor,
                    quantity * unit_price_minor AS total_minor
             FROM order_items WHERE order_id = ? ORDER BY order_item_id', [$id],
        );
        $order['payments'] = $this->select(
            'SELECT order_payment_id, amount_minor, currency, state, created_at
             FROM order_payments WHERE order_id = ? ORDER BY order_payment_id', [$id],
        );
        return $order;
    }

    /** Lock order lines and associated holds in stable product/item order. */
    public function lockLines(int $orderId): array
    {
        return $this->select(
            'SELECT i.order_item_id, i.product_id, i.quantity, i.unit_price_minor,
                    h.quantity AS hold_quantity, h.state AS hold_state
             FROM order_items i LEFT JOIN retail_holds h ON h.order_item_id = i.order_item_id
             WHERE i.order_id = ? ORDER BY i.product_id, i.order_item_id FOR UPDATE', [$orderId],
        );
    }

    public function paymentsForUpdate(int $orderId): array
    {
        return $this->select(
            'SELECT order_payment_id, amount_minor, currency, state FROM order_payments
             WHERE order_id = ? ORDER BY order_payment_id FOR UPDATE', [$orderId],
        );
    }

    public function setCustomerArchived(int $id, bool $archived): void
    {
        if ($archived) {
            $this->execute("UPDATE customers SET account_state = 'archived', archived_at = UTC_TIMESTAMP() WHERE customer_id = ? AND role = 'customer' AND account_state = 'active'", [$id]);
        } else {
            $this->execute("UPDATE customers SET account_state = 'active', archived_at = NULL WHERE customer_id = ? AND role = 'customer' AND account_state = 'archived'", [$id]);
        }
    }

    public function setOrderArchived(int $id, bool $archived): void
    {
        $this->execute(
            $archived
                ? 'UPDATE sales_orders SET archived_at = UTC_TIMESTAMP() WHERE order_id = ? AND archived_at IS NULL'
                : 'UPDATE sales_orders SET archived_at = NULL WHERE order_id = ? AND archived_at IS NOT NULL',
            [$id],
        );
    }

    public function releaseHolds(int $orderId): void
    {
        $this->execute(
            "UPDATE retail_holds h JOIN order_items i ON i.order_item_id = h.order_item_id
             SET h.state = 'released' WHERE i.order_id = ? AND h.state = 'active'", [$orderId],
        );
    }

    public function setOrderState(int $id, string $state): void
    {
        $this->execute('UPDATE sales_orders SET state = ? WHERE order_id = ?', [$state, $id]);
    }

    public function fulfil(int $id): void
    {
        $this->execute('UPDATE sales_orders SET fulfilled_at = UTC_TIMESTAMP() WHERE order_id = ? AND fulfilled_at IS NULL', [$id]);
    }

}
