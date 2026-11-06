<?php

declare(strict_types=1);

use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== 'apply' || isset($argv[2])) {
    fwrite(STDERR, "Usage: php database/bin/seed-collections.php apply\n");
    exit(1);
}

function collectionSeedStatement(mysqli $connection, string $sql, array $parameters = []): mysqli_stmt
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

$connection = null;
$locked = false;
$inTransaction = false;
$lockName = '';
try {
    $definitions = require dirname(__DIR__) . '/seeds/collections.php';
    $config = DatabaseConfig::fromEnvironment();
    $connection = ConnectionFactory::connect($config);
    $lockName = 'verdant:collections-fixture:' . substr(hash('sha256', $config->name), 0, 30);
    $lock = collectionSeedStatement($connection, 'SELECT GET_LOCK(?, 5)', [$lockName]);
    $locked = (int) $lock->get_result()->fetch_row()[0] === 1;
    $lock->close();
    if (!$locked) {
        throw new RuntimeException('Another collections fixture run is active.');
    }

    $migrationName = '010_collections.sql';
    $migration = collectionSeedStatement(
        $connection,
        'SELECT status, checksum FROM schema_migrations WHERE migration_name = ?',
        [$migrationName],
    );
    $migrationRow = $migration->get_result()->fetch_assoc();
    $migration->close();
    $migrationPath = dirname(__DIR__) . '/migrations/' . $migrationName;
    if (!is_file($migrationPath) || ($migrationRow['status'] ?? null) !== 'applied'
        || !hash_equals((string) ($migrationRow['checksum'] ?? ''), (string) hash_file('sha256', $migrationPath))) {
        throw new RuntimeException('Apply the reviewed collections migration before the collection fixture.');
    }

    $connection->begin_transaction();
    $inTransaction = true;
    $counts = ['inserted' => 0, 'collision' => 0, 'already processed' => 0, 'composition links added' => 0];
    $fixtureCode = 'starter-collections-v1';
    $insertedCollectionIds = [];
    foreach ($definitions as $definition) {
        $code = (string) $definition['code'];
        $ledger = collectionSeedStatement(
            $connection,
            'SELECT disposition FROM collection_fixture_ledger WHERE fixture_code = ? AND collection_code = ?',
            [$fixtureCode, $code],
        );
        $processed = $ledger->get_result()->fetch_assoc();
        $ledger->close();
        if ($processed !== null) {
            ++$counts['already processed'];
            echo $code . ': already processed (' . $processed['disposition'] . ").\n";
            continue;
        }
        $existing = collectionSeedStatement($connection, 'SELECT collection_id FROM collections WHERE collection_code = ?', [$code]);
        $row = $existing->get_result()->fetch_assoc();
        $existing->close();
        if ($row !== null) {
            collectionSeedStatement(
                $connection,
                "INSERT INTO collection_fixture_ledger(fixture_code, collection_code, disposition, collection_id)
                 VALUES (?, ?, 'collision', NULL)",
                [$fixtureCode, $code],
            )->close();
            ++$counts['collision'];
            echo $code . ": collision; existing collection preserved.\n";
            continue;
        }

        $productIds = [];
        foreach ($definition['products'] as [$sku]) {
            $product = collectionSeedStatement(
                $connection,
                'SELECT p.product_id FROM products p JOIN categories c ON c.category_id = p.category_id
                 LEFT JOIN product_families f ON f.family_id = p.family_id
                 WHERE p.sku = ? AND p.archived_at IS NULL AND c.archived_at IS NULL
                   AND (f.family_id IS NULL OR f.archived_at IS NULL)',
                [$sku],
            );
            $productRow = $product->get_result()->fetch_assoc();
            $product->close();
            if ($productRow === null) {
                throw new RuntimeException('A referenced starter SKU is not active; collection seed rolled back.');
            }
            $productIds[$sku] = (int) $productRow['product_id'];
        }
        collectionSeedStatement(
            $connection,
            "INSERT INTO collections(collection_code, name, description, state) VALUES (?, ?, ?, 'active')",
            [$code, $definition['name'], $definition['description']],
        )->close();
        $collectionId = (int) $connection->insert_id;
        $insertedCollectionIds[$code] = $collectionId;
        collectionSeedStatement(
            $connection,
            "INSERT INTO collection_fixture_ledger(fixture_code, collection_code, disposition, collection_id)
             VALUES (?, ?, 'inserted', ?)",
            [$fixtureCode, $code, $collectionId],
        )->close();
        foreach ($definition['products'] as [$sku, $explanation, $sort]) {
            collectionSeedStatement(
                $connection,
                'INSERT INTO collection_products(collection_id, product_id, explanation, sort_order) VALUES (?, ?, ?, ?)',
                [$collectionId, $productIds[$sku], $explanation, (int) $sort],
            )->close();
        }
        ++$counts['inserted'];
        echo $code . ": inserted with existing product SKUs only.\n";
    }

    // Add arrangement links when their owner-reviewed definitions exist. No stock or availability is copied here.
    foreach ($definitions as $definition) {
        if (!isset($definition['compositions']) || $definition['compositions'] === []) {
            continue;
        }
        if (!isset($insertedCollectionIds[$definition['code']])) {
            continue;
        }
        foreach ($definition['compositions'] as [$compositionCode, $explanation, $sort]) {
            $composition = collectionSeedStatement(
                $connection,
                "SELECT definition_id FROM composition_definitions WHERE definition_code = ? AND status = 'active'",
                [$compositionCode],
            );
            $compositionRow = $composition->get_result()->fetch_assoc();
            $composition->close();
            if ($compositionRow === null) {
                throw new RuntimeException('A referenced composition definition is not active; collection seed rolled back.');
            }
            collectionSeedStatement(
                $connection,
                'INSERT INTO collection_compositions(collection_id, definition_id, explanation, sort_order) VALUES (?, ?, ?, ?)',
                [$insertedCollectionIds[$definition['code']], $compositionRow['definition_id'], $explanation, (int) $sort],
            )->close();
            ++$counts['composition links added'];
        }
    }
    $connection->commit();
    $inTransaction = false;
    echo 'Collections fixture: ' . $counts['inserted'] . ' inserted, ' . $counts['collision'] . ' collisions, '
        . $counts['already processed'] . ' already processed, ' . $counts['composition links added'] . " arrangement links added.\n";
} catch (Throwable $error) {
    if ($connection !== null && $inTransaction) {
        try { $connection->rollback(); } catch (Throwable) { }
    }
    fwrite(STDERR, ($error instanceof RuntimeException ? $error->getMessage() : 'Collections fixture failed; inspect the isolated database privately.') . "\n");
    $failed = true;
} finally {
    if ($connection !== null) {
        try {
            if ($locked) { collectionSeedStatement($connection, 'SELECT RELEASE_LOCK(?)', [$lockName])->close(); }
            $connection->close();
        } catch (Throwable) {
            fwrite(STDERR, "Database cleanup unavailable; connection will be released on exit.\n");
            $failed = true;
        }
    }
}
exit(isset($failed) ? 1 : 0);
