<?php

declare(strict_types=1);

namespace Verdant\Admin;

use Verdant\Persistence\Repository;

/** Persistence for the administrator access allowlist. */
final class AdminAllowlistRepository extends Repository
{
    public function isAllowed(string $email): bool
    {
        return $this->select(
            'SELECT email FROM admin_allowlist WHERE email = ?',
            [strtolower(trim($email))],
        ) !== [];
    }

    /** @return list<array{email:string,added_by:string|null,created_at:string}> */
    public function all(): array
    {
        return $this->select(
            'SELECT a.email, c.email AS added_by, a.created_at
             FROM admin_allowlist a LEFT JOIN customers c ON c.customer_id = a.added_by_customer_id
             ORDER BY a.email',
        );
    }

    public function count(): int
    {
        return (int) ($this->select('SELECT COUNT(*) AS total FROM admin_allowlist')[0]['total'] ?? 0);
    }

    public function add(string $email, int $addedByCustomerId): void
    {
        $this->execute(
            'INSERT INTO admin_allowlist (email, added_by_customer_id) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE added_by_customer_id = VALUES(added_by_customer_id)',
            [strtolower(trim($email)), $addedByCustomerId],
        );
    }

    public function remove(string $email): bool
    {
        return $this->execute('DELETE FROM admin_allowlist WHERE email = ?', [strtolower(trim($email))]) > 0;
    }

    /** The allowlist only admits current administrator accounts; it never promotes anyone. */
    public function isActiveAdministrator(string $email): bool
    {
        return $this->select(
            "SELECT customer_id FROM customers WHERE LOWER(email) = ? AND account_state = 'active' AND role = 'admin'",
            [strtolower(trim($email))],
        ) !== [];
    }
}
