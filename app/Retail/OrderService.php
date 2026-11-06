<?php

declare(strict_types=1);

namespace Verdant\Retail;

use Verdant\Inventory\InventoryRepository;
use Verdant\Persistence\TransactionManager;
use Verdant\Support\ValidationException;

final class OrderService
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly InventoryRepository $inventory,
        private readonly RetailAvailabilityRepository $availability,
        private readonly TransactionManager $transactions,
    ) {
    }

    public function checkoutForCustomer(int $customerId, int $orderId): ?array
    {
        return $customerId > 0 && $orderId > 0 ? $this->orders->owned($customerId, $orderId) : null;
    }

    /** @param array<string|int, int> $cart */
    public function createPending(int $customerId, array $cart, string $checkoutKey): array
    {
        if ($customerId < 1 || preg_match('/^[a-f0-9]{64}$/D', $checkoutKey) !== 1) {
            throw new ValidationException(['checkout' => 'Your checkout session is invalid. Please review your basket and try again.']);
        }
        if ($cart === [] || count($cart) > 50) {
            throw new ValidationException(['cart' => 'Add at least one available item before continuing.']);
        }
        $items = [];
        foreach ($cart as $rawId => $quantity) {
            $id = is_int($rawId) ? $rawId : (is_string($rawId) && ctype_digit($rawId) ? (int) $rawId : 0);
            if ($id < 1 || !is_int($quantity) || $quantity < 1 || $quantity > 99) {
                throw new ValidationException(['cart' => 'Your basket contains an invalid item or quantity. Review it and try again.']);
            }
            $items[$id] = $quantity;
        }
        ksort($items, SORT_NUMERIC);

        for ($attempt = 0; ; ++$attempt) {
            try {
                return $this->transactions->run(function () use ($customerId, $items, $checkoutKey): array {
                    $existing = $this->orders->forCheckoutUpdate($customerId, $checkoutKey);
                    if ($existing !== null) {
                        return $existing;
                    }

                    $this->inventory->lockProducts(array_keys($items));
                    $products = $this->availability->forProducts(array_keys($items));
                    if (count($products) !== count($items)) {
                        throw new ValidationException(['cart' => 'One or more items are no longer available. Your basket is unchanged.']);
                    }
                    $total = 0;
                    foreach ($products as $product) {
                        $id = (int) $product['product_id'];
                        $quantity = $items[$id];
                        if (!(bool) $product['buy_enabled']) {
                            throw new ValidationException(['cart' => $product['name'] . ' is no longer available to buy.']);
                        }
                        if ($quantity > (int) $product['available_quantity']) {
                            throw new ValidationException(['cart' => 'Only ' . (int) $product['available_quantity'] . ' of ' . $product['name'] . ' are available. Your basket is unchanged.']);
                        }
                        $price = (int) $product['retail_price_minor'];
                        if ($price < 0 || $price > intdiv(PHP_INT_MAX, $quantity)
                            || $total > PHP_INT_MAX - ($price * $quantity)) {
                            throw new ValidationException(['cart' => 'The order total cannot be processed. Contact the nursery for help.']);
                        }
                        $total += $price * $quantity;
                    }

                    $orderId = $this->orders->insertOrder($customerId, $checkoutKey);
                    foreach ($products as $product) {
                        $id = (int) $product['product_id'];
                        $itemId = $this->orders->insertItem(
                            $orderId, $id, (string) $product['name'], (string) $product['sku'],
                            $items[$id], (int) $product['retail_price_minor'],
                        );
                        $this->orders->insertHold($itemId, $items[$id]);
                    }
                    return ['order_id' => $orderId, 'state' => 'pending_payment', 'total_minor' => $total, 'existing' => false];
                });
            } catch (\Verdant\Persistence\DatabaseException $error) {
                if (in_array($error->getCode(), [1205, 1213], true) && $attempt < 2) {
                    usleep(random_int(10000, 50000));
                    continue;
                }
                if ($error->getCode() === 1062) {
                    $existing = $this->orders->forCheckout($customerId, $checkoutKey);
                    if ($existing !== null) {
                        return $existing;
                    }
                    throw $error;
                }
                if (in_array($error->getCode(), [1205, 1213], true)) {
                    throw new ValidationException(['cart' => 'Stock availability changed while checkout was prepared. Please review your basket and retry.']);
                }
                throw $error;
            }
        }
    }
}
