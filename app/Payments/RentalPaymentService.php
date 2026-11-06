<?php

declare(strict_types=1);

namespace Verdant\Payments;

use Verdant\Inventory\CapacityRepository;
use Verdant\Inventory\InventoryRepository;
use Verdant\Notifications\CommerceNotifier;
use Verdant\Persistence\DatabaseException;
use Verdant\Persistence\TransactionManager;

/** Server-verified Paystack test-mode checkout for rental reservations. */
final class RentalPaymentService
{
    public function __construct(
        private readonly RentalPaymentRepository $payments,
        private readonly InventoryRepository $inventory,
        private readonly CapacityRepository $capacity,
        private readonly TransactionManager $transactions,
        private readonly PaystackGateway $gateway,
        private readonly string $callbackUrl,
        private readonly ?CommerceNotifier $notifications = null,
    ) {
    }

    /** @return array{state:string,authorization_url?:string,reference?:string} */
    public function start(int $customerId, int $rentalId, string $email): array
    {
        if ($customerId < 1 || $rentalId < 1 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \DomainException('Your rental could not be prepared for payment.');
        }
        $reference = 'verdant-rental-' . $rentalId . '-' . bin2hex(random_bytes(12));
        $prepared = $this->transactions->run(function () use ($customerId, $rentalId, $reference): array {
            $rental = $this->payments->rentalForCustomer($customerId, $rentalId, true);
            if ($rental === null) { throw new \DomainException('Rental is unavailable.'); }
            $productIds = $this->payments->productIds($rentalId, true);
            if ($productIds === []) { throw new \DomainException('Rental has no reservable items.'); }
            $this->inventory->lockProducts($productIds);
            if (!$this->payments->activeCustomer($customerId, true)) { throw new \DomainException('Your account is unavailable for payment.'); }
            $prepared = $this->payments->prepareLocked($customerId, $rentalId, $reference);
            if (!in_array($prepared['rental']['state'], ['pending_payment', 'reconciliation_required'], true)) {
                return $prepared;
            }
            foreach ($prepared['items'] as $item) {
                if (!in_array($item['allocation_state'], ['held', 'reconciliation_required'], true)
                    || (int) $item['allocation_quantity'] !== (int) $item['quantity']) {
                    throw new \DomainException('The rental no longer has an active payment hold.');
                }
            }
            return $prepared;
        });
        $rental = $prepared['rental'];
        $payment = $prepared['payment'];
        if ($rental['state'] === 'confirmed') { return ['state' => 'succeeded']; }
        if ($rental['state'] === 'cancelled' && ($payment['state'] ?? null) === 'succeeded') { return ['state' => 'cancelled_paid']; }
        if ($payment === null || $rental['state'] === 'cancelled' || $payment['state'] === 'failed') { return ['state' => 'failed']; }
        if (is_string($payment['provider_checkout_url'] ?? null) && $payment['provider_checkout_url'] !== '') {
            return ['state' => 'ready', 'authorization_url' => $payment['provider_checkout_url'], 'reference' => (string) $payment['provider_reference']];
        }
        if (($payment['provider_reference'] ?? null) !== $reference) {
            return ['state' => $this->verifyAndApply((string) $payment['provider_reference'])];
        }
        try {
            $response = $this->gateway->initialize($email, (int) $payment['amount_minor'], $reference, $this->callbackUrl);
            $data = $response['data'] ?? null;
            if (($response['status'] ?? false) !== true || !is_array($data)
                || ($data['reference'] ?? null) !== $reference
                || !$this->isPaystackCheckoutUrl($data['authorization_url'] ?? null)) {
                throw new PaystackException('The sandbox checkout response could not be validated.', true);
            }
            $this->transactions->run(fn (): mixed => $this->payments->saveCheckoutUrl((int) $payment['rental_payment_id'], (string) $data['authorization_url']));
            return ['state' => 'ready', 'authorization_url' => (string) $data['authorization_url'], 'reference' => $reference];
        } catch (PaystackException $error) {
            if ($error->uncertain) {
                $this->setReconciliation($reference);
                throw new PaystackException('The provider response is uncertain. Your rental capacity remains held while the payment is checked.', true);
            }
            $this->releaseDefinitivelyFailedInitialization($reference);
            throw new PaystackException('The sandbox checkout could not be started. The rental was cancelled and its capacity released.', false);
        }
    }

    public function verifyForCustomer(int $customerId, string $reference): string
    {
        $payment = $this->payments->byReference($reference);
        if ($payment === null || (int) $payment['customer_id'] !== $customerId) { return 'unavailable'; }
        return $this->verifyAndApply($reference);
    }

    /** @return array{accepted:bool,state:string} */
    public function handleWebhook(string $rawBody, ?string $signature, string $secret): array
    {
        if ($signature === null || $signature === '' || !hash_equals(hash_hmac('sha512', $rawBody, $secret), $signature)) {
            return ['accepted' => false, 'state' => 'invalid_signature'];
        }
        try { $event = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return ['accepted' => false, 'state' => 'invalid_payload']; }
        if (!is_array($event) || !is_string($event['event'] ?? null)) { return ['accepted' => false, 'state' => 'invalid_payload']; }
        if ($event['event'] !== 'charge.success') { return ['accepted' => true, 'state' => 'ignored']; }
        $data = $event['data'] ?? null;
        $reference = is_array($data) ? ($data['reference'] ?? null) : null;
        if (!is_string($reference) || preg_match('/^[A-Za-z0-9_-]{1,160}$/D', $reference) !== 1) {
            return ['accepted' => false, 'state' => 'invalid_reference'];
        }
        $eventReference = 'charge.success:' . $reference;
        $payloadHash = hash('sha256', $rawBody);
        if ($this->payments->byReference($reference) === null) {
            try {
                $this->transactions->run(function () use ($eventReference, $payloadHash): void {
                    if ($this->payments->event($eventReference, true) === null) {
                        $this->payments->recordEvent($eventReference, null, $payloadHash, 'quarantined');
                    }
                });
                return ['accepted' => true, 'state' => 'unknown_reference'];
            } catch (DatabaseException $error) {
                if ($error->getCode() === 1062) { return ['accepted' => true, 'state' => 'duplicate']; }
                throw $error;
            }
        }
        try { $verification = $this->gateway->verify($reference); }
        catch (PaystackException) { return ['accepted' => false, 'state' => 'verification_unavailable']; }
        try { $state = $this->applyVerification($reference, $verification, $eventReference, $payloadHash); }
        catch (DatabaseException $error) {
            if ($error->getCode() !== 1062) { throw $error; }
            $state = 'duplicate';
        }
        return ['accepted' => true, 'state' => $state];
    }

    private function verifyAndApply(string $reference): string
    {
        try { $verification = $this->gateway->verify($reference); }
        catch (PaystackException $error) {
            if ($error->uncertain) { $this->setReconciliation($reference); }
            throw $error;
        }
        return $this->applyVerification($reference, $verification);
    }

    private function applyVerification(string $reference, array $response, ?string $eventReference = null, ?string $payloadHash = null): string
    {
        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            $this->setReconciliation($reference);
            return 'reconciliation_required';
        }
        $meta = $this->payments->byReference($reference);
        if ($meta === null) { return 'unknown_reference'; }
        $rentalId = (int) $meta['rental_id'];
        return $this->transactions->run(function () use ($reference, $response, $data, $meta, $rentalId, $eventReference, $payloadHash): string {
            $rental = $this->payments->rental($rentalId, true);
            if ($rental === null) { return 'unknown_reference'; }
            $productIds = $this->payments->productIds($rentalId, true);
            $this->inventory->lockProducts($productIds);
            $payment = $this->payments->byReference($reference, true);
            if ($payment === null) { return 'unknown_reference'; }
            $items = $this->payments->lines($rentalId, true);
            if ($eventReference !== null && $this->payments->event($eventReference, true) !== null) { return 'duplicate'; }
            if ($eventReference !== null) {
                $this->payments->recordEvent($eventReference, (int) $payment['rental_payment_id'], (string) $payloadHash, 'received');
            }
            $matches = ($response['status'] ?? false) === true
                && ($data['reference'] ?? null) === $reference
                && (string) ($data['currency'] ?? '') === (string) $payment['currency']
                && $this->amountMatches($data['amount'] ?? null, (int) $payment['amount_minor'])
                && ($data['domain'] ?? null) === 'test';
            $status = $data['status'] ?? null;

            // A verified success is immutable; later failure signals cannot undo it.
            if ($payment['payment_state'] === 'succeeded' && $rental['state'] === 'confirmed') {
                $state = 'succeeded';
            } elseif ($payment['payment_state'] === 'succeeded' && $rental['state'] === 'cancelled') {
                $state = 'cancelled_paid';
            } elseif ($matches && $status === 'success' && $payment['payment_state'] !== 'succeeded'
                && in_array($rental['state'], ['pending_payment', 'reconciliation_required', 'cancelled'], true)) {
                $today = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
                $canReacquire = $items !== [];
                foreach ($items as $item) {
                    if (!in_array($item['allocation_state'], ['held', 'reconciliation_required', 'released'], true)
                        || (int) $item['allocation_quantity'] !== (int) $item['quantity']
                        || (string) $item['end_date_exclusive'] <= $today
                        || $this->capacity->availableForPeriod((int) $item['product_id'], (string) $item['start_date'], (string) $item['end_date_exclusive'], $rentalId) < (int) $item['quantity']) {
                        $canReacquire = false;
                    }
                }
                if ($canReacquire) {
                    foreach ($items as $item) { $this->payments->setAllocationState((int) $item['rental_item_id'], 'confirmed'); }
                    $this->payments->setRentalState($rentalId, 'confirmed');
                    $this->payments->setPaymentState((int) $payment['rental_payment_id'], 'succeeded');
                    $this->notifications?->record(
                        'rental_confirmed',
                        'rental-confirmed:' . $rentalId,
                        'rental',
                        $rentalId,
                    );
                    $state = 'succeeded';
                } else {
                    $this->payments->setPaymentState((int) $payment['rental_payment_id'], 'reconciliation_required');
                    $this->payments->setRentalState($rentalId, 'reconciliation_required');
                    // Released late-success attempts remain unallocated until capacity can be safely reacquired.
                    foreach ($items as $item) {
                        if (in_array($item['allocation_state'], ['held', 'reconciliation_required'], true)) {
                            $this->payments->setAllocationState((int) $item['rental_item_id'], 'reconciliation_required');
                        }
                    }
                    $state = 'reconciliation_required';
                }
            } elseif ($matches && $status === 'failed'
                && in_array($payment['payment_state'], ['pending', 'reconciliation_required'], true)
                && in_array($rental['state'], ['pending_payment', 'reconciliation_required'], true)) {
                foreach ($items as $item) {
                    if (in_array($item['allocation_state'], ['held', 'reconciliation_required'], true)) {
                        $this->payments->setAllocationState((int) $item['rental_item_id'], 'released');
                    }
                }
                $this->payments->setPaymentState((int) $payment['rental_payment_id'], 'failed');
                $this->payments->setRentalState($rentalId, 'cancelled');
                $state = 'failed';
            } elseif ($payment['payment_state'] === 'succeeded') {
                $state = 'succeeded';
            } elseif ($payment['payment_state'] === 'failed' && $status === 'failed' && $matches) {
                $state = 'failed';
            } else {
                $this->payments->setPaymentState((int) $payment['rental_payment_id'], 'reconciliation_required');
                if (in_array($rental['state'], ['pending_payment', 'reconciliation_required'], true)) {
                    $this->payments->setRentalState($rentalId, 'reconciliation_required');
                }
                foreach ($items as $item) {
                    if ($item['allocation_state'] === 'held') { $this->payments->setAllocationState((int) $item['rental_item_id'], 'reconciliation_required'); }
                }
                $state = 'reconciliation_required';
            }
            if ($eventReference !== null) { $this->payments->setEventState($eventReference, 'applied'); }
            return $state;
        });
    }

    private function setReconciliation(string $reference): void
    {
        $payment = $this->payments->byReference($reference);
        if ($payment === null) { return; }
        $this->transactions->run(function () use ($reference, $payment): void {
            $rental = $this->payments->rental((int) $payment['rental_id'], true);
            if ($rental === null) { return; }
            $this->inventory->lockProducts($this->payments->productIds((int) $payment['rental_id'], true));
            $stored = $this->payments->byReference($reference, true);
            if ($stored === null || $stored['payment_state'] === 'succeeded') { return; }
            $items = $this->payments->lines((int) $payment['rental_id'], true);
            $this->payments->setPaymentState((int) $stored['rental_payment_id'], 'reconciliation_required');
            if (in_array($rental['state'], ['pending_payment', 'reconciliation_required'], true)) {
                $this->payments->setRentalState((int) $rental['rental_id'], 'reconciliation_required');
            }
            foreach ($items as $item) {
                if ($item['allocation_state'] === 'held') { $this->payments->setAllocationState((int) $item['rental_item_id'], 'reconciliation_required'); }
            }
        });
    }

    private function releaseDefinitivelyFailedInitialization(string $reference): void
    {
        $payment = $this->payments->byReference($reference);
        if ($payment === null) { return; }
        $this->transactions->run(function () use ($reference, $payment): void {
            $rental = $this->payments->rental((int) $payment['rental_id'], true);
            if ($rental === null) { return; }
            $this->inventory->lockProducts($this->payments->productIds((int) $payment['rental_id'], true));
            $stored = $this->payments->byReference($reference, true);
            if ($stored === null || $stored['payment_state'] === 'succeeded') { return; }
            $items = $this->payments->lines((int) $payment['rental_id'], true);
            foreach ($items as $item) {
                if (in_array($item['allocation_state'], ['held', 'reconciliation_required'], true)) {
                    $this->payments->setAllocationState((int) $item['rental_item_id'], 'released');
                }
            }
            $this->payments->setPaymentState((int) $stored['rental_payment_id'], 'failed');
            $this->payments->setRentalState((int) $rental['rental_id'], 'cancelled');
        });
    }

    private function amountMatches(mixed $amount, int $expected): bool
    {
        if (!is_int($amount) && !is_string($amount)) { return false; }
        $digits = (string) $amount;
        return preg_match('/^(0|[1-9][0-9]*)$/D', $digits) === 1 && $digits === (string) $expected;
    }

    private function isPaystackCheckoutUrl(mixed $url): bool
    {
        if (!is_string($url)) { return false; }
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'checkout.paystack.com'
            && !isset($parts['user'], $parts['pass']);
    }
}
