<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;

if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'local' || getenv('DB_NAME') !== 'verdant_test_setup_phase012') {
    fwrite(STDERR, "Use only the dedicated verdant_test_setup_phase012 schema.\n");
    exit(2);
}

$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$checks = 0;
$fixtureCode = 'starter-collections-v1';
$ownerCollectionId = null;
$treeOliveId = null;
$treeOliveArchive = null;

$query = static function (string $sql, array $values = []) use ($db): array {
    $statement = $db->prepare($sql);
    $statement->execute($values);
    $result = $statement->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $statement->close();
    return $rows;
};
$execute = static function (string $sql, array $values = []) use ($db): int {
    $statement = $db->prepare($sql);
    $statement->execute($values);
    $affected = $statement->affected_rows;
    $statement->close();
    return $affected;
};
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($label); }
    ++$checks;
};
$runFixture = static function (): array {
    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__) . '/database/bin/seed-collections.php', 'apply'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
    );
    if (!is_resource($process)) { throw new RuntimeException('Could not start the collection fixture process.'); }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output];
};

try {
    $migration = $query('SELECT status, checksum FROM schema_migrations WHERE migration_name = ?', ['010_collections.sql'])[0] ?? null;
    $migrationPath = dirname(__DIR__) . '/database/migrations/010_collections.sql';
    if (($migration['status'] ?? null) !== 'applied'
        || !hash_equals((string) $migration['checksum'], (string) hash_file('sha256', $migrationPath))) {
        throw new RuntimeException('The active 010 migration must be applied unchanged before fixture checks.');
    }
    $pristine = (int) ($query('SELECT COUNT(*) AS n FROM collections')[0]['n'] ?? -1) === 0
        && (int) ($query('SELECT COUNT(*) AS n FROM collection_products')[0]['n'] ?? -1) === 0
        && (int) ($query('SELECT COUNT(*) AS n FROM collection_compositions')[0]['n'] ?? -1) === 0
        && (int) ($query('SELECT COUNT(*) AS n FROM collection_fixture_ledger')[0]['n'] ?? -1) === 0;
    if (!$pristine) { throw new RuntimeException('Setup schema is not empty of collection fixture state; refusing to alter it.'); }
    $activeProducts = (int) ($query(
        "SELECT COUNT(DISTINCT p.sku) AS n FROM products p JOIN categories c ON c.category_id = p.category_id
         LEFT JOIN product_families f ON f.family_id = p.family_id
         WHERE p.sku IN ('PL-MON-S','PL-ZZ','POT-STONE-S','POT-TERRA','TOOL-CAN',
             'VG-N','VG-M','VG-L','TREE-OLIVE','TREE-CHERRY','PL-BIRD','POT-CHAR',
             'FL-ROSE','FL-LILY','FL-PROTEA','FL-EUC','BQ-WHITE','BQ-WILD','BQ-PASTEL')
           AND p.archived_at IS NULL AND c.archived_at IS NULL AND (f.family_id IS NULL OR f.archived_at IS NULL)",
    )[0]['n'] ?? 0);
    $check($activeProducts === 19, 'All referenced starter SKUs are active.');
    $check((int) ($query("SELECT COUNT(*) AS n FROM composition_definitions WHERE definition_code='starter-bouquet-rental-container' AND status='active'")[0]['n'] ?? 0) === 1,
        'The configured floral composition prerequisite is active.');

    // An owner-owned collision must be recorded without changing its label or membership.
    $product = $query("SELECT product_id FROM products WHERE sku='PL-MON-S' AND archived_at IS NULL")[0] ?? null;
    if ($product === null) { throw new RuntimeException('Owner collision fixture product is missing.'); }
    $db->begin_transaction();
    $execute("INSERT INTO collections(collection_code,name,description,state) VALUES('desk-greenery','Owner collection','Owner-authored synthetic description','active')");
    $ownerCollectionId = (int) $db->insert_id;
    $execute('INSERT INTO collection_products(collection_id,product_id,explanation,sort_order) VALUES(?,?,?,?)',
        [$ownerCollectionId, $product['product_id'], 'Owner-authored membership explanation.', 7]);
    $db->commit();

    [$code, $output] = $runFixture();
    $check($code === 0 && str_contains($output, 'desk-greenery: collision; existing collection preserved.'), 'Existing collection code is handled as a collision.');
    $check((int) ($query('SELECT COUNT(*) AS n FROM collections WHERE collection_id=? AND name=? AND description=?',
        [$ownerCollectionId, 'Owner collection', 'Owner-authored synthetic description'])[0]['n'] ?? 0) === 1,
        'Collection collision preserves owner fields.');
    $check((int) ($query('SELECT COUNT(*) AS n FROM collection_products WHERE collection_id=? AND product_id=? AND explanation=? AND sort_order=?',
        [$ownerCollectionId, $product['product_id'], 'Owner-authored membership explanation.', 7])[0]['n'] ?? 0) === 1,
        'Collection collision preserves owner membership.');

    // Change and remove synthetic fixture memberships, commit them so the child process can observe them, then replay.
    $green = $query("SELECT collection_id FROM collections WHERE collection_code='green-events'")[0] ?? null;
    if ($green === null) { throw new RuntimeException('Fixture did not insert the green-events collection.'); }
    $greenId = (int) $green['collection_id'];
    $members = $query('SELECT product_id, explanation, sort_order FROM collection_products WHERE collection_id=? ORDER BY sort_order LIMIT 2', [$greenId]);
    if (count($members) !== 2) { throw new RuntimeException('Green event fixture does not contain two memberships.'); }
    $editedProductId = (int) $members[0]['product_id'];
    $removedProductId = (int) $members[1]['product_id'];
    $db->begin_transaction();
    $execute('UPDATE collection_products SET explanation=? WHERE collection_id=? AND product_id=?', ['Owner-edited synthetic explanation.', $greenId, $editedProductId]);
    $execute('DELETE FROM collection_products WHERE collection_id=? AND product_id=?', [$greenId, $removedProductId]);
    $db->commit();
    [$code, $output] = $runFixture();
    $check($code === 0 && str_contains($output, '4 already processed'), 'Fixture replay uses its processed ledger.');
    $check((int) ($query('SELECT COUNT(*) AS n FROM collection_products WHERE collection_id=? AND product_id=? AND explanation=?',
        [$greenId, $editedProductId, 'Owner-edited synthetic explanation.'])[0]['n'] ?? 0) === 1,
        'Fixture replay preserves an edited membership explanation.');
    $check((int) ($query('SELECT COUNT(*) AS n FROM collection_products WHERE collection_id=? AND product_id=?', [$greenId, $removedProductId])[0]['n'] ?? -1) === 0,
        'Fixture replay does not restore a membership removed by an owner.');

    $homes = $query("SELECT collection_id FROM collections WHERE collection_code='homes'")[0] ?? null;
    if ($homes === null) { throw new RuntimeException('Fixture did not insert the Homes collection.'); }
    $homesId = (int) $homes['collection_id'];
    $homeMembers = $query('SELECT product_id, explanation FROM collection_products WHERE collection_id=? ORDER BY sort_order LIMIT 2', [$homesId]);
    if (count($homeMembers) !== 2) { throw new RuntimeException('Homes fixture does not contain its expected memberships.'); }
    $editedHomeProductId = (int) $homeMembers[0]['product_id'];
    $removedHomeProductId = (int) $homeMembers[1]['product_id'];
    $homeCompositionCount = (int) ($query('SELECT COUNT(*) AS n FROM collection_compositions WHERE collection_id=?', [$homesId])[0]['n'] ?? 0);
    $check($homeCompositionCount === 2, 'Homes fixture includes the two active configured plant arrangements.');
    $db->begin_transaction();
    $execute('UPDATE collection_products SET explanation=? WHERE collection_id=? AND product_id=?', ['Owner-edited Homes selection note.', $homesId, $editedHomeProductId]);
    $execute('DELETE FROM collection_products WHERE collection_id=? AND product_id=?', [$homesId, $removedHomeProductId]);
    $db->commit();
    [$code, $output] = $runFixture();
    $check($code === 0 && str_contains($output, '4 already processed'), 'Homes fixture replay stays ledger-protected.');
    $check((int) ($query('SELECT COUNT(*) AS n FROM collection_products WHERE collection_id=? AND product_id=? AND explanation=?',
        [$homesId, $editedHomeProductId, 'Owner-edited Homes selection note.'])[0]['n'] ?? 0) === 1,
        'Homes replay preserves owner-edited membership content.');
    $check((int) ($query('SELECT COUNT(*) AS n FROM collection_products WHERE collection_id=? AND product_id=?', [$homesId, $removedHomeProductId])[0]['n'] ?? -1) === 0,
        'Homes replay does not restore membership removed by an owner.');

    // Clear only the rows created by this test, then force a later referenced SKU to be unavailable.
    $db->begin_transaction();
    $fixtureCollections = $query("SELECT collection_id FROM collections WHERE collection_code IN ('green-events','floral-inspiration','homes')");
    foreach ($fixtureCollections as $row) {
        $id = (int) $row['collection_id'];
        $execute('DELETE FROM collection_compositions WHERE collection_id=?', [$id]);
        $execute('DELETE FROM collection_products WHERE collection_id=?', [$id]);
    }
    $execute('DELETE FROM collection_fixture_ledger WHERE fixture_code=?', [$fixtureCode]);
    foreach ($fixtureCollections as $row) { $execute('DELETE FROM collections WHERE collection_id=?', [(int) $row['collection_id']]); }
    $db->commit();

    $tree = $query("SELECT product_id, archived_at FROM products WHERE sku='TREE-OLIVE'")[0] ?? null;
    if ($tree === null || $tree['archived_at'] !== null) { throw new RuntimeException('Rollback case requires TREE-OLIVE to start active.'); }
    $treeOliveId = (int) $tree['product_id'];
    $treeOliveArchive = $tree['archived_at'];
    $execute('UPDATE products SET archived_at=UTC_TIMESTAMP() WHERE product_id=?', [$treeOliveId]);
    [$code, $output] = $runFixture();
    $check($code === 1 && str_contains($output, 'referenced starter SKU is not active'), 'Missing prerequisite fails the fixture.');
    $check((int) ($query('SELECT COUNT(*) AS n FROM collections WHERE collection_id<>?', [$ownerCollectionId])[0]['n'] ?? -1) === 0,
        'Missing prerequisite rolls back collection inserts.');
    $check((int) ($query('SELECT COUNT(*) AS n FROM collection_fixture_ledger')[0]['n'] ?? -1) === 0,
        'Missing prerequisite rolls back collision and fixture ledger rows.');
} finally {
    if ($ownerCollectionId !== null && $db->errno === 0) {
        try {
            if ($treeOliveId !== null) { $execute('UPDATE products SET archived_at=? WHERE product_id=?', [$treeOliveArchive, $treeOliveId]); }
            $rows = $query("SELECT collection_id FROM collections WHERE collection_code IN ('desk-greenery','green-events','floral-inspiration','homes')");
            $db->begin_transaction();
            foreach ($rows as $row) {
                $id = (int) $row['collection_id'];
                $execute('DELETE FROM collection_fixture_ledger WHERE collection_id=?', [$id]);
                $execute('DELETE FROM collection_compositions WHERE collection_id=?', [$id]);
                $execute('DELETE FROM collection_products WHERE collection_id=?', [$id]);
                $execute('DELETE FROM collections WHERE collection_id=?', [$id]);
            }
            $execute('DELETE FROM collection_fixture_ledger WHERE fixture_code=?', [$fixtureCode]);
            $db->commit();
        } catch (Throwable $cleanupError) {
            try { $db->rollback(); } catch (Throwable) { }
            $db->close();
            throw new RuntimeException('Fixture-test cleanup could not restore the setup schema.', 0, $cleanupError);
        }
    }
    $db->close();
}

echo "Collection fixture checks: $checks passed, 0 failed.\n";
