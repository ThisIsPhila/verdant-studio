<?php

declare(strict_types=1);

use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Storefront\SectionRegistry;
use Verdant\Storefront\StorefrontSectionService;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== 'apply' || isset($argv[2])) {
    fwrite(STDERR, "Usage: php database/bin/seed-storefront-sections.php apply\n");
    exit(1);
}

$connection = null;
$locked = false;
$lockName = '';
try {
    $definitions = require dirname(__DIR__) . '/seeds/storefront-sections.php';
    if ($definitions !== SectionRegistry::defaults()) {
        throw new RuntimeException('Storefront section defaults do not match the fixed registry.');
    }
    $config = DatabaseConfig::fromEnvironment();
    $connection = ConnectionFactory::connect($config);
    $lockName = 'verdant:storefront-defaults:' . substr(hash('sha256', $config->name), 0, 30);
    $statement = $connection->prepare('SELECT GET_LOCK(?, 5)');
    $statement->execute([$lockName]);
    $locked = (int) $statement->get_result()->fetch_row()[0] === 1;
    $statement->close();
    if (!$locked) {
        throw new RuntimeException('Another storefront defaults run is active.');
    }

    $migrationPath = dirname(__DIR__) . '/migrations/011_storefront_sections.sql';
    $statement = $connection->prepare('SELECT status, checksum FROM schema_migrations WHERE migration_name = ?');
    $statement->execute(['011_storefront_sections.sql']);
    $migration = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_file($migrationPath) || ($migration['status'] ?? null) !== 'applied'
        || !hash_equals((string) ($migration['checksum'] ?? ''), (string) hash_file('sha256', $migrationPath))) {
        throw new RuntimeException('Apply the reviewed storefront sections migration before the defaults fixture.');
    }

    $counts = (new StorefrontSectionService($connection))->initialiseDefaults();
    echo 'Storefront defaults: ' . $counts['pages'] . ' pages checked; ' . $counts['sections'] . " sections present. Existing edits and archives are preserved.\n";
} catch (Throwable $error) {
    $message = $error instanceof RuntimeException && !$error instanceof mysqli_sql_exception
        ? $error->getMessage() : 'Storefront defaults failed; inspect the isolated database privately.';
    fwrite(STDERR, $message . "\n");
    $failed = true;
} finally {
    if ($connection !== null) {
        try {
            if ($locked) {
                $statement = $connection->prepare('SELECT RELEASE_LOCK(?)');
                $statement->execute([$lockName]);
                $statement->close();
            }
            $connection->close();
        } catch (Throwable) {
            fwrite(STDERR, "Database cleanup unavailable; connection will be released on exit.\n");
            $failed = true;
        }
    }
}
exit(isset($failed) ? 1 : 0);
