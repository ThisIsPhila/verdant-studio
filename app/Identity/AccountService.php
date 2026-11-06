<?php

declare(strict_types=1);

namespace Verdant\Identity;

use Verdant\Retail\OrderRepository;
use Verdant\Rentals\RentalRepository;

final class AccountService
{
    public function __construct(private readonly OrderRepository $orders, private readonly RentalRepository $rentals)
    {
    }

    public function overview(array $actor): array
    {
        $id = $this->customerId($actor);
        return ['orders' => $this->orders->forCustomer($id), 'rentals' => $this->rentals->forCustomer($id)];
    }

    public function order(array $actor, int $orderId): ?array
    {
        $id = $this->customerId($actor);
        return $orderId > 0 ? $this->orders->owned($id, $orderId) : null;
    }

    public function rental(array $actor, int $rentalId): ?array
    {
        $id = $this->customerId($actor);
        return $rentalId > 0 ? $this->rentals->owned($id, $rentalId) : null;
    }

    private function customerId(array $actor): int
    {
        // Only a DB-resolved active actor crosses this internal service boundary.
        $id = $actor['customer_id'] ?? null;
        if (!is_int($id) || $id < 1) {
            throw new \DomainException('Account access required.');
        }
        return $id;
    }
}
