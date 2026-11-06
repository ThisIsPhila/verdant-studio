<?php

declare(strict_types=1);

namespace Verdant\Identity;

use DateTimeImmutable;
use DateTimeZone;
use mysqli;
use Verdant\Support\PrivateTokenCipher;

/**
 * Enrolment, challenge, backup-code, and reset orchestration for admin TOTP.
 * This control fails closed and never depends on the environment: the
 * cryptography works identically everywhere the application runs.
 */
final class TotpService
{
    public const BACKUP_CODE_COUNT = 10;
    private const ACCOUNT_LIMIT = 10;
    private const SOURCE_LIMIT = 30;

    public function __construct(
        private readonly mysqli $connection,
        private readonly TotpRepository $repository,
    ) {
    }

    public function needsSetup(int $customerId): bool
    {
        if ($customerId < 1) {
            return true;
        }
        $credential = $this->repository->findCredential($customerId);
        return $credential === null || ($credential['verified_at'] ?? null) === null;
    }

    /**
     * Start or resume enrolment. Reuses the pending secret so a failed
     * confirmation never rotates the secret already scanned into the app.
     *
     * @return array{secret:string,uri:string}|null Null when already enrolled.
     */
    public function beginSetup(int $customerId, string $email): ?array
    {
        if ($customerId < 1) {
            return null;
        }
        $this->connection->begin_transaction();
        $transactionOpen = true;
        try {
            $existing = $this->repository->findCredential($customerId, true);
            if ($existing !== null && ($existing['verified_at'] ?? null) !== null) {
                $this->connection->commit();
                $transactionOpen = false;
                return null;
            }
            if ($existing !== null) {
                $secret = PrivateTokenCipher::openOpaque((string) $existing['secret_sealed']);
                $this->connection->commit();
                $transactionOpen = false;
                if (strlen($secret) === Totp::SECRET_BYTES) {
                    return ['secret' => Totp::base32Encode($secret), 'uri' => Totp::provisioningUri($email, $secret)];
                }
                return $this->beginSetupFresh($customerId, $email);
            }
            $secret = Totp::generateSecret();
            $this->repository->upsertPendingSecret($customerId, PrivateTokenCipher::sealOpaque($secret));
            $this->connection->commit();
            $transactionOpen = false;
            return ['secret' => Totp::base32Encode($secret), 'uri' => Totp::provisioningUri($email, $secret)];
        } catch (\Throwable $error) {
            if ($transactionOpen) {
                $this->connection->rollback();
            }
            throw $error;
        }
    }

    /** @return array{customer_id:int,email:string}|null */
    public function resettableAdmin(string $email, int $requesterId): ?array
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '' || strlen($normalized) > 254 || $requesterId < 1) {
            return null;
        }
        $account = $this->repository->findActiveAdminByEmail($normalized);
        if ($account === null || (int) $account['customer_id'] === $requesterId) {
            return null;
        }
        return ['customer_id' => (int) $account['customer_id'], 'email' => (string) $account['email']];
    }

    /** Confirm enrolment with a first valid code; returns fresh plaintext backup codes. */
    public function confirmSetup(mixed $code, int $customerId, string $sourceAddress): ?array
    {
        if ($customerId < 1 || !is_string($code)) {
            return null;
        }
        $this->cleanupExpiredState();
        if (!$this->withinLimit('totp_source', TotpIdentifier::sourceAddress($sourceAddress), self::SOURCE_LIMIT)
            || !$this->withinLimit('totp_account', TotpIdentifier::account($customerId), self::ACCOUNT_LIMIT)) {
            return null;
        }

        $this->connection->begin_transaction();
        $transactionOpen = true;
        try {
            $credential = $this->repository->findCredential($customerId, true);
            if ($credential === null || ($credential['verified_at'] ?? null) !== null) {
                $this->connection->rollback();
                $transactionOpen = false;
                return null;
            }
            $secret = PrivateTokenCipher::openOpaque((string) $credential['secret_sealed']);
            if (strlen($secret) !== Totp::SECRET_BYTES || !Totp::verify($secret, trim($code))) {
                $this->connection->rollback();
                $transactionOpen = false;
                return null;
            }
            if (!$this->repository->markVerified($customerId)) {
                $this->connection->rollback();
                $transactionOpen = false;
                return null;
            }
            $codes = $this->issueBackupCodes($customerId);
            $this->connection->commit();
            $transactionOpen = false;
            return $codes;
        } catch (\Throwable $error) {
            if ($transactionOpen) {
                $this->connection->rollback();
            }
            throw $error;
        }
    }

    /** Accept a current TOTP code or one unused backup code for a verified credential. */
    public function challenge(mixed $code, int $customerId, string $sourceAddress): bool
    {
        if ($customerId < 1 || !is_string($code)) {
            return false;
        }
        $this->cleanupExpiredState();
        if (!$this->withinLimit('totp_source', TotpIdentifier::sourceAddress($sourceAddress), self::SOURCE_LIMIT)
            || !$this->withinLimit('totp_account', TotpIdentifier::account($customerId), self::ACCOUNT_LIMIT)) {
            return false;
        }

        $credential = $this->repository->findCredential($customerId);
        if ($credential === null || ($credential['verified_at'] ?? null) === null) {
            return false;
        }
        $presented = trim($code);
        $secret = PrivateTokenCipher::openOpaque((string) $credential['secret_sealed']);
        if (strlen($secret) === Totp::SECRET_BYTES && Totp::verify($secret, $presented)) {
            return true;
        }
        if (!Totp::isBackupCodeFormat($presented)) {
            return false;
        }
        $normalized = Totp::normalizeBackupCode($presented);
        foreach ($this->repository->unconsumedBackupCodes($customerId) as $stored) {
            if (password_verify($normalized, (string) $stored['code_hash'])
                && $this->repository->consumeBackupCode((int) $stored['backup_code_id'])) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> Fresh plaintext backup codes for a verified credential. */
    public function regenerateBackupCodes(int $customerId): ?array
    {
        if ($customerId < 1) {
            return null;
        }
        $this->connection->begin_transaction();
        $transactionOpen = true;
        try {
            $credential = $this->repository->findCredential($customerId, true);
            if ($credential === null || ($credential['verified_at'] ?? null) === null) {
                $this->connection->rollback();
                $transactionOpen = false;
                return null;
            }
            $codes = $this->issueBackupCodes($customerId);
            $this->connection->commit();
            $transactionOpen = false;
            return $codes;
        } catch (\Throwable $error) {
            if ($transactionOpen) {
                $this->connection->rollback();
            }
            throw $error;
        }
    }

    public function remainingBackupCodes(int $customerId): int
    {
        return count($this->repository->unconsumedBackupCodes($customerId));
    }

    /** Destroy the credential in place and consume every backup code. */
    public function resetForAdmin(int $customerId): bool
    {
        if ($customerId < 1) {
            return false;
        }
        $this->connection->begin_transaction();
        $transactionOpen = true;
        try {
            $replaced = $this->repository->replaceSecret($customerId, PrivateTokenCipher::sealOpaque(Totp::generateSecret()));
            $this->repository->consumeAllBackupCodes($customerId);
            $this->connection->commit();
            $transactionOpen = false;
            return $replaced;
        } catch (\Throwable $error) {
            if ($transactionOpen) {
                $this->connection->rollback();
            }
            throw $error;
        }
    }

    /** @return array{secret:string,uri:string} */
    private function beginSetupFresh(int $customerId, string $email): array
    {
        $this->connection->begin_transaction();
        $transactionOpen = true;
        try {
            $secret = Totp::generateSecret();
            $this->repository->upsertPendingSecret($customerId, PrivateTokenCipher::sealOpaque($secret));
            $this->connection->commit();
            $transactionOpen = false;
            return ['secret' => Totp::base32Encode($secret), 'uri' => Totp::provisioningUri($email, $secret)];
        } catch (\Throwable $error) {
            if ($transactionOpen) {
                $this->connection->rollback();
            }
            throw $error;
        }
    }

    /** @return list<string> */
    private function issueBackupCodes(int $customerId): array
    {
        $this->repository->consumeAllBackupCodes($customerId);
        $codes = [];
        for ($index = 0; $index < self::BACKUP_CODE_COUNT; ++$index) {
            $code = Totp::generateBackupCode();
            $hash = password_hash($code, PASSWORD_DEFAULT);
            if (!is_string($hash)) {
                throw new \RuntimeException('Backup code could not be secured.');
            }
            $this->repository->insertBackupCode($customerId, $hash);
            $codes[] = $code;
        }
        return $codes;
    }

    private function cleanupExpiredState(): void
    {
        $this->repository->deleteExpiredRateLimitBuckets(25);
    }

    private function withinLimit(string $purpose, string $fingerprint, int $limit): bool
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $window = $now->setTime((int) $now->format('H'), 0, 0);
        $count = $this->repository->recordRateLimitAttempt($purpose, $fingerprint, $window, $window->modify('+2 hours'));
        return $count <= $limit;
    }
}
