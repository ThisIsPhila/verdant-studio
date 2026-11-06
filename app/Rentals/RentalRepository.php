<?php

declare(strict_types=1);

namespace Verdant\Rentals;

use Verdant\Persistence\Repository;

final class RentalRepository extends Repository
{
    public function compositionLink(int $rentalId, bool $lock = false): ?array
    {
        return (new \Verdant\Compositions\CompositionLinkRepository($this->connection))->forRental($rentalId, $lock);
    }

    public function rentableProduct(int $productId, bool $lock = false): ?array
    {
        $suffix = $lock ? ' FOR UPDATE' : '';
        return $this->select(
            'SELECT p.product_id, p.sku, p.name, p.rental_daily_rate_minor FROM products p
             JOIN categories c ON c.category_id=p.category_id
             WHERE p.product_id = ? AND p.rent_enabled = 1 AND p.archived_at IS NULL AND c.archived_at IS NULL' . $suffix,
            [$productId],
        )[0] ?? null;
    }

    public function activeActorRole(int $actorId, bool $lock = false): ?string
    {
        $row = $this->select("SELECT role FROM customers WHERE customer_id = ? AND account_state = 'active'" . ($lock ? ' FOR UPDATE' : ''), [$actorId])[0] ?? null;
        return $row === null ? null : (string) $row['role'];
    }

    public function hasPaymentAttempt(int $rentalId, bool $lock = false): bool
    {
        return $this->select('SELECT rental_payment_id FROM rental_payments WHERE rental_id = ? ORDER BY rental_payment_id DESC LIMIT 1' . ($lock ? ' FOR UPDATE' : ''), [$rentalId]) !== [];
    }

    public function byCheckoutKey(int $customerId, string $checkoutKey, bool $lock = false): ?array
    {
        return $this->select(
            'SELECT rental_id, state FROM rentals WHERE customer_id = ? AND checkout_key = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$customerId, $checkoutKey],
        )[0] ?? null;
    }

    public function matchesCheckout(int $rentalId, int $productId, int $quantity, string $start, string $endExclusive): bool
    {
        return $this->select(
            'SELECT rental_item_id FROM rental_items WHERE rental_id = ? AND product_id = ? AND quantity = ? AND start_date = ? AND end_date_exclusive = ?',
            [$rentalId, $productId, $quantity, $start, $endExclusive],
        ) !== [];
    }

    public function insertPending(int $customerId, string $checkoutKey, array $product, int $quantity, string $start, string $endExclusive): int
    {
        if ($this->activeActorRole($customerId, true) !== 'customer') { throw new \DomainException('Your account is unavailable for checkout.'); }
        $this->execute("INSERT INTO rentals(customer_id,checkout_key,state) VALUES(?,?,'pending_payment')", [$customerId, $checkoutKey]);
        $rentalId = (int) $this->connection->insert_id;
        $this->execute(
            'INSERT INTO rental_items(rental_id,product_id,product_name_snapshot,sku_snapshot,quantity,start_date,end_date_exclusive,daily_rate_minor)
             VALUES(?,?,?,?,?,?,?,?)',
            [$rentalId, (int) $product['product_id'], (string) $product['name'], (string) $product['sku'], $quantity, $start, $endExclusive, (int) $product['rental_daily_rate_minor']],
        );
        $itemId = (int) $this->connection->insert_id;
        $this->execute("INSERT INTO rental_allocations(rental_item_id,quantity,state) VALUES(?,?,'held')", [$itemId, $quantity]);
        return $rentalId;
    }

    public function forLifecycle(int $rentalId, bool $lock = false): ?array
    {
        return $this->select('SELECT rental_id, customer_id, state FROM rentals WHERE rental_id = ?' . ($lock ? ' FOR UPDATE' : ''), [$rentalId])[0] ?? null;
    }

    public function productIds(int $rentalId, bool $lock = false): array
    {
        $rows = $this->select('SELECT product_id FROM rental_items WHERE rental_id = ? ORDER BY product_id,rental_item_id' . ($lock ? ' FOR UPDATE' : ''), [$rentalId]);
        return array_values(array_unique(array_map(static fn (array $row): int => (int) $row['product_id'], $rows)));
    }

    public function productIdForItem(int $rentalId, int $rentalItemId): ?int
    {
        $row = $this->select('SELECT product_id FROM rental_items WHERE rental_id = ? AND rental_item_id = ?', [$rentalId, $rentalItemId])[0] ?? null;
        return $row === null ? null : (int) $row['product_id'];
    }

    public function rentalState(int $rentalId, bool $lock = false): ?string
    {
        $row = $this->select('SELECT state FROM rentals WHERE rental_id = ?' . ($lock ? ' FOR UPDATE' : ''), [$rentalId])[0] ?? null;
        return $row === null ? null : (string) $row['state'];
    }

    public function lifecycleItems(int $rentalId, bool $lock = false): array
    {
        return $this->select(
            'SELECT i.rental_item_id, i.product_id, i.quantity, i.start_date, i.end_date_exclusive, a.state AS allocation_state,
                    iss.quantity AS issue_quantity
             FROM rental_items i JOIN rental_allocations a ON a.rental_item_id = i.rental_item_id
             LEFT JOIN rental_issues iss ON iss.rental_item_id = i.rental_item_id
             WHERE i.rental_id = ? ORDER BY i.product_id, i.rental_item_id' . ($lock ? ' FOR UPDATE' : ''),
            [$rentalId],
        );
    }

    public function lifecycleItem(int $rentalId, int $rentalItemId, bool $lock = false): ?array
    {
        return $this->select(
            'SELECT r.customer_id,r.state AS rental_state,i.rental_item_id,i.product_id,i.quantity,
                    a.state AS allocation_state,iss.quantity AS issue_quantity
             FROM rentals r JOIN rental_items i ON i.rental_id=r.rental_id
             JOIN rental_allocations a ON a.rental_item_id=i.rental_item_id
             LEFT JOIN rental_issues iss ON iss.rental_item_id=i.rental_item_id
             WHERE r.rental_id=? AND i.rental_item_id=?' . ($lock ? ' FOR UPDATE' : ''),
            [$rentalId, $rentalItemId],
        )[0] ?? null;
    }

    public function setAllocationState(int $itemId, string $state): void
    {
        $this->execute('UPDATE rental_allocations SET state = ? WHERE rental_item_id = ?', [$state, $itemId]);
    }

    public function setRentalState(int $rentalId, string $state): void
    {
        $this->execute('UPDATE rentals SET state = ? WHERE rental_id = ?', [$state, $rentalId]);
    }

    public function insertIssue(int $itemId, int $quantity, ?int $actorId = null): void
    {
        $this->execute('INSERT INTO rental_issues(rental_item_id,quantity,issued_at,actor_id) VALUES(?,?,UTC_TIMESTAMP(),?)', [$itemId, $quantity, $actorId]);
    }

    public function returnedQuantity(int $itemId, bool $lock = false): int
    {
        $row = $this->select('SELECT COALESCE(SUM(quantity),0) AS quantity FROM rental_returns WHERE rental_item_id = ?' . ($lock ? ' FOR UPDATE' : ''), [$itemId])[0];
        return (int) $row['quantity'];
    }

    public function returnByRequestKey(string $requestKey, bool $lock = false): ?array
    {
        return $this->select('SELECT rental_return_id,rental_item_id,quantity FROM rental_returns WHERE request_key = ?' . ($lock ? ' FOR UPDATE' : ''), [$requestKey])[0] ?? null;
    }

    public function insertReturn(int $itemId, int $quantity, ?string $requestKey, ?int $actorId = null): int
    {
        $this->execute('INSERT INTO rental_returns(rental_item_id,quantity,returned_at,request_key,actor_id) VALUES(?,?,UTC_TIMESTAMP(),?,?)', [$itemId, $quantity, $requestKey, $actorId]);
        return (int) $this->connection->insert_id;
    }

    public function forCustomer(int $customerId): array
    {
        return $this->select(
            'SELECT r.rental_id, r.state, r.created_at,
                (SELECT i.product_name_snapshot FROM rental_items i WHERE i.rental_id=r.rental_id ORDER BY i.rental_item_id LIMIT 1) AS first_product_name,
                (SELECT MIN(i.start_date) FROM rental_items i WHERE i.rental_id = r.rental_id) AS start_date,
                (SELECT MAX(i.end_date_exclusive) FROM rental_items i WHERE i.rental_id = r.rental_id) AS end_date_exclusive,
                (SELECT COALESCE(SUM(i.quantity * DATEDIFF(i.end_date_exclusive, i.start_date) * i.daily_rate_minor), 0)
                 FROM rental_items i WHERE i.rental_id = r.rental_id) AS total_minor
             FROM rentals r WHERE r.customer_id = ? ORDER BY r.created_at DESC, r.rental_id DESC', [$customerId],
        );
    }

    public function owned(int $customerId, int $rentalId): ?array
    {
        $rental = $this->select('SELECT rental_id, state, created_at FROM rentals WHERE customer_id = ? AND rental_id = ?', [$customerId, $rentalId])[0] ?? null;
        if ($rental === null) {
            return null;
        }
        $rental['items'] = $this->select(
            'SELECT i.rental_item_id, i.product_id, i.product_name_snapshot, i.sku_snapshot, i.quantity, i.start_date, i.end_date_exclusive,
                i.daily_rate_minor, i.quantity * DATEDIFF(i.end_date_exclusive, i.start_date) * i.daily_rate_minor AS total_minor,
                COALESCE(iss.quantity, 0) AS issued_quantity,
                (SELECT COALESCE(SUM(ret.quantity), 0) FROM rental_returns ret WHERE ret.rental_item_id = i.rental_item_id) AS returned_quantity
             FROM rental_items i LEFT JOIN rental_issues iss ON iss.rental_item_id = i.rental_item_id
             WHERE i.rental_id = ? ORDER BY i.rental_item_id', [$rentalId],
        );
        $rental['payments'] = $this->select('SELECT amount_minor, currency, state, provider_code, provider_reference, provider_checkout_url FROM rental_payments WHERE rental_id = ? ORDER BY created_at, rental_payment_id', [$rentalId]);
        $rental['total_minor'] = $this->select('SELECT COALESCE(SUM(quantity * DATEDIFF(end_date_exclusive, start_date) * daily_rate_minor), 0) AS total FROM rental_items WHERE rental_id = ?', [$rentalId])[0]['total'];
        return $rental;
    }
}
