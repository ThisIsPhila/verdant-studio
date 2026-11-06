<?php

declare(strict_types=1);

namespace Verdant\Identity;

use Verdant\Persistence\Repository;

final class CustomerRepository extends Repository
{
    public function createCustomer(string $email, string $passwordHash, string $name, ?string $phone = null, string $accountState = 'active'): int
    {
        if (!in_array($accountState, ['active', 'unverified'], true)) {
            throw new \InvalidArgumentException('Account state must be active or unverified.');
        }
        $this->execute(
            "INSERT INTO customers (email, password_hash, name, phone, account_state)
             VALUES (?, ?, ?, ?, ?)",
            [strtolower(trim($email)), $passwordHash, $name, $phone, $accountState],
        );
        return (int) $this->connection->insert_id;
    }

    // Only Identity consumes this result; the password hash never belongs in a view.
    public function findForAuthentication(string $email): ?array
    {
        return $this->select(
            'SELECT customer_id, email, password_hash, name, role, account_state, credential_version
             FROM customers WHERE email = ? AND account_state = ?',
            [strtolower(trim($email)), 'active'],
        )[0] ?? null;
    }

    // Only Identity consumes this result; it distinguishes unverified credentials for messaging.
    public function findUnverifiedForAuthentication(string $email): ?array
    {
        return $this->select(
            'SELECT customer_id, email, password_hash, name, role, account_state, credential_version
             FROM customers WHERE email = ? AND account_state = ?',
            [strtolower(trim($email)), 'unverified'],
        )[0] ?? null;
    }

    /** Return the minimum actor fields for an active account. */
    public function findActiveById(int $customerId): ?array
    {
        if ($customerId < 1) {
            return null;
        }

        $actor = $this->select(
            'SELECT customer_id, email, name, role, credential_version
             FROM customers WHERE customer_id = ? AND account_state = ?',
            [$customerId, 'active'],
        )[0] ?? null;
        if ($actor !== null) {
            $actor['customer_id'] = (int) $actor['customer_id'];
            $actor['credential_version'] = (int) $actor['credential_version'];
        }
        return $actor;
    }
}
