<?php

declare(strict_types=1);

namespace Verdant\Support;

/** Backwards-compatible support-link facade over the shared private token cipher. */
final class SupportTokenCipher
{
    public static function seal(string $token): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new \InvalidArgumentException('Invalid support token.');
        }
        return PrivateTokenCipher::seal($token);
    }

    public static function open(string $sealed): string
    {
        $token = PrivateTokenCipher::open($sealed);
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new \RuntimeException('Unable to open support token.');
        }
        return $token;
    }

    public static function fingerprint(string $purpose, string $value): string
    {
        return PrivateTokenCipher::fingerprint($purpose, $value);
    }
}
