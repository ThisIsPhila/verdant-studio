<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Config\TrustedOrigins;
use Verdant\Identity\PasswordRecoveryService;
use Verdant\Identity\SessionManager;

/** HTTP boundary for store/admin password recovery. */
final class PasswordRecoveryController
{
    public function __construct(private readonly PasswordRecoveryService $recovery)
    {
    }

    public function requestForm(string $surface): void
    {
        $this->headers();
        View::render('password-recovery/request', [
            'title' => 'Reset your password',
            'surface' => $surface,
            'supportUrl' => TrustedOrigins::local()->store . '/contact',
        ], 200, 'recovery');
    }

    public function request(array $input, string $surface, string $sourceAddress): void
    {
        try {
            $this->recovery->request($input['email'] ?? null, $surface, $sourceAddress);
        } catch (\Throwable) {
            error_log('Password recovery request failed.');
        }
        $this->headers();
        View::render('password-recovery/requested', [
            'title' => 'Check your email',
            'surface' => $surface,
            'supportUrl' => TrustedOrigins::local()->store . '/contact',
        ], 200, 'recovery');
    }

    public function resetForm(string $surface): void
    {
        $this->headers();
        View::render('password-recovery/reset', [
            'title' => 'Choose a new password',
            'surface' => $surface,
            'errors' => [],
            'token' => '',
            'validationUrl' => Url::local('/password-reset/validate'),
        ], 200, 'recovery');
    }

    public function validate(array $input, string $surface, string $sourceAddress): void
    {
        $valid = false;
        try {
            $valid = $this->recovery->tokenIsValid($input['token'] ?? null, $surface, $sourceAddress);
        } catch (\Throwable) {
            error_log('Password recovery link validation failed.');
        }
        $this->headers();
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['valid' => $valid], JSON_THROW_ON_ERROR);
    }

    public function csrfRejected(string $path): void
    {
        $requestExpired = $path === '/password-reset/request';
        $this->headers();
        View::render('password-recovery/expired', [
            'title' => $requestExpired ? 'Request form expired' : 'Reset form expired',
            'message' => $requestExpired
                ? 'This request form has expired. Reload it and send your request again.'
                : 'This reset form has expired. Request a new reset link to continue.',
            'recoveryUrl' => Url::local('/password-reset/request'),
            'loginUrl' => Url::local('/login'),
        ], 419, 'recovery');
    }

    public function reset(array $input, string $surface, string $sourceAddress): void
    {
        try {
            $result = $this->recovery->complete(
                $input['token'] ?? null,
                $surface,
                $sourceAddress,
                $input['password'] ?? null,
                $input['password_confirmation'] ?? null,
            );
        } catch (\Throwable) {
            error_log('Password recovery completion failed.');
            $result = ['status' => 'invalid'];
        }

        $this->headers();
        if ($result['status'] === 'success') {
            SessionManager::signOut();
            header('Location: ' . Url::local('/login?password_reset=complete'), true, 303);
            return;
        }
        if ($result['status'] === 'password_error') {
            View::render('password-recovery/reset', [
                'title' => 'Choose a new password',
                'surface' => $surface,
                'errors' => $result['errors'] ?? [],
                'token' => is_string($input['token'] ?? null) ? $input['token'] : '',
                'validationUrl' => Url::local('/password-reset/validate'),
            ], 422, 'recovery');
            return;
        }
        View::render('password-recovery/invalid', [
            'title' => 'Reset link unavailable',
            'surface' => $surface,
            'requestUrl' => Url::local('/password-reset/request'),
        ], 422, 'recovery');
    }

    private function headers(): void
    {
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
    }
}
