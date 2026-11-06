<?php

declare(strict_types=1);

namespace Verdant\Rentals;

use Verdant\Inventory\CapacityRepository;
use Verdant\Inventory\InventoryRepository;
use Verdant\Inventory\RentalPeriod;
use Verdant\Notifications\CommerceNotifier;
use Verdant\Persistence\DatabaseException;
use Verdant\Persistence\TransactionManager;

/** Customer reservation and administrator rental lifecycle rules. */
final class RentalService
{
    public function __construct(
        private readonly RentalRepository $rentals,
        private readonly InventoryRepository $inventory,
        private readonly CapacityRepository $capacity,
        private readonly TransactionManager $transactions,
        private readonly ?CommerceNotifier $notifications = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function quote(int $productId, int $quantity, string $startInclusive, string $endInclusive): array
    {
        if ($productId < 1 || $quantity < 1 || $quantity > 10000) {
            throw new \InvalidArgumentException('Choose a valid product and quantity.');
        }
        $period = RentalPeriod::fromInclusive($startInclusive, $endInclusive);
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
        if ($period['start_date'] < $today) {
            throw new \InvalidArgumentException('Rental dates cannot be in the past.');
        }
        $product = $this->rentals->rentableProduct($productId);
        if ($product === null) {
            throw new \DomainException('This product is not available for rental.');
        }
        $available = $this->capacity->availableForPeriod($productId, $period['start_date'], $period['end_date_exclusive']);
        $dailyRate = (int) $product['rental_daily_rate_minor'];
        $total = RentalPeriod::totalMinor($quantity, $period['start_date'], $period['end_date_exclusive'], $dailyRate);
        $days = (int) (new \DateTimeImmutable($period['start_date']))
            ->diff(new \DateTimeImmutable($period['end_date_exclusive']))->days;
        return [
            'product_id' => $productId,
            'product_name_snapshot' => (string) $product['name'],
            'sku_snapshot' => (string) $product['sku'],
            'quantity' => $quantity,
            'start_date' => $period['start_date'],
            'end_date_exclusive' => $period['end_date_exclusive'],
            'daily_rate_minor' => $dailyRate,
            'available_quantity' => $available,
            'days' => $days,
            'total_minor' => $total,
            'available' => $available >= $quantity,
        ];
    }

    /** @return array<string, mixed> */
    public function createPending(int $customerId, int $productId, int $quantity, string $startInclusive, string $endInclusive, string $checkoutKey): array
    {
        if ($customerId < 1 || $productId < 1 || $quantity < 1 || $quantity > 10000
            || preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $checkoutKey) !== 1) {
            throw new \InvalidArgumentException('The rental checkout details are invalid.');
        }
        $period = RentalPeriod::fromInclusive($startInclusive, $endInclusive);
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
        if ($period['start_date'] < $today) {
            throw new \InvalidArgumentException('Rental dates cannot be in the past.');
        }
        for ($attempt = 0; ; ++$attempt) {
            try {
                $rentalId = $this->transactions->run(function () use ($customerId, $productId, $quantity, $period, $checkoutKey): int {
                    $existing = $this->rentals->byCheckoutKey($customerId, $checkoutKey, true);
                    if ($existing !== null) {
                        if (!$this->rentals->matchesCheckout((int) $existing['rental_id'], $productId, $quantity, $period['start_date'], $period['end_date_exclusive'])) {
                            throw new \DomainException('This checkout key was already used for a different rental.');
                        }
                        return (int) $existing['rental_id'];
                    }
                    $locked = $this->inventory->lockProducts([$productId]);
                    if ($locked === []) {
                        throw new \DomainException('This product has no rental capacity.');
                    }
                    $product = $this->rentals->rentableProduct($productId, true);
                    if ($product === null) {
                        throw new \DomainException('This product is not available for rental.');
                    }
                    if ($this->capacity->availableForPeriod($productId, $period['start_date'], $period['end_date_exclusive']) < $quantity) {
                        throw new \DomainException('The requested quantity is no longer available for those dates.');
                    }
                    return $this->rentals->insertPending($customerId, $checkoutKey, $product, $quantity, $period['start_date'], $period['end_date_exclusive']);
                });
                break;
            } catch (DatabaseException $error) {
                if (in_array($error->getCode(), [1205, 1213], true) && $attempt < 2) {
                    usleep(random_int(10000, 50000));
                    continue;
                }
                if ($error->getCode() === 1062) {
                    // A concurrent identical submit may win the unique customer/key insert.
                    $existing = $this->rentals->byCheckoutKey($customerId, $checkoutKey);
                    if ($existing === null || !$this->rentals->matchesCheckout((int) $existing['rental_id'], $productId, $quantity, $period['start_date'], $period['end_date_exclusive'])) {
                        throw new \DomainException('The rental checkout could not be safely resumed.');
                    }
                    $rentalId = (int) $existing['rental_id'];
                    break;
                }
                if (in_array($error->getCode(), [1205, 1213], true)) {
                    throw new \DomainException('Rental capacity changed while checkout was being prepared. Please retry.');
                }
                throw $error;
            }
        }
        return $this->rentals->owned($customerId, $rentalId) ?? throw new \LogicException('Created rental could not be loaded.');
    }

    public function issue(int $actorId, string $role, int $rentalId): void
    {
        $this->requireAdmin($actorId, $role);
        $this->transactions->run(function () use ($actorId, $rentalId): void {
            $rental = $this->rentals->forLifecycle($rentalId, true);
            if ($rental === null) { throw new \DomainException('Rental is unavailable.'); }
            $items = $this->rentals->lifecycleItems($rentalId, true);
            $this->inventory->lockProducts(array_map(static fn (array $item): int => (int) $item['product_id'], $items));
            if ($this->rentals->activeActorRole($actorId, true) !== 'admin') { throw new \DomainException('Administrator access is required.'); }
            if ($items === [] || $rental['state'] !== 'confirmed') {
                throw new \DomainException('Only confirmed rentals can be issued.');
            }
            $link = $this->rentals->compositionLink($rentalId, true);
            if ($link !== null && !\Verdant\Compositions\CompositionLinkRepository::hasConfirmedPayment($link)) {
                throw new \DomainException('The full arrangement needs a confirmed aggregate payment before issue.');
            }
            $today = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
            $issueOperationId = null;
            foreach ($items as $item) {
                if ($item['allocation_state'] === 'issued') { continue; }
                if ($item['allocation_state'] !== 'confirmed' || $item['issue_quantity'] !== null) {
                    throw new \DomainException('The rental allocation is not ready to issue.');
                }
                if ($today < $item['start_date'] || $today >= $item['end_date_exclusive']) {
                    throw new \DomainException('A rental can only be issued during its booked dates.');
                }
                $free = $this->capacity->availableForPeriod((int) $item['product_id'], (string) $item['start_date'], (string) $item['end_date_exclusive'], $rentalId);
                if ($free < (int) $item['quantity']) {
                    throw new \DomainException('Issuing this rental would exceed currently available physical capacity.');
                }
                $this->rentals->insertIssue((int) $item['rental_item_id'], (int) $item['quantity'], $actorId);
                $issueOperationId ??= (int) $item['rental_item_id'];
                $this->rentals->setAllocationState((int) $item['rental_item_id'], 'issued');
            }
            if ($issueOperationId !== null) {
                $this->notifications?->record(
                    'rental_issued',
                    'rental-issued:' . $rentalId,
                    'rental',
                    $rentalId,
                    $issueOperationId,
                );
            }
        });
    }

    /** @return array{state:string,returned_quantity:int} */
    public function return(int $actorId, string $role, int $rentalId, int $rentalItemId, int $quantity, ?string $requestKey = null): array
    {
        $this->requireAdmin($actorId, $role);
        if ($rentalItemId < 1 || $quantity < 1 || ($requestKey !== null && preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $requestKey) !== 1)) {
            throw new \InvalidArgumentException('Return details are invalid.');
        }
        return $this->transactions->run(function () use ($actorId, $rentalId, $rentalItemId, $quantity, $requestKey, $role): array {
            if ($this->rentals->forLifecycle($rentalId, true) === null) { throw new \DomainException('Rental item is unavailable.'); }
            $item = $this->rentals->lifecycleItem($rentalId, $rentalItemId, true);
            if ($item === null) { throw new \DomainException('Rental item is unavailable.'); }
            $this->inventory->lockProducts([(int) $item['product_id']]);
            if ($this->rentals->activeActorRole($actorId, true) !== 'admin' || $role !== 'admin') { throw new \DomainException('Administrator access is required.'); }
            if ($requestKey !== null && ($duplicate = $this->rentals->returnByRequestKey($requestKey, true)) !== null) {
                if ((int) $duplicate['rental_item_id'] !== $rentalItemId || (int) $duplicate['quantity'] !== $quantity) {
                    throw new \DomainException('This return request key was already used for different return details.');
                }
                $returned = (int) $this->rentals->returnedQuantity($rentalItemId);
                return ['state' => 'duplicate', 'returned_quantity' => $returned];
            }
            if ($item['allocation_state'] !== 'issued' || $item['issue_quantity'] === null) {
                throw new \DomainException('Only issued rental quantities can be returned.');
            }
            $already = (int) $this->rentals->returnedQuantity($rentalItemId, true);
            $issued = (int) $item['issue_quantity'];
            if ($quantity > $issued - $already) { throw new \DomainException('Return quantity exceeds the outstanding issued quantity.'); }
            $returnId = $this->rentals->insertReturn($rentalItemId, $quantity, $requestKey, $actorId);
            $returned = $already + $quantity;
            if ($returned === $issued) { $this->rentals->setAllocationState($rentalItemId, 'closed'); }
            $this->notifications?->record(
                'rental_returned',
                'rental-returned:' . $returnId,
                'rental',
                $rentalId,
                $returnId,
            );
            return ['state' => $returned === $issued ? 'returned' : 'partially_returned', 'returned_quantity' => $returned];
        });
    }

    public function cancel(int $actorId, string $role, int $rentalId): void
    {
        if ($actorId < 1 || !in_array($role, ['customer', 'admin'], true)) { throw new \DomainException('Rental is unavailable.'); }
        $this->transactions->run(function () use ($actorId, $role, $rentalId): void {
            $rental = $this->rentals->forLifecycle($rentalId, true);
            if ($rental === null) { throw new \DomainException('Rental is unavailable.'); }
            $items = $this->rentals->lifecycleItems($rentalId, true);
            $this->inventory->lockProducts(array_map(static fn (array $item): int => (int) $item['product_id'], $items));
            if (($role !== 'admin' && (int) $rental['customer_id'] !== $actorId)
                || $this->rentals->activeActorRole($actorId, true) !== $role) {
                throw new \DomainException('Rental is unavailable.');
            }
            if ($this->rentals->compositionLink($rentalId, true) !== null) {
                throw new \DomainException('Cancel the complete unstarted arrangement through its checkout.');
            }
            if ($rental['state'] === 'cancelled') { return; }
            if ($rental['state'] === 'pending_payment' && !$this->rentals->hasPaymentAttempt($rentalId, true)) {
                foreach ($items as $item) {
                    if (!in_array($item['allocation_state'], ['held', 'reconciliation_required'], true)) {
                        throw new \DomainException('The pending rental hold is not cancellable.');
                    }
                }
                foreach ($items as $item) { $this->rentals->setAllocationState((int) $item['rental_item_id'], 'released'); }
                $this->rentals->setRentalState($rentalId, 'cancelled');
                return;
            }
            if ($rental['state'] !== 'confirmed') {
                throw new \DomainException('An unpaid or unresolved payment must be authoritatively reconciled before cancellation.');
            }
            foreach ($items as $item) {
                if ($item['allocation_state'] !== 'confirmed' || $item['issue_quantity'] !== null) {
                    throw new \DomainException('Issued rentals cannot be cancelled.');
                }
            }
            foreach ($items as $item) { $this->rentals->setAllocationState((int) $item['rental_item_id'], 'released'); }
            $this->rentals->setRentalState($rentalId, 'cancelled');
            $this->notifications?->record(
                'rental_cancelled',
                'rental-cancelled:' . $rentalId,
                'rental',
                $rentalId,
            );
        });
    }

    private function requireAdmin(int $actorId, string $role): void
    {
        if ($actorId < 1 || $role !== 'admin') {
            throw new \DomainException('Administrator access is required.');
        }
    }
}
