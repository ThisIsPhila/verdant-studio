<?php

declare(strict_types=1);

use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== 'apply' || isset($argv[2])) {
    fwrite(STDERR, "Usage: php database/bin/seed-catalogue-variants.php apply\n");
    exit(1);
}

function catalogueVariantStatement(mysqli $connection, string $sql, array $parameters = []): mysqli_stmt
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
    $fixture = require dirname(__DIR__) . '/seeds/catalogue-variants.php';
    $config = DatabaseConfig::fromEnvironment();
    $connection = ConnectionFactory::connect($config);
    $lockName = 'verdant:catalogue-variants:' . substr(hash('sha256', $config->name), 0, 32);
    $lock = catalogueVariantStatement($connection, 'SELECT GET_LOCK(?, 5)', [$lockName]);
    $locked = (int) $lock->get_result()->fetch_row()[0] === 1;
    $lock->close();
    if (!$locked) {
        throw new RuntimeException('Another catalogue variant fixture run is active.');
    }

    foreach (['005_catalogue_kinds.sql', '006_product_families_compatibility.sql'] as $migrationName) {
        $migration = catalogueVariantStatement(
            $connection,
            'SELECT status, checksum FROM schema_migrations WHERE migration_name = ?',
            [$migrationName],
        );
        $row = $migration->get_result()->fetch_assoc();
        $migration->close();
        $path = dirname(__DIR__) . '/migrations/' . $migrationName;
        if (($row['status'] ?? null) !== 'applied'
            || !hash_equals((string) ($row['checksum'] ?? ''), (string) hash_file('sha256', $path))) {
            throw new RuntimeException('Apply the current migrations before the catalogue variant fixture.');
        }
    }

    $connection->begin_transaction();
    $inTransaction = true;
    $counts = ['inserted' => 0, 'collision' => 0, 'skipped' => 0, 'already_processed' => 0];
    $fixtureCode = 'starter-catalogue-variants-v1';
    $familyIds = [];
    $insertedProductIds = [];
    $variantSkus = [];
    foreach ($fixture['families'] as $family) {
        foreach ($family['variants'] as $variant) {
            $variantSkus[] = $variant['sku'];
        }
    }
    $variantSkus = array_values(array_unique($variantSkus));
    $skuMarks = implode(',', array_fill(0, count($variantSkus), '?'));
    $ownedProductIds = catalogueVariantStatement(
        $connection,
        "SELECT p.product_id FROM catalogue_fixture_ledger l JOIN products p ON p.product_id = l.product_id
         WHERE l.fixture_code = 'starter-catalogue-v1' AND l.disposition = 'inserted' AND l.sku IN ($skuMarks)
         ORDER BY p.product_id",
        $variantSkus,
    );
    $productIds = array_map('intval', array_column($ownedProductIds->get_result()->fetch_all(MYSQLI_ASSOC), 'product_id'));
    $ownedProductIds->close();
    $lockedInventoryIds = [];
    if ($productIds !== []) {
        $idMarks = implode(',', array_fill(0, count($productIds), '?'));
        $inventoryLocks = catalogueVariantStatement(
            $connection,
            "SELECT product_id FROM inventory WHERE product_id IN ($idMarks) ORDER BY product_id FOR UPDATE",
            $productIds,
        );
        $lockedInventoryIds = array_map('intval', array_column($inventoryLocks->get_result()->fetch_all(MYSQLI_ASSOC), 'product_id'));
        $inventoryLocks->close();
        $productLocks = catalogueVariantStatement(
            $connection,
            "SELECT product_id FROM products WHERE product_id IN ($idMarks) ORDER BY product_id FOR UPDATE",
            $productIds,
        );
        $productLocks->close();
    }

    foreach ($fixture['families'] as $family) {
        $entryKey = 'family:' . $family['family_code'];
        $ledger = catalogueVariantStatement(
            $connection,
            'SELECT disposition, family_id FROM catalogue_variant_fixture_ledger WHERE fixture_code = ? AND entry_key = ?',
            [$fixtureCode, $entryKey],
        );
        $prior = $ledger->get_result()->fetch_assoc();
        $ledger->close();
        if ($prior !== null) {
            if ($prior['family_id'] !== null && $prior['disposition'] === 'inserted') {
                $familyIds[$family['family_code']] = (int) $prior['family_id'];
            }
            ++$counts['already_processed'];
            echo $entryKey . ': already processed (' . $prior['disposition'] . ").\n";
            continue;
        }

        $ownedMembers = [];
        foreach ($family['variants'] as $variant) {
            $owned = catalogueVariantStatement(
                $connection,
                "SELECT l.product_id, p.family_id, p.variant_label, p.product_kind, p.accessory_subtype,
                        p.archived_at, c.archived_at AS category_archived_at
                 FROM catalogue_fixture_ledger l JOIN products p ON p.product_id = l.product_id
                 JOIN categories c ON c.category_id = p.category_id
                 WHERE l.fixture_code = 'starter-catalogue-v1' AND l.sku = ? AND l.disposition = 'inserted' FOR UPDATE",
                [$variant['sku']],
            );
            $product = $owned->get_result()->fetch_assoc();
            $owned->close();
            if ($product !== null) {
                $ownedMembers[] = ['definition' => $variant, 'product' => $product];
            }
        }
        if ($ownedMembers === []) {
            catalogueVariantStatement(
                $connection,
                "INSERT INTO catalogue_variant_fixture_ledger (fixture_code, entry_key, disposition) VALUES (?, ?, 'skipped')",
                [$fixtureCode, $entryKey],
            )->close();
            ++$counts['skipped'];
            echo $entryKey . ": no fixture-owned SKUs; skipped.\n";
            continue;
        }

        $existingFamily = catalogueVariantStatement(
            $connection,
            'SELECT family_id FROM product_families WHERE family_code = ? FOR UPDATE',
            [$family['family_code']],
        );
        $existing = $existingFamily->get_result()->fetch_assoc();
        $existingFamily->close();
        if ($existing !== null) {
            catalogueVariantStatement(
                $connection,
                "INSERT INTO catalogue_variant_fixture_ledger (fixture_code, entry_key, disposition, family_id) VALUES (?, ?, 'collision', ?)",
                [$fixtureCode, $entryKey, $existing['family_id']],
            )->close();
            ++$counts['collision'];
            echo $entryKey . ": family code collision; existing family preserved.\n";
            continue;
        }

        catalogueVariantStatement(
            $connection,
            'INSERT INTO product_families (family_code, name, product_kind) VALUES (?, ?, ?)',
            [$family['family_code'], $family['name'], $family['product_kind']],
        )->close();
        $familyId = (int) $connection->insert_id;
        $familyIds[$family['family_code']] = $familyId;
        catalogueVariantStatement(
            $connection,
            "INSERT INTO catalogue_variant_fixture_ledger (fixture_code, entry_key, disposition, family_id) VALUES (?, ?, 'inserted', ?)",
            [$fixtureCode, $entryKey, $familyId],
        )->close();
        ++$counts['inserted'];

        foreach ($ownedMembers as $member) {
            $variant = $member['definition'];
            $product = $member['product'];
            $skuEntry = 'sku:' . $variant['sku'];
            $previous = catalogueVariantStatement(
                $connection,
                'SELECT disposition FROM catalogue_variant_fixture_ledger WHERE fixture_code = ? AND entry_key = ?',
                [$fixtureCode, $skuEntry],
            );
            $processed = $previous->get_result()->fetch_assoc();
            $previous->close();
            if ($processed !== null) {
                ++$counts['already_processed'];
                continue;
            }
            $expectedKind = $family['product_kind'];
            $subtypeMatches = !isset($variant['accessory_subtype'])
                || $product['accessory_subtype'] === 'other';
            $available = $product['family_id'] === null && $product['variant_label'] === null
                && $product['product_kind'] === $expectedKind && $product['archived_at'] === null
                && $product['category_archived_at'] === null && $subtypeMatches
                && in_array((int) $product['product_id'], $lockedInventoryIds, true);
            if (!$available) {
                catalogueVariantStatement(
                    $connection,
                    "INSERT INTO catalogue_variant_fixture_ledger (fixture_code, entry_key, disposition, family_id) VALUES (?, ?, 'collision', ?)",
                    [$fixtureCode, $skuEntry, $familyId],
                )->close();
                ++$counts['collision'];
                echo $skuEntry . ": existing product edits preserved.\n";
                continue;
            }
            $updated = catalogueVariantStatement(
                $connection,
                'UPDATE products SET family_id = ?, variant_label = ?, accessory_subtype = ?
                 WHERE product_id = ? AND family_id IS NULL AND variant_label IS NULL AND product_kind = ?
                   AND accessory_subtype = ? AND archived_at IS NULL',
                [$familyId, $variant['variant_label'], $variant['accessory_subtype'] ?? 'other',
                    $product['product_id'], $expectedKind, $product['accessory_subtype']],
            );
            $applied = $updated->affected_rows === 1;
            $updated->close();
            if (!$applied) {
                catalogueVariantStatement(
                    $connection,
                    "INSERT INTO catalogue_variant_fixture_ledger (fixture_code, entry_key, disposition, family_id) VALUES (?, ?, 'collision', ?)",
                    [$fixtureCode, $skuEntry, $familyId],
                )->close();
                ++$counts['collision'];
                echo $skuEntry . ": concurrent product edit preserved.\n";
                continue;
            }
            $versioned = catalogueVariantStatement(
                $connection,
                'UPDATE inventory SET version = version + 1 WHERE product_id = ?',
                [(int) $product['product_id']],
            );
            $versionAdvanced = $versioned->affected_rows === 1;
            $versioned->close();
            if (!$versionAdvanced) {
                throw new RuntimeException('Assigned variant inventory row was not available for versioning.');
            }
            catalogueVariantStatement(
                $connection,
                "INSERT INTO catalogue_variant_fixture_ledger (fixture_code, entry_key, disposition, family_id) VALUES (?, ?, 'inserted', ?)",
                [$fixtureCode, $skuEntry, $familyId],
            )->close();
            $insertedProductIds[$variant['sku']] = (int) $product['product_id'];
            ++$counts['inserted'];
        }
    }

    foreach ($fixture['compatibilities'] as $pair) {
        $entryKey = 'compat:' . $pair['plant_sku'] . ':' . $pair['pot_sku'];
        $ledger = catalogueVariantStatement(
            $connection,
            'SELECT disposition FROM catalogue_variant_fixture_ledger WHERE fixture_code = ? AND entry_key = ?',
            [$fixtureCode, $entryKey],
        );
        $prior = $ledger->get_result()->fetch_assoc();
        $ledger->close();
        if ($prior !== null) {
            ++$counts['already_processed'];
            echo $entryKey . ': already processed (' . $prior['disposition'] . ").\n";
            continue;
        }
        if (!isset($insertedProductIds[$pair['plant_sku']], $insertedProductIds[$pair['pot_sku']])) {
            catalogueVariantStatement(
                $connection,
                "INSERT INTO catalogue_variant_fixture_ledger (fixture_code, entry_key, disposition) VALUES (?, ?, 'skipped')",
                [$fixtureCode, $entryKey],
            )->close();
            ++$counts['skipped'];
            echo $entryKey . ": fixture-owned variant pair unavailable; skipped.\n";
            continue;
        }
        $plantId = $insertedProductIds[$pair['plant_sku']];
        $potId = $insertedProductIds[$pair['pot_sku']];
        $existing = catalogueVariantStatement(
            $connection,
            'SELECT compatibility_id FROM product_compatibilities WHERE plant_product_id = ? AND pot_product_id = ? FOR UPDATE',
            [$plantId, $potId],
        );
        $existingPair = $existing->get_result()->fetch_assoc();
        $existing->close();
        if ($existingPair !== null) {
            catalogueVariantStatement(
                $connection,
                "INSERT INTO catalogue_variant_fixture_ledger (fixture_code, entry_key, disposition) VALUES (?, ?, 'collision')",
                [$fixtureCode, $entryKey],
            )->close();
            ++$counts['collision'];
            echo $entryKey . ": existing pairing preserved.\n";
            continue;
        }
        catalogueVariantStatement(
            $connection,
            'INSERT INTO product_compatibilities (plant_product_id, pot_product_id, source_status) VALUES (?, ?, ?)',
            [$plantId, $potId, $pair['source_status']],
        )->close();
        catalogueVariantStatement(
            $connection,
            "INSERT INTO catalogue_variant_fixture_ledger (fixture_code, entry_key, disposition) VALUES (?, ?, 'inserted')",
            [$fixtureCode, $entryKey],
        )->close();
        ++$counts['inserted'];
    }

    $connection->commit();
    $inTransaction = false;
    echo 'Catalogue variant fixture complete: ' . $counts['inserted'] . ' inserted, '
        . $counts['collision'] . ' collisions, ' . $counts['skipped'] . ' skipped, '
        . $counts['already_processed'] . " already processed.\n";
} catch (Throwable $error) {
    if ($connection !== null && $inTransaction) {
        try {
            $connection->rollback();
        } catch (Throwable) {
            // Preserve the original failure; a closed connection has no live transaction.
        }
    }
    $message = $error instanceof RuntimeException && !$error instanceof mysqli_sql_exception
        ? $error->getMessage() : 'Catalogue variant fixture failed; inspect the isolated database privately.';
    fwrite(STDERR, $message . "\n");
    $failed = true;
} finally {
    if ($connection !== null) {
        try {
            if ($locked) {
                catalogueVariantStatement($connection, 'SELECT RELEASE_LOCK(?)', [$lockName])->close();
            }
            $connection->close();
        } catch (Throwable) {
            fwrite(STDERR, "Database cleanup unavailable; connection will be released on exit.\n");
            $failed = true;
        }
    }
}
exit(isset($failed) ? 1 : 0);
