<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Config\TrustedOrigins;

/** Resolves and enforces the local customer/admin website boundary. */
final class LocalSiteSurface
{
    public const STORE = 'store';
    public const ADMIN = 'admin';
    public const LEGACY = 'legacy';

    private function __construct(public readonly string $name, private readonly bool $local)
    {
    }

    /** Return null when a local request uses an unknown or malformed Host. */
    public static function resolve(array $server): ?self
    {
        if ((getenv('APP_ENV') ?: '') !== 'local') {
            // Hosted split: the configured public origins decide the surface.
            // Any other host keeps the single-site legacy behaviour.
            $host = strtolower(trim((string) ($server['HTTP_HOST'] ?? '')));
            $host = preg_replace('/:\d+$/', '', $host) ?? $host;
            $storeHost = self::originHost(getenv('EMAIL_BASE_URL') ?: '');
            $adminHost = self::originHost(getenv('ADMIN_BASE_URL') ?: '');
            // A single shared host (no split configured) stays legacy:
            // the split only applies when store and admin hosts differ.
            if ($adminHost !== '' && $adminHost !== $storeHost && $host === $adminHost) {
                return new self(self::ADMIN, false);
            }
            if ($storeHost !== '' && $adminHost !== $storeHost && $host === $storeHost) {
                return new self(self::STORE, false);
            }
            return new self(self::LEGACY, false);
        }

        $host = $server['HTTP_HOST'] ?? null;
        if (!is_string($host) || $host === '' || preg_match('/[\x00-\x20\x7f]/', $host) === 1
            || strpbrk($host, "/\\?#@") !== false) {
            return null;
        }

        $name = match (strtolower($host)) {
            'verdant.localhost:8088', '127.0.0.1:8088', 'localhost:8088' => self::STORE,
            'admin.verdant.localhost:8088' => self::ADMIN,
            default => null,
        };

        return $name === null ? null : new self($name, true);
    }

    public static function rejectUnknownHost(string $method): never
    {
        (new self('invalid', true))->respond(['action' => 'bad_request', 'status' => 400], $method);
    }

    /**
     * Select a surface path or a response that must be handled before the app
     * opens a session, connects to MySQL, or dispatches a controller.
     *
     * @return array{action: string, request_uri?: string, location?: string, status?: int, store_url?: string}
     */
    public function requestPolicy(string $requestUri, string $method): array
    {
        if (!$this->local && $this->name === self::LEGACY) {
            return ['action' => 'continue', 'request_uri' => $requestUri];
        }
        $origins = $this->local ? TrustedOrigins::local() : TrustedOrigins::hosted();
        if ($origins === null) {
            return ['action' => 'continue', 'request_uri' => $requestUri];
        }

        if ($requestUri === '' || preg_match('/[\x00-\x20\x7f]/', $requestUri) === 1
            || strpbrk($requestUri, "\\#") !== false
            || !str_starts_with($requestUri, '/') || str_starts_with($requestUri, '//')) {
            return ['action' => 'bad_request', 'status' => 400];
        }

        $parts = parse_url($requestUri);
        if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host'])
            || !is_string($parts['path'] ?? null)) {
            return ['action' => 'bad_request', 'status' => 400];
        }

        $path = $parts['path'];
        if ($this->name === self::ADMIN) {
            if ($path === '/') {
                $path = '/admin';
            }
        }

        $query = array_key_exists('query', $parts) ? '?' . $parts['query'] : '';
        $effectiveUri = $path . $query;
        $logicalPath = $path;
        $isAdminPath = $logicalPath === '/admin' || str_starts_with($logicalPath, '/admin/');

        if ($this->name === self::STORE && $isAdminPath) {
            if (in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
                return ['action' => 'redirect', 'location' => $origins->admin . $effectiveUri];
            }
            return ['action' => 'reject', 'status' => 404];
        }

        if ($this->name === self::ADMIN) {
            $isAssetPath = $path === '/assets' || str_starts_with($path, '/assets/');
            $isAuthPath = in_array($logicalPath, [
                '/login', '/logout', '/password-reset/request', '/password-reset', '/password-reset/validate',
            ], true);
            if (!$isAdminPath && !$isAssetPath && !$isAuthPath) {
                return ['action' => 'not_found', 'status' => 404, 'store_url' => $origins->store];
            }
        }

        return ['action' => 'continue', 'request_uri' => $effectiveUri];
    }

    /** Send a policy response without opening the normal customer/admin app. */
    public function respond(array $decision, string $method): never
    {
        $action = $decision['action'] ?? 'reject';
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');

        if ($action === 'redirect' && is_string($decision['location'] ?? null)) {
            header('Location: ' . $decision['location'], true, 302);
            exit;
        }

        $status = is_int($decision['status'] ?? null) ? $decision['status'] : 404;
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        header("Content-Security-Policy: default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");

        if (strtoupper($method) === 'HEAD') {
            exit;
        }

        if ($action === 'bad_request') {
            echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Request unavailable</title><body><h1>Request unavailable</h1></body></html>';
            exit;
        }

        $storeUrl = $decision['store_url'] ?? null;
        if (is_string($storeUrl)) {
            $safeUrl = htmlspecialchars($storeUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Page unavailable · Verdant</title><body><main><h1>This page is not available here.</h1><p>Continue at the <a href="' . $safeUrl . '">Verdant store</a>.</p></main></body></html>';
            exit;
        }

        echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Page unavailable · Verdant</title><body><h1>Page unavailable</h1></body></html>';
        exit;
    }

    private static function originHost(string $origin): string
    {
        $host = parse_url(trim($origin), PHP_URL_HOST);
        return is_string($host) ? strtolower($host) : '';
    }
}
