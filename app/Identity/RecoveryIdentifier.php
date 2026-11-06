<?php

declare(strict_types=1);

namespace Verdant\Identity;

use Verdant\Support\SupportTokenCipher;

/** Produces purpose-separated, non-reversible identifiers for recovery limits. */
final class RecoveryIdentifier
{
    public static function email(string $email): string
    {
        return SupportTokenCipher::fingerprint('password-recovery-email', strtolower(trim($email)));
    }

    public static function sourceAddress(string $address): string
    {
        $packed = filter_var($address, FILTER_VALIDATE_IP) !== false ? inet_pton($address) : false;
        if (!is_string($packed)) {
            throw new \InvalidArgumentException('Invalid recovery source address.');
        }
        return SupportTokenCipher::fingerprint('password-recovery-source', $packed);
    }

    public static function token(string $token): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
            throw new \InvalidArgumentException('Invalid recovery token.');
        }
        return SupportTokenCipher::fingerprint('password-recovery-token', $token);
    }
}
