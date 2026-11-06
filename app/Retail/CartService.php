<?php

declare(strict_types=1);

namespace Verdant\Retail;

use Verdant\Support\ValidationException;

final class CartService
{
    public function __construct(private readonly RetailAvailabilityRepository $products)
    {
    }

    /** @param array<string, int> $cart @return array<string, int> */
    public function add(array $cart, mixed $productId, mixed $quantity): array
    {
        [$id, $amount] = $this->validateInput($productId, $quantity);
        $product = $this->products->find($id);
        if ($product === null || !(bool) $product['buy_enabled']) {
            throw new ValidationException(['cart' => 'This item is no longer available to buy.']);
        }
        if (!array_key_exists((string) $id, $cart) && count($cart) >= 50) {
            throw new ValidationException(['cart' => 'Your basket can contain up to 50 different items.']);
        }
        $next = ($cart[(string) $id] ?? 0) + $amount;
        $available = (int) $product['available_quantity'];
        if ($next > 99 || $next > $available) {
            throw new ValidationException(['cart' => 'Choose a quantity that is currently available (' . $available . ' in stock).']);
        }
        $cart[(string) $id] = $next;
        return $cart;
    }

    /** @param array<string, int> $cart @return array<string, int> */
    public function update(array $cart, mixed $productId, mixed $quantity): array
    {
        [$id, $amount] = $this->validateInput($productId, $quantity);
        if (!array_key_exists((string) $id, $cart)) {
            throw new ValidationException(['cart' => 'That item is no longer in your cart.']);
        }
        $product = $this->products->find($id);
        if ($product === null || !(bool) $product['buy_enabled']) {
            throw new ValidationException(['cart' => 'This item is no longer available to buy. Remove it to continue.']);
        }
        $available = (int) $product['available_quantity'];
        if ($amount > $available) {
            throw new ValidationException(['cart' => 'Only ' . $available . ' are currently available. Your cart was kept.']);
        }
        $cart[(string) $id] = $amount;
        return $cart;
    }

    /** @param array<string, int> $cart @return array<string, int> */
    public function remove(array $cart, mixed $productId): array
    {
        $id = $this->productId($productId);
        unset($cart[(string) $id]);
        return $cart;
    }

    /** @param array<string, int> $cart */
    public function summary(array $cart): array
    {
        if (count($cart) > 50) {
            throw new ValidationException(['cart' => 'Your cart has too many different items. Remove some before continuing.']);
        }
        $items = [];
        $errors = [];
        $total = 0;
        foreach ($cart as $rawId => $quantity) {
            $idText = is_int($rawId) ? (string) $rawId : $rawId;
            if (!is_string($idText) || !ctype_digit($idText) || (int) $idText < 1 || !is_int($quantity) || $quantity < 1 || $quantity > 99) {
                $errors['cart'] = 'Your saved cart needs attention. Remove invalid items and try again.';
                continue;
            }
            $product = $this->products->find((int) $idText);
            if ($product === null || !(bool) $product['buy_enabled']) {
                $errors['product_' . $idText] = 'An item is no longer available to buy. Remove it to continue.';
                continue;
            }
            $available = (int) $product['available_quantity'];
            $lineErrors = [];
            if ($quantity > $available) {
                $lineErrors[] = 'Only ' . $available . ' currently available.';
            }
            $price = (int) $product['retail_price_minor'];
            $lineTotal = 0;
            if ($price > intdiv(PHP_INT_MAX, $quantity) || $total > PHP_INT_MAX - ($price * $quantity)) {
                $lineErrors[] = 'This total is too large to process.';
            } else {
                $lineTotal = $price * $quantity;
                $total += $lineTotal;
            }
            $items[] = $product + ['quantity' => $quantity, 'line_total_minor' => $lineTotal, 'errors' => $lineErrors];
            if ($lineErrors !== []) {
                $errors['product_' . $idText] = implode(' ', $lineErrors);
            }
        }
        return ['items' => $items, 'total_minor' => $total, 'errors' => $errors, 'valid' => $errors === [] && $items !== []];
    }

    /** @return array{0:int,1:int} */
    private function validateInput(mixed $productId, mixed $quantity): array
    {
        $id = $this->productId($productId);
        if (!is_string($quantity) || preg_match('/^[1-9][0-9]{0,2}$/D', $quantity) !== 1 || (int) $quantity > 99) {
            throw new ValidationException(['quantity' => 'Enter a quantity from 1 to 99.']);
        }
        return [$id, (int) $quantity];
    }

    private function productId(mixed $value): int
    {
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,17}$/D', $value) !== 1 || (int) $value < 1) {
            throw new ValidationException(['product' => 'Choose a valid product.']);
        }
        return (int) $value;
    }
}
