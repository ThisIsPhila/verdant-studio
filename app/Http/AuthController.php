<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Admin\AdminAllowlistService;
use Verdant\Identity\IdentityService;
use Verdant\Identity\SessionManager;
use Verdant\Identity\TotpService;
use Verdant\Support\ValidationException;

final class AuthController
{
    public function __construct(
        private readonly IdentityService $identity,
        private readonly ?TotpService $twoFactor = null,
        private readonly ?AdminAllowlistService $allowlist = null,
    ) {
    }

    public function form(string $mode, ?array $actor, mixed $intent = null, bool $adminSurface = false,
        mixed $returnTo = null, bool $passwordResetComplete = false): void
    {
        if ($adminSurface && $mode !== 'login') {
            View::render('error', ['title' => 'Page unavailable', 'message' => 'Registration is available from the Verdant store.'], 404, 'admin-auth');
            return;
        }
        if ($actor !== null) {
            self::redirect($adminSurface ? '/admin' : '/account');
            return;
        }
        $this->render($mode, intent: self::supportIntent($intent), adminSurface: $adminSurface,
            returnTo: $adminSurface ? self::validatedAdminReturnTo($returnTo) : null,
            passwordResetComplete: $passwordResetComplete);
    }

    public function submit(string $mode, array $input, bool $adminSurface = false): void
    {
        if ($adminSurface && $mode !== 'login') {
            View::render('error', ['title' => 'Page unavailable', 'message' => 'Registration is available from the Verdant store.'], 404, 'admin-auth');
            return;
        }
        $returnTo = $adminSurface ? self::validatedAdminReturnTo($input['return_to'] ?? null) : null;
        $intent = self::supportIntent($input['intent'] ?? null);
        if ($mode === 'register') {
            try {
                $this->identity->register($input);
            } catch (ValidationException $error) {
                $this->render($mode, $error->errors, $input, 422, $intent);
                return;
            }
            View::render('verify-email/check-email', [
                'title' => 'Check your email',
                'email' => strtolower(trim((string) ($input['email'] ?? ''))),
            ], 200, 'recovery');
            return;
        } else {
            $email = is_string($input['email'] ?? null) ? $input['email'] : '';
            $password = is_string($input['password'] ?? null) ? $input['password'] : '';
            $actor = $this->identity->authenticate($email, $password);
            if ($actor === null) {
                $verificationPending = !$adminSurface && $this->identity->unverifiedCredentialsValid($email, $password);
                $this->render($mode, ['login' => $verificationPending
                    ? 'Please verify your email address before signing in. Use the link we sent you, or request a new one below.'
                    : 'The email or password is incorrect.'], $input, 422, $intent,
                    $adminSurface, $returnTo, false, $verificationPending);
                return;
            }
            if ($adminSurface && !$this->adminLoginPermitted($actor)) {
                $this->render($mode, ['login' => 'The email or password is incorrect.'], $input, 422, $intent,
                    $adminSurface, $returnTo);
                return;
            }
            if ($adminSurface && ($actor['role'] ?? null) === 'admin' && $this->twoFactor !== null) {
                try {
                    $needsSetup = $this->twoFactor->needsSetup((int) $actor['customer_id']);
                } catch (\Throwable) {
                    error_log('Admin two-factor status check failed.');
                    $this->render($mode, ['login' => 'Sign in is temporarily unavailable. Please try again shortly.'],
                        $input, 503, $intent, $adminSurface, $returnTo);
                    return;
                }
                SessionManager::stageAdminTwoFactorPending((int) $actor['customer_id'], $returnTo);
                self::redirect($needsSetup ? '/admin/2fa/setup' : '/admin/2fa');
                return;
            }
            SessionManager::signIn($actor['customer_id'], $actor['credential_version'] ?? 1);
        }
        $draft = $_SESSION['_customer_enquiry_draft'] ?? null;
        $hasDraft = is_array($draft) && is_int($draft['saved_at'] ?? null)
            && $draft['saved_at'] >= time() - 1800 && is_array($draft['values'] ?? null);
        if ($adminSurface) {
            self::redirect($returnTo ?? '/admin');
            return;
        }
        self::redirect($intent === 'support' && $hasDraft ? '/contact?intent=support#contact-form-customer' : '/account');
    }

    /** Administrator sign-in needs the admin role plus an allowlist entry; customers fail shut. */
    private function adminLoginPermitted(array $actor): bool
    {
        if (($actor['role'] ?? null) !== 'admin' || $this->allowlist === null) {
            return false;
        }
        try {
            return $this->allowlist->isAllowed((string) ($actor['email'] ?? ''));
        } catch (\Throwable) {
            error_log('Admin allowlist check failed.');
            return false;
        }
    }

    public function logout(bool $adminSurface = false): void
    {
        SessionManager::signOut();
        self::redirect($adminSurface ? '/login' : '/catalogue');
    }

    private function render(string $mode, array $errors = [], array $input = [], int $status = 200, ?string $intent = null,
        bool $adminSurface = false, ?string $returnTo = null, bool $passwordResetComplete = false,
        bool $verificationPending = false): void
    {
        $values = [];
        foreach (['name', 'email', 'phone'] as $key) {
            $values[$key] = is_string($input[$key] ?? null) ? substr($input[$key], 0, 254) : '';
        }
        if ($adminSurface) {
            View::render('admin/auth', [
                'title' => 'Administrator sign in', 'errors' => $errors, 'values' => $values,
                'actor' => null, 'returnTo' => $returnTo, 'passwordResetComplete' => $passwordResetComplete,
                'auth_split' => true,
            ], $status, 'admin-auth');
            return;
        }
        View::render('auth', [
            'title' => $mode === 'register' ? 'Create your account' : 'Welcome back',
            'mode' => $mode, 'errors' => $errors, 'values' => $values, 'actor' => null, 'intent' => $intent,
            'passwordResetComplete' => $passwordResetComplete, 'verificationPending' => $verificationPending,
        ], $status);
    }

    /** Accept only a same-origin route handled by the admin route tree. */
    public static function validatedAdminReturnTo(mixed $candidate): ?string
    {
        if (!is_string($candidate) || $candidate === '' || strlen($candidate) > 2048
            || preg_match('/[\x00-\x20\x7f\\\\]/', $candidate) === 1
            || !str_starts_with($candidate, '/') || str_starts_with($candidate, '//')) {
            return null;
        }
        $parts = parse_url($candidate);
        if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user'])
            || isset($parts['pass']) || isset($parts['fragment'])
            || !is_string($parts['path'] ?? null)
            || preg_match('~^/admin(?:/[A-Za-z0-9_-]+)*$~D', $parts['path']) !== 1) {
            return null;
        }
        $query = $parts['query'] ?? null;
        if ($query !== null && (!is_string($query)
            || preg_match('~%(?![0-9A-Fa-f]{2})|[^A-Za-z0-9._!$&\'()*+,;=:@%/?\-\~]~', $query) === 1)) {
            return null;
        }
        return $parts['path'] . ($query === null ? '' : '?' . $query);
    }

    private static function supportIntent(mixed $intent): ?string
    {
        return $intent === 'support' ? 'support' : null;
    }

    public static function redirect(string $destination): void
    {
        header('Location: ' . Url::redirectTarget($destination), true, 303);
    }
}
