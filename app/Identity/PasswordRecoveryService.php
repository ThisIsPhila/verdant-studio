<?php

declare(strict_types=1);

namespace Verdant\Identity;

use DateTimeImmutable;
use DateTimeZone;
use mysqli;
use Verdant\Notifications\PasswordRecoveryMailer;
use Verdant\Support\Mail\EmailConfig;

/** Coordinates one-time local account recovery without exposing account state. */
final class PasswordRecoveryService
{
    private const REQUEST_EMAIL_LIMIT = 5;
    private const REQUEST_SOURCE_LIMIT = 10;
    private const COMPLETE_TOKEN_LIMIT = 5;
    private const COMPLETE_SOURCE_LIMIT = 20;

    public function __construct(
        private readonly mysqli $connection,
        private readonly PasswordRecoveryRepository $repository,
        private readonly PasswordRecoveryMailer $mailer,
    ) {
    }

    /** Every caller receives the same outcome; only permitted identities can queue mail. */
    public function request(mixed $email, string $surface, string $sourceAddress): void
    {
        if (!$this->mailSurface($surface)) {
            return;
        }
        $this->cleanupExpiredState();
        if (!$this->withinLimit('request_source', RecoveryIdentifier::sourceAddress($sourceAddress), self::REQUEST_SOURCE_LIMIT)) {
            return;
        }

        $normalized = is_string($email) ? strtolower(trim($email)) : '';
        if ($normalized === '' || strlen($normalized) > 254 || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }
        $permitted = in_array($normalized, EmailConfig::TEST_RECIPIENTS, true)
            || getenv('EMAIL_OPEN_DELIVERY') === '1';
        if (!$this->withinLimit('request_email', RecoveryIdentifier::email($normalized), self::REQUEST_EMAIL_LIMIT)
            || !$permitted) {
            return;
        }

        $this->connection->begin_transaction();
        $transactionOpen = true;
        try {
            $account = $this->repository->findEligibleAccount($normalized, $surface);
            if ($account === null) {
                $this->connection->commit();
                $transactionOpen = false;
                return;
            }
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $resetId = $this->repository->issueForActiveCustomer(
                (int) $account['customer_id'],
                $surface,
                $token,
                $now->modify('+30 minutes'),
            );
            if ($resetId === null) {
                $this->connection->commit();
                $transactionOpen = false;
                return;
            }
            $this->mailer->queueReset(
                $resetId,
                (int) $account['customer_id'],
                (string) $account['email'],
                (string) $account['role'],
                $surface,
                $token,
            );
            $this->connection->commit();
            $transactionOpen = false;
        } catch (\Throwable $error) {
            if ($transactionOpen) {
                $this->connection->rollback();
            }
            throw $error;
        }
    }

    /** Non-consuming check used after the email fragment is handed to the page. */
    public function tokenIsValid(mixed $token, string $surface, string $sourceAddress): bool
    {
        if (!$this->mailSurface($surface)) {
            return false;
        }
        $this->cleanupExpiredState();
        if (!$this->withinLimit('complete_source', RecoveryIdentifier::sourceAddress($sourceAddress), self::COMPLETE_SOURCE_LIMIT)
            || !is_string($token) || preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
            return false;
        }
        return $this->repository->findValidToken($token, $surface) !== null;
    }

    /** @return array{status:'success'|'invalid'|'password_error',errors?:array<string,string>} */
    public function complete(
        mixed $token,
        string $surface,
        string $sourceAddress,
        mixed $password,
        mixed $confirmation,
    ): array {
        if (!$this->mailSurface($surface)) {
            return ['status' => 'invalid'];
        }
        $this->cleanupExpiredState();
        if (!$this->withinLimit('complete_source', RecoveryIdentifier::sourceAddress($sourceAddress), self::COMPLETE_SOURCE_LIMIT)
            || !is_string($token) || preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1
            || !$this->withinLimit('complete_token', RecoveryIdentifier::token($token), self::COMPLETE_TOKEN_LIMIT)) {
            return ['status' => 'invalid'];
        }

        $this->connection->begin_transaction();
        $transactionOpen = true;
        try {
            $reset = $this->repository->findValidToken($token, $surface);
            if ($reset === null) {
                $this->connection->rollback();
                $transactionOpen = false;
                return ['status' => 'invalid'];
            }
            $errors = PasswordPolicy::errors($password, $confirmation);
            if ($errors !== []) {
                $this->connection->rollback();
                $transactionOpen = false;
                return ['status' => 'password_error', 'errors' => $errors];
            }
            $account = $this->repository->findActiveAccountById((int) $reset['customer_id']);
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if ($account === null || !is_string($hash)
                || !$this->repository->consume((int) $reset['password_reset_id'])
                || !$this->repository->replacePasswordHash((int) $reset['customer_id'], $hash)) {
                $this->connection->rollback();
                $transactionOpen = false;
                return ['status' => 'invalid'];
            }
            $this->mailer->queuePasswordChanged(
                (int) $reset['password_reset_id'],
                (int) $account['customer_id'],
                (string) $account['email'],
                (string) $account['role'],
            );
            $this->connection->commit();
            $transactionOpen = false;
            return ['status' => 'success'];
        } catch (\Throwable $error) {
            if ($transactionOpen) {
                $this->connection->rollback();
            }
            throw $error;
        }
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
     * Recovery mail flows run on both surfaces wherever mail delivery is
     * switched on: locally, and hosted with in-request Resend dispatch.
     */
    private function mailSurface(string $surface): bool
    {
        $mailEnabled = (getenv('APP_ENV') ?: '') === 'local' || getenv('EMAIL_AUTO_QUEUE') === '1';
        return $mailEnabled && in_array($surface, ['store', 'admin'], true);
    }
}
