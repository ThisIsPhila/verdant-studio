<?php

declare(strict_types=1);

namespace Verdant\Compositions;

use Verdant\Inventory\CapacityRepository;
use Verdant\Inventory\InventoryRepository;
use Verdant\Notifications\CommerceNotifier;
use Verdant\Payments\PaystackException;
use Verdant\Payments\PaystackGateway;
use Verdant\Persistence\DatabaseException;
use Verdant\Persistence\TransactionManager;

/** One TEST-mode Paystack attempt and one atomic outcome for a composition's child legs. */
final class CompositionPaymentService
{
    public function __construct(
        private readonly CompositionPaymentRepository $payments,
        private readonly InventoryRepository $inventory,
        private readonly CapacityRepository $capacity,
        private readonly TransactionManager $transactions,
        private readonly ?PaystackGateway $gateway = null,
        private readonly string $callbackUrl = '',
        private readonly ?CommerceNotifier $notifications = null,
    ) {
    }

    public function isCompositionOrder(int $orderId): bool
    {
        return $orderId > 0 && $this->payments->checkoutForOrder($orderId) !== null;
    }

    public function isCompositionRental(int $rentalId): bool
    {
        return $rentalId > 0 && $this->payments->isCompositionRental($rentalId);
    }

    public function paymentForReference(string $reference): ?array
    {
        return $this->validReference($reference) ? $this->payments->linkedPaymentForReference($reference) : null;
    }

    /**
     * Release both child reservations only while the checkout is still unstarted. Repeating a
     * coherent cancellation is harmless; any payment attempt or partial lifecycle state is denied.
     */
    public function cancelUnstarted(int $customerId, int $orderId): bool
    {
        if ($customerId < 1 || $orderId < 1) { return false; }
        return $this->retryTransaction(function () use ($customerId, $orderId): bool {
            $productIds = $this->payments->productIdsForOrder($orderId);
            if ($productIds === []) { return false; }
            $this->inventory->lockProducts($productIds);
            $checkout = $this->payments->checkoutForOrder($orderId, true);
            if ($checkout === null || (int) $checkout['customer_id'] !== $customerId) { return false; }
            $order = $this->payments->order($orderId, true);
            if ($order === null || (int) $order['customer_id'] !== $customerId
                || !$this->payments->activeCustomer($customerId, true)) { return false; }
            $rentalId = $checkout['rental_id'] === null ? null : (int) $checkout['rental_id'];
            $rental = $rentalId === null ? null : $this->payments->rental($rentalId, true);
            $lines = $this->payments->lines((int) $checkout['composition_checkout_id'], true);
            if ($lines === []) { return false; }

            $alreadyCancelled = $checkout['checkout_state'] === 'cancelled' && $order['state'] === 'cancelled'
                && ($rentalId === null || ($rental !== null && $rental['state'] === 'cancelled'))
                && $this->allReservationsReleased($lines);
            if ($alreadyCancelled) { return true; }

            if ($this->payments->hasAnyPaymentAttempt($orderId, $rentalId, true)
                || $checkout['order_payment_id'] !== null
                || $checkout['checkout_state'] !== 'pending_payment'
                || $order['state'] !== 'pending_payment'
                || ($rentalId !== null && ($rental === null || $rental['state'] !== 'pending_payment'))
                || !$this->unstartedReservationsMatch($lines)) {
                return false;
            }
            foreach ($lines as $line) {
                if ($line['mode'] === 'sale') { $this->payments->setHoldState((int) $line['order_item_id'], 'released'); }
                else { $this->payments->setAllocationState((int) $line['rental_item_id'], 'released'); }
            }
            $this->payments->setOrderState($orderId, 'cancelled');
            if ($rentalId !== null) { $this->payments->setRentalState($rentalId, 'cancelled'); }
            $this->payments->setCheckoutState((int) $checkout['composition_checkout_id'], 'cancelled');
            return true;
        });
    }

    /** @return array{state:string,authorization_url?:string,reference?:string} */
    public function start(int $customerId, int $orderId, string $email): array
    {
        $gateway = $this->gateway ?? throw new \DomainException('Sandbox payment is unavailable.');
        if ($customerId < 1 || $orderId < 1 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \DomainException('Your composition could not be prepared for payment.');
        }
        $reference = 'verdant-composition-' . $orderId . '-' . bin2hex(random_bytes(12));
        $prepared = $this->retryTransaction(function () use ($customerId, $orderId, $reference): array {
            // The pre-read only discovers the complete sorted inventory lock set. All authoritative
            // child, payment, and parent rows are reread under lock below.
            $productIds = $this->payments->productIdsForOrder($orderId);
            if ($productIds === []) {
                throw new \DomainException('Composition is unavailable for payment.');
            }
            $this->inventory->lockProducts($productIds);
            $checkout = $this->payments->checkoutForOrder($orderId, true);
            if ($checkout === null) {
                throw new \DomainException('Composition is unavailable for payment.');
            }
            if ((int) $checkout['customer_id'] !== $customerId) {
                throw new \DomainException('Composition is unavailable for payment.');
            }
            $order = $this->payments->order($orderId, true);
            if ($order === null) {
                throw new \DomainException('Composition order is unavailable for payment.');
            }
            $rentalId = $checkout['rental_id'] === null ? null : (int) $checkout['rental_id'];
            $rental = $rentalId === null ? null : $this->payments->rental($rentalId, true);
            $lines = $this->payments->lines((int) $checkout['composition_checkout_id'], true);
            if (!$this->payments->activeCustomer($customerId, true) || (int) $order['customer_id'] !== $customerId) {
                throw new \DomainException('Composition is unavailable for payment.');
            }
            if ($lines === []) {
                throw new \DomainException('The composition has no payable component lines.');
            }
            $linked = $checkout['order_payment_id'] === null
                ? null : $this->payments->payment((int) $checkout['order_payment_id'], true);
            $fullyConfirmed = $checkout['checkout_state'] === 'confirmed' && $order['state'] === 'confirmed'
                && $this->rentalHasConfirmedPaymentState($rentalId === null ? null : ($rental['state'] ?? null));
            if ($fullyConfirmed && $linked !== null && $linked['payment_state'] === 'succeeded') {
                return ['state' => 'succeeded', 'payment' => $linked];
            }
            $fullyCancelled = $checkout['checkout_state'] === 'cancelled' && $order['state'] === 'cancelled'
                && ($rentalId === null || ($rental !== null && $rental['state'] === 'cancelled'))
                && $this->allReservationsReleased($lines);
            $hasAnyAttempt = $this->payments->hasAnyPaymentAttempt($orderId, $rentalId, true);
            if ($fullyCancelled && (($linked !== null && in_array($linked['payment_state'], ['failed', 'cancelled'], true))
                || ($linked === null && !$hasAnyAttempt))) {
                return ['state' => 'failed', 'payment' => $linked];
            }
            $childrenPending = $this->headersArePendingCoherent($checkout, $order, $rental);
            if (!$childrenPending || !$this->activeReservationsMatch($lines)) {
                if ($linked !== null) {
                    $this->markReconciliationLocked($checkout, $order, $rental, $linked, $lines,
                        $linked['payment_state'] === 'succeeded');
                } else {
                    $this->markChildrenReconciliation($checkout, $order, $rental, $lines);
                }
                return ['state' => 'reconciliation_required', 'payment' => $linked];
            }

            $payment = $checkout['order_payment_id'] === null
                ? null : $this->payments->payment((int) $checkout['order_payment_id'], true);
            if ($payment === null) {
                // A standalone order payment must never be silently reused as the parent amount.
                if ($this->payments->standalonePaymentForOrder($orderId, true) !== null
                    || (int) $checkout['total_minor'] < 1) {
                    throw new \DomainException('The aggregate composition payment is unavailable.');
                }
                $paymentId = $this->payments->insertPayment(
                    $orderId, $reference, (int) $checkout['total_minor'], (string) $checkout['currency'],
                );
                $this->payments->linkPayment((int) $checkout['composition_checkout_id'], $paymentId);
                $payment = $this->payments->payment($paymentId, true);
            }
            if ($payment === null || (int) $payment['amount_minor'] !== (int) $checkout['total_minor']
                || (string) $payment['currency'] !== (string) $checkout['currency']) {
                throw new \DomainException('The aggregate composition payment does not match its saved total.');
            }
            if (in_array($payment['payment_state'], ['failed', 'cancelled'], true)) {
                $this->markReconciliationLocked($checkout, $order, $rental, $payment, $lines);
                return ['state' => 'reconciliation_required', 'payment' => $payment];
            }
            return ['state' => 'prepared', 'payment' => $payment];
        });

        if ($prepared['state'] !== 'prepared') {
            return ['state' => $prepared['state']];
        }
        $payment = $prepared['payment'];
        if (is_string($payment['provider_checkout_url']) && $payment['provider_checkout_url'] !== '') {
            return ['state' => 'ready', 'authorization_url' => $payment['provider_checkout_url'],
                'reference' => (string) $payment['provider_reference']];
        }
        if ($payment['provider_reference'] !== $reference) {
            return ['state' => $this->verifyAndApply((string) $payment['provider_reference'])];
        }

        // Provider I/O is deliberately outside every transaction and inventory lock.
        try {
            $response = $gateway->initialize($email, (int) $payment['amount_minor'], $reference, $this->callbackUrl);
            $data = $response['data'] ?? null;
            if (($response['status'] ?? false) !== true || !is_array($data)
                || ($data['reference'] ?? null) !== $reference
                || !$this->isPaystackCheckoutUrl($data['authorization_url'] ?? null)) {
                throw new PaystackException('The sandbox checkout response could not be validated.', true);
            }
            $this->transactions->run(fn (): mixed => $this->payments->saveCheckoutUrl(
                (int) $payment['order_payment_id'], (string) $data['authorization_url'],
            ));
            return ['state' => 'ready', 'authorization_url' => (string) $data['authorization_url'], 'reference' => $reference];
        } catch (PaystackException $error) {
            if ($error->uncertain) {
                $this->setReconciliation($reference);
                throw new PaystackException('The provider response is uncertain. Both composition reservations remain held while payment is checked.', true);
            }
            if ($this->releaseDefinitivelyFailedInitialization($reference) === 'succeeded') {
                return ['state' => 'succeeded'];
            }
            throw new PaystackException('The sandbox checkout could not be started. Both composition reservations were released.', false);
        }
    }

    public function verifyForCustomer(int $customerId, string $reference): string
    {
        $payment = $this->paymentForReference($reference);
        if ($payment === null || (int) $payment['customer_id'] !== $customerId) {
            return 'unavailable';
        }
        return $this->verifyAndApply($reference);
    }

    /**
     * Called before standalone order handling for a reference. Null means the reference is not a
     * composition payment, so the existing retail/rental handlers may continue unchanged.
     * @return array{accepted:bool,state:string}|null
     */
    public function handleWebhook(string $rawBody, ?string $signature, string $secret): ?array
    {
        if ($signature === null || $signature === ''
            || !hash_equals(hash_hmac('sha512', $rawBody, $secret), $signature)) {
            return ['accepted' => false, 'state' => 'invalid_signature'];
        }
        try {
            $event = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['accepted' => false, 'state' => 'invalid_payload'];
        }
        if (!is_array($event) || !is_string($event['event'] ?? null)) {
            return ['accepted' => false, 'state' => 'invalid_payload'];
        }
        if ($event['event'] !== 'charge.success') {
            return ['accepted' => true, 'state' => 'ignored'];
        }
        $data = $event['data'] ?? null;
        $reference = is_array($data) ? ($data['reference'] ?? null) : null;
        if (!is_string($reference) || !$this->validReference($reference)) {
            return ['accepted' => false, 'state' => 'invalid_reference'];
        }
        if ($this->paymentForReference($reference) === null) {
            return null;
        }
        try {
            $verification = ($this->gateway ?? throw new PaystackException('Sandbox payment is unavailable.', true))->verify($reference);
        } catch (PaystackException $error) {
            if ($error->uncertain) { $this->setReconciliation($reference); }
            return ['accepted' => false, 'state' => 'verification_unavailable'];
        }
        $eventReference = 'charge.success:' . $reference;
        try {
            $state = $this->applyVerification($reference, $verification, $eventReference, hash('sha256', $rawBody));
        } catch (DatabaseException $error) {
            if ($error->getCode() !== 1062) { throw $error; }
            $state = 'duplicate';
        }
        return ['accepted' => true, 'state' => $state];
    }

    private function verifyAndApply(string $reference): string
    {
        try {
            $response = ($this->gateway ?? throw new PaystackException('Sandbox payment is unavailable.', true))->verify($reference);
        } catch (PaystackException $error) {
            if ($error->uncertain) { $this->setReconciliation($reference); }
            throw $error;
        }
        return $this->applyVerification($reference, $response);
    }

    private function applyVerification(string $reference, array $response, ?string $eventReference = null, ?string $payloadHash = null): string
    {
        $payment = $this->paymentForReference($reference);
        if ($payment === null) { return 'unknown_reference'; }
        $checkoutId = (int) $payment['composition_checkout_id'];
        $orderId = (int) $payment['order_id'];
        $productIds = $this->payments->productIdsForOrder($orderId);
        if ($productIds === []) {
            return $this->markReconciliation($reference, $eventReference, $payloadHash);
        }
        return $this->retryTransaction(function () use ($reference, $response, $eventReference, $payloadHash, $checkoutId, $orderId, $productIds): string {
            $this->inventory->lockProducts($productIds);
            $checkout = $this->payments->checkoutById($checkoutId, true);
            $order = $this->payments->order($orderId, true);
            if ($checkout === null || $order === null || (int) $checkout['order_id'] !== $orderId) {
                return 'unknown_reference';
            }
            $rentalId = $checkout['rental_id'] === null ? null : (int) $checkout['rental_id'];
            $rental = $rentalId === null ? null : $this->payments->rental($rentalId, true);
            $paymentId = $checkout['order_payment_id'] === null ? 0 : (int) $checkout['order_payment_id'];
            $stored = $paymentId > 0 ? $this->payments->payment($paymentId, true) : null;
            $lines = $this->payments->lines($checkoutId, true);
            if ($stored === null || $stored['provider_reference'] !== $reference || $lines === []) {
                return 'unknown_reference';
            }
            if ($eventReference !== null && $this->payments->event($eventReference, true) !== null) {
                return 'duplicate';
            }
            if ($eventReference !== null) {
                $this->payments->recordEvent($eventReference, $paymentId, (string) $payloadHash);
            }

            $data = $response['data'] ?? null;
            $matches = is_array($data) && ($response['status'] ?? false) === true
                && ($data['reference'] ?? null) === $reference
                && (string) ($data['currency'] ?? '') === (string) $stored['currency']
                && (string) $stored['currency'] === (string) $checkout['currency']
                && (int) $stored['amount_minor'] === (int) $checkout['total_minor']
                && $this->amountMatches($data['amount'] ?? null, (int) $checkout['total_minor'])
                && ($data['domain'] ?? null) === 'test';
            $providerStatus = is_array($data) ? ($data['status'] ?? null) : null;

            if ($stored['payment_state'] === 'succeeded') {
                $state = $checkout['checkout_state'] === 'confirmed' && $order['state'] === 'confirmed'
                    && $this->rentalHasConfirmedPaymentState($rentalId === null ? null : ($rental['state'] ?? null))
                    ? 'succeeded' : 'reconciliation_required';
                if ($state === 'reconciliation_required') {
                    $this->markReconciliationLocked($checkout, $order, $rental, $stored, $lines, true);
                }
            } elseif ($matches && $providerStatus === 'success') {
                $pendingHeaders = $this->headersArePendingCoherent($checkout, $order, $rental);
                $coherentlyCancelled = $checkout['checkout_state'] === 'cancelled' && $order['state'] === 'cancelled'
                    && ($rentalId === null || ($rental !== null && $rental['state'] === 'cancelled'));
                if ($pendingHeaders && in_array($stored['payment_state'], ['pending', 'reconciliation_required'], true)
                    && $this->allReservationsActive($lines) && $this->rentalBookingsNotExpired($lines)) {
                    $this->consumeAndConfirm($checkout, $order, $rental, $stored, $lines);
                    $state = 'succeeded';
                } elseif ($coherentlyCancelled && $this->allReservationsReleased($lines)
                    && $this->rentalBookingsNotExpired($lines)
                    && $this->canReacquireReleasedReservation($lines, $rentalId)) {
                    foreach ($lines as $line) {
                        if ($line['mode'] === 'sale') { $this->payments->setHoldState((int) $line['order_item_id'], 'active'); }
                        else { $this->payments->setAllocationState((int) $line['rental_item_id'], 'held'); }
                    }
                    $this->consumeAndConfirm($checkout, $order, $rental, $stored, $lines);
                    $state = 'succeeded';
                } else {
                    $this->markReconciliationLocked($checkout, $order, $rental, $stored, $lines);
                    $state = 'reconciliation_required';
                }
            } elseif ($matches && $providerStatus === 'failed'
                && in_array($stored['payment_state'], ['pending', 'reconciliation_required'], true)
                && $this->headersArePendingCoherent($checkout, $order, $rental)
                && $this->allReservationsActive($lines)) {
                foreach ($lines as $line) {
                    if ($line['mode'] === 'sale') { $this->payments->setHoldState((int) $line['order_item_id'], 'released'); }
                    else { $this->payments->setAllocationState((int) $line['rental_item_id'], 'released'); }
                }
                $this->payments->setPaymentState($paymentId, 'failed');
                $this->payments->setOrderState($orderId, 'cancelled');
                if ($rentalId !== null) { $this->payments->setRentalState($rentalId, 'cancelled'); }
                $this->payments->setCheckoutState($checkoutId, 'cancelled');
                $state = 'failed';
            } elseif ($matches && $providerStatus === 'failed' && $stored['payment_state'] === 'failed'
                && $checkout['checkout_state'] === 'cancelled' && $order['state'] === 'cancelled'
                && ($rentalId === null || ($rental !== null && $rental['state'] === 'cancelled'))
                && $this->allReservationsReleased($lines)) {
                $state = 'failed';
            } else {
                $this->markReconciliationLocked($checkout, $order, $rental, $stored, $lines);
                $state = 'reconciliation_required';
            }
            if ($eventReference !== null) {
                $this->payments->setEventState($eventReference, 'applied');
            }
            return $state;
        });
    }

    private function consumeAndConfirm(array $checkout, array $order, ?array $rental, array $payment, array $lines): void
    {
        foreach ($lines as $line) {
            if ($line['mode'] !== 'sale') { continue; }
            if (!$this->payments->consumeInventory((int) $line['product_id'], (int) $line['quantity'])) {
                throw new \LogicException('Locked stock changed while confirming the composition payment.');
            }
            $this->payments->setHoldState((int) $line['order_item_id'], 'consumed');
        }
        foreach ($lines as $line) {
            if ($line['mode'] === 'rent') {
                $this->payments->setAllocationState((int) $line['rental_item_id'], 'confirmed');
            }
        }
        $this->payments->setPaymentState((int) $payment['order_payment_id'], 'succeeded');
        $this->payments->setOrderState((int) $order['order_id'], 'confirmed');
        if ($rental !== null) { $this->payments->setRentalState((int) $rental['rental_id'], 'confirmed'); }
        $this->payments->setCheckoutState((int) $checkout['composition_checkout_id'], 'confirmed');
        $this->notifications?->record(
            'composition_confirmed',
            'composition-confirmed:' . (int) $checkout['composition_checkout_id'],
            'composition',
            (int) $checkout['composition_checkout_id'],
        );
    }

    private function markReconciliationLocked(array $checkout, array $order, ?array $rental, array $payment, array $lines, bool $preservePaymentSuccess = false): void
    {
        if (!$preservePaymentSuccess) {
            $this->payments->setPaymentState((int) $payment['order_payment_id'], 'reconciliation_required');
        }
        $this->markChildrenReconciliation($checkout, $order, $rental, $lines);
    }

    private function markChildrenReconciliation(array $checkout, array $order, ?array $rental, array $lines): void
    {
        if ($order['state'] !== 'confirmed') { $this->payments->setOrderState((int) $order['order_id'], 'reconciliation_required'); }
        if ($rental !== null && !$this->rentalHasConfirmedPaymentState((string) $rental['state'])) {
            $this->payments->setRentalState((int) $rental['rental_id'], 'reconciliation_required');
        }
        if ($checkout['checkout_state'] !== 'confirmed') {
            $this->payments->setCheckoutState((int) $checkout['composition_checkout_id'], 'reconciliation_required');
        }
        foreach ($lines as $line) {
            if ($line['mode'] === 'sale' && in_array($line['hold_state'], ['active', 'reconciliation_required'], true)) {
                $this->payments->setHoldState((int) $line['order_item_id'], 'reconciliation_required');
            } elseif ($line['mode'] === 'rent' && in_array($line['allocation_state'], ['held', 'reconciliation_required'], true)) {
                $this->payments->setAllocationState((int) $line['rental_item_id'], 'reconciliation_required');
            }
        }
    }

    private function rentalHasConfirmedPaymentState(?string $state): bool
    {
        return $state === 'confirmed';
    }

    private function rentalBookingsNotExpired(array $lines): bool
    {
        $businessDate = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
        foreach ($lines as $line) {
            if ($line['mode'] !== 'rent') { continue; }
            $end = $line['end_date_exclusive'] ?? null;
            if (!is_string($end) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $end) !== 1 || $end <= $businessDate) {
                return false;
            }
        }
        return $lines !== [];
    }

    private function headersArePendingCoherent(array $checkout, array $order, ?array $rental): bool
    {
        $states = [$checkout['checkout_state'], $order['state']];
        if ($rental !== null) { $states[] = $rental['state']; }
        return count(array_unique($states)) === 1
            && in_array($states[0], ['pending_payment', 'reconciliation_required'], true);
    }

    private function activeReservationsMatch(array $lines): bool
    {
        foreach ($lines as $line) {
            if ($line['mode'] === 'sale') {
                if (!in_array($line['hold_state'], ['active', 'reconciliation_required'], true)
                    || $line['hold_quantity'] !== $line['quantity'] || $line['sale_quantity'] !== $line['quantity']) { return false; }
            } elseif (!in_array($line['allocation_state'], ['held', 'reconciliation_required'], true)
                || $line['allocation_quantity'] !== $line['quantity'] || $line['rental_quantity'] !== $line['quantity']) { return false; }
        }
        return $lines !== [];
    }

    private function unstartedReservationsMatch(array $lines): bool
    {
        foreach ($lines as $line) {
            if ($line['mode'] === 'sale') {
                if ($line['hold_state'] !== 'active' || $line['hold_quantity'] !== $line['quantity']
                    || $line['sale_quantity'] !== $line['quantity']) { return false; }
            } elseif ($line['allocation_state'] !== 'held' || $line['allocation_quantity'] !== $line['quantity']
                || $line['rental_quantity'] !== $line['quantity']) { return false; }
        }
        return $lines !== [];
    }

    private function allReservationsActive(array $lines): bool
    {
        if ($lines === []) { return false; }
        foreach ($lines as $line) {
            if ($line['mode'] === 'sale') {
                if (!in_array($line['hold_state'], ['active', 'reconciliation_required'], true)
                    || $line['hold_quantity'] !== $line['quantity'] || $line['sale_quantity'] !== $line['quantity']) { return false; }
            } elseif (!in_array($line['allocation_state'], ['held', 'reconciliation_required'], true)
                || $line['allocation_quantity'] !== $line['quantity'] || $line['rental_quantity'] !== $line['quantity']) { return false; }
        }
        return true;
    }

    private function allReservationsReleased(array $lines): bool
    {
        if ($lines === []) { return false; }
        foreach ($lines as $line) {
            if ($line['mode'] === 'sale') {
                if ($line['hold_state'] !== 'released' || $line['hold_quantity'] !== $line['quantity']
                    || $line['sale_quantity'] !== $line['quantity']) { return false; }
            } elseif ($line['allocation_state'] !== 'released' || $line['allocation_quantity'] !== $line['quantity']
                || $line['rental_quantity'] !== $line['quantity']) { return false; }
        }
        return true;
    }

    /** Capacity must be restored for the complete released sale and every overlapping rental claim. */
    private function canReacquireReleasedReservation(array $lines, ?int $rentalId): bool
    {
        $saleByProduct = [];
        $rentByProduct = [];
        foreach ($lines as $line) {
            $productId = (int) $line['product_id'];
            if ($line['mode'] === 'sale') {
                $saleByProduct[$productId] = ($saleByProduct[$productId] ?? 0) + (int) $line['quantity'];
            } else {
                $rentByProduct[$productId][] = [
                    'start_date' => (string) $line['rental_start_date'],
                    'end_date_exclusive' => (string) $line['rental_end_date_exclusive'],
                    'quantity' => (int) $line['quantity'],
                ];
            }
        }
        foreach (array_values(array_unique(array_merge(array_keys($saleByProduct), array_keys($rentByProduct)))) as $productId) {
            $saleQuantity = $saleByProduct[$productId] ?? 0;
            if ($saleQuantity > 0 && $this->capacity->retailAvailable((int) $productId) < $saleQuantity) { return false; }
            $claims = $rentByProduct[$productId] ?? [];
            if ($claims === []) { continue; }
            $boundaries = [];
            foreach ($claims as $claim) {
                $boundaries[$claim['start_date']] = true;
                $boundaries[$claim['end_date_exclusive']] = true;
            }
            $dates = array_keys($boundaries);
            sort($dates, SORT_STRING);
            for ($i = 0; $i < count($dates) - 1; ++$i) {
                $from = $dates[$i];
                $to = $dates[$i + 1];
                $rentQuantity = 0;
                foreach ($claims as $claim) {
                    if ($claim['start_date'] < $to && $claim['end_date_exclusive'] > $from) {
                        $rentQuantity += $claim['quantity'];
                    }
                }
                if ($rentQuantity > 0 && $this->capacity->availableForPeriod((int) $productId, $from, $to, $rentalId)
                    < $saleQuantity + $rentQuantity) { return false; }
            }
        }
        return true;
    }

    private function setReconciliation(string $reference): ?string
    {
        return $this->retryTransaction(function () use ($reference): ?string {
            $meta = $this->payments->linkedPaymentForReference($reference);
            if ($meta === null) { return null; }
            $productIds = $this->payments->productIdsForOrder((int) $meta['order_id']);
            if ($productIds === []) { return null; }
            $this->inventory->lockProducts($productIds);
            $checkout = $this->payments->checkoutById((int) $meta['composition_checkout_id'], true);
            $order = $this->payments->order((int) $meta['order_id'], true);
            if ($checkout === null || $order === null) { return null; }
            $rental = $meta['rental_id'] === null ? null : $this->payments->rental((int) $meta['rental_id'], true);
            $stored = $checkout['order_payment_id'] === null ? null : $this->payments->payment((int) $checkout['order_payment_id'], true);
            $lines = $this->payments->lines((int) $meta['composition_checkout_id'], true);
            if ($checkout !== null && $order !== null && $stored !== null && $stored['payment_state'] !== 'succeeded') {
                $this->markReconciliationLocked($checkout, $order, $rental, $stored, $lines);
                return 'reconciliation_required';
            }
            return $stored !== null && $stored['payment_state'] === 'succeeded' ? 'succeeded' : null;
        });
    }

    private function markReconciliation(string $reference, ?string $eventReference, ?string $payloadHash): string
    {
        $state = $this->setReconciliation($reference) ?? 'reconciliation_required';
        if ($eventReference !== null) {
            $this->retryTransaction(function () use ($reference, $eventReference, $payloadHash): void {
                $payment = $this->payments->linkedPaymentForReference($reference, true);
                if ($payment !== null && $this->payments->event($eventReference, true) === null) {
                    $this->payments->recordEvent($eventReference, (int) $payment['order_payment_id'], (string) $payloadHash);
                    $this->payments->setEventState($eventReference, 'applied');
                }
            });
        }
        return $state;
    }

    private function releaseDefinitivelyFailedInitialization(string $reference): ?string
    {
        return $this->retryTransaction(function () use ($reference): ?string {
            $meta = $this->payments->linkedPaymentForReference($reference);
            if ($meta === null) { return null; }
            $productIds = $this->payments->productIdsForOrder((int) $meta['order_id']);
            $this->inventory->lockProducts($productIds);
            $checkout = $this->payments->checkoutById((int) $meta['composition_checkout_id'], true);
            $order = $this->payments->order((int) $meta['order_id'], true);
            if ($checkout === null || $order === null) { return null; }
            $rental = $meta['rental_id'] === null ? null : $this->payments->rental((int) $meta['rental_id'], true);
            $stored = $checkout['order_payment_id'] === null ? null : $this->payments->payment((int) $checkout['order_payment_id'], true);
            $lines = $this->payments->lines((int) $meta['composition_checkout_id'], true);
            if ($checkout === null || $order === null || $stored === null) { return null; }
            if ($stored['payment_state'] === 'succeeded') { return 'succeeded'; }
            if (!$this->allReservationsActive($lines)) {
                $this->markReconciliationLocked($checkout, $order, $rental, $stored, $lines);
                return null;
            }
            foreach ($lines as $line) {
                if ($line['mode'] === 'sale') { $this->payments->setHoldState((int) $line['order_item_id'], 'released'); }
                else { $this->payments->setAllocationState((int) $line['rental_item_id'], 'released'); }
            }
            $this->payments->setPaymentState((int) $stored['order_payment_id'], 'failed');
            $this->payments->setOrderState((int) $order['order_id'], 'cancelled');
            if ($rental !== null) { $this->payments->setRentalState((int) $rental['rental_id'], 'cancelled'); }
            $this->payments->setCheckoutState((int) $checkout['composition_checkout_id'], 'cancelled');
            return null;
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

    private function validReference(string $reference): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $reference) === 1;
    }

    private function retryTransaction(callable $operation): mixed
    {
        for ($attempt = 0; ; ++$attempt) {
            try {
                return $this->transactions->run($operation);
            } catch (DatabaseException $error) {
                if (!in_array($error->getCode(), [1205, 1213], true) || $attempt >= 2) { throw $error; }
                usleep(10_000 * ($attempt + 1));
            }
        }
    }
}
