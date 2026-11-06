<?php

declare(strict_types=1);

namespace Verdant\Payments;

use Verdant\Inventory\InventoryRepository;
use Verdant\Notifications\CommerceNotifier;
use Verdant\Persistence\DatabaseException;
use Verdant\Persistence\TransactionManager;

final class PaymentService
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly InventoryRepository $inventory,
        private readonly TransactionManager $transactions,
        private readonly PaystackGateway $gateway,
        private readonly string $callbackUrl,
        private readonly ?CommerceNotifier $notifications = null,
    ) {
    }

    /** @return array{state:string, authorization_url?:string, reference?:string} */
    public function start(int $customerId, int $orderId, string $email): array
    {
        if ($customerId < 1 || $orderId < 1 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \DomainException('Your order could not be prepared for payment.');
        }
        $reference = 'verdant-' . $orderId . '-' . bin2hex(random_bytes(12));
        $prepared = $this->transactions->run(fn (): array => $this->payments->prepareOrderPayment($customerId, $orderId, $reference));
        $order = $prepared['order'];
        $payment = $prepared['payment'];
        if ($order['state'] === 'confirmed') {
            return ['state' => 'succeeded'];
        }
        if ($payment === null || $order['state'] === 'cancelled' || $payment['state'] === 'failed') {
            return ['state' => 'failed'];
        }
        if (is_string($payment['provider_checkout_url']) && $payment['provider_checkout_url'] !== '') {
            return ['state' => 'ready', 'authorization_url' => $payment['provider_checkout_url'], 'reference' => $payment['provider_reference']];
        }
        if ($payment['provider_reference'] !== $reference) {
            $outcome = $this->verifyAndApply((string) $payment['provider_reference']);
            return ['state' => $outcome];
        }

        try {
            $response = $this->gateway->initialize($email, (int) $payment['amount_minor'], $reference, $this->callbackUrl);
            $data = $response['data'] ?? null;
            if (($response['status'] ?? false) !== true || !is_array($data)
                || ($data['reference'] ?? null) !== $reference
                || !$this->isPaystackCheckoutUrl($data['authorization_url'] ?? null)) {
                throw new PaystackException('The sandbox checkout response could not be validated.', true);
            }
            $this->transactions->run(fn (): mixed => $this->payments->saveCheckoutUrl((int) $payment['order_payment_id'], (string) $data['authorization_url']));
            return ['state' => 'ready', 'authorization_url' => (string) $data['authorization_url'], 'reference' => $reference];
        } catch (PaystackException $error) {
            if ($error->uncertain) {
                if ($this->setReconciliation($reference) === 'succeeded') {
                    return ['state' => 'succeeded'];
                }
                throw new PaystackException('The provider response is uncertain. Your stock remains held while the payment is checked.', true);
            }
            if ($this->releaseDefinitivelyFailedInitialization($reference) === 'succeeded') {
                return ['state' => 'succeeded'];
            }
            throw new PaystackException('The sandbox checkout could not be started. The order was cancelled and its stock released.', false);
        }
    }

    public function verifyForCustomer(int $customerId, string $reference): string
    {
        $payment = $this->payments->byReference($reference);
        if ($payment === null || (int) $payment['customer_id'] !== $customerId) {
            return 'unavailable';
        }
        return $this->verifyAndApply($reference);
    }

    /** @return array{accepted:bool,state:string} */
    public function handleWebhook(string $rawBody, ?string $signature, string $secret): array
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
        $eventData = $event['data'] ?? null;
        if (!is_array($eventData)) {
            return ['accepted' => false, 'state' => 'invalid_payload'];
        }
        $reference = $eventData['reference'] ?? null;
        if (!is_string($reference) || preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $reference) !== 1) {
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
        try {
            $verification = $this->gateway->verify($reference);
        } catch (PaystackException) {
            return ['accepted' => false, 'state' => 'verification_unavailable'];
        }
        try {
            $state = $this->applyVerification($reference, $verification, $eventReference, $payloadHash);
        } catch (DatabaseException $error) {
            if ($error->getCode() !== 1062) { throw $error; }
            $state = 'duplicate';
        }
        return ['accepted' => true, 'state' => $state];
    }

    private function verifyAndApply(string $reference): string
    {
        try {
            $verification = $this->gateway->verify($reference);
        } catch (PaystackException $error) {
            if ($error->uncertain && $this->setReconciliation($reference) === 'succeeded') {
                return 'succeeded';
            }
            throw $error;
        }
        return $this->applyVerification($reference, $verification);
    }

    private function applyVerification(string $reference, array $response, ?string $eventReference = null, ?string $payloadHash = null): string
    {
        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            return 'reconciliation_required';
        }
        $providerStatus = $data['status'] ?? null;
        return $this->transactions->run(function () use ($reference, $response, $data, $providerStatus, $eventReference, $payloadHash): string {
            if ($eventReference !== null && $this->payments->event($eventReference, true) !== null) {
                return 'duplicate';
            }
            $payment = $this->payments->byReference($reference, true);
            if ($payment === null) {
                if ($eventReference !== null) {
                    $this->payments->recordEvent($eventReference, null, (string) $payloadHash, 'quarantined');
                }
                return 'unknown_reference';
            }
            if ($eventReference !== null) {
                $this->payments->recordEvent($eventReference, (int) $payment['order_payment_id'], (string) $payloadHash, 'received');
            }
            if ($payment['payment_state'] === 'succeeded') {
                if ($eventReference !== null) {
                    $this->payments->setEventState($eventReference,
                        $payment['order_state'] === 'confirmed' ? 'applied' : 'ignored');
                }
                return $payment['order_state'] === 'confirmed' ? 'succeeded' : 'reconciliation_required';
            }
            $matches = ($response['status'] ?? false) === true
                && ($data['reference'] ?? null) === $reference
                && (string) ($data['currency'] ?? '') === (string) $payment['currency']
                && $this->amountMatches($data['amount'] ?? null, (int) $payment['amount_minor'])
                && ($data['domain'] ?? null) === 'test';
            $lines = $this->payments->lines((int) $payment['order_id'], true);
            if ($lines === []) {
                $this->markReconciliationLocked($payment, $lines);
                $state = 'reconciliation_required';
            } elseif ($matches && $providerStatus === 'success'
                && $payment['payment_state'] === 'succeeded' && $payment['order_state'] === 'confirmed') {
                $state = 'succeeded';
            } elseif ($matches && $providerStatus === 'success'
                && in_array($payment['payment_state'], ['pending', 'reconciliation_required'], true)
                && in_array($payment['order_state'], ['pending_payment', 'reconciliation_required'], true)
                && $this->holdsAreActive($lines)) {
                $lockedInventory = $this->inventory->lockProducts(array_map(static fn (array $line): int => (int) $line['product_id'], $lines));
                $onHand = [];
                foreach ($lockedInventory as $row) { $onHand[(int) $row['product_id']] = (int) $row['on_hand']; }
                $canConsume = $this->holdsAreActive($lines);
                foreach ($lines as $line) {
                    $id = (int) $line['product_id'];
                    if (($onHand[$id] ?? 0) < (int) $line['hold_quantity']) { $canConsume = false; }
                }
                if ($canConsume) {
                    foreach ($lines as $line) {
                        if (!$this->payments->consumeInventory((int) $line['product_id'], (int) $line['hold_quantity'])) {
                            throw new \LogicException('Locked inventory changed during payment confirmation.');
                        }
                    }
                    foreach ($lines as $line) { $this->payments->setHoldState((int) $line['order_item_id'], 'consumed'); }
                    $this->payments->setPaymentState((int) $payment['order_payment_id'], 'succeeded');
                    $this->payments->setOrderState((int) $payment['order_id'], 'confirmed');
                    $this->notifications?->record(
                        'purchase_confirmed',
                        'purchase-confirmed:' . (int) $payment['order_id'],
                        'order',
                        (int) $payment['order_id'],
                    );
                    $state = 'succeeded';
                } else {
                    $this->markReconciliationLocked($payment, $lines);
                    $state = 'reconciliation_required';
                }
            } elseif ($matches && $providerStatus === 'failed'
                && in_array($payment['payment_state'], ['pending', 'reconciliation_required'], true)
                && in_array($payment['order_state'], ['pending_payment', 'reconciliation_required'], true)
                && $this->holdsAreActive($lines)) {
                $this->inventory->lockProducts(array_map(static fn (array $line): int => (int) $line['product_id'], $lines));
                foreach ($lines as $line) { $this->payments->setHoldState((int) $line['order_item_id'], 'released'); }
                $this->payments->setPaymentState((int) $payment['order_payment_id'], 'failed');
                $this->payments->setOrderState((int) $payment['order_id'], 'cancelled');
                $state = 'failed';
            } elseif ($payment['payment_state'] === 'failed' && $providerStatus === 'failed' && $matches) {
                $state = 'failed';
            } else {
                $this->inventory->lockProducts(array_map(static fn (array $line): int => (int) $line['product_id'], $lines));
                $this->markReconciliationLocked($payment, $lines);
                $state = 'reconciliation_required';
            }
            if ($eventReference !== null) { $this->payments->setEventState($eventReference, 'applied'); }
            return $state;
        });
    }

    private function holdsAreActive(array $lines): bool
    {
        foreach ($lines as $line) {
            if (!in_array($line['hold_state'], ['active', 'reconciliation_required'], true)
                || (int) $line['hold_quantity'] !== (int) $line['item_quantity']) {
                return false;
            }
        }
        return true;
    }

    private function markReconciliationLocked(array $payment, array $lines): void
    {
        $this->payments->setPaymentState((int) $payment['order_payment_id'], 'reconciliation_required');
        $this->payments->setOrderState((int) $payment['order_id'], 'reconciliation_required');
        foreach ($lines as $line) {
            if (in_array($line['hold_state'], ['active', 'released', 'reconciliation_required'], true)) {
                $this->payments->setHoldState((int) $line['order_item_id'], 'reconciliation_required');
            }
        }
    }

    private function setReconciliation(string $reference): ?string
    {
        return $this->transactions->run(function () use ($reference): ?string {
            $payment = $this->payments->byReference($reference, true);
            if ($payment === null) { return null; }
            if ($payment['payment_state'] === 'succeeded') {
                return $payment['order_state'] === 'confirmed' ? 'succeeded' : 'reconciliation_required';
            }
            $lines = $this->payments->lines((int) $payment['order_id'], true);
            if ($lines !== []) {
                $this->inventory->lockProducts(array_map(static fn (array $line): int => (int) $line['product_id'], $lines));
            }
            $this->markReconciliationLocked($payment, $lines);
            return 'reconciliation_required';
        });
    }

    private function releaseDefinitivelyFailedInitialization(string $reference): ?string
    {
        return $this->transactions->run(function () use ($reference): ?string {
            $payment = $this->payments->byReference($reference, true);
            if ($payment === null) { return null; }
            if ($payment['payment_state'] === 'succeeded') {
                return $payment['order_state'] === 'confirmed' ? 'succeeded' : 'reconciliation_required';
            }
            $lines = $this->payments->lines((int) $payment['order_id'], true);
            if ($lines !== []) {
                $this->inventory->lockProducts(array_map(static fn (array $line): int => (int) $line['product_id'], $lines));
            }
            foreach ($lines as $line) {
                if (in_array($line['hold_state'], ['active', 'reconciliation_required'], true)) {
                    $this->payments->setHoldState((int) $line['order_item_id'], 'released');
                }
            }
            $this->payments->setPaymentState((int) $payment['order_payment_id'], 'failed');
            $this->payments->setOrderState((int) $payment['order_id'], 'cancelled');
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
}
