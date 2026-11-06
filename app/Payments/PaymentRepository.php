<?php

declare(strict_types=1);

namespace Verdant\Payments;

use Verdant\Persistence\Repository;

final class PaymentRepository extends Repository
{
    public function compositionLink(int $orderId, bool $lock = false): ?array
    {
        return (new \Verdant\Compositions\CompositionLinkRepository($this->connection))->forOrder($orderId, $lock);
    }

    public function prepareOrderPayment(int $customerId, int $orderId, string $reference): array
    {
        $order = $this->select(
            'SELECT order_id, state FROM sales_orders WHERE order_id = ? AND customer_id = ? FOR UPDATE',
            [$orderId, $customerId],
        )[0] ?? null;
        if ($order === null) {
            throw new \DomainException('Order is unavailable.');
        }
        if ($this->compositionLink($orderId, true) !== null) {
            throw new \DomainException('Pay for the complete arrangement through its checkout.');
        }
        $order['total_minor'] = (int) ($this->select(
            'SELECT COALESCE(SUM(quantity * unit_price_minor), 0) AS total FROM order_items WHERE order_id = ?',
            [$orderId],
        )[0]['total'] ?? 0);
        $payment = $this->select(
            "SELECT order_payment_id, provider_reference, provider_checkout_url, amount_minor, currency, state
             FROM order_payments WHERE order_id = ? AND provider_code = 'paystack' ORDER BY order_payment_id DESC LIMIT 1 FOR UPDATE",
            [$orderId],
        )[0] ?? null;
        if ($payment === null && in_array($order['state'], ['pending_payment', 'reconciliation_required'], true)) {
            $statement = $this->connection->prepare(
                "INSERT INTO order_payments(order_id, provider_code, provider_reference, amount_minor, currency, state)
                 VALUES (?, 'paystack', ?, ?, 'ZAR', 'pending')",
            );
            try {
                $statement->execute([$orderId, $reference, $order['total_minor']]);
                $payment = [
                    'order_payment_id' => (int) $this->connection->insert_id,
                    'provider_reference' => $reference, 'provider_checkout_url' => null,
                    'amount_minor' => $order['total_minor'], 'currency' => 'ZAR', 'state' => 'pending',
                ];
            } finally {
                $statement->close();
            }
        }
        return ['order' => $order, 'payment' => $payment];
    }

    public function byReference(string $reference, bool $lock = false): ?array
    {
        $suffix = $lock ? ' FOR UPDATE' : '';
        return $this->select(
            "SELECT p.order_payment_id, p.order_id, p.provider_code, p.provider_reference, p.provider_checkout_url,
                    p.amount_minor, p.currency, p.state AS payment_state, o.customer_id, o.state AS order_state
             FROM order_payments p JOIN sales_orders o ON o.order_id = p.order_id
             WHERE p.provider_code = 'paystack' AND p.provider_reference = ?$suffix",
            [$reference],
        )[0] ?? null;
    }

    public function latestForCustomerOrder(int $customerId, int $orderId): ?array
    {
        return $this->select(
            "SELECT p.order_payment_id, p.provider_reference, p.provider_checkout_url, p.amount_minor, p.currency, p.state
             FROM order_payments p JOIN sales_orders o ON o.order_id = p.order_id
             WHERE o.customer_id = ? AND o.order_id = ? AND p.provider_code = 'paystack'
             ORDER BY p.order_payment_id DESC LIMIT 1",
            [$customerId, $orderId],
        )[0] ?? null;
    }

    public function orderForCustomer(int $customerId, int $orderId): ?array
    {
        $order = $this->select('SELECT order_id, state FROM sales_orders WHERE customer_id = ? AND order_id = ?', [$customerId, $orderId])[0] ?? null;
        if ($order !== null) {
            $order['total_minor'] = (int) ($this->select(
                'SELECT COALESCE(SUM(quantity * unit_price_minor), 0) AS total FROM order_items WHERE order_id = ?', [$orderId],
            )[0]['total'] ?? 0);
        }
        return $order;
    }

    public function lines(int $orderId, bool $lock = false): array
    {
        $suffix = $lock ? ' FOR UPDATE' : '';
        return $this->select(
            "SELECT i.order_item_id, i.product_id, i.quantity AS item_quantity,
                    h.quantity AS hold_quantity, h.state AS hold_state
             FROM order_items i JOIN retail_holds h ON h.order_item_id = i.order_item_id
             WHERE i.order_id = ? ORDER BY i.product_id, i.order_item_id$suffix",
            [$orderId],
        );
    }

    public function saveCheckoutUrl(int $paymentId, string $url): void
    {
        $this->execute("UPDATE order_payments SET provider_checkout_url = ? WHERE order_payment_id = ? AND provider_code = 'paystack'", [$url, $paymentId]);
    }

    public function setPaymentState(int $paymentId, string $state): void
    {
        $this->execute('UPDATE order_payments SET state = ? WHERE order_payment_id = ?', [$state, $paymentId]);
    }

    public function setOrderState(int $orderId, string $state): void
    {
        $this->execute('UPDATE sales_orders SET state = ? WHERE order_id = ?', [$state, $orderId]);
    }

    public function setHoldState(int $itemId, string $state): void
    {
        $this->execute('UPDATE retail_holds SET state = ? WHERE order_item_id = ?', [$state, $itemId]);
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
        $suffix = $lock ? ' FOR UPDATE' : '';
        return $this->select(
            "SELECT provider_event_id, processing_state FROM provider_events WHERE provider_code = 'paystack' AND event_reference = ?$suffix",
            [$eventReference],
        )[0] ?? null;
    }

    public function recordEvent(string $eventReference, ?int $paymentId, string $payloadHash, string $state): void
    {
        if ($state === 'quarantined') {
            $this->execute(
                "INSERT INTO provider_events(provider_code,event_reference,payment_kind,payload_hash,received_at,processing_state)
                 VALUES('paystack',?,NULL,?,UTC_TIMESTAMP(),'quarantined')",
                [$eventReference, $payloadHash],
            );
            return;
        }
        $this->execute(
            "INSERT INTO provider_events(provider_code,event_reference,payment_kind,order_payment_id,payload_hash,received_at,processing_state)
             VALUES('paystack',?,'order',?,?,UTC_TIMESTAMP(),?)",
            [$eventReference, $paymentId, $payloadHash, $state],
        );
    }

    public function setEventState(string $eventReference, string $state): void
    {
        $this->execute("UPDATE provider_events SET processing_state = ? WHERE provider_code = 'paystack' AND event_reference = ?", [$state, $eventReference]);
    }
}
