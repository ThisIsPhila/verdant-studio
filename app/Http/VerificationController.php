<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Identity\SessionManager;
use Verdant\Identity\VerificationService;

/** HTTP boundary for store signup email verification. */
final class VerificationController
{
    public function __construct(private readonly VerificationService $verification)
    {
    }

    public function confirm(mixed $token, string $sourceAddress): void
    {
        try {
            $account = $this->verification->confirm($token, $sourceAddress);
        } catch (\Throwable) {
            error_log('Email verification failed.');
            $account = null;
        }

        $this->headers();
        if ($account === null) {
            View::render('verify-email/invalid', [
                'title' => 'Verification link unavailable',
                'resendUrl' => Url::local('/verify-email/resend'),
                'loginUrl' => Url::local('/login'),
            ], 422, 'recovery');
            return;
        }
        SessionManager::signIn($account['customer_id'], $account['credential_version']);
        View::render('verify-email/verified', [
            'title' => 'Email verified',
            'accountUrl' => Url::local('/account'),
        ], 200, 'recovery');
    }

    public function resendForm(mixed $email): void
    {
        $this->headers();
        View::render('verify-email/resend', [
            'title' => 'Resend verification email',
            'email' => is_string($email) ? substr($email, 0, 254) : '',
            'supportUrl' => Url::local('/contact'),
        ], 200, 'recovery');
    }

    public function resend(array $input, string $sourceAddress): void
    {
        try {
            $this->verification->resend($input['email'] ?? null, $sourceAddress);
        } catch (\Throwable) {
            error_log('Verification resend failed.');
        }
        $this->headers();
        View::render('verify-email/resent', [
            'title' => 'Check your email',
            'loginUrl' => Url::local('/login'),
        ], 200, 'recovery');
    }

    private function headers(): void
    {
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
    }
}
