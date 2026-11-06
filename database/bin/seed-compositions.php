<?php

declare(strict_types=1);

use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== 'apply' || isset($argv[2])) {
    fwrite(STDERR, "Usage: php database/bin/seed-compositions.php apply\n");
    exit(1);
}

function compositionSeedStatement(mysqli $connection, string $sql, array $parameters = []): mysqli_stmt
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
    $definitions = require dirname(__DIR__) . '/seeds/compositions.php';
    $config = DatabaseConfig::fromEnvironment();
    $connection = ConnectionFactory::connect($config);
    $lockName = 'verdant:composition-fixture:' . substr(hash('sha256', $config->name), 0, 32);
    $lock = compositionSeedStatement($connection, 'SELECT GET_LOCK(?, 5)', [$lockName]);
    $locked = (int) $lock->get_result()->fetch_row()[0] === 1;
    $lock->close();
    if (!$locked) {
        throw new RuntimeException('Another composition fixture run is active.');
    }

    foreach (['006_product_families_compatibility.sql', '007_compositions.sql'] as $migrationName) {
        $statement = compositionSeedStatement(
            $connection,
            'SELECT status, checksum FROM schema_migrations WHERE migration_name = ?',
            [$migrationName],
        );
        $migration = $statement->get_result()->fetch_assoc();
        $statement->close();
        $path = dirname(__DIR__) . '/migrations/' . $migrationName;
        if (($migration['status'] ?? null) !== 'applied'
            || !hash_equals((string) ($migration['checksum'] ?? ''), (string) hash_file('sha256', $path))) {
            throw new RuntimeException('Apply the current unchanged migrations before the composition fixture.');
        }
    }

    $connection->begin_transaction();
    $inTransaction = true;
    $counts = ['inserted' => 0, 'collision' => 0, 'already processed' => 0, 'prerequisites missing' => 0];
    $fixtureCode = 'starter-compositions-v1';

    foreach ($definitions as $definition) {
        $code = (string) $definition['code'];
        $ledgerStatement = compositionSeedStatement(
            $connection,
            'SELECT disposition, definition_id FROM composition_fixture_ledger WHERE fixture_code = ? AND definition_code = ?',
            [$fixtureCode, $code],
        );
        $prior = $ledgerStatement->get_result()->fetch_assoc();
        $ledgerStatement->close();
        if ($prior !== null) {
            ++$counts['already processed'];
            echo $code . ': already processed (' . $prior['disposition'] . ").\n";
            continue;
        }

        $existsStatement = compositionSeedStatement(
            $connection,
            'SELECT definition_id FROM composition_definitions WHERE definition_code = ? FOR UPDATE',
            [$code],
        );
        $exists = $existsStatement->get_result()->fetch_assoc();
        $existsStatement->close();
        if ($exists !== null) {
            compositionSeedStatement(
                $connection,
                "INSERT INTO composition_fixture_ledger(fixture_code, definition_code, disposition, definition_id)
                 VALUES (?, ?, 'collision', ?)",
                [$fixtureCode, $code, $exists['definition_id']],
            )->close();
            ++$counts['collision'];
            echo $code . ": definition code collision; existing definition preserved.\n";
            continue;
        }

        $familyIds = [];
        $productIds = [];
        $missing = false;
        $finishedProductId = null;
        if (isset($definition['finished_product_sku'])) {
            $statement = compositionSeedStatement(
                $connection,
                'SELECT p.product_id FROM products p JOIN categories c ON c.category_id = p.category_id
                 WHERE p.sku = ? AND p.archived_at IS NULL AND c.archived_at IS NULL',
                [$definition['finished_product_sku']],
            );
            $row = $statement->get_result()->fetch_assoc();
            $statement->close();
            if ($row === null) {
                $missing = true;
            } else {
                $finishedProductId = (int) $row['product_id'];
            }
        }
        foreach ($definition['components'] as $component) {
            if ($component['source_type'] === 'family') {
                $familyCode = (string) $component['family_code'];
                if (!isset($familyIds[$familyCode])) {
                    $statement = compositionSeedStatement(
                        $connection,
                        "SELECT family_id FROM product_families WHERE family_code = ? AND archived_at IS NULL",
                        [$familyCode],
                    );
                    $row = $statement->get_result()->fetch_assoc();
                    $statement->close();
                    if ($row === null) {
                        $missing = true;
                    } else {
                        $familyIds[$familyCode] = (int) $row['family_id'];
                    }
                }
            }
            foreach (($component['product_skus'] ?? []) as $sku) {
                if (isset($productIds[$sku])) {
                    continue;
                }
                $statement = compositionSeedStatement(
                    $connection,
                    'SELECT p.product_id FROM products p JOIN categories c ON c.category_id = p.category_id
                     WHERE p.sku = ? AND p.archived_at IS NULL AND c.archived_at IS NULL',
                    [$sku],
                );
                $row = $statement->get_result()->fetch_assoc();
                $statement->close();
                if ($row === null) {
                    $missing = true;
                } else {
                    $productIds[$sku] = (int) $row['product_id'];
                }
            }
        }
        if ($missing) {
            ++$counts['prerequisites missing'];
            echo $code . ": starter products or families are not ready; no rows written.\n";
            continue;
        }

        compositionSeedStatement(
            $connection,
            'INSERT INTO composition_definitions(definition_code, name, arrangement_kind, strategy, finished_product_id)
             VALUES (?, ?, ?, ?, ?)',
            [$code, $definition['name'], $definition['arrangement_kind'], $definition['strategy'], $finishedProductId],
        )->close();
        $definitionId = (int) $connection->insert_id;
        $componentIds = [];
        foreach ($definition['components'] as $component) {
            $anchorId = isset($component['anchor_key']) ? ($componentIds[$component['anchor_key']] ?? null) : null;
            if ($component['source_type'] === 'compatible_pot' && $anchorId === null) {
                throw new RuntimeException('A compatible-pot seed rule must follow its plant anchor.');
            }
            compositionSeedStatement(
                $connection,
                'INSERT INTO composition_definition_components
                    (definition_id, component_key, label, component_role, source_type,
                     compatibility_anchor_component_id, required, min_selections, max_selections,
                     min_quantity, max_quantity, permitted_mode, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $definitionId, $component['key'], $component['label'], $component['role'], $component['source_type'],
                    $anchorId, (int) $component['required'], (int) $component['min_selections'], (int) $component['max_selections'],
                    (int) $component['min_quantity'], (int) $component['max_quantity'], $component['mode'], (int) $component['sort'],
                ],
            )->close();
            $componentId = (int) $connection->insert_id;
            $componentIds[$component['key']] = $componentId;
            if ($component['source_type'] === 'family') {
                compositionSeedStatement(
                    $connection,
                    'INSERT INTO composition_component_family_allowlist(component_id, family_id) VALUES (?, ?)',
                    [$componentId, $familyIds[$component['family_code']]],
                )->close();
            } elseif ($component['source_type'] === 'product') {
                foreach ($component['product_skus'] as $sku) {
                    compositionSeedStatement(
                        $connection,
                        'INSERT INTO composition_component_product_allowlist(component_id, product_id) VALUES (?, ?)',
                        [$componentId, $productIds[$sku]],
                    )->close();
                }
            }
        }
        compositionSeedStatement(
            $connection,
            "INSERT INTO composition_fixture_ledger(fixture_code, definition_code, disposition, definition_id)
             VALUES (?, ?, 'inserted', ?)",
            [$fixtureCode, $code, $definitionId],
        )->close();
        ++$counts['inserted'];
        echo $code . ": inserted with allowlisted component rules.\n";
    }

    $connection->commit();
    $inTransaction = false;
    echo 'Summary: ' . json_encode($counts, JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $error) {
    if ($connection instanceof mysqli && $inTransaction) {
        $connection->rollback();
    }
    fwrite(STDERR, 'Composition fixture failed: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    if ($connection instanceof mysqli) {
        if ($locked) {
            try {
                compositionSeedStatement($connection, 'SELECT RELEASE_LOCK(?)', [$lockName])->close();
            } catch (Throwable) {
                fwrite(STDERR, "Composition fixture lock release failed; connection will close.\n");
            }
        }
        $connection->close();
    }
}
