<?php

declare(strict_types=1);

namespace Verdant\Admin;

final class AdminAccess
{
    public function __construct(private readonly \mysqli $connection)
    {
    }

    /** Resolve the role and allowlist entry from current database state on every service boundary. */
    public function require(array $actor, bool $lock = false): void
    {
        $id = $actor['customer_id'] ?? null;
        if (!is_int($id) || $id < 1) {
            throw new \DomainException('Administrator access required.');
        }
        $statement = $this->connection->prepare(
            "SELECT c.customer_id FROM customers c
             WHERE c.customer_id = ? AND c.account_state = 'active' AND c.role = 'admin'
               AND EXISTS (SELECT 1 FROM admin_allowlist a WHERE a.email = LOWER(c.email))"
            . ($lock ? ' FOR UPDATE' : ''),
        );
        try {
            $statement->execute([$id]);
            if ($statement->get_result()->num_rows !== 1) {
                throw new \DomainException('Administrator access required.');
            }
        } finally {
            $statement->close();
        }
    }
}
