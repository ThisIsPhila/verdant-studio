<?php

declare(strict_types=1);

namespace Verdant\Identity;

use Verdant\Persistence\DatabaseException;
use Verdant\Support\ValidationException;

final class IdentityService
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly ?VerificationService $verification = null,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function register(array $input): int
    {
        $errors = [];
        if (array_key_exists('role', $input) || array_key_exists('is_admin', $input)
            || array_key_exists('admin', $input) || array_key_exists('account_state', $input)) {
            $errors['role'] = 'Account privileges cannot be selected during registration.';
        }

        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        if ($name === '' || self::characterLength($name) > 180) {
            $errors['name'] = 'Enter a name of 1 to 180 characters.';
        }

        $email = is_string($input['email'] ?? null) ? strtolower(trim($input['email'])) : '';
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        }

        $password = $input['password'] ?? null;
        $errors += PasswordPolicy::errors($password, $input['password_confirmation'] ?? null);

        $phoneValue = $input['phone'] ?? null;
        $phone = is_string($phoneValue) ? trim($phoneValue) : '';
        if (($phoneValue !== null && !is_string($phoneValue))
            || ($phone !== '' && (self::characterLength($phone) > 30 || preg_match('/^[0-9+().\-\s]+$/D', $phone) !== 1))) {
            $errors['phone'] = 'Enter a valid phone number of up to 30 characters.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        try {
            $id = $this->customers->createCustomer(
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                $name,
                $phone === '' ? null : $phone,
                'unverified',
            );
        } catch (DatabaseException $error) {
            if ($error->getCode() === 1062) {
                throw new ValidationException(['email' => 'An account with this email address already exists.']);
            }
            throw $error;
        }
        $this->verification?->issueForNewAccount($id, $email);
        return $id;
    }

    /** Return safe actor fields, or null for every invalid/archived login. */
    public function authenticate(string $email, string $password): ?array
    {
        $normalizedEmail = strtolower(trim($email));
        if ($normalizedEmail === '' || strlen($normalizedEmail) > 254 || strlen($password) > 72 || str_contains($password, "\0")) {
            return null;
        }

        $account = $this->customers->findForAuthentication($normalizedEmail);
        // Match password work for missing accounts to reduce username timing disclosure.
        $hash = $account['password_hash'] ?? '$2y$12$gWMIzLjfzgU1fZfR95FUj.7aw8uwnYn4e2WM1HyJpwv82wUb8cOZm';
        $verified = password_verify($password, (string) $hash);
        if ($account === null || !$verified) {
            return null;
        }

        return $this->actor((int) $account['customer_id']);
    }

    /** True only for correct credentials on an account still awaiting email verification. */
    public function unverifiedCredentialsValid(string $email, string $password): bool
    {
        $normalizedEmail = strtolower(trim($email));
        if ($normalizedEmail === '' || strlen($normalizedEmail) > 254 || strlen($password) > 72 || str_contains($password, "\0")) {
            return false;
        }

        $account = $this->customers->findUnverifiedForAuthentication($normalizedEmail);
        if ($account === null) {
            return false;
        }
        return password_verify($password, (string) $account['password_hash']);
    }

    /** Re-read the actor from persistence so archived accounts lose access. */
    public function actor(int $customerId): ?array
    {
        return $this->customers->findActiveById($customerId);
    }

    /** @param array<string, mixed> $actor */
    public function requireRole(array $actor, string $role): void
    {
        if ($role === '' || !isset($actor['role']) || !is_string($actor['role']) || $actor['role'] !== $role) {
            throw new \DomainException('Forbidden.');
        }
    }

    private static function characterLength(string $value): int
    {
        $count = preg_match_all('/./us', $value);
        return $count === false ? PHP_INT_MAX : $count;
    }
}
