<?php

declare(strict_types=1);

namespace Verdant\Persistence;

final class TransactionManager
{
    private bool $active = false;

    public function __construct(private readonly \mysqli $connection)
    {
    }

    public function run(callable $operation): mixed
    {
        if ($this->active) {
            throw new \LogicException('Nested transactions are not supported.');
        }
        $this->active = true;
        try {
            $this->connection->begin_transaction();
            try {
                $result = $operation();
                $this->connection->commit();
                return $result;
            } catch (\Throwable $error) {
                $this->connection->rollback();
                throw $error;
            }
        } catch (\mysqli_sql_exception $error) {
            throw new DatabaseException('Database transaction failed.', $error->getCode());
        } finally {
            $this->active = false;
        }
    }
}
