<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Identity\IdentityService;
use Verdant\Identity\SessionManager;
use Verdant\Identity\TotpService;

/** HTTP boundary for admin two-factor enrolment, challenge, and recovery. */
final class TwoFactorController
{
    public function __construct(
        private readonly TotpService $totp,
        private readonly IdentityService $identity,
    ) {
    }

    /** @param array{customer_id:int,return_to:?string} $pending */
    public function form(string $path, array $pending, array $errors = []): void
    {
        $account = $this->pendingAccount($pending);
        if ($account === null) {
            return;
        }
        try {
            $needsSetup = $this->totp->needsSetup($pending['customer_id']);
        } catch (\Throwable) {
            error_log('Admin two-factor status check failed.');
            $this->unavailable();
            return;
        }
        if ($path === '/admin/2fa/setup') {
            if (!$needsSetup) {
                self::redirect('/admin/2fa');
                return;
            }
            $this->setupForm($account, $errors);
            return;
        }
        if ($needsSetup) {
            self::redirect('/admin/2fa/setup');
            return;
        }
        View::render('admin/2fa-challenge', [
            'title' => 'Two-factor check', 'errors' => $errors, 'auth_split' => true,
        ], $errors === [] ? 200 : 422, 'admin-auth');
    }

    /** @param array{customer_id:int,return_to:?string} $pending */
    public function submit(string $path, array $input, array $pending, string $sourceAddress): void
    {
        $account = $this->pendingAccount($pending);
        if ($account === null) {
            return;
        }
        try {
            if ($path === '/admin/2fa/setup') {
                $codes = $this->totp->confirmSetup($input['code'] ?? null, $pending['customer_id'], $sourceAddress);
                if ($codes === null) {
                    $this->form($path, $pending, ['code' => 'That code did not match. Check your authenticator app and try again.']);
                    return;
                }
                $this->finishSignIn($pending, (int) $account['customer_id'], (int) ($account['credential_version'] ?? 1));
                View::render('admin/2fa-backup-codes', [
                    'title' => 'Save your backup codes', 'codes' => $codes, 'auth_split' => true,
                    'continueUrl' => Url::local($this->returnTarget($pending)),
                ], 200, 'admin-auth');
                return;
            }
            $valid = $this->totp->challenge($input['code'] ?? null, $pending['customer_id'], $sourceAddress);
            if (!$valid) {
                $this->form($path, $pending, ['code' => 'That code did not match. Check your authenticator app, its clock, and try again.']);
                return;
            }
            $this->finishSignIn($pending, (int) $account['customer_id'], (int) ($account['credential_version'] ?? 1));
            self::redirect($this->returnTarget($pending));
        } catch (\Throwable) {
            error_log('Admin two-factor verification failed.');
            $this->unavailable();
        }
    }

    /** @param array<string, mixed> $actor */
    public function codes(array $input, array $actor): void
    {
        AdminView::render('admin/2fa-manage', [
            'title' => 'Two-factor authentication', 'actor' => $actor,
            'remaining' => $this->totp->remainingBackupCodes((int) $actor['customer_id']),
            'resetUrl' => Url::local('/admin/2fa/reset'),
        ]);
    }

    /** @param array<string, mixed> $actor */
    public function regenerateCodes(array $input, array $actor): void
    {
        try {
            $codes = $this->totp->regenerateBackupCodes((int) $actor['customer_id']);
        } catch (\Throwable) {
            error_log('Admin backup-code regeneration failed.');
            $codes = null;
        }
        if ($codes === null) {
            AdminView::render('admin/2fa-manage', [
                'title' => 'Two-factor authentication', 'actor' => $actor, 'remaining' => 0,
                'resetUrl' => Url::local('/admin/2fa/reset'),
                'notice' => 'Two-factor authentication was reset for this account. Sign out and sign in to enrol again.',
            ]);
            return;
        }
        AdminView::render('admin/2fa-backup-codes', [
            'title' => 'Save your backup codes', 'actor' => $actor, 'codes' => $codes,
            'continueUrl' => Url::local('/admin/2fa/codes'),
        ]);
    }

    /** @param array<string, mixed> $actor */
    public function resetForm(array $input, array $actor): void
    {
        AdminView::render('admin/2fa-reset', [
            'title' => 'Reset two-factor authentication', 'actor' => $actor, 'email' => '', 'result' => null,
        ]);
    }

    /** @param array<string, mixed> $actor */
    public function resetSubmit(array $input, array $actor): void
    {
        $email = is_string($input['email'] ?? null) ? strtolower(trim($input['email'])) : '';
        $requesterId = (int) $actor['customer_id'];
        try {
            $target = $this->totp->resettableAdmin($email, $requesterId);
            if ($target !== null) {
                $this->totp->resetForAdmin($target['customer_id']);
                error_log(sprintf('Admin two-factor authentication was reset for %s by administrator %d.',
                    $target['email'], $requesterId));
            }
        } catch (\Throwable) {
            error_log('Admin two-factor reset failed.');
            $target = null;
        }
        AdminView::render('admin/2fa-reset', [
            'title' => 'Reset two-factor authentication', 'actor' => $actor, 'email' => '', 'result' => $target === null
                ? 'If that address belongs to another administrator, their two-factor authentication is now reset and they must enrol again at the next sign-in.'
                : 'Two-factor authentication for ' . $target['email'] . ' is now reset. They must enrol again at the next sign-in.',
        ]);
    }

    /** @return array<string, mixed>|null */
    private function pendingAccount(array $pending): ?array
    {
        $account = $this->identity->actor($pending['customer_id']);
        if ($account === null || ($account['role'] ?? null) !== 'admin') {
            SessionManager::clearAdminTwoFactorPending();
            self::redirect('/login');
            return null;
        }
        return $account;
    }

    /** @param array<string, mixed> $account */
    private function setupForm(array $account, array $errors): void
    {
        try {
            $setup = $this->totp->beginSetup((int) $account['customer_id'], (string) $account['email']);
        } catch (\Throwable) {
            error_log('Admin two-factor enrolment failed.');
            $this->unavailable();
            return;
        }
        if ($setup === null) {
            self::redirect('/admin/2fa');
            return;
        }
        View::render('admin/2fa-setup', [
            'title' => 'Set up two-factor authentication', 'errors' => $errors, 'auth_split' => true,
            'secret' => $setup['secret'], 'uri' => $setup['uri'],
        ], $errors === [] ? 200 : 422, 'admin-auth');
    }

    /** @param array{customer_id:int,return_to:?string} $pending */
    private function finishSignIn(array $pending, int $customerId, int $credentialVersion): void
    {
        SessionManager::clearAdminTwoFactorPending();
        SessionManager::signIn($customerId, $credentialVersion);
        SessionManager::markAdminTwoFactorVerified();
    }

    /** @param array{customer_id:int,return_to:?string} $pending */
    private function returnTarget(array $pending): string
    {
        $target = $pending['return_to'] === null ? null : AuthController::validatedAdminReturnTo($pending['return_to']);
        return $target ?? '/admin';
    }

    private function unavailable(): void
    {
        View::render('admin/2fa-challenge', [
            'title' => 'Two-factor check',
            'errors' => ['code' => 'Two-factor authentication is temporarily unavailable. Please try again shortly.'],
            'auth_split' => true,
        ], 503, 'admin-auth');
    }

    private static function redirect(string $destination): void
    {
        header('Location: ' . Url::redirectTarget($destination), true, 303);
    }
}
