<?php

declare(strict_types=1);

use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require __DIR__ . '/demo-seed-guard.php';

if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['migrate', 'seed'], true)) {
    fwrite(STDERR, "Usage: php database/bin/setup.php migrate|seed\n");
    exit(1);
}

function query(mysqli $connection, string $sql, array $parameters = []): mysqli_stmt
{
    $statement = $connection->prepare($sql);
    try {
        $statement->execute($parameters);
        return $statement;
    } catch (Throwable $error) {
        $statement->close();
        throw $error;
    }
}

function executeFile(mysqli $connection, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('SQL file is empty or unavailable.');
    }
    $connection->multi_query($sql);
    do {
        $result = $connection->store_result();
        if ($result !== false) {
            $result->free();
        }
        if (!$connection->more_results()) {
            break;
        }
    } while ($connection->next_result());
}

$connection = null;
$locked = false;
$lockName = '';
try {
    $config = DatabaseConfig::fromEnvironment();
    $connection = ConnectionFactory::connect($config);
    $version = $connection->query('SELECT VERSION()')->fetch_row()[0];
    if (!preg_match('/^(\d+\.\d+\.\d+)/', $version, $match)
        || str_contains(strtolower($version), 'mariadb')
        || version_compare($match[1], '8.0.16', '<')) {
        throw new RuntimeException('MySQL with enforced CHECK support is required.');
    }
    $lockName = 'verdant:migrate:' . substr(hash('sha256', $config->name), 0, 40);
    $lock = query($connection, 'SELECT GET_LOCK(?, 5)', [$lockName]);
    $locked = (int) $lock->get_result()->fetch_row()[0] === 1;
    $lock->close();
    if (!$locked) {
        throw new RuntimeException('Another database setup is running.');
    }
    $connection->query("CREATE TABLE IF NOT EXISTS schema_migrations (
        migration_name VARCHAR(190) PRIMARY KEY,
        checksum CHAR(64) NOT NULL,
        status ENUM('running','applied') NOT NULL,
        started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        applied_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $dirty = $connection->query("SELECT migration_name FROM schema_migrations WHERE status = 'running'")->fetch_row();
    if ($dirty !== null) {
        throw new RuntimeException('Incomplete migration recorded; inspect the schema and recover before retrying.');
    }
    $paths = glob(dirname(__DIR__) . '/migrations/[0-9]*.sql');
    sort($paths, SORT_STRING);
    if ($paths === []) {
        throw new RuntimeException('No migration files found.');
    }
    if ($argv[1] === 'seed') {
        foreach ($paths as $path) {
            $statement = query($connection, 'SELECT checksum, status FROM schema_migrations WHERE migration_name = ?', [basename($path)]);
            $row = $statement->get_result()->fetch_assoc();
            $statement->close();
            if ($row === null || $row['status'] !== 'applied' || $row['checksum'] !== hash_file('sha256', $path)) {
                throw new RuntimeException('Apply unchanged migrations before seeding.');
            }
        }
        $paths = [dirname(__DIR__) . '/seeds/demo.sql'];
    }
    foreach ($paths as $path) {
        $name = ($argv[1] === 'seed' ? 'seed:' : '') . basename($path);
        $checksum = hash_file('sha256', $path);
        if ($checksum === false) {
            throw new RuntimeException('SQL file unavailable.');
        }
        $statement = query($connection, 'SELECT checksum FROM schema_migrations WHERE migration_name = ?', [$name]);
        $existing = $statement->get_result()->fetch_assoc();
        $statement->close();
        if ($existing !== null) {
            if (!hash_equals($existing['checksum'], $checksum)) {
                throw new RuntimeException('Applied SQL changed; create a new migration instead.');
            }
            echo "Already applied: $name\n";
            continue;
        }
        if ($argv[1] === 'seed') {
            $tables = $connection->query("SELECT table_name FROM information_schema.tables
                WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetch_all(MYSQLI_NUM);
            foreach ($tables as [$table]) {
                if ($table !== 'schema_migrations') {
                    if ($table === 'information_policy_state') {
                        if (!demoSeedPolicyRowsAreInitial($connection)) {
                            throw new RuntimeException('Demo seed requires untouched information policy defaults.');
                        }
                        continue;
                    }
                    $identifier = str_replace('`', '``', $table);
                    if ($connection->query("SELECT 1 FROM `$identifier` LIMIT 1")->fetch_row() !== null) {
                        throw new RuntimeException('Demo seed requires empty application tables.');
                    }
                }
            }
            $connection->begin_transaction();
            try {
                executeFile($connection, $path);
                query($connection, "INSERT INTO schema_migrations (migration_name, checksum, status, applied_at)
                    VALUES (?, ?, 'applied', UTC_TIMESTAMP())", [$name, $checksum])->close();
                $connection->commit();
            } catch (Throwable $error) {
                $connection->rollback();
                throw $error;
            }
        } else {
            // DDL commits implicitly; a durable running marker prevents unsafe automatic retries.
            query($connection, "INSERT INTO schema_migrations (migration_name, checksum, status)
                VALUES (?, ?, 'running')", [$name, $checksum])->close();
            executeFile($connection, $path);
            query($connection, "UPDATE schema_migrations SET status = 'applied', applied_at = UTC_TIMESTAMP()
                WHERE migration_name = ?", [$name])->close();
        }
        echo "Applied: $name\n";
    }
} catch (Throwable $error) {
    $message = $error instanceof RuntimeException && !$error instanceof mysqli_sql_exception
        ? $error->getMessage() : 'Database setup failed; inspect the migration state using private tooling.';
    fwrite(STDERR, $message . "\n");
    $failed = true;
} finally {
    if ($connection !== null) {
        try {
            if ($locked) {
                query($connection, 'SELECT RELEASE_LOCK(?)', [$lockName])->close();
            }
            $connection->close();
        } catch (Throwable) {
            fwrite(STDERR, "Database cleanup unavailable; connection will be released on exit.\n");
            $failed = true;
        }
    }
}
exit(isset($failed) ? 1 : 0);
