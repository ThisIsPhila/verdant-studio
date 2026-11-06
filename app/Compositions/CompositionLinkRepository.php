<?php

declare(strict_types=1);

namespace Verdant\Compositions;

use Verdant\Persistence\Repository;

/** Read-only parent links for standalone guards and shared fulfilment/issue services. */
final class CompositionLinkRepository extends Repository
{
    public function forOrder(int $orderId, bool $lock = false): ?array
    {
        return $this->linked('order_id', $orderId, $lock);
    }

    public function forRental(int $rentalId, bool $lock = false): ?array
    {
        return $this->linked('rental_id', $rentalId, $lock);
    }

    public static function hasConfirmedPayment(array $link): bool
    {
        return $link['composition_state'] === 'confirmed' && $link['order_state'] === 'confirmed'
            && ($link['rental_id'] === null || $link['rental_state'] === 'confirmed')
            && $link['payment_state'] === 'succeeded' && $link['currency'] === 'ZAR'
            && $link['payment_currency'] === 'ZAR' && (int) $link['payment_amount_minor'] === (int) $link['total_minor']
            && (int) $link['customer_id'] === (int) $link['order_customer_id']
            && ($link['rental_id'] === null || (int) $link['customer_id'] === (int) $link['rental_customer_id']);
    }

    private function linked(string $column, int $id, bool $lock): ?array
    {
        // The column comes only from the two methods above, never a request parameter.
        return $this->select(
            "SELECT cc.composition_checkout_id, cc.customer_id, cc.order_id, cc.order_role, cc.rental_id,
                    cc.state AS composition_state, cc.total_minor, cc.currency,
                    o.state AS order_state, o.customer_id AS order_customer_id,
                    r.state AS rental_state, r.customer_id AS rental_customer_id,
                    p.state AS payment_state, p.amount_minor AS payment_amount_minor, p.currency AS payment_currency
             FROM composition_checkouts cc JOIN sales_orders o ON o.order_id = cc.order_id
             LEFT JOIN rentals r ON r.rental_id = cc.rental_id
             LEFT JOIN order_payments p ON p.order_payment_id = cc.order_payment_id AND p.order_id = cc.order_id
                 AND p.provider_code = 'paystack'
             WHERE cc.$column = ?" . ($lock ? ' FOR UPDATE' : ''),
            [$id],
        )[0] ?? null;
    }
}
