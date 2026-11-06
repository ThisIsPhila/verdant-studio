<?php

declare(strict_types=1);

namespace Verdant\Payments;

use Verdant\Persistence\Repository;

final class RentalPaymentRepository extends Repository
{
    public function rentalForCustomer(int $customerId, int $rentalId, bool $lock = false): ?array
    {
        return $this->select('SELECT rental_id,customer_id,state FROM rentals WHERE customer_id=? AND rental_id=?' . ($lock ? ' FOR UPDATE' : ''), [$customerId, $rentalId])[0] ?? null;
    }

    public function activeCustomer(int $customerId, bool $lock = false): bool
    {
        return $this->select("SELECT customer_id FROM customers WHERE customer_id=? AND account_state='active' AND role='customer'" . ($lock ? ' FOR UPDATE' : ''), [$customerId]) !== [];
    }

    public function rental(int $rentalId, bool $lock = false): ?array
    {
        return $this->select('SELECT rental_id,customer_id,state FROM rentals WHERE rental_id=?' . ($lock ? ' FOR UPDATE' : ''), [$rentalId])[0] ?? null;
    }

    public function productIds(int $rentalId, bool $lock = false): array
    {
        $rows = $this->select('SELECT product_id FROM rental_items WHERE rental_id=? ORDER BY product_id,rental_item_id' . ($lock ? ' FOR UPDATE' : ''), [$rentalId]);
        return array_values(array_unique(array_map(static fn (array $row): int => (int) $row['product_id'], $rows)));
    }

    /** Must be called after rental header and inventory rows are locked. */
    public function prepareLocked(int $customerId, int $rentalId, string $reference): array
    {
        $rental = $this->rentalForCustomer($customerId, $rentalId, true);
        if ($rental === null) { throw new \DomainException('Rental is unavailable.'); }
        if ((new \Verdant\Compositions\CompositionLinkRepository($this->connection))->forRental($rentalId, true) !== null) {
            throw new \DomainException('Pay for the complete arrangement through its checkout.');
        }
        $items = $this->lines($rentalId, true);
        $total = (int) ($this->select(
            'SELECT COALESCE(SUM(quantity * DATEDIFF(end_date_exclusive,start_date) * daily_rate_minor),0) AS total FROM rental_items WHERE rental_id=?',
            [$rentalId],
        )[0]['total'] ?? 0);
        $payment = $this->latestForRental($rentalId, true);
        if ($payment === null && in_array($rental['state'], ['pending_payment', 'reconciliation_required'], true)) {
            $this->execute(
                "INSERT INTO rental_payments(rental_id,provider_code,provider_reference,amount_minor,currency,state)
                 VALUES(?,'paystack',?,?,'ZAR','pending')",
                [$rentalId, $reference, $total],
            );
            $payment = [
                'rental_payment_id' => (int) $this->connection->insert_id,
                'provider_reference' => $reference,
                'provider_checkout_url' => null,
                'amount_minor' => $total,
                'currency' => 'ZAR',
                'state' => 'pending',
            ];
        }
        return ['rental' => $rental, 'payment' => $payment, 'items' => $items, 'total_minor' => $total];
    }

    public function latestForRental(int $rentalId, bool $lock = false): ?array
    {
        return $this->select(
            "SELECT rental_payment_id,provider_reference,provider_checkout_url,amount_minor,currency,state
             FROM rental_payments WHERE rental_id=? AND provider_code='paystack'
             ORDER BY rental_payment_id DESC LIMIT 1" . ($lock ? ' FOR UPDATE' : ''),
            [$rentalId],
        )[0] ?? null;
    }

    public function byReference(string $reference, bool $lock = false): ?array
    {
        return $this->select(
            "SELECT p.rental_payment_id,p.rental_id,p.provider_code,p.provider_reference,p.provider_checkout_url,
                    p.amount_minor,p.currency,p.state AS payment_state,r.customer_id,r.state AS rental_state
             FROM rental_payments p JOIN rentals r ON r.rental_id=p.rental_id
             WHERE p.provider_code='paystack' AND p.provider_reference=?" . ($lock ? ' FOR UPDATE' : ''),
            [$reference],
        )[0] ?? null;
    }

    public function lines(int $rentalId, bool $lock = false): array
    {
        return $this->select(
            'SELECT i.rental_item_id,i.product_id,i.quantity,i.start_date,i.end_date_exclusive,
                    a.quantity AS allocation_quantity,a.state AS allocation_state
             FROM rental_items i JOIN rental_allocations a ON a.rental_item_id=i.rental_item_id
             WHERE i.rental_id=? ORDER BY i.product_id,i.rental_item_id' . ($lock ? ' FOR UPDATE' : ''),
            [$rentalId],
        );
    }

    public function saveCheckoutUrl(int $paymentId, string $url): void
    {
        $this->execute("UPDATE rental_payments SET provider_checkout_url=? WHERE rental_payment_id=? AND provider_code='paystack'", [$url, $paymentId]);
    }

    public function setPaymentState(int $paymentId, string $state): void
    {
        $this->execute('UPDATE rental_payments SET state=? WHERE rental_payment_id=?', [$state, $paymentId]);
    }

    public function setRentalState(int $rentalId, string $state): void
    {
        $this->execute('UPDATE rentals SET state=? WHERE rental_id=?', [$state, $rentalId]);
    }

    public function setAllocationState(int $itemId, string $state): void
    {
        $this->execute('UPDATE rental_allocations SET state=? WHERE rental_item_id=?', [$state, $itemId]);
    }

    public function event(string $eventReference, bool $lock = false): ?array
    {
        return $this->select("SELECT provider_event_id,processing_state FROM provider_events WHERE provider_code='paystack' AND event_reference=?" . ($lock ? ' FOR UPDATE' : ''), [$eventReference])[0] ?? null;
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
            "INSERT INTO provider_events(provider_code,event_reference,payment_kind,rental_payment_id,payload_hash,received_at,processing_state)
             VALUES(?,?,'rental',?,?,UTC_TIMESTAMP(),?)",
            ['paystack', $eventReference, $paymentId, $payloadHash, $state],
        );
    }

    public function setEventState(string $eventReference, string $state): void
    {
        $this->execute("UPDATE provider_events SET processing_state=? WHERE provider_code='paystack' AND event_reference=?", [$state, $eventReference]);
    }
}
