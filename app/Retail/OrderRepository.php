<?php

declare(strict_types=1);

namespace Verdant\Retail;

use Verdant\Persistence\Repository;

final class OrderRepository extends Repository
{
    public function forCustomer(int $customerId): array
    {
        return $this->select(
            'SELECT o.order_id, o.state, o.created_at, o.fulfilled_at,
                (SELECT i.product_name_snapshot FROM order_items i WHERE i.order_id=o.order_id ORDER BY i.order_item_id LIMIT 1) AS first_product_name,
                (SELECT COALESCE(SUM(i.quantity * i.unit_price_minor), 0) FROM order_items i WHERE i.order_id = o.order_id) AS total_minor
             FROM sales_orders o WHERE o.customer_id = ?
               AND NOT EXISTS (SELECT 1 FROM composition_checkouts cc
                               WHERE cc.order_id = o.order_id AND cc.order_role = \'payment_anchor\')
             ORDER BY o.created_at DESC, o.order_id DESC', [$customerId],
        );
    }

    public function owned(int $customerId, int $orderId): ?array
    {
        $order = $this->select(
            "SELECT o.order_id, o.state, o.created_at, o.fulfilled_at FROM sales_orders o
             WHERE o.customer_id = ? AND o.order_id = ? AND NOT EXISTS (
                 SELECT 1 FROM composition_checkouts cc WHERE cc.order_id = o.order_id AND cc.order_role = 'payment_anchor')",
            [$customerId, $orderId],
        )[0] ?? null;
        if ($order === null) {
            return null;
        }
        $order['items'] = $this->select(
            'SELECT product_name_snapshot, sku_snapshot, quantity, unit_price_minor,
                quantity * unit_price_minor AS total_minor
             FROM order_items WHERE order_id = ? ORDER BY order_item_id', [$orderId],
        );
        $order['payments'] = $this->select('SELECT amount_minor, currency, state, provider_code, provider_reference FROM order_payments WHERE order_id = ? ORDER BY created_at, order_payment_id', [$orderId]);
        $order['total_minor'] = $this->select('SELECT COALESCE(SUM(quantity * unit_price_minor), 0) AS total FROM order_items WHERE order_id = ?', [$orderId])[0]['total'];
        return $order;
    }

    public function forCheckoutUpdate(int $customerId, string $key): ?array
    {
        $order = $this->select(
            'SELECT order_id, state FROM sales_orders WHERE customer_id = ? AND checkout_key = ? FOR UPDATE',
            [$customerId, $key],
        )[0] ?? null;
        return $order === null ? null : $this->withTotal($order) + ['existing' => true];
    }

    public function forCheckout(int $customerId, string $key): ?array
    {
        $order = $this->select(
            'SELECT order_id, state FROM sales_orders WHERE customer_id = ? AND checkout_key = ?',
            [$customerId, $key],
        )[0] ?? null;
        return $order === null ? null : $this->withTotal($order);
    }


    private function withTotal(array $order): array
    {
        $order['total_minor'] = $this->select(
            'SELECT COALESCE(SUM(quantity * unit_price_minor), 0) AS total FROM order_items WHERE order_id = ?',
            [$order['order_id']],
        )[0]['total'];
        return $order;
    }

    public function insertOrder(int $customerId, string $key): int
    {
        $statement = $this->connection->prepare("INSERT INTO sales_orders(customer_id, checkout_key, state) VALUES (?, ?, 'pending_payment')");
        try {
            $statement->execute([$customerId, $key]);
            return (int) $this->connection->insert_id;
        } finally {
            $statement->close();
        }
    }

    public function insertItem(int $orderId, int $productId, string $name, string $sku, int $quantity, int $price): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO order_items(order_id, product_id, product_name_snapshot, sku_snapshot, quantity, unit_price_minor) VALUES (?, ?, ?, ?, ?, ?)',
        );
        try {
            $statement->execute([$orderId, $productId, $name, $sku, $quantity, $price]);
            return (int) $this->connection->insert_id;
        } finally {
            $statement->close();
        }
    }

    public function insertHold(int $itemId, int $quantity): void
    {
        $statement = $this->connection->prepare("INSERT INTO retail_holds(order_item_id, quantity, state) VALUES (?, ?, 'active')");
        try {
            $statement->execute([$itemId, $quantity]);
        } finally {
            $statement->close();
        }
    }
}
