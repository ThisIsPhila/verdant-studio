<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Compositions\CompositionService;
use Verdant\Compositions\CompositionPaymentRepository;
use Verdant\Compositions\CompositionPaymentService;
use Verdant\Inventory\CapacityRepository;
use Verdant\Inventory\InventoryRepository;
use Verdant\Payments\PaystackConfiguration;
use Verdant\Payments\PaystackException;
use Verdant\Payments\PaystackHttpClient;
use Verdant\Persistence\TransactionManager;

final class CompositionPaymentController
{
    public function __construct(
        private readonly CompositionService $compositions,
        private readonly CompositionPaymentRepository $payments,
        private readonly InventoryRepository $inventory,
        private readonly CapacityRepository $capacity,
        private readonly TransactionManager $transactions,
        private readonly ?\Verdant\Notifications\CommerceNotifier $notifications = null,
    ) {
    }

    public function handlesOrder(int $orderId): bool
    {
        return $this->payments->checkoutForOrder($orderId) !== null;
    }

    public function handlesReference(mixed $reference): bool
    {
        return is_string($reference) && preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $reference) === 1
            && $this->payments->linkedPaymentForReference($reference) !== null;
    }

    public function start(array $input, ?array $actor): void
    {
        if ($actor === null) {
            AuthController::redirect('/login');
            return;
        }
        $id = $input['composition_checkout_id'] ?? null;
        $checkout = is_string($id) && ctype_digit($id) && strlen($id) <= 18
            ? $this->compositions->checkout((int) $actor['customer_id'], (int) $id) : null;
        if ($checkout === null) {
            $this->result('unavailable', $actor, 404);
            return;
        }
        $this->startOrder($actor, (int) $checkout['order_id']);
    }

    public function startOrder(array $actor, int $orderId): void
    {
        try {
            $service = $this->service(PaystackConfiguration::fromEnvironment());
            $result = $service->start((int) $actor['customer_id'], $orderId, (string) $actor['email']);
            if ($result['state'] === 'ready') {
                PaymentRedirect::toCheckout($result['authorization_url'], $actor);
                return;
            }
            $this->clearTerminalKey($result['state'], $orderId, (int) $actor['customer_id']);
            $this->result($result['state'], $actor);
        } catch (PaystackException $error) {
            $state = $error->uncertain ? 'reconciliation_required' : 'sandbox_unavailable';
            $checkout = $this->payments->checkoutForOrder($orderId);
            if (!$error->uncertain && ($checkout['checkout_state'] ?? null) === 'cancelled') {
                $state = 'initialization_failed';
                $this->clearTerminalKey('failed', $orderId, (int) $actor['customer_id']);
            }
            $this->result($state, $actor, 503);
        } catch (\DomainException) {
            $this->result('unavailable', $actor, 404);
        }
    }

    public function cancel(array $input, ?array $actor): void
    {
        if ($actor === null) {
            AuthController::redirect('/login');
            return;
        }
        $id = $input['composition_checkout_id'] ?? null;
        $checkout = is_string($id) && ctype_digit($id) && strlen($id) <= 18
            ? $this->compositions->checkout((int) $actor['customer_id'], (int) $id) : null;
        if ($checkout === null) {
            $this->result('unavailable', $actor, 404);
            return;
        }
        $service = new CompositionPaymentService(
            $this->payments, $this->inventory, $this->capacity, $this->transactions,
        );
        $cancelled = $service->cancelUnstarted((int) $actor['customer_id'], (int) $checkout['order_id']);
        if ($cancelled) {
            $this->clearCheckoutKey((string) ($checkout['checkout_key'] ?? ''));
            AuthController::redirect('/compositions/checkout?id=' . (int) $checkout['composition_checkout_id']);
            return;
        }
        $this->result('reconciliation_required', $actor, 409);
    }

    public function callback(mixed $reference, ?array $actor): void
    {
        if ($actor === null) {
            AuthController::redirect('/login');
            return;
        }
        if (!is_string($reference) || preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $reference) !== 1) {
            $this->result('unavailable', $actor, 400);
            return;
        }
        try {
            $service = $this->service(PaystackConfiguration::fromEnvironment());
            $payment = $service->paymentForReference($reference);
            if ($payment === null || (int) $payment['customer_id'] !== (int) $actor['customer_id']) {
                $this->result('unavailable', $actor, 404);
                return;
            }
            $state = $service->verifyForCustomer((int) $actor['customer_id'], $reference);
            $this->clearTerminalKey($state, (int) $payment['order_id'], (int) $actor['customer_id']);
            $this->result($state, $actor);
        } catch (PaystackException) {
            $this->result('reconciliation_required', $actor, 503);
        } catch (\DomainException) {
            $this->result('unavailable', $actor, 404);
        }
    }

    public function webhook(string $body): void
    {
        try {
            $configuration = PaystackConfiguration::fromEnvironment();
            $result = $this->service($configuration)->handleWebhook(
                $body, $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? null, $configuration->secretKey,
            ) ?? ['accepted' => false, 'state' => 'unknown_reference'];
            $status = $result['accepted'] ? 200 : ($result['state'] === 'verification_unavailable' ? 503 : 401);
        } catch (PaystackException) {
            $result = ['accepted' => false];
            $status = 503;
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode(['received' => $result['accepted']], JSON_THROW_ON_ERROR);
    }

    private function service(PaystackConfiguration $configuration): CompositionPaymentService
    {
        return new CompositionPaymentService($this->payments, $this->inventory, $this->capacity, $this->transactions,
            new PaystackHttpClient($configuration->secretKey), $configuration->callbackUrl, $this->notifications);
    }

    private function clearTerminalKey(string $state, int $orderId, int $customerId): void
    {
        if (!in_array($state, ['succeeded', 'failed'], true)) {
            return;
        }
        $parent = $this->payments->checkoutForOrder($orderId);
        if ($parent === null || (int) $parent['customer_id'] !== $customerId) {
            return;
        }
        $checkout = $this->compositions->checkout($customerId, (int) $parent['composition_checkout_id']);
        $this->clearCheckoutKey((string) ($checkout['checkout_key'] ?? ''));
    }

    private function clearCheckoutKey(string $checkoutKey): void
    {
        if ($checkoutKey === '') { return; }
        foreach ($_SESSION['_composition_checkout_keys'] ?? [] as $key => $value) {
            if ($value === $checkoutKey) { unset($_SESSION['_composition_checkout_keys'][$key]); }
        }
    }

    private function result(string $state, array $actor, int $status = 200): void
    {
        View::render('composition-payment-result', ['title' => 'Arrangement payment status', 'state' => $state, 'actor' => $actor], $status);
    }
}
