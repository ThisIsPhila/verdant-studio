<?php

declare(strict_types=1);

namespace Verdant\Identity;

use DateTimeImmutable;
use DateTimeZone;
use Verdant\Persistence\Repository;

/** Persistence for one-time signup verification tokens and short-lived request counters. */
final class VerificationRepository extends Repository
{
    /** @return array{customer_id:string,credential_version:string}|null */
    public function findActiveAccountById(int $customerId): ?array
    {
        if ($customerId < 1) {
            return null;
        }
        return $this->select(
            "SELECT customer_id, credential_version FROM customers
             WHERE customer_id = ? AND account_state = 'active' FOR UPDATE",
            [$customerId],
        )[0] ?? null;
    }

    /** Lock only the unverified customer the store verification flow may activate. */
    public function findUnverifiedAccount(string $email): ?array
    {
        return $this->select(
            "SELECT customer_id, email, role FROM customers
             WHERE LOWER(email) = ? AND role = 'customer' AND account_state = 'unverified' FOR UPDATE",
            [strtolower(trim($email))],
        )[0] ?? null;
    }

    /**
     * Caller owns the transaction so token issuance can commit atomically with
     * its matching email-outbox row. Returns null for a missing/already-verified account.
     */
    public function issueForUnverifiedCustomer(
        int $customerId,
        string $token,
        DateTimeImmutable $expiresAt,
    ): ?int {
        if ($customerId < 1 || preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
            throw new \InvalidArgumentException('Invalid verification token request.');
        }

        $customer = $this->select(
            "SELECT customer_id FROM customers
             WHERE customer_id = ? AND role = 'customer' AND account_state = 'unverified' FOR UPDATE",
            [$customerId],
        )[0] ?? null;
        if ($customer === null) {
            return null;
        }

        $this->execute(
            'UPDATE email_verification_tokens
             SET revoked_at = UTC_TIMESTAMP()
             WHERE customer_id = ? AND consumed_at IS NULL AND revoked_at IS NULL',
            [$customerId],
        );

        $this->execute(
            "INSERT INTO email_verification_tokens (customer_id, token_hash, requesting_surface, expires_at)
             VALUES (?, ?, 'store', ?)",
            [$customerId, hash('sha256', $token, true), self::utc($expiresAt)],
        );
        return (int) $this->connection->insert_id;
    }

    /** Caller should hold the transaction through validation and activation. */
    public function findValidToken(string $token): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
            return null;
        }

        return $this->select(
            "SELECT t.email_verification_id, t.customer_id, t.requesting_surface, t.expires_at
             FROM email_verification_tokens t
             INNER JOIN customers c ON c.customer_id = t.customer_id
             WHERE t.token_hash = ?
               AND t.consumed_at IS NULL AND t.revoked_at IS NULL
               AND t.expires_at > UTC_TIMESTAMP() AND c.account_state = 'unverified'
             FOR UPDATE",
            [hash('sha256', $token, true)],
        )[0] ?? null;
    }

    /** Flip one unverified account to active; concurrent confirmations yield one winner. */
    public function activateAccount(int $customerId): bool
    {
        if ($customerId < 1) {
            return false;
        }

        return $this->execute(
            "UPDATE customers SET account_state = 'active', updated_at = UTC_TIMESTAMP()
             WHERE customer_id = ? AND role = 'customer' AND account_state = 'unverified'",
            [$customerId],
        ) === 1;
    }

    /** Conditional update gives concurrent verification submissions one winner. */
    public function consume(int $emailVerificationId): bool
    {
        if ($emailVerificationId < 1) {
            return false;
        }

        return $this->execute(
            'UPDATE email_verification_tokens
             SET consumed_at = UTC_TIMESTAMP()
             WHERE email_verification_id = ? AND consumed_at IS NULL AND revoked_at IS NULL
               AND expires_at > UTC_TIMESTAMP()',
            [$emailVerificationId],
        ) === 1;
    }

    /** Increment one fixed-window counter atomically and return its new value. */
    public function recordRateLimitAttempt(
        string $purpose,
        string $subjectFingerprint,
        DateTimeImmutable $windowStartedAt,
        DateTimeImmutable $expiresAt,
    ): int {
        $purposes = ['verify_email', 'verify_source', 'verify_complete_source', 'verify_complete_token'];
        if (!in_array($purpose, $purposes, true) || strlen($subjectFingerprint) !== 32) {
            throw new \InvalidArgumentException('Invalid verification rate-limit bucket.');
        }

        $window = self::utc($windowStartedAt);
        $this->execute(
            'INSERT INTO auth_recovery_rate_limits
                (purpose, subject_fingerprint, window_started_at, attempt_count, expires_at)
             VALUES (?, ?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE attempt_count = LEAST(attempt_count + 1, 65535),
                expires_at = GREATEST(expires_at, VALUES(expires_at))',
            [$purpose, $subjectFingerprint, $window, self::utc($expiresAt)],
        );

        $row = $this->select(
            'SELECT attempt_count FROM auth_recovery_rate_limits
             WHERE purpose = ? AND subject_fingerprint = ? AND window_started_at = ?',
            [$purpose, $subjectFingerprint, $window],
        )[0] ?? null;
        if ($row === null) {
            throw new \RuntimeException('Verification rate-limit bucket was not persisted.');
        }
        return (int) $row['attempt_count'];
    }

    /** Remove expired rate-limit rows without an unbounded table cleanup. */
    public function deleteExpiredRateLimitBuckets(int $limit = 250): int
    {
        $limit = max(1, min($limit, 500));
        return $this->execute(
            'DELETE FROM auth_recovery_rate_limits
             WHERE expires_at <= UTC_TIMESTAMP()
             ORDER BY expires_at
             LIMIT ' . $limit,
        );
    }

    /** Mark expired tokens unusable in bounded batches while retaining outbox history. */
    public function invalidateExpiredTokens(int $limit = 250): int
    {
        $limit = max(1, min($limit, 500));
        return $this->execute(
            'UPDATE email_verification_tokens SET revoked_at = UTC_TIMESTAMP()
             WHERE consumed_at IS NULL AND revoked_at IS NULL AND expires_at <= UTC_TIMESTAMP()
             ORDER BY expires_at
             LIMIT ' . $limit,
        );
    }

    private static function utc(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
