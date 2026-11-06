<?php

declare(strict_types=1);

namespace Verdant\Payments;

use Verdant\Config\TrustedOrigins;

final class PaystackConfiguration
{
    private function __construct(public readonly string $secretKey, public readonly string $callbackUrl)
    {
    }

    /**
     * Test keys only, in every environment. This is a fictional academic
     * store, so live keys are refused outright. Local mode returns to the
     * fixed local store origin; hosted mode returns to the configured
     * public store origin (EMAIL_BASE_URL) over HTTPS.
     */
    public static function fromEnvironment(): self
    {
        $secret = getenv('PAYSTACK_SECRET_KEY');
        if (!is_string($secret) || preg_match('/^sk_test_[A-Za-z0-9_-]{12,}$/D', $secret) !== 1) {
            throw new PaystackException('A Paystack test secret key is not configured.', false);
        }

        if ((getenv('APP_ENV') ?: '') === 'local') {
            $callback = getenv('PAYSTACK_CALLBACK_URL');
            $callback = is_string($callback) && $callback !== ''
                ? $callback
                : TrustedOrigins::local()->store . '/payment/return';
            if (!TrustedOrigins::permitsLocalCallback($callback)) {
                throw new PaystackException('The sandbox callback URL must use an approved local origin.', false);
            }
            return new self($secret, TrustedOrigins::local()->store . '/payment/return');
        }

        try {
            $expected = TrustedOrigins::store() . '/payment/return';
        } catch (\LogicException) {
            throw new PaystackException('The hosted store origin is not configured for sandbox payments.', false);
        }
        $callback = getenv('PAYSTACK_CALLBACK_URL');
        if (!is_string($callback) || rtrim($callback, '/') !== $expected) {
            throw new PaystackException('The sandbox callback URL must be the configured store origin followed by /payment/return.', false);
        }
        return new self($secret, $expected);
    }
}
