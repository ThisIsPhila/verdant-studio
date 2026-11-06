<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Catalogue\CatalogueService;
use Verdant\Retail\CartService;
use Verdant\Retail\OrderService;
use Verdant\Payments\PaystackConfiguration;
use Verdant\Payments\PaystackException;
use Verdant\Support\CommerceSummary;
use Verdant\Support\ValidationException;

final class CartController
{
    public function __construct(
        private readonly CartService $cart,
        private readonly OrderService $orders,
        private readonly ?CatalogueService $catalogue = null,
    ) {
    }

    public function show(?array $actor, array $errors = [], int $status = 200): void
    {
        $this->currentCheckout($actor);
        $summary = $this->cart->summary(self::sessionCart());
        $summary['errors'] = $errors + $summary['errors'];
        View::render('cart', $summary + ['title' => 'Your basket', 'actor' => $actor], $status);
    }

    public function add(array $input, ?array $actor): void
    {
        if ($this->checkoutStarted($actor)) { return; }
        try {
            $nextCart = $this->cart->add(self::sessionCart(), $input['product_id'] ?? null, $input['quantity'] ?? null);
            $potId = $input['pot_id'] ?? '';
            $productId = $input['product_id'] ?? '';
            $selectedProduct = is_string($productId) && ctype_digit($productId) && strlen($productId) <= 18
                ? $this->catalogue?->product((int) $productId) : null;
            if (($selectedProduct['product_kind'] ?? '') === 'plant' && ($potId === '' || $potId === '0')) {
                throw new ValidationException(['cart' => 'Choose a compatible pot for your plant.']);
            }
            if ($potId !== '') {
                if (!is_string($potId) || !ctype_digit($potId) || strlen($potId) > 18) throw new ValidationException(['cart' => 'Choose an available pot.']);
                $plantId = $input['product_id'] ?? '';
                $plant = is_string($plantId) && ctype_digit($plantId) && strlen($plantId) <= 18 ? $this->catalogue?->product((int) $plantId) : null;
                $eligible = false;
                foreach ($plant['compatible_pots'] ?? [] as $pot) if ((string) $pot['product_id'] === $potId) $eligible = true;
                if (!$eligible) throw new ValidationException(['cart' => 'That pot is not available for this plant.']);
                $nextCart = $this->cart->add($nextCart, $potId, $input['quantity'] ?? null);
            }
            $_SESSION['_retail_cart'] = $nextCart;
        } catch (ValidationException $error) {
            $this->show($actor, $error->errors, 422);
            return;
        }
        AuthController::redirect('/cart');
    }

    public function update(array $input, ?array $actor): void
    {
        if ($this->checkoutStarted($actor)) { return; }
        try {
            $_SESSION['_retail_cart'] = $this->cart->update(self::sessionCart(), $input['product_id'] ?? null, $input['quantity'] ?? null);
        } catch (ValidationException $error) {
            $this->show($actor, $error->errors, 422);
            return;
        }
        AuthController::redirect('/cart');
    }

    public function remove(array $input, ?array $actor): void
    {
        if ($this->checkoutStarted($actor)) { return; }
        try {
            $_SESSION['_retail_cart'] = $this->cart->remove(self::sessionCart(), $input['product_id'] ?? null);
        } catch (ValidationException $error) {
            $this->show($actor, $error->errors, 422);
            return;
        }
        AuthController::redirect('/cart');
    }

    public function validateCheckout(?array $actor): void
    {
        if ($actor === null) {
            AuthController::redirect('/login');
            return;
        }
        $existing = $this->currentCheckout($actor);
        if ($existing !== null) {
            $this->renderCheckout($existing, $actor);
            return;
        }
        $summary = $this->cart->summary(self::sessionCart());
        if (!$summary['valid']) {
            $this->show($actor, $summary['errors'] ?: ['cart' => 'Add an available item before continuing.'], 422);
            return;
        }
        try {
            PaystackConfiguration::fromEnvironment();
        } catch (PaystackException) {
            $this->show($actor, ['checkout' => 'Configure a Paystack test key before creating an order. Your basket is saved and no stock has been reserved.'], 503);
            return;
        }
        try {
            $_SESSION['_checkout_key'] ??= bin2hex(random_bytes(32));
            $order = $this->orders->createPending((int) $actor['customer_id'], self::sessionCart(), $_SESSION['_checkout_key']);
            $_SESSION['_checkout_order_id'] = (int) $order['order_id'];
        } catch (ValidationException $error) {
            $this->show($actor, $error->errors, 422);
            return;
        }
        $this->renderCheckout($order, $actor);
    }

    private function checkoutStarted(?array $actor): bool
    {
        if ($this->currentCheckout($actor) === null) { return false; }
        $this->show($actor, ['checkout' => 'Your order is waiting for a payment result. You cannot change its basket while it is being verified.'], 409);
        return true;
    }

    private function currentCheckout(?array $actor): ?array
    {
        $id = $_SESSION['_checkout_order_id'] ?? null;
        if ($actor === null || !is_int($id) || $id < 1) {
            return null;
        }
        $order = $this->orders->checkoutForCustomer((int) $actor['customer_id'], $id);
        if ($order !== null && in_array($order['state'], ['pending_payment', 'reconciliation_required'], true)) {
            return $order;
        }
        // A webhook or administrator can end checkout without updating this browser's session.
        unset($_SESSION['_checkout_order_id'], $_SESSION['_checkout_key']);
        if (($order['state'] ?? null) === 'confirmed') {
            unset($_SESSION['_retail_cart']);
        }
        return null;
    }

    private function renderCheckout(array $order, array $actor): void
    {
        // Re-load this customer's persisted snapshots; createPending's return value is intentionally small.
        $ownedOrder = $this->orders->checkoutForCustomer((int) $actor['customer_id'], (int) ($order['order_id'] ?? 0));
        if ($ownedOrder === null) {
            AuthController::redirect('/account/order?id=' . (int) ($order['order_id'] ?? 0));
            return;
        }
        $order = $ownedOrder;
        try {
            PaystackConfiguration::fromEnvironment();
            $sandboxEnabled = true;
        } catch (PaystackException) {
            $sandboxEnabled = false;
        }
        View::render('checkout', ['title' => 'Order ready', 'actor' => $actor, 'order' => $order,
            'summary' => CommerceSummary::fromRetailOrder($order),
            'sandboxEnabled' => $sandboxEnabled]);
    }

    /** @return array<string, int> */
    private static function sessionCart(): array
    {
        $cart = $_SESSION['_retail_cart'] ?? [];
        return is_array($cart) ? $cart : [];
    }
}
