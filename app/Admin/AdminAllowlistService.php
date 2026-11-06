<?php

declare(strict_types=1);

namespace Verdant\Admin;

use Verdant\Support\ValidationException;

/** Administrator access allowlist: role plus listing are both required. */
final class AdminAllowlistService
{
    public function __construct(
        private readonly AdminAllowlistRepository $allowlist,
        private readonly AdminAccess $access,
    ) {
    }

    public function isAllowed(string $email): bool
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '' || strlen($normalized) > 254) {
            return false;
        }
        return $this->allowlist->isAllowed($normalized);
    }

    /** @return list<array{email:string,added_by:string|null,created_at:string}> */
    public function entries(array $actor): array
    {
        $this->access->require($actor);
        return $this->allowlist->all();
    }

    public function add(array $actor, mixed $email): string
    {
        $this->access->require($actor);
        $normalized = is_string($email) ? strtolower(trim($email)) : '';
        if ($normalized === '' || strlen($normalized) > 254 || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException(['email' => 'Enter a valid email address.']);
        }
        if (!$this->allowlist->isActiveAdministrator($normalized)) {
            throw new ValidationException(['email' => 'Only current administrator accounts can join the allowlist.']);
        }
        $this->allowlist->add($normalized, (int) $actor['customer_id']);
        return $normalized;
    }

    public function remove(array $actor, mixed $email): string
    {
        $this->access->require($actor);
        $normalized = is_string($email) ? strtolower(trim($email)) : '';
        if ($normalized === '' || strlen($normalized) > 254 || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException(['email' => 'Enter a valid email address.']);
        }
        if ($normalized === strtolower(trim((string) ($actor['email'] ?? '')))) {
            throw new ValidationException(['email' => 'Ask a fellow administrator to remove your own access.']);
        }
        if ($this->allowlist->count() < 2 && $this->allowlist->isAllowed($normalized)) {
            throw new ValidationException(['email' => 'The allowlist must keep at least one administrator.']);
        }
        $this->allowlist->remove($normalized);
        return $normalized;
    }
}
