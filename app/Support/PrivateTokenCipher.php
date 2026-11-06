<?php

declare(strict_types=1);

namespace Verdant\Support;

/** Seals only supported opaque credentials before they enter private mail context. */
final class PrivateTokenCipher
{
    private const FINGERPRINT_PURPOSES = [
        'password-recovery-email',
        'password-recovery-source',
        'password-recovery-token',
        'email-verification-email',
        'email-verification-source',
        'email-verification-token',
        'totp-account',
        'totp-source',
    ];

    public static function seal(string $token): string
    {
        if (!self::validToken($token)) {
            throw new \InvalidArgumentException('Invalid private token.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $sealed = sodium_crypto_secretbox($token, $nonce, self::key());
        return rtrim(strtr(base64_encode($nonce . $sealed), '+/', '-_'), '=');
    }

    public static function open(string $sealed): string
    {
        if (strlen($sealed) > 512 || preg_match('/^[A-Za-z0-9_-]+$/D', $sealed) !== 1) {
            throw new \InvalidArgumentException('Invalid sealed private token.');
        }
        $encoded = strtr($sealed, '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $packed = base64_decode($encoded, true);
        if (!is_string($packed) || strlen($packed) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \InvalidArgumentException('Invalid sealed private token.');
        }
        $nonce = substr($packed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($packed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key());
        if (!is_string($plain) || !self::validToken($plain)) {
            throw new \RuntimeException('Unable to open private token.');
        }
        return $plain;
    }

    /** Purpose-specific HMAC key; the database receives only the resulting fingerprint. */
    public static function fingerprint(string $purpose, string $value): string
    {
        if (!in_array($purpose, self::FINGERPRINT_PURPOSES, true)) {
            throw new \InvalidArgumentException('Unsupported private fingerprint purpose.');
        }
        $derivedKey = hash_hkdf('sha256', self::key(), 32, 'verdant/' . $purpose);
        return hash_hmac('sha256', $value, $derivedKey, true);
    }

    /** Seal a short opaque secret such as a TOTP key; the token format does not apply. */
    public static function sealOpaque(string $secret): string
    {
        if ($secret === '' || strlen($secret) > 128) {
            throw new \InvalidArgumentException('Invalid opaque secret.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $sealed = sodium_crypto_secretbox($secret, $nonce, self::key());
        return rtrim(strtr(base64_encode($nonce . $sealed), '+/', '-_'), '=');
    }

    public static function openOpaque(string $sealed): string
    {
        if (strlen($sealed) > 512 || preg_match('/^[A-Za-z0-9_-]+$/D', $sealed) !== 1) {
            throw new \InvalidArgumentException('Invalid sealed opaque secret.');
        }
        $encoded = strtr($sealed, '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $packed = base64_decode($encoded, true);
        if (!is_string($packed) || strlen($packed) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \InvalidArgumentException('Invalid sealed opaque secret.');
        }
        $nonce = substr($packed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($packed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key());
        if (!is_string($plain) || $plain === '' || strlen($plain) > 128) {
            throw new \RuntimeException('Unable to open opaque secret.');
        }
        return $plain;
    }

    public static function isValidToken(string $token): bool
    {
        return self::validToken($token);
    }

    private static function validToken(string $token): bool
    {
        return preg_match('/^(?:[a-f0-9]{64}|[A-Za-z0-9_-]{43})$/D', $token) === 1;
    }

    private static function key(): string
    {
        if (!function_exists('sodium_crypto_secretbox') || !function_exists('sodium_crypto_secretbox_open')) {
            throw new \RuntimeException('Private token sealing requires the PHP sodium extension.');
        }
        $configured = getenv('SUPPORT_TOKEN_KEY');
        if (is_string($configured) && trim($configured) !== '') {
            $key = base64_decode(trim($configured), true);
            if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new \RuntimeException('SUPPORT_TOKEN_KEY must be base64 encoding of a 32-byte key.');
            }
            return $key;
        }
        if (getenv('APP_ENV') !== 'local') {
            throw new \RuntimeException('SUPPORT_TOKEN_KEY is required outside local development.');
        }
        $root = dirname(__DIR__, 2);
        $directory = $root . '/.local';
        if (!file_exists($directory) && !is_link($directory)) {
            if (!mkdir($directory, 0700) && !is_dir($directory)) {
                throw new \RuntimeException('Private token storage is unavailable.');
            }
        }
        if (is_link($directory) || !is_dir($directory)) {
            throw new \RuntimeException('Private token storage is unsafe.');
        }
        $path = $directory . '/support-token.key';
        if (is_link($path)) {
            throw new \RuntimeException('Private token key is unsafe.');
        }
        $lockPath = $directory . '/support-token-key.lock';
        if (is_link($lockPath)) {
            throw new \RuntimeException('Private token lock is unsafe.');
        }
        $lock = @fopen($lockPath, 'c');
        if (!is_resource($lock)) {
            throw new \RuntimeException('Private token storage is unavailable.');
        }
        @chmod($lockPath, 0600);
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Private token storage is unavailable.');
            }
            if (!file_exists($path)) {
                $temporary = $directory . '/support-token.key.tmp-' . bin2hex(random_bytes(8));
                $handle = @fopen($temporary, 'x');
                if (!is_resource($handle)) {
                    throw new \RuntimeException('Private token key could not be created.');
                }
                @chmod($temporary, 0600);
                $bytes = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
                $written = fwrite($handle, $bytes);
                fflush($handle);
                fclose($handle);
                if ($written !== strlen($bytes) || !rename($temporary, $path)) {
                    @unlink($temporary);
                    throw new \RuntimeException('Private token key could not be created.');
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if (is_link($path) || !is_file($path)) {
            throw new \RuntimeException('Private token key is unsafe.');
        }
        $raw = @file_get_contents($path);
        $key = is_string($raw) ? base64_decode(trim($raw), true) : false;
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Private token key is invalid.');
        }
        if ((fileperms($path) & 0077) !== 0) {
            @chmod($path, 0600);
        }
        return $key;
    }
}
