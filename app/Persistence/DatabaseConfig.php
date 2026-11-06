<?php

declare(strict_types=1);

namespace Verdant\Persistence;

final class DatabaseConfig
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $name,
        public readonly string $username,
        public readonly string $password,
        public readonly ?string $socket = null,
    ) {
        if ($name === '' || $username === '' || $port < 1 || $port > 65535) {
            throw new DatabaseException('Database configuration is incomplete.');
        }
    }

    public static function fromEnvironment(): self
    {
        $read = static fn (string $key, string $default = ''): string => getenv($key) === false
            ? $default : (string) getenv($key);
        $password = getenv('DB_PASSWORD');
        $port = $read('DB_PORT', '3306');
        if ($password === false || !ctype_digit($port)) {
            throw new DatabaseException('Database configuration is incomplete.');
        }
        return new self(
            $read('DB_HOST', '127.0.0.1'), (int) $port, $read('DB_NAME'),
            $read('DB_USER'), $password, $read('DB_SOCKET') ?: null,
        );
    }
}
