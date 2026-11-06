<?php

declare(strict_types=1);

namespace Verdant\Identity;

use DateTimeImmutable;
use DateTimeZone;
use Verdant\Persistence\Repository;

/** Persistence for one-time recovery tokens and short-lived request counters. */
final class PasswordRecoveryRepository extends Repository
{
    /** Lock only the active role that belongs to the requested local surface. */
    public function findEligibleAccount(string $email, string $surface): ?array
    {
        if (!in_array($surface, ['store', 'admin'], true)) {
            return null;
        }
        $role = $surface === 'admin' ? 'admin' : 'customer';
        return $this->select(
            "SELECT customer_id, email, role FROM customers
             WHERE LOWER(email) = ? AND role = ? AND account_state = 'active' FOR UPDATE",
            [strtolower(trim($email)), $role],
        )[0] ?? null;
    }

    /** @return array{customer_id:string,email:string,role:string}|null */
    public function findActiveAccountById(int $customerId): ?array
    {
        if ($customerId < 1) {
            return null;
        }
        return $this->select(
            "SELECT customer_id, email, role FROM customers
             WHERE customer_id = ? AND account_state = 'active' FOR UPDATE",
            [$customerId],
        )[0] ?? null;
    }

    public function replacePasswordHash(int $customerId, string $passwordHash): bool
    {
        if ($customerId < 1 || $passwordHash === '' || strlen($passwordHash) > 255) {
            return false;
        }
        return $this->execute(
            "UPDATE customers SET password_hash = ?, credential_version = credential_version + 1,
             updated_at = UTC_TIMESTAMP()
             WHERE customer_id = ? AND account_state = 'active'",
            [$passwordHash, $customerId],
        ) === 1;
    }

    /**
     * Caller owns the transaction so token issuance can commit atomically with
     * its matching email-outbox row. Returns null for an inactive/missing account.
     */
    public function issueForActiveCustomer(
        int $customerId,
        string $surface,
        string $token,
        DateTimeImmutable $expiresAt,
    ): ?int {
        if ($customerId < 1 || !in_array($surface, ['store', 'admin'], true)
            || preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
            throw new \InvalidArgumentException('Invalid recovery token request.');
        }

        $customer = $this->select(
            "SELECT customer_id FROM customers
             WHERE customer_id = ? AND account_state = 'active' FOR UPDATE",
            [$customerId],
        )[0] ?? null;
        if ($customer === null) {
            return null;
        }

        $this->execute(
            'UPDATE password_reset_tokens
             SET revoked_at = UTC_TIMESTAMP()
             WHERE customer_id = ? AND consumed_at IS NULL AND revoked_at IS NULL',
            [$customerId],
        );

        $this->execute(
            'INSERT INTO password_reset_tokens (customer_id, token_hash, requesting_surface, expires_at)
             VALUES (?, ?, ?, ?)',
            [$customerId, hash('sha256', $token, true), $surface, self::utc($expiresAt)],
        );
        return (int) $this->connection->insert_id;
    }

    /** Caller should hold the transaction through validation and password update. */
    public function findValidToken(string $token, string $surface): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1
            || !in_array($surface, ['store', 'admin'], true)) {
            return null;
        }

        return $this->select(
            "SELECT t.password_reset_id, t.customer_id, t.requesting_surface, t.expires_at
             FROM password_reset_tokens t
             INNER JOIN customers c ON c.customer_id = t.customer_id
             WHERE t.token_hash = ? AND t.requesting_surface = ?
               AND t.consumed_at IS NULL AND t.revoked_at IS NULL
               AND t.expires_at > UTC_TIMESTAMP() AND c.account_state = 'active'
             FOR UPDATE",
            [hash('sha256', $token, true), $surface],
        )[0] ?? null;
    }

    /** Conditional update gives concurrent reset submissions one winner. */
    public function consume(int $passwordResetId): bool
    {
        if ($passwordResetId < 1) {
            return false;
        }

        return $this->execute(
            'UPDATE password_reset_tokens
             SET consumed_at = UTC_TIMESTAMP()
             WHERE password_reset_id = ? AND consumed_at IS NULL AND revoked_at IS NULL
               AND expires_at > UTC_TIMESTAMP()',
            [$passwordResetId],
        ) === 1;
    }

    public function revokeForCustomer(int $customerId): int
    {
        if ($customerId < 1) {
            return 0;
        }

        return $this->execute(
            'UPDATE password_reset_tokens
             SET revoked_at = UTC_TIMESTAMP()
             WHERE customer_id = ? AND consumed_at IS NULL AND revoked_at IS NULL',
            [$customerId],
        );
    }

    /** Increment one fixed-window counter atomically and return its new value. */
    public function recordRateLimitAttempt(
        string $purpose,
        string $subjectFingerprint,
        DateTimeImmutable $windowStartedAt,
        DateTimeImmutable $expiresAt,
    ): int {
        $purposes = ['request_email', 'request_source', 'complete_source', 'complete_token'];
        if (!in_array($purpose, $purposes, true) || strlen($subjectFingerprint) !== 32) {
            throw new \InvalidArgumentException('Invalid recovery rate-limit bucket.');
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
            throw new \RuntimeException('Recovery rate-limit bucket was not persisted.');
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
            'UPDATE password_reset_tokens SET revoked_at = UTC_TIMESTAMP()
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
