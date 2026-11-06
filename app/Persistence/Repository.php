<?php

declare(strict_types=1);

namespace Verdant\Persistence;

abstract class Repository
{
    public function __construct(protected readonly \mysqli $connection)
    {
    }

    /** @param list<int|string|float|null> $parameters */
    protected function select(string $sql, array $parameters = []): array
    {
        $statement = $this->prepare($sql, $parameters);
        try {
            return $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        } finally {
            $statement->close();
        }
    }

    /** @param list<int|string|float|null> $parameters */
    protected function execute(string $sql, array $parameters = []): int
    {
        $statement = $this->prepare($sql, $parameters);
        try {
            return $statement->affected_rows;
        } finally {
            $statement->close();
        }
    }

    private function prepare(string $sql, array $parameters): \mysqli_stmt
    {
        $statement = null;
        try {
            $statement = $this->connection->prepare($sql);
            $statement->execute($parameters);
            return $statement;
        } catch (\mysqli_sql_exception $error) {
            $statement?->close();
            throw new DatabaseException('Database operation failed.', $error->getCode());
        }
    }
}
