<?php

declare(strict_types=1);

namespace Verdant\Http;

final class PaymentRedirect
{
    /** Render a navigation handoff: form-action CSP can block cross-origin POST redirects. */
    public static function toCheckout(string $url, array $actor): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== 'checkout.paystack.com'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new \DomainException('The sandbox checkout destination is unavailable.');
        }
        View::render('payment-forward', ['title' => 'Opening Paystack TEST', 'actor' => $actor, 'checkoutUrl' => $url]);
    }
}
