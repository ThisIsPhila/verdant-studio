<?php

declare(strict_types=1);

namespace Verdant\Persistence;

final class ConnectionFactory
{
    public static function connect(DatabaseConfig $config): \mysqli
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $connection = mysqli_init();
        try {
            $connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
            $connection->real_connect(
                $config->host, $config->username, $config->password,
                $config->name, $config->port, $config->socket,
            );
            $connection->set_charset('utf8mb4');
            $connection->query("SET SESSION time_zone = '+00:00'");
            $connection->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            return $connection;
        } catch (\mysqli_sql_exception $error) {
            $connection->close();
            throw new DatabaseException('Database connection unavailable.', $error->getCode());
        }
    }
}
