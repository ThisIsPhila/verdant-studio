<?php

declare(strict_types=1);

namespace Verdant\Identity;

use Verdant\Http\LocalSiteSurface;

final class SessionManager
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (headers_sent()) {
            throw new \RuntimeException('Session could not be started.');
        }

        $cookieName = self::cookieNameForCurrentSurface();
        if (session_name($cookieName) === false) {
            throw new \RuntimeException('Secure session configuration is unavailable.');
        }

        $https = isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off'
            && (string) $_SERVER['HTTPS'] !== '';
        if (!$https && !self::isAllowedLocalHttp()) {
            throw new \RuntimeException('Secure session transport is required.');
        }

        $settings = [
            'session.use_strict_mode' => '1',
            'session.use_cookies' => '1',
            'session.use_only_cookies' => '1',
            'session.use_trans_sid' => '0',
            'session.cookie_httponly' => '1',
            'session.cookie_samesite' => 'Lax',
        ];
        foreach ($settings as $setting => $value) {
            if (ini_set($setting, $value) === false || ini_get($setting) !== $value) {
                throw new \RuntimeException('Secure session configuration is unavailable.');
            }
        }
        if (!session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ])) {
            throw new \RuntimeException('Secure session configuration is unavailable.');
        }
        if (!session_start()) {
            throw new \RuntimeException('Session could not be started.');
        }
    }

    public static function currentCustomerId(): ?int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        $value = $_SESSION['customer_id'] ?? null;
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1
            || strlen($value) > strlen((string) PHP_INT_MAX)
            || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) {
            return null;
        }
        return (int) $value;
    }

    /** Read the authenticated credential generation; version-1 sessions predate this field. */
    public static function currentCredentialVersion(): ?int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $value = $_SESSION['credential_version'] ?? 1;
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1
            || strlen($value) > strlen((string) PHP_INT_MAX)
            || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) {
            return null;
        }
        return (int) $value;
    }

    public static function signIn(int $customerId, int $credentialVersion = 1): void
    {
        if ($customerId < 1 || $credentialVersion < 1) {
            throw new \InvalidArgumentException('A valid customer and credential version are required.');
        }
        self::start();
        if (!session_regenerate_id(true)) {
            throw new \RuntimeException('Session could not be renewed.');
        }
        $cart = $_SESSION['_retail_cart'] ?? null;
        $supportDraft = $_SESSION['_customer_enquiry_draft'] ?? null;
        if (!is_array($supportDraft) || !is_int($supportDraft['saved_at'] ?? null)
            || $supportDraft['saved_at'] < time() - 1800 || !is_array($supportDraft['values'] ?? null)) {
            $supportDraft = null;
        }
        $_SESSION = ['customer_id' => $customerId, 'credential_version' => $credentialVersion];
        if (is_array($cart) && count($cart) <= 50) {
            $_SESSION['_retail_cart'] = $cart;
        }
        if ($supportDraft !== null) {
            $_SESSION['_customer_enquiry_draft'] = $supportDraft;
        }
    }

    public static function signOut(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::start();
        }
        $_SESSION = [];
        $parameters = session_get_cookie_params();
        if (ini_get('session.use_cookies')) {
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $parameters['path'] ?: '/',
                'domain' => $parameters['domain'],
                'secure' => (bool) $parameters['secure'],
                'httponly' => (bool) $parameters['httponly'],
                'samesite' => $parameters['samesite'] ?: 'Lax',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE && !session_destroy()) {
            throw new \RuntimeException('Session could not be invalidated.');
        }
    }

    /**
     * Stage an admin password success that still needs its second factor.
     * The session carries no actor until the challenge passes.
     */
    public static function stageAdminTwoFactorPending(int $customerId, ?string $returnTo, int $ttlSeconds = 600): void
    {
        if ($customerId < 1 || $ttlSeconds < 60 || $ttlSeconds > 3600) {
            throw new \InvalidArgumentException('A valid customer and pending lifetime are required.');
        }
        self::start();
        if (!session_regenerate_id(true)) {
            throw new \RuntimeException('Session could not be renewed.');
        }
        $_SESSION['_admin_2fa_pending'] = [
            'customer_id' => $customerId,
            'return_to' => is_string($returnTo) ? substr($returnTo, 0, 2048) : null,
            'expires_at' => time() + $ttlSeconds,
        ];
    }

    /** @return array{customer_id:int,return_to:?string}|null */
    public static function adminTwoFactorPending(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        $pending = $_SESSION['_admin_2fa_pending'] ?? null;
        if (!is_array($pending) || !is_int($pending['customer_id'] ?? null) || $pending['customer_id'] < 1
            || !is_int($pending['expires_at'] ?? null)) {
            unset($_SESSION['_admin_2fa_pending']);
            return null;
        }
        if ($pending['expires_at'] < time()) {
            unset($_SESSION['_admin_2fa_pending']);
            return null;
        }
        $returnTo = $pending['return_to'] ?? null;
        return ['customer_id' => $pending['customer_id'], 'return_to' => is_string($returnTo) ? $returnTo : null];
    }

    public static function clearAdminTwoFactorPending(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION['_admin_2fa_pending']);
        }
    }

    /** Mark the signed-in admin session as second-factor verified. Call after signIn. */
    public static function markAdminTwoFactorVerified(): void
    {
        self::start();
        $_SESSION['_admin_2fa_verified'] = true;
    }

    public static function adminTwoFactorVerified(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE && ($_SESSION['_admin_2fa_verified'] ?? false) === true;
    }

    /** @param array<string, mixed> $payload */
    public static function flash(string $key, array $payload): void
    {
        self::start();
        if ($key === '') {
            throw new \InvalidArgumentException('Flash key is required.');
        }
        $_SESSION['_flash'][$key] = $payload;
    }

    /** @return array<string, mixed>|null */
    public static function takeFlash(string $key): ?array
    {
        self::start();
        $value = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return is_array($value) ? $value : null;
    }

    private static function isAllowedLocalHttp(): bool
    {
        if ((getenv('APP_ENV') ?: '') !== 'local') {
            return false;
        }
        $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
        if ($host === '') {
            return PHP_SAPI === 'cli';
        }
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        return in_array(trim($host, '[]'), [
            'localhost', '127.0.0.1', '::1', 'verdant.localhost', 'admin.verdant.localhost',
        ], true);
    }

    private static function cookieNameForCurrentSurface(): string
    {
        if ((getenv('APP_ENV') ?: '') !== 'local') {
            return 'PHPSESSID';
        }

        $surface = LocalSiteSurface::resolve($_SERVER);
        if ($surface === null) {
            throw new \RuntimeException('A trusted local website surface is required for sessions.');
        }

        return match ($surface->name) {
            LocalSiteSurface::STORE => 'VERDANT_STORE_SESSION',
            LocalSiteSurface::ADMIN => 'VERDANT_ADMIN_SESSION',
            default => 'PHPSESSID',
        };
    }
}
