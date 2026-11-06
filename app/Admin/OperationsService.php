<?php

declare(strict_types=1);

namespace Verdant\Admin;

use Verdant\Inventory\InventoryRepository;
use Verdant\Notifications\CommerceNotifier;
use Verdant\Persistence\TransactionManager;
use Verdant\Support\ValidationException;

final class OperationsService
{
    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly OperationsRepository $operations,
        private readonly InventoryRepository $inventory,
        private readonly TransactionManager $transactions,
        private readonly AdminAccess $access,
        private readonly ?CommerceNotifier $notifications = null,
    ) {
    }

    public function customers(array $actor, int $page = 1): array
    {
        $this->access->require($actor);
        return $this->page('customers', $page);
    }

    public function customer(array $actor, int $id): ?array
    {
        $this->access->require($actor);
        return $id > 0 ? $this->operations->customer($id) : null;
    }

    public function orders(array $actor, int $page = 1): array
    {
        $this->access->require($actor);
        return $this->page('orders', $page);
    }

    public function order(array $actor, int $id): ?array
    {
        $this->access->require($actor);
        return $id > 0 ? $this->operations->order($id) : null;
    }

    public function setCustomerArchived(array $actor, int $id, bool $archived): void
    {
        if ($id < 1) {
            throw new ValidationException(['customer' => 'Choose a valid customer.']);
        }
        $this->transactions->run(function () use ($actor, $id, $archived): void {
            $this->access->require($actor, true);
            $customer = $this->operations->customer($id, true);
            if ($customer === null || $customer['role'] !== 'customer') {
                throw new ValidationException(['customer' => 'This customer record is unavailable.']);
            }
            $this->operations->setCustomerArchived($id, $archived);
        });
    }

    public function fulfilOrder(array $actor, int $id): void
    {
        if ($id < 1) {
            throw new ValidationException(['order' => 'Choose a valid order.']);
        }
        $this->transactions->run(function () use ($actor, $id): void {
            $this->access->require($actor, true);
            $header = $this->operations->lockOrderHeader($id);
            if ($header === null) {
                throw new ValidationException(['order' => 'This order is unavailable.']);
            }
            if ($header['fulfilled_at'] !== null) {
                return;
            }
            if ($header['state'] !== 'confirmed') {
                throw new ValidationException(['order' => 'Only confirmed, paid orders can be fulfilled.']);
            }
            $lines = $this->operations->lockLines($id);
            if ($lines === []) {
                throw new ValidationException(['order' => 'This order has no fulfilment items.']);
            }
            $productIds = array_values(array_unique(array_map(static fn (array $line): int => (int) $line['product_id'], $lines)));
            sort($productIds, SORT_NUMERIC);
            $this->inventory->lockProducts($productIds);

            $total = 0;
            foreach ($lines as $line) {
                $quantity = (int) $line['quantity'];
                $price = (int) $line['unit_price_minor'];
                if ($quantity < 1 || $price < 0 || $price > intdiv(PHP_INT_MAX, $quantity)
                    || $total > PHP_INT_MAX - $price * $quantity) {
                    throw new ValidationException(['order' => 'The order total is invalid and cannot be fulfilled.']);
                }
                $total += $price * $quantity;
                if ($line['hold_state'] !== 'consumed' || (int) $line['hold_quantity'] !== $quantity) {
                    throw new ValidationException(['order' => 'The order stock must already be consumed by verified payment before fulfilment.']);
                }
            }
            $link = $this->operations->compositionLink($id, true);
            $paid = $link !== null && $link['order_role'] === 'sale'
                && \Verdant\Compositions\CompositionLinkRepository::hasConfirmedPayment($link);
            foreach ($link === null ? $this->operations->paymentsForUpdate($id) : [] as $payment) {
                if ($payment['state'] === 'succeeded' && $payment['currency'] === 'ZAR' && (int) $payment['amount_minor'] === $total) {
                    $paid = true;
                    break;
                }
            }
            if (!$paid) {
                throw new ValidationException(['order' => 'A matching successful payment is required before fulfilment.']);
            }
            $this->operations->fulfil($id);
            $this->notifications?->record(
                'order_fulfilled',
                'order-fulfilled:' . $id,
                'order',
                $id,
            );
        });
    }

    public function cancelOrder(array $actor, int $id): void
    {
        if ($id < 1) {
            throw new ValidationException(['order' => 'Choose a valid order.']);
        }
        $this->transactions->run(function () use ($actor, $id): void {
            $this->access->require($actor, true);
            $header = $this->operations->lockOrderHeader($id);
            if ($header === null) {
                throw new ValidationException(['order' => 'This order is unavailable.']);
            }
            if ($this->operations->compositionLink($id, true) !== null) {
                throw new ValidationException(['order' => 'Cancel the complete unstarted arrangement through its checkout.']);
            }
            if ($header['state'] === 'cancelled') {
                return;
            }
            if ($header['state'] !== 'pending_payment') {
                throw new ValidationException(['order' => 'Only an unstarted order can be cancelled.']);
            }
            $lines = $this->operations->lockLines($id);
            $productIds = array_values(array_unique(array_map(static fn (array $line): int => (int) $line['product_id'], $lines)));
            sort($productIds, SORT_NUMERIC);
            if ($productIds !== []) {
                $this->inventory->lockProducts($productIds);
            }
            if ($this->operations->paymentsForUpdate($id) !== []) {
                throw new ValidationException(['order' => 'This order has a payment attempt and must be reconciled before cancellation.']);
            }
            $this->operations->releaseHolds($id);
            $this->operations->setOrderState($id, 'cancelled');
            $this->notifications?->record(
                'order_cancelled',
                'order-cancelled:' . $id,
                'order',
                $id,
            );
        });
    }

    public function setOrderArchived(array $actor, int $id, bool $archived): void
    {
        if ($id < 1) {
            throw new ValidationException(['order' => 'Choose a valid order.']);
        }
        $this->transactions->run(function () use ($actor, $id, $archived): void {
            $this->access->require($actor, true);
            $header = $this->operations->lockOrderHeader($id);
            if ($header === null) {
                throw new ValidationException(['order' => 'This order is unavailable.']);
            }
            $this->operations->setOrderArchived($id, $archived);
        });
    }

    private function page(string $kind, int $page): array
    {
        if ($page < 1 || $page > 1000000) {
            $page = 1;
        }
        $offset = ($page - 1) * self::PAGE_SIZE;
        $items = $kind === 'customers'
            ? $this->operations->customers($offset, self::PAGE_SIZE + 1)
            : $this->operations->orders($offset, self::PAGE_SIZE + 1);
        $hasNext = count($items) > self::PAGE_SIZE;
        if ($hasNext) {
            array_pop($items);
        }
        return ['items' => $items, 'page' => $page, 'has_next' => $hasNext];
    }
}
