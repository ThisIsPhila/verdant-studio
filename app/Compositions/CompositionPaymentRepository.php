<?php

declare(strict_types=1);

namespace Verdant\Compositions;

use Verdant\Persistence\Repository;

/** Payment and child-lifecycle persistence for one composition checkout. */
final class CompositionPaymentRepository extends Repository
{
    public function productIdsForOrder(int $orderId): array
    {
        return array_values(array_unique(array_map('intval', array_column($this->select(
            'SELECT cl.product_id FROM composition_checkout_lines cl
             JOIN composition_checkouts cc ON cc.composition_checkout_id = cl.composition_checkout_id
             WHERE cc.order_id = ? ORDER BY cl.product_id',
            [$orderId],
        ), 'product_id'))));
    }

    public function isCompositionRental(int $rentalId): bool
    {
        return $this->select(
            'SELECT composition_checkout_id FROM composition_checkouts WHERE rental_id = ? LIMIT 1',
            [$rentalId],
        ) !== [];
    }

    public function checkoutForOrder(int $orderId, bool $lock = false): ?array
    {
        return $this->select(
            'SELECT cc.composition_checkout_id, cc.customer_id, cc.order_id, cc.order_payment_id,
                    cc.order_role, cc.rental_id, cc.state AS checkout_state, cc.total_minor, cc.currency,
                    so.state AS order_state, so.customer_id AS order_customer_id,
                    r.state AS rental_state, r.customer_id AS rental_customer_id
             FROM composition_checkouts cc
             JOIN sales_orders so ON so.order_id = cc.order_id
             LEFT JOIN rentals r ON r.rental_id = cc.rental_id
             WHERE cc.order_id = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$orderId],
        )[0] ?? null;
    }

    public function linkedPaymentForReference(string $reference, bool $lock = false): ?array
    {
        return $this->select(
            "SELECT p.order_payment_id, p.order_id, p.provider_code, p.provider_reference,
                    p.provider_checkout_url, p.amount_minor, p.currency, p.state AS payment_state,
                    o.customer_id, o.state AS order_state, cc.composition_checkout_id, cc.rental_id,
                    cc.order_role, cc.state AS checkout_state, cc.total_minor, cc.currency AS checkout_currency
             FROM order_payments p
             JOIN sales_orders o ON o.order_id = p.order_id
             JOIN composition_checkouts cc ON cc.order_payment_id = p.order_payment_id
             WHERE p.provider_code = 'paystack' AND p.provider_reference = ?" . ($lock ? ' FOR UPDATE' : ''),
            [$reference],
        )[0] ?? null;
    }

    public function checkoutById(int $checkoutId, bool $lock = false): ?array
    {
        return $this->select(
            'SELECT composition_checkout_id, customer_id, order_id, order_payment_id, order_role,
                    rental_id, state AS checkout_state, total_minor, currency
             FROM composition_checkouts WHERE composition_checkout_id = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$checkoutId],
        )[0] ?? null;
    }

    public function order(int $orderId, bool $lock = false): ?array
    {
        return $this->select(
            'SELECT order_id, customer_id, state FROM sales_orders WHERE order_id = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$orderId],
        )[0] ?? null;
    }

    public function rental(int $rentalId, bool $lock = false): ?array
    {
        return $this->select(
            'SELECT rental_id, customer_id, state FROM rentals WHERE rental_id = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$rentalId],
        )[0] ?? null;
    }

    public function activeCustomer(int $customerId, bool $lock = false): bool
    {
        return $this->select(
            "SELECT customer_id FROM customers WHERE customer_id = ? AND account_state = 'active' AND role = 'customer'" . ($lock ? ' FOR UPDATE' : ''),
            [$customerId],
        ) !== [];
    }

    /** All leaf rows, in SKU order, with both children and their current capacity states. */
    public function lines(int $checkoutId, bool $lock = false): array
    {
        $rows = $this->select(
            'SELECT cl.composition_checkout_line_id, cl.product_id, cl.mode, cl.quantity,
                    cl.start_date, cl.end_date_exclusive, cl.order_item_id, cl.rental_item_id,
                    oi.quantity AS sale_quantity, h.quantity AS hold_quantity, h.state AS hold_state,
                    ri.quantity AS rental_quantity, ri.start_date AS rental_start_date,
                    ri.end_date_exclusive AS rental_end_date_exclusive,
                    a.quantity AS allocation_quantity, a.state AS allocation_state
             FROM composition_checkout_lines cl
             LEFT JOIN order_items oi ON oi.order_item_id = cl.order_item_id
             LEFT JOIN retail_holds h ON h.order_item_id = oi.order_item_id
             LEFT JOIN rental_items ri ON ri.rental_item_id = cl.rental_item_id
             LEFT JOIN rental_allocations a ON a.rental_item_id = ri.rental_item_id
             WHERE cl.composition_checkout_id = ?
             ORDER BY cl.product_id, cl.mode, cl.start_date, cl.composition_checkout_line_id' . ($lock ? ' FOR UPDATE' : ''),
            [$checkoutId],
        );
        foreach ($rows as &$row) {
            foreach (['composition_checkout_line_id', 'product_id', 'quantity', 'order_item_id', 'rental_item_id',
                      'sale_quantity', 'hold_quantity', 'rental_quantity', 'allocation_quantity'] as $field) {
                if ($row[$field] !== null) { $row[$field] = (int) $row[$field]; }
            }
        }
        unset($row);
        return $rows;
    }

    public function standalonePaymentForOrder(int $orderId, bool $lock = false): ?array
    {
        return $this->select(
            "SELECT order_payment_id, amount_minor, state FROM order_payments
             WHERE order_id = ? AND provider_code = 'paystack' ORDER BY order_payment_id DESC LIMIT 1" . ($lock ? ' FOR UPDATE' : ''),
            [$orderId],
        )[0] ?? null;
    }

    public function hasAnyPaymentAttempt(int $orderId, ?int $rentalId, bool $lock = false): bool
    {
        $suffix = $lock ? ' FOR UPDATE' : '';
        if ($this->select('SELECT order_payment_id FROM order_payments WHERE order_id = ? LIMIT 1' . $suffix, [$orderId]) !== []) {
            return true;
        }
        return $rentalId !== null && $this->select(
            'SELECT rental_payment_id FROM rental_payments WHERE rental_id = ? LIMIT 1' . $suffix,
            [$rentalId],
        ) !== [];
    }

    public function insertPayment(int $orderId, string $reference, int $amountMinor, string $currency): int
    {
        $this->execute(
            "INSERT INTO order_payments(order_id, provider_code, provider_reference, amount_minor, currency, state)
             VALUES (?, 'paystack', ?, ?, ?, 'pending')",
            [$orderId, $reference, $amountMinor, $currency],
        );
        return (int) $this->connection->insert_id;
    }

    public function linkPayment(int $checkoutId, int $paymentId): void
    {
        if ($this->execute(
            'UPDATE composition_checkouts SET order_payment_id = ? WHERE composition_checkout_id = ? AND order_payment_id IS NULL',
            [$paymentId, $checkoutId],
        ) !== 1) {
            throw new \LogicException('The composition payment link changed while checkout was prepared.');
        }
    }

    public function payment(int $paymentId, bool $lock = false): ?array
    {
        return $this->select(
            "SELECT order_payment_id, order_id, provider_code, provider_reference, provider_checkout_url,
                    amount_minor, currency, state AS payment_state FROM order_payments
             WHERE order_payment_id = ? AND provider_code = 'paystack'" . ($lock ? ' FOR UPDATE' : ''),
            [$paymentId],
        )[0] ?? null;
    }

    public function saveCheckoutUrl(int $paymentId, string $url): void
    {
        $this->execute(
            "UPDATE order_payments SET provider_checkout_url = ? WHERE order_payment_id = ? AND provider_code = 'paystack'",
            [$url, $paymentId],
        );
    }

    public function setPaymentState(int $paymentId, string $state): void
    {
        $this->execute('UPDATE order_payments SET state = ? WHERE order_payment_id = ?', [$state, $paymentId]);
    }

    public function setOrderState(int $orderId, string $state): void
    {
        $this->execute('UPDATE sales_orders SET state = ? WHERE order_id = ?', [$state, $orderId]);
    }

    public function setRentalState(int $rentalId, string $state): void
    {
        $this->execute('UPDATE rentals SET state = ? WHERE rental_id = ?', [$state, $rentalId]);
    }

    public function setCheckoutState(int $checkoutId, string $state): void
    {
        $this->execute('UPDATE composition_checkouts SET state = ? WHERE composition_checkout_id = ?', [$state, $checkoutId]);
    }

    public function setHoldState(int $itemId, string $state): void
    {
        $this->execute('UPDATE retail_holds SET state = ? WHERE order_item_id = ?', [$state, $itemId]);
    }

    public function setAllocationState(int $itemId, string $state): void
    {
        $this->execute('UPDATE rental_allocations SET state = ? WHERE rental_item_id = ?', [$state, $itemId]);
    }

    public function consumeInventory(int $productId, int $quantity): bool
    {
        return $this->execute(
            'UPDATE inventory SET on_hand = on_hand - ?, version = version + 1 WHERE product_id = ? AND on_hand >= ?',
            [$quantity, $productId, $quantity],
        ) === 1;
    }

    public function event(string $eventReference, bool $lock = false): ?array
    {
        return $this->select(
            "SELECT provider_event_id, processing_state FROM provider_events
             WHERE provider_code = 'paystack' AND event_reference = ?" . ($lock ? ' FOR UPDATE' : ''),
            [$eventReference],
        )[0] ?? null;
    }

    public function recordEvent(string $eventReference, int $paymentId, string $payloadHash): void
    {
        $this->execute(
            "INSERT INTO provider_events(provider_code, event_reference, payment_kind, order_payment_id,
                                         payload_hash, received_at, processing_state)
             VALUES ('paystack', ?, 'order', ?, ?, UTC_TIMESTAMP(), 'received')",
            [$eventReference, $paymentId, $payloadHash],
        );
    }

    public function setEventState(string $eventReference, string $state): void
    {
        $this->execute(
            "UPDATE provider_events SET processing_state = ? WHERE provider_code = 'paystack' AND event_reference = ?",
            [$state, $eventReference],
        );
    }
}
