<?php

declare(strict_types=1);

namespace Verdant\Identity;

use DateTimeImmutable;
use DateTimeZone;
use mysqli;
use Verdant\Notifications\VerificationMailer;
use Verdant\Support\Mail\EmailConfig;

/** Coordinates one-time signup verification without exposing account state. */
final class VerificationService
{
    private const RESEND_EMAIL_LIMIT = 5;
    private const RESEND_SOURCE_LIMIT = 10;
    private const CONFIRM_TOKEN_LIMIT = 5;
    private const CONFIRM_SOURCE_LIMIT = 20;

    public function __construct(
        private readonly mysqli $connection,
        private readonly VerificationRepository $repository,
        private readonly VerificationMailer $mailer,
    ) {
    }

    /** Issue the first verification mail for an account registration just created. */
    public function issueForNewAccount(int $customerId, string $email): void
    {
        if (!$this->mailFlowsEnabled() || $customerId < 1) {
            return;
        }
        $normalized = strtolower(trim($email));
        if ($normalized === '' || strlen($normalized) > 254 || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $this->connection->begin_transaction();
        $transactionOpen = true;
        try {
            $this->issueLocked($customerId, $normalized);
            $this->connection->commit();
            $transactionOpen = false;
        } catch (\Throwable $error) {
            if ($transactionOpen) {
                $this->connection->rollback();
            }
            throw $error;
        }
    }

    /** Every caller receives the same outcome; only permitted identities can queue mail. */
    public function resend(mixed $email, string $sourceAddress): void
    {
        if (!$this->mailFlowsEnabled()) {
            return;
        }
        $this->cleanupExpiredState();
        if (!$this->withinLimit('verify_source', VerificationIdentifier::sourceAddress($sourceAddress), self::RESEND_SOURCE_LIMIT)) {
            return;
        }

        $normalized = is_string($email) ? strtolower(trim($email)) : '';
        if ($normalized === '' || strlen($normalized) > 254 || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }
        $permitted = in_array($normalized, EmailConfig::TEST_RECIPIENTS, true)
            || getenv('EMAIL_OPEN_DELIVERY') === '1';
        if (!$this->withinLimit('verify_email', VerificationIdentifier::email($normalized), self::RESEND_EMAIL_LIMIT)
            || !$permitted) {
            return;
        }

        $this->connection->begin_transaction();
        $transactionOpen = true;
        try {
            $account = $this->repository->findUnverifiedAccount($normalized);
            if ($account === null) {
                $this->connection->commit();
                $transactionOpen = false;
                return;
            }
            $this->issueLocked((int) $account['customer_id'], (string) $account['email']);
            $this->connection->commit();
            $transactionOpen = false;
        } catch (\Throwable $error) {
            if ($transactionOpen) {
                $this->connection->rollback();
            }
            throw $error;
        }
    }

    /**
     * Consume one verification token and activate its account.
     *
     * @return array{customer_id:int,credential_version:int}|null
     */
    public function confirm(mixed $token, string $sourceAddress): ?array
    {
        if (!$this->mailFlowsEnabled()) {
            return null;
        }
        $this->cleanupExpiredState();
        if (!$this->withinLimit('verify_complete_source', VerificationIdentifier::sourceAddress($sourceAddress), self::CONFIRM_SOURCE_LIMIT)
            || !is_string($token) || preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1
            || !$this->withinLimit('verify_complete_token', VerificationIdentifier::token($token), self::CONFIRM_TOKEN_LIMIT)) {
            return null;
        }

        $this->connection->begin_transaction();
        $transactionOpen = true;
        try {
            $verification = $this->repository->findValidToken($token);
            if ($verification === null) {
                $this->connection->rollback();
                $transactionOpen = false;
                return null;
            }
            $customerId = (int) $verification['customer_id'];
            if (!$this->repository->consume((int) $verification['email_verification_id'])
                || !$this->repository->activateAccount($customerId)) {
                $this->connection->rollback();
                $transactionOpen = false;
                return null;
            }
            $account = $this->repository->findActiveAccountById($customerId);
            if ($account === null) {
                $this->connection->rollback();
                $transactionOpen = false;
                return null;
            }
            $this->connection->commit();
            $transactionOpen = false;
            return ['customer_id' => $customerId, 'credential_version' => (int) $account['credential_version']];
        } catch (\Throwable $error) {
            if ($transactionOpen) {
                $this->connection->rollback();
            }
            throw $error;
        }
    }

    private function issueLocked(int $customerId, string $email): void
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $verificationId = $this->repository->issueForUnverifiedCustomer(
            $customerId,
            $token,
            $now->modify('+24 hours'),
        );
        if ($verificationId === null) {
            return;
        }
        $this->mailer->queueVerification($verificationId, $customerId, $email, $token);
    }

    private function cleanupExpiredState(): void
    {
        $this->repository->invalidateExpiredTokens(25);
        $this->repository->deleteExpiredRateLimitBuckets(25);
    }

    private function withinLimit(string $purpose, string $fingerprint, int $limit): bool
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $window = $now->setTime((int) $now->format('H'), 0, 0);
        $count = $this->repository->recordRateLimitAttempt($purpose, $fingerprint, $window, $window->modify('+2 hours'));
        return $count <= $limit;
    }

    /**
     * Verification mail flows run wherever mail delivery is switched on:
     * locally, and on a hosted deployment with in-request Resend dispatch.
     */
    private function mailFlowsEnabled(): bool
    {
        return (getenv('APP_ENV') ?: '') === 'local' || getenv('EMAIL_AUTO_QUEUE') === '1';
    }
}
