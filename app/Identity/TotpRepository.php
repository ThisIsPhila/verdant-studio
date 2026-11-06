<?php

declare(strict_types=1);

namespace Verdant\Identity;

use DateTimeImmutable;
use DateTimeZone;
use Verdant\Persistence\Repository;

/** Persistence for admin TOTP credentials, backup codes, and short-lived attempt counters. */
final class TotpRepository extends Repository
{
    /** @return array{secret_sealed:string,verified_at:?string}|null */
    public function findCredential(int $customerId, bool $lock = false): ?array
    {
        if ($customerId < 1) {
            return null;
        }
        return $this->select(
            'SELECT secret_sealed, verified_at FROM admin_totp_credentials
             WHERE customer_id = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$customerId],
        )[0] ?? null;
    }

    /** @return array{customer_id:string,email:string}|null No password material leaves this query. */
    public function findActiveAdminByEmail(string $email): ?array
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '' || strlen($normalized) > 254) {
            return null;
        }
        return $this->select(
            "SELECT customer_id, email FROM customers
             WHERE LOWER(email) = ? AND role = 'admin' AND account_state = 'active'",
            [$normalized],
        )[0] ?? null;
    }

    /** Caller must hold the row lock and confirm no verified credential exists. */
    public function upsertPendingSecret(int $customerId, string $sealedSecret): void
    {
        if ($customerId < 1 || $sealedSecret === '' || strlen($sealedSecret) > 255) {
            throw new \InvalidArgumentException('Invalid TOTP credential.');
        }
        $this->execute(
            'INSERT INTO admin_totp_credentials (customer_id, secret_sealed) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE secret_sealed = VALUES(secret_sealed)',
            [$customerId, $sealedSecret],
        );
    }

    public function markVerified(int $customerId): bool
    {
        if ($customerId < 1) {
            return false;
        }
        return $this->execute(
            'UPDATE admin_totp_credentials SET verified_at = UTC_TIMESTAMP()
             WHERE customer_id = ? AND verified_at IS NULL',
            [$customerId],
        ) === 1;
    }

    /**
     * Destroy the current secret in place and force re-enrolment without
     * requiring row deletion privileges. False when no credential exists.
     */
    public function replaceSecret(int $customerId, string $sealedSecret): bool
    {
        if ($customerId < 1 || $sealedSecret === '' || strlen($sealedSecret) > 255) {
            return false;
        }
        return $this->execute(
            'UPDATE admin_totp_credentials
             SET secret_sealed = ?, verified_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE customer_id = ?',
            [$sealedSecret, $customerId],
        ) === 1;
    }

    public function consumeAllBackupCodes(int $customerId): int
    {
        if ($customerId < 1) {
            return 0;
        }
        return $this->execute(
            'UPDATE admin_totp_backup_codes SET consumed_at = UTC_TIMESTAMP()
             WHERE customer_id = ? AND consumed_at IS NULL',
            [$customerId],
        );
    }

    public function insertBackupCode(int $customerId, string $codeHash): int
    {
        if ($customerId < 1 || $codeHash === '' || strlen($codeHash) > 255) {
            throw new \InvalidArgumentException('Invalid backup code.');
        }
        $this->execute(
            'INSERT INTO admin_totp_backup_codes (customer_id, code_hash) VALUES (?, ?)',
            [$customerId, $codeHash],
        );
        return (int) $this->connection->insert_id;
    }

    /** @return list<array{backup_code_id:string,code_hash:string}> */
    public function unconsumedBackupCodes(int $customerId): array
    {
        if ($customerId < 1) {
            return [];
        }
        return $this->select(
            'SELECT backup_code_id, code_hash FROM admin_totp_backup_codes
             WHERE customer_id = ? AND consumed_at IS NULL ORDER BY backup_code_id',
            [$customerId],
        );
    }

    /** Conditional update gives concurrent backup-code submissions one winner. */
    public function consumeBackupCode(int $backupCodeId): bool
    {
        if ($backupCodeId < 1) {
            return false;
        }
        return $this->execute(
            'UPDATE admin_totp_backup_codes SET consumed_at = UTC_TIMESTAMP()
             WHERE backup_code_id = ? AND consumed_at IS NULL',
            [$backupCodeId],
        ) === 1;
    }

    /** Increment one fixed-window counter atomically and return its new value. */
    public function recordRateLimitAttempt(
        string $purpose,
        string $subjectFingerprint,
        DateTimeImmutable $windowStartedAt,
        DateTimeImmutable $expiresAt,
    ): int {
        $purposes = ['totp_account', 'totp_source'];
        if (!in_array($purpose, $purposes, true) || strlen($subjectFingerprint) !== 32) {
            throw new \InvalidArgumentException('Invalid TOTP rate-limit bucket.');
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
            throw new \RuntimeException('TOTP rate-limit bucket was not persisted.');
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

    private static function utc(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
