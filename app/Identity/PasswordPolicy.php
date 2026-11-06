<?php

declare(strict_types=1);

namespace Verdant\Identity;

/** Shared validation for account creation and password recovery. */
final class PasswordPolicy
{
    /** @return array<string,string> */
    public static function errors(mixed $password, mixed $confirmation): array
    {
        $errors = [];
        if (!is_string($password) || self::characterLength($password) < 12
            || strlen($password) > 72 || str_contains($password, "\0")) {
            $errors['password'] = 'Use at least 12 characters, up to 72 bytes.';
        }
        if (!is_string($confirmation) || !is_string($password) || !hash_equals($password, $confirmation)) {
            $errors['password_confirmation'] = 'The password confirmation does not match.';
        }
        return $errors;
    }

    private static function characterLength(string $value): int
    {
        $count = preg_match_all('/./us', $value);
        return $count === false ? PHP_INT_MAX : $count;
    }
}
