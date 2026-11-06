<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Inventory\InventoryRepository;
use Verdant\Payments\PaystackConfiguration;
use Verdant\Payments\PaystackException;
use Verdant\Payments\PaystackHttpClient;
use Verdant\Payments\PaymentRepository;
use Verdant\Payments\PaymentService;
use Verdant\Persistence\TransactionManager;

final class PaymentController
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly InventoryRepository $inventory,
        private readonly TransactionManager $transactions,
        private readonly ?RentalController $rentals = null,
        private readonly ?CompositionPaymentController $compositionPayments = null,
        private readonly ?\Verdant\Notifications\CommerceNotifier $notifications = null,
    ) {
    }

    public function start(?array $actor): void
    {
        if ($actor === null) {
            AuthController::redirect('/login');
            return;
        }
        $orderId = $_SESSION['_checkout_order_id'] ?? null;
        if (!is_int($orderId) || $orderId < 1) {
            AuthController::redirect('/cart');
            return;
        }
        if ($this->compositionPayments?->handlesOrder($orderId)) {
            $this->compositionPayments->startOrder($actor, $orderId);
            return;
        }
        try {
            $configuration = PaystackConfiguration::fromEnvironment();
            $service = $this->service($configuration);
            $result = $service->start((int) $actor['customer_id'], $orderId, (string) $actor['email']);
            if ($result['state'] === 'ready') {
                PaymentRedirect::toCheckout($result['authorization_url'], $actor);
                return;
            }
            if (in_array($result['state'], ['succeeded', 'failed'], true)) {
                $this->clearCheckoutState($result['state']);
            }
            View::render('payment-result', ['title' => 'Payment status', 'actor' => $actor, 'state' => $result['state']]);
        } catch (PaystackException $error) {
            $state = $error->uncertain ? 'reconciliation_required' : 'sandbox_unavailable';
            if (!$error->uncertain
                && ($this->payments->orderForCustomer((int) $actor['customer_id'], $orderId)['state'] ?? null) === 'cancelled') {
                $this->clearCheckoutState('failed');
                $state = 'initialization_failed';
            }
            View::render('payment-result', [
                'title' => 'Sandbox checkout unavailable', 'actor' => $actor,
                'state' => $state,
            ], 503);
        } catch (\DomainException) {
            View::render('payment-result', ['title' => 'Order unavailable', 'actor' => $actor, 'state' => 'unavailable'], 404);
        }
    }

    public function callback(mixed $reference, ?array $actor): void
    {
        if ($actor === null) {
            AuthController::redirect('/login');
            return;
        }
        if (!is_string($reference) || preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $reference) !== 1) {
            View::render('payment-result', ['title' => 'Payment status', 'actor' => $actor, 'state' => 'unavailable'], 400);
            return;
        }
        if ($this->compositionPayments?->handlesReference($reference)) {
            $this->compositionPayments->callback($reference, $actor);
            return;
        }
        try {
            $configuration = PaystackConfiguration::fromEnvironment();
            $payment = $this->payments->byReference($reference);
            if ($payment === null || (int) $payment['customer_id'] !== (int) $actor['customer_id']) {
                View::render('payment-result', ['title' => 'Payment status', 'actor' => $actor, 'state' => 'unavailable'], 404);
                return;
            }
            $service = $this->service($configuration);
            $state = $service->verifyForCustomer((int) $actor['customer_id'], $reference);
            if (in_array($state, ['succeeded', 'failed'], true)
                && (int) ($_SESSION['_checkout_order_id'] ?? 0) === (int) $payment['order_id']) {
                $this->clearCheckoutState($state);
            }
            View::render('payment-result', ['title' => 'Payment status', 'actor' => $actor, 'state' => $state]);
        } catch (PaystackException) {
            View::render('payment-result', ['title' => 'Payment status', 'actor' => $actor, 'state' => 'reconciliation_required'], 503);
        }
    }

    public function webhook(): void
    {
        $rawBody = file_get_contents('php://input');
        $body = is_string($rawBody) ? $rawBody : '';
        if ($this->rentals !== null) {
            try {
                $event = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $event = null;
            }
            $reference = is_array($event) ? ($event['data']['reference'] ?? null) : null;
            if ($this->compositionPayments?->handlesReference($reference)) {
                $this->compositionPayments->webhook($body);
                return;
            }
            if (is_string($reference) && str_starts_with($reference, 'verdant-rental-')) {
                $this->rentals->webhook($body);
                return;
            }
        }
        try {
            $configuration = PaystackConfiguration::fromEnvironment();
        } catch (PaystackException) {
            http_response_code(503);
            header('Content-Type: application/json; charset=UTF-8');
            echo '{"received":false}';
            return;
        }
        $service = $this->service($configuration);
        $result = $service->handleWebhook($body, $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? null, $configuration->secretKey);
        http_response_code($result['accepted'] ? 200 : ($result['state'] === 'verification_unavailable' ? 503 : 401));
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode(['received' => $result['accepted']], JSON_THROW_ON_ERROR);
    }


    private function clearCheckoutState(string $state): void
    {
        unset($_SESSION['_checkout_order_id'], $_SESSION['_checkout_key']);
        if ($state === 'succeeded') { unset($_SESSION['_retail_cart']); }
    }

    private function service(PaystackConfiguration $configuration): PaymentService
    {
        return new PaymentService($this->payments, $this->inventory, $this->transactions,
            new PaystackHttpClient($configuration->secretKey), $configuration->callbackUrl, $this->notifications);
    }
}
