<?php

declare(strict_types=1);

namespace Verdant\Identity;

use Verdant\Support\SupportTokenCipher;

/** Produces purpose-separated, non-reversible identifiers for verification limits. */
final class VerificationIdentifier
{
    public static function email(string $email): string
    {
        return SupportTokenCipher::fingerprint('email-verification-email', strtolower(trim($email)));
    }

    public static function sourceAddress(string $address): string
    {
        $packed = filter_var($address, FILTER_VALIDATE_IP) !== false ? inet_pton($address) : false;
        if (!is_string($packed)) {
            throw new \InvalidArgumentException('Invalid verification source address.');
        }
        return SupportTokenCipher::fingerprint('email-verification-source', $packed);
    }

    public static function token(string $token): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
            throw new \InvalidArgumentException('Invalid verification token.');
        }
        return SupportTokenCipher::fingerprint('email-verification-token', $token);
    }
}
