<?php

declare(strict_types=1);

namespace Verdant\Identity;

use Verdant\Support\SupportTokenCipher;

/** Produces purpose-separated, non-reversible identifiers for TOTP limits. */
final class TotpIdentifier
{
    public static function account(int $customerId): string
    {
        if ($customerId < 1) {
            throw new \InvalidArgumentException('Invalid TOTP account.');
        }
        return SupportTokenCipher::fingerprint('totp-account', (string) $customerId);
    }

    public static function sourceAddress(string $address): string
    {
        $packed = filter_var($address, FILTER_VALIDATE_IP) !== false ? inet_pton($address) : false;
        if (!is_string($packed)) {
            throw new \InvalidArgumentException('Invalid TOTP source address.');
        }
        return SupportTokenCipher::fingerprint('totp-source', $packed);
    }
}
