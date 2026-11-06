<?php

declare(strict_types=1);

namespace Verdant\Support;

use Verdant\Identity\SessionManager;

final class Csrf
{
    public static function token(): string
    {
        SessionManager::start();
        if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])
            || preg_match('/^[a-f0-9]{64}$/D', $_SESSION['_csrf']) !== 1) {
            self::rotate();
        }
        return $_SESSION['_csrf'];
    }

    public static function valid(mixed $candidate): bool
    {
        if (!is_string($candidate) || strlen($candidate) !== 64 || preg_match('/^[a-f0-9]{64}$/D', $candidate) !== 1) {
            return false;
        }
        SessionManager::start();
        $expected = $_SESSION['_csrf'] ?? null;
        return is_string($expected) && hash_equals($expected, $candidate);
    }

    public static function rotate(): void
    {
        SessionManager::start();
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
}
