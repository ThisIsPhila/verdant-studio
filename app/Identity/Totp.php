<?php

declare(strict_types=1);

namespace Verdant\Identity;

/** Pure RFC 6238 time-based one-time password helpers (SHA-1, 30-second steps, 6 digits). */
final class Totp
{
    public const STEP_SECONDS = 30;
    public const DIGITS = 6;
    public const SECRET_BYTES = 20;

    public static function generateSecret(): string
    {
        return random_bytes(self::SECRET_BYTES);
    }

    public static function base32Encode(string $raw): string
    {
        if ($raw === '' || strlen($raw) > 64) {
            throw new \InvalidArgumentException('Invalid TOTP secret.');
        }
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($raw) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
        }
        return $encoded;
    }

    /** Strict decode for secrets the server generated; null for anything else. */
    public static function base32Decode(string $encoded): ?string
    {
        $clean = strtoupper(str_replace([' ', '-', "\t", "\n", "\r"], '', $encoded));
        if ($clean === '' || strlen($clean) > 104 || preg_match('/^[A-Z2-7]+$/D', $clean) !== 1) {
            return null;
        }
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($clean) as $char) {
            $position = strpos($alphabet, $char);
            if ($position === false) {
                return null;
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $raw = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) < 8) {
                break;
            }
            $raw .= chr((int) bindec($chunk));
        }
        if ($raw === '' || strlen($raw) > 64 || self::base32Encode($raw) !== $clean) {
            return null;
        }
        return $raw;
    }

    /** Provisioning URI understood by Google Authenticator, Authy, and other TOTP apps. */
    public static function provisioningUri(string $email, string $secret): string
    {
        if (strlen($secret) !== self::SECRET_BYTES) {
            throw new \InvalidArgumentException('Invalid TOTP secret.');
        }
        $account = rawurlencode('Verdant:' . strtolower(trim($email)));
        $query = http_build_query(['secret' => self::base32Encode($secret), 'issuer' => 'Verdant',
            'digits' => self::DIGITS, 'period' => self::STEP_SECONDS], '', '&', PHP_QUERY_RFC3986);
        return 'otpauth://totp/' . $account . '?' . $query;
    }

    /** The code for one counter value, per RFC 4226 dynamic truncation. */
    public static function codeForCounter(string $secret, int $counter): string
    {
        if ($secret === '' || strlen($secret) > 64 || $counter < 0) {
            throw new \InvalidArgumentException('Invalid TOTP parameters.');
        }
        $movingFactor = pack('J', $counter);
        $hash = hash_hmac('sha1', $movingFactor, $secret, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);
        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** Verify a presented code within a window of neighbouring steps. */
    public static function verify(string $secret, string $code, ?int $time = null, int $window = 1): bool
    {
        if (preg_match('/^[0-9]{6}$/D', $code) !== 1 || $window < 0 || $window > 2) {
            return false;
        }
        $step = intdiv($time ?? time(), self::STEP_SECONDS);
        for ($counter = $step - $window; $counter <= $step + $window; ++$counter) {
            if ($counter < 0) {
                continue;
            }
            if (hash_equals(self::codeForCounter($secret, $counter), $code)) {
                return true;
            }
        }
        return false;
    }

    /** One human-typable backup code; unambiguous alphabet, no padding or separators. */
    public static function generateBackupCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($index = 0; $index < 8; ++$index) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $code;
    }

    public static function isBackupCodeFormat(string $code): bool
    {
        return preg_match('/^[A-Z2-9]{8}$/D', strtoupper(str_replace([' ', '-', "\t"], '', $code))) === 1;
    }

    public static function normalizeBackupCode(string $code): string
    {
        return strtoupper(str_replace([' ', '-', "\t", "\n", "\r"], '', $code));
    }
}
