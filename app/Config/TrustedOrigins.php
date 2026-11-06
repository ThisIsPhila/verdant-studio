<?php

declare(strict_types=1);

namespace Verdant\Config;

/** Fixed origins for local links that cross the current website boundary. */
final class TrustedOrigins
{
    private function __construct(public readonly string $store, public readonly string $admin)
    {
    }

    public static function local(): self
    {
        $origins = require dirname(__DIR__, 2) . '/config/trusted-origins.php';
        if (!is_array($origins)) {
            throw new \LogicException('Trusted local origins are unavailable.');
        }
        $store = self::validateOrigin($origins['store'] ?? null);
        $admin = self::validateOrigin($origins['admin'] ?? null);
        if ($store !== 'http://verdant.localhost:8088' || $admin !== 'http://admin.verdant.localhost:8088') {
            throw new \LogicException('Trusted local origins must match the approved local websites.');
        }
        return new self($store, $admin);
    }

    /** Hosted store and admin origins, or null when no admin origin is set. */
    public static function hosted(): ?self
    {
        $admin = self::admin();
        if ($admin === null) {
            return null;
        }
        return new self(self::store(), $admin);
    }

    /** Customer storefront origin for links that leave the current surface. */
    public static function store(): string
    {
        if ((getenv('APP_ENV') ?: '') === 'local') {
            return self::local()->store;
        }
        $baseUrl = rtrim(getenv('EMAIL_BASE_URL') ?: '', '/');
        if ($baseUrl === '') {
            throw new \LogicException('EMAIL_BASE_URL must be configured for store links.');
        }
        return self::validateRemoteOrigin($baseUrl);
    }

    /** Administration origin for return links, or null when it is not configured. */
    public static function admin(): ?string
    {
        if ((getenv('APP_ENV') ?: '') === 'local') {
            return self::local()->admin;
        }
        $adminUrl = rtrim(getenv('ADMIN_BASE_URL') ?: '', '/');
        if ($adminUrl === '') {
            return null;
        }
        return self::validateRemoteOrigin($adminUrl);
    }

    private static function validateRemoteOrigin(string $origin): string
    {
        $parts = parse_url($origin);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['http', 'https'], true) || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ($parts['path'] ?? '') !== '') {
            throw new \LogicException('Cross-surface origin is invalid.');
        }
        if (($parts['scheme'] ?? null) === 'http'
            && !in_array(strtolower((string) $parts['host']), ['localhost', '127.0.0.1', '::1', 'verdant.localhost', 'admin.verdant.localhost'], true)) {
            throw new \LogicException('HTTP cross-surface origins are allowed only for loopback hosts.');
        }
        return $origin;
    }

    /** Accept only configured local callback authorities; callers normalize the URL. */
    public static function permitsLocalCallback(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'http'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        $port = $parts['port'] ?? null;
        $path = $parts['path'] ?? '';
        return in_array($host, ['verdant.localhost', 'localhost', '127.0.0.1', '::1'], true)
            && $port === 8088
            && in_array($path, ['', '/', '/payment/return', '/rental/payment/return'], true);
    }

    private static function validateOrigin(mixed $origin): string
    {
        if (!is_string($origin)) {
            throw new \LogicException('Trusted local origin is invalid.');
        }
        $parts = parse_url($origin);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'http' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ($parts['path'] ?? '') !== '') {
            throw new \LogicException('Trusted local origin is invalid.');
        }
        return rtrim($origin, '/');
    }
}
