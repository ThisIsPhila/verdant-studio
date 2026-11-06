<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Inventory\CapacityRepository;
use Verdant\Inventory\InventoryRepository;
use Verdant\Payments\PaystackConfiguration;
use Verdant\Payments\PaystackException;
use Verdant\Payments\PaystackHttpClient;
use Verdant\Payments\RentalPaymentRepository;
use Verdant\Payments\RentalPaymentService;
use Verdant\Persistence\TransactionManager;
use Verdant\Rentals\RentalRepository;
use Verdant\Rentals\RentalService;
use Verdant\Support\CommerceSummary;

final class RentalController
{
    public function __construct(
        private readonly RentalService $rentals,
        private readonly RentalRepository $rentalRecords,
        private readonly RentalPaymentRepository $payments,
        private readonly InventoryRepository $inventory,
        private readonly CapacityRepository $capacity,
        private readonly TransactionManager $transactions,
        private readonly ?\Verdant\Notifications\CommerceNotifier $notifications = null,
    ) {
    }

    public function quote(array $query, ?array $actor): void
    {
        $input = [
            'product_id' => self::inputString($query['product_id'] ?? null),
            'quantity' => self::inputString($query['quantity'] ?? '1'),
            'start_date' => self::inputString($query['start_date'] ?? self::today()),
            'end_date' => self::inputString($query['end_date'] ?? self::tomorrow()),
        ];
        $quote = null;
        $errors = [];
        $status = 200;
        if (!self::positiveInteger($input['product_id'])) {
            $errors['product_id'] = 'Choose a valid product to rent.';
            $status = 422;
        } elseif (!self::positiveInteger($input['quantity'])) {
            $errors['quantity'] = 'Enter a positive rental quantity.';
            $status = 422;
        } else {
            try {
                $quote = $this->rentals->quote((int) $input['product_id'], (int) $input['quantity'], $input['start_date'], $input['end_date']);
            } catch (\InvalidArgumentException $error) {
                $errors['dates'] = $error->getMessage();
                $status = 422;
            } catch (\OverflowException) {
                $errors['quantity'] = 'The requested rental total exceeds the supported amount. Choose a smaller quantity or shorter period.';
                $status = 422;
            } catch (\DomainException) {
                View::render('error', ['title' => 'Rental unavailable', 'message' => 'This product is not currently available for rental.', 'actor' => $actor], 404);
                return;
            }
        }
        View::render('rental', ['title' => 'Check rental availability', 'actor' => $actor, 'input' => $input,
            'quote' => $quote, 'errors' => $errors, 'status' => $status], $status);
    }

    public function checkout(array $input, ?array $actor): void
    {
        if ($actor === null) {
            AuthController::redirect('/login');
            return;
        }
        $fields = [
            'product_id' => self::inputString($input['product_id'] ?? null),
            'quantity' => self::inputString($input['quantity'] ?? null),
            'start_date' => self::inputString($input['start_date'] ?? null),
            'end_date' => self::inputString($input['end_date'] ?? null),
        ];
        if (!self::positiveInteger($fields['product_id']) || !self::positiveInteger($fields['quantity'])) {
            $this->renderCheckoutError($fields, ['quantity' => 'Choose a valid product and a positive quantity.'], 422, $actor);
            return;
        }
        try {
            PaystackConfiguration::fromEnvironment();
        } catch (PaystackException) {
            $this->renderCheckoutError($fields, ['checkout' => 'Configure a Paystack test key before reserving dates. No rental hold has been created.'], 503, $actor);
            return;
        }
        try {
            $_SESSION['_rental_checkout_key'] ??= bin2hex(random_bytes(24));
            $rental = $this->rentals->createPending(
                (int) $actor['customer_id'], (int) $fields['product_id'], (int) $fields['quantity'],
                $fields['start_date'], $fields['end_date'], (string) $_SESSION['_rental_checkout_key'],
            );
            $_SESSION['_rental_checkout_id'] = (int) $rental['rental_id'];
            if ($rental['state'] === 'confirmed') {
                $this->clearCheckoutState((int) $rental['rental_id']);
                View::render('rental-payment-result', ['title' => 'Rental confirmed', 'actor' => $actor,
                    'state' => 'succeeded', 'rentalId' => (int) $rental['rental_id']]);
                return;
            }
            if ($rental['state'] === 'cancelled') {
                $this->clearCheckoutState((int) $rental['rental_id']);
                $this->renderCheckoutError($fields, ['checkout' => 'This reservation attempt has ended. Check the dates again to start a new reservation.'], 409, $actor);
                return;
            }
            if ($rental['state'] === 'reconciliation_required') {
                View::render('rental-payment-result', ['title' => 'Rental payment status', 'actor' => $actor,
                    'state' => 'reconciliation_required', 'rentalId' => (int) $rental['rental_id']]);
                return;
            }
            $this->renderCheckout($rental, $actor);
        } catch (\InvalidArgumentException $error) {
            $this->renderCheckoutError($fields, ['dates' => $error->getMessage()], 422, $actor);
        } catch (\OverflowException) {
            $this->renderCheckoutError($fields, ['checkout' => 'The requested rental total exceeds the supported amount. Choose a smaller quantity or shorter period.'], 422, $actor);
        } catch (\DomainException $error) {
            $this->renderCheckoutError($fields, ['checkout' => $error->getMessage()], 409, $actor);
        }
    }

    public function startPayment(?array $actor): void
    {
        if ($actor === null) {
            AuthController::redirect('/login');
            return;
        }
        $rentalId = $_SESSION['_rental_checkout_id'] ?? null;
        if (!is_int($rentalId) || $rentalId < 1) {
            AuthController::redirect('/account');
            return;
        }
        try {
            $configuration = PaystackConfiguration::fromEnvironment();
            $result = $this->paymentService($configuration)->start((int) $actor['customer_id'], $rentalId, (string) $actor['email']);
            if ($result['state'] === 'ready') {
                PaymentRedirect::toCheckout($result['authorization_url'], $actor);
                return;
            }
            if (in_array($result['state'], ['succeeded', 'failed', 'cancelled_paid'], true)) {
                $this->clearCheckoutState($rentalId);
            }
            View::render('rental-payment-result', ['title' => 'Rental payment status', 'actor' => $actor, 'state' => $result['state'], 'rentalId' => $rentalId]);
        } catch (PaystackException $error) {
            $state = $error->uncertain ? 'reconciliation_required' : 'sandbox_unavailable';
            if (!$error->uncertain) {
                $rental = $this->rentalRecords->owned((int) $actor['customer_id'], $rentalId);
                if (($rental['state'] ?? null) === 'cancelled') {
                    $this->clearCheckoutState($rentalId);
                    $state = 'initialization_failed';
                }
            }
            View::render('rental-payment-result', ['title' => 'Sandbox checkout unavailable', 'actor' => $actor, 'state' => $state, 'rentalId' => $rentalId], 503);
        } catch (\DomainException) {
            View::render('rental-payment-result', ['title' => 'Rental unavailable', 'actor' => $actor, 'state' => 'unavailable'], 404);
        }
    }

    public function callback(mixed $reference, ?array $actor): void
    {
        if ($actor === null) {
            AuthController::redirect('/login');
            return;
        }
        if (!is_string($reference) || preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $reference) !== 1) {
            View::render('rental-payment-result', ['title' => 'Rental payment status', 'actor' => $actor, 'state' => 'unavailable'], 400);
            return;
        }
        try {
            $configuration = PaystackConfiguration::fromEnvironment();
            $payment = $this->payments->byReference($reference);
            if ($payment === null || (int) ($payment['customer_id'] ?? 0) !== (int) $actor['customer_id']) {
                View::render('rental-payment-result', ['title' => 'Rental payment status', 'actor' => $actor, 'state' => 'unavailable'], 404);
                return;
            }
            $state = $this->paymentService($configuration)->verifyForCustomer((int) $actor['customer_id'], $reference);
            $rentalId = (int) $payment['rental_id'];
            if (in_array($state, ['succeeded', 'failed', 'cancelled_paid'], true)) {
                $this->clearCheckoutState($rentalId);
            }
            View::render('rental-payment-result', ['title' => 'Rental payment status', 'actor' => $actor, 'state' => $state, 'rentalId' => $rentalId ?: null]);
        } catch (PaystackException) {
            View::render('rental-payment-result', ['title' => 'Rental payment status', 'actor' => $actor, 'state' => 'reconciliation_required'], 503);
        }
    }

    public function webhook(?string $incomingRawBody = null): void
    {
        try {
            $configuration = PaystackConfiguration::fromEnvironment();
        } catch (PaystackException) {
            $this->webhookResponse(false, 503);
            return;
        }
        $rawBody = $incomingRawBody ?? file_get_contents('php://input');
        $result = $this->paymentService($configuration)->handleWebhook(
            is_string($rawBody) ? $rawBody : '', $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? null, $configuration->secretKey,
        );
        $this->webhookResponse((bool) $result['accepted'], $result['accepted'] ? 200 : ($result['state'] === 'verification_unavailable' ? 503 : 401));
    }

    public function cancel(array $input, ?array $actor): void
    {
        if ($actor === null) {
            AuthController::redirect('/login');
            return;
        }
        $value = $input['rental_id'] ?? null;
        if (!is_string($value) || !self::positiveInteger($value)) {
            View::render('error', ['title' => 'Rental unavailable', 'message' => 'This rental could not be cancelled. Review it in My account.', 'actor' => $actor], 404);
            return;
        }
        $rentalId = (int) $value;
        if (($actor['role'] ?? null) !== 'admin'
            && $this->rentalRecords->owned((int) $actor['customer_id'], $rentalId) === null) {
            View::render('error', ['title' => 'Rental unavailable', 'message' => 'This rental is unavailable. You can review your own rentals in My account.', 'actor' => $actor], 404);
            return;
        }
        try {
            $this->rentals->cancel((int) $actor['customer_id'], (string) $actor['role'], $rentalId);
            $this->clearCheckoutState($rentalId);
            AuthController::redirect('/account/rental?id=' . $rentalId);
        } catch (\DomainException $error) {
            View::render('rental-payment-result', ['title' => 'Rental not cancelled', 'actor' => $actor,
                'state' => 'cannot_cancel', 'message' => $error->getMessage(), 'rentalId' => $rentalId], 409);
        }
    }

    private function paymentService(PaystackConfiguration $configuration): RentalPaymentService
    {
        return new RentalPaymentService(
            $this->payments, $this->inventory, $this->capacity, $this->transactions,
            new PaystackHttpClient($configuration->secretKey), self::rentalCallbackUrl($configuration->callbackUrl), $this->notifications,
        );
    }

    private static function rentalCallbackUrl(string $configuredUrl): string
    {
        $parts = parse_url($configuredUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new PaystackException('The sandbox callback URL could not be validated.', false);
        }
        $host = str_contains((string) $parts['host'], ':') ? '[' . $parts['host'] . ']' : $parts['host'];
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        return strtolower((string) $parts['scheme']) . '://' . $host . $port . '/rental/payment/return';
    }

    private function renderCheckout(array $rental, array $actor): void
    {
        try {
            PaystackConfiguration::fromEnvironment();
            $sandboxEnabled = true;
        } catch (PaystackException) {
            $sandboxEnabled = false;
        }
        View::render('rental-checkout', ['title' => 'Rental ready', 'actor' => $actor, 'rental' => $rental,
            'summary' => CommerceSummary::fromRental($rental),
            'sandboxEnabled' => $sandboxEnabled]);
    }

    private function renderCheckoutError(array $input, array $errors, int $status, array $actor): void
    {
        $quote = null;
        if (self::positiveInteger($input['product_id'] ?? null) && self::positiveInteger($input['quantity'] ?? null)) {
            try {
                $quote = $this->rentals->quote((int) $input['product_id'], (int) $input['quantity'], (string) $input['start_date'], (string) $input['end_date']);
            } catch (\Throwable) {
                // Preserve entered values and let the page explain the checkout failure.
            }
        }
        View::render('rental', ['title' => 'Check rental availability', 'actor' => $actor, 'input' => $input,
            'quote' => $quote, 'errors' => $errors, 'status' => $status], $status);
    }

    private function clearCheckoutState(int $rentalId): void
    {
        if ($rentalId > 0 && (int) ($_SESSION['_rental_checkout_id'] ?? 0) === $rentalId) {
            unset($_SESSION['_rental_checkout_id'], $_SESSION['_rental_checkout_key']);
        }
    }

    private function webhookResponse(bool $accepted, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode(['received' => $accepted], JSON_THROW_ON_ERROR);
    }

    private static function positiveInteger(?string $value): bool
    {
        return is_string($value) && ctype_digit($value) && strlen($value) <= 18 && (int) $value > 0;
    }

    private static function inputString(mixed $value): string
    {
        return is_string($value) && strlen($value) <= 100 ? $value : '';
    }

    private static function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
    }

    private static function tomorrow(): string
    {
        return (new \DateTimeImmutable('tomorrow', new \DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
    }
}
