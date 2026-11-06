<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;

if (PHP_SAPI !== 'cli' || preg_match('/^verdant_test_[a-z0-9_]+$/', getenv('DB_NAME') ?: '') !== 1) {
    fwrite(STDERR, "Use an isolated verdant_test_ database.\n");
    exit(2);
}

$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
};
$run = static function () use (&$checks): string {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/database/bin/seed-catalogue.php') . ' apply 2>&1';
    exec($command, $output, $status);
    if ($status !== 0) {
        throw new RuntimeException('Catalogue fixture command failed; inspect its safe test output.');
    }
    return implode("\n", $output);
};
$one = static function (mysqli $connection, string $sql, array $parameters = []): ?array {
    $statement = $connection->prepare($sql);
    $statement->execute($parameters);
    try {
        return $statement->get_result()->fetch_assoc() ?: null;
    } finally {
        $statement->close();
    }
};

try {
    $products = require dirname(__DIR__) . '/database/seeds/catalogue.php';
    $check(count($products) === 28, 'The starter fixture has 28 stable offers.');
    $ledger = (int) ($one($db, "SELECT COUNT(*) AS n FROM catalogue_fixture_ledger WHERE fixture_code='starter-catalogue-v1'")['n'] ?? 0);
    $freshFixture = $ledger === 0;

    if ($ledger === 0) {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $db->begin_transaction();
        try {
            $statement = $db->prepare('INSERT INTO categories (name) VALUES (?)');
            $statement->execute(['Fixture collision ' . $suffix]);
            $collisionCategory = (int) $db->insert_id;
            $statement->close();
            $statement = $db->prepare("INSERT INTO products
                (category_id, sku, name, description, light_level, watering_level, toxicity,
                 product_kind, selling_unit, size_label, details_status,
                 buy_enabled, rent_enabled, retail_price_minor)
                VALUES (?, 'TOOL-BRUSH', 'Soil brush', 'Synthetic collision record', 'unknown', 'unknown', 'unknown',
                        'accessory', 'Each', 'Each', 'unknown', 1, 0, 7600)");
            $statement->execute([$collisionCategory]);
            $collisionId = (int) $db->insert_id;
            $statement->close();
            $statement = $db->prepare('INSERT INTO inventory (product_id, on_hand) VALUES (?, 13)');
            $statement->execute([$collisionId]);
            $statement->close();

            $existingCategory = $one($db, "SELECT category_id, archived_at FROM categories WHERE name='Tools & care'");
            if ($existingCategory === null) {
                $statement = $db->prepare("INSERT INTO categories (name, archived_at) VALUES ('Tools & care', UTC_TIMESTAMP())");
                $statement->execute();
                $archivedCategory = (int) $db->insert_id;
                $statement->close();
            } else {
                if ($existingCategory['archived_at'] !== null) {
                    throw new RuntimeException('The isolated test database already contains an archived Tools & care category.');
                }
                $archivedCategory = (int) $existingCategory['category_id'];
                $statement = $db->prepare('UPDATE categories SET archived_at = COALESCE(archived_at, UTC_TIMESTAMP()) WHERE category_id = ?');
                $statement->execute([$archivedCategory]);
                $statement->close();
            }
            $db->commit();
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/database/bin/seed-catalogue.php') . ' apply 2>&1';
        try {
            exec($command, $failedOutput, $failedStatus);
            $check($failedStatus !== 0 && str_contains(implode("\n", $failedOutput), 'archived category'),
                'An archived category causes the fixture transaction to stop with a clear diagnostic.');
            $check((int) ($one($db, "SELECT COUNT(*) AS n FROM catalogue_fixture_ledger WHERE fixture_code='starter-catalogue-v1'")['n'] ?? 0) === 0,
                'A category failure rolls back every fixture ledger row.');
            $check((int) ($one($db, "SELECT COUNT(*) AS n FROM products WHERE sku LIKE 'TREE-%' OR sku LIKE 'PL-%' OR sku LIKE 'VG-%' OR sku LIKE 'FL-%' OR sku LIKE 'BQ-%' OR sku LIKE 'POT-%' OR sku LIKE 'CARE-%'")['n'] ?? 0) === 0,
                'A category failure rolls back all preceding fixture products.');
            $check($one($db, 'SELECT category_id FROM categories WHERE category_id = ?', [$archivedCategory]) !== null,
                'The test-owned archived category remains available for explicit recovery.');
        } finally {
            $statement = $db->prepare('UPDATE categories SET archived_at = NULL WHERE category_id = ?');
            $statement->execute([$archivedCategory]);
            $statement->close();
        }
    }

    $firstOutput = $run();
    $rows = [];
    foreach ($products as $row) {
        $rows[$row[0]] = $row;
    }
    $fixtureRows = $db->query("SELECT l.sku, l.disposition, l.product_id, p.category_id, p.product_kind,
                                      p.selling_unit, p.size_label, p.colour_label, p.light_level,
                                      p.watering_level, p.toxicity, p.image_path, p.rent_enabled,
                                      p.buy_enabled, p.retail_price_minor, p.rental_daily_rate_minor
                               FROM catalogue_fixture_ledger l
                               LEFT JOIN products p ON p.product_id = l.product_id
                               WHERE l.fixture_code = 'starter-catalogue-v1'")->fetch_all(MYSQLI_ASSOC);
    $bySku = [];
    foreach ($fixtureRows as $row) {
        $bySku[$row['sku']] = $row;
    }
    $check(count($bySku) === 28, 'Every stable SKU receives one ledger disposition.');
    $check(count(array_intersect(array_keys($rows), array_keys($bySku))) === 28, 'All 28 stable offer SKUs are accounted for.');
    $expectedByCategory = [];
    foreach ($rows as $row) {
        $expectedByCategory[$row[1]] = ($expectedByCategory[$row[1]] ?? 0) + 1;
        $fixture = $bySku[$row[0]];
        if ($fixture['disposition'] === 'inserted') {
            $check($fixture['product_kind'] === $row[2] && $fixture['selling_unit'] === $row[6]
                && $fixture['size_label'] === $row[4] && $fixture['colour_label'] === $row[5],
                'Typed detail fields match the stable offer ' . $row[0] . '.');
            $check($fixture['light_level'] === 'unknown' && $fixture['watering_level'] === 'unknown'
                && $fixture['toxicity'] === 'unknown', 'Care and safety remain unknown for ' . $row[0] . '.');
            $check(is_file(dirname(__DIR__) . '/public' . $fixture['image_path']), 'Placeholder loads for ' . $row[0] . '.');
            if ($freshFixture) {
                $check((int) $fixture['buy_enabled'] === 1
                    && (int) $fixture['retail_price_minor'] === $row[8]
                    && (($row[9] === null && $fixture['rental_daily_rate_minor'] === null)
                        || (int) $fixture['rental_daily_rate_minor'] === $row[9]),
                    'Synthetic prices and eligible modes match ' . $row[0] . '.');
            }
        }
        if (in_array($row[2], ['cut_flower', 'cut_foliage', 'floral_arrangement'], true)) {
            $check((int) $fixture['rent_enabled'] === 0, 'Floral offer ' . $row[0] . ' is sale-only.');
        }
    }
    if ($freshFixture) {
        $check(str_contains($firstOutput, '27 inserted, 1 collisions, 0 already processed'),
            'The first fixture run inserts missing offers and reports the existing SKU collision.');
    }
    $categoryCounts = [];
    foreach ($db->query("SELECT c.name, COUNT(*) AS n FROM catalogue_fixture_ledger l
                         JOIN products p ON p.product_id = l.product_id
                         JOIN categories c ON c.category_id = p.category_id
                         WHERE l.fixture_code = 'starter-catalogue-v1' AND l.disposition='inserted'
                         GROUP BY c.name")->fetch_all(MYSQLI_ASSOC) as $row) {
        $categoryCounts[$row['name']] = (int) $row['n'];
    }
    foreach ($expectedByCategory as $category => $count) {
        $collisionsInCategory = 0;
        foreach ($rows as $sku => $row) {
            if ($row[1] === $category && $bySku[$sku]['disposition'] === 'collision') {
                ++$collisionsInCategory;
            }
        }
        $check(($categoryCounts[$category] ?? 0) === $count - $collisionsInCategory,
            'Inserted category count is correct for ' . $category . '.');
    }

    $collision = $bySku['TOOL-BRUSH'];
    if ($collision['disposition'] === 'collision') {
        $record = $one($db, 'SELECT p.retail_price_minor, i.on_hand, p.name FROM products p JOIN inventory i ON i.product_id=p.product_id WHERE p.sku=?', ['TOOL-BRUSH']);
        $check((int) $record['retail_price_minor'] === 7600 && (int) $record['on_hand'] === 13
            && $record['name'] === 'Soil brush', 'An existing SKU collision preserves its product and stock.');
    }

    $monstera = $one($db, "SELECT p.product_id FROM products p JOIN catalogue_fixture_ledger l ON l.product_id=p.product_id
                          WHERE l.fixture_code='starter-catalogue-v1' AND l.sku='PL-MON-S' AND l.disposition='inserted'");
    $check($monstera !== null, 'The replay edit target is a fixture-owned product.');
    $editedImage = '/assets/images/catalogue/placeholders/other.svg';
    $editedAlt = 'Administrator selected neutral catalogue illustration; not product photography.';
    $statement = $db->prepare('UPDATE products SET retail_price_minor = 19876, image_path = ?, image_alt = ? WHERE product_id = ?');
    $statement->execute([$editedImage, $editedAlt, $monstera['product_id']]);
    $statement->close();
    $statement = $db->prepare('UPDATE inventory SET on_hand = 17, version = version + 1 WHERE product_id = ?');
    $statement->execute([$monstera['product_id']]);
    $statement->close();
    $secondOutput = $run();
    $edited = $one($db, 'SELECT p.retail_price_minor, p.image_path, p.image_alt, i.on_hand FROM products p JOIN inventory i ON i.product_id=p.product_id WHERE p.product_id=?', [$monstera['product_id']]);
    $check((int) $edited['retail_price_minor'] === 19876 && (int) $edited['on_hand'] === 17,
        'Fixture replay preserves an administrator-edited price and stock.');
    $check($edited['image_path'] === $editedImage && $edited['image_alt'] === $editedAlt,
        'Fixture replay preserves an administrator-edited image and alt text.');
    $check(str_contains($secondOutput, '28 already processed'), 'Fixture replay reports all rows as already processed.');
    echo "Catalogue fixture checks: $checks passed, 0 failed.\n";
} finally {
    $db->close();
}
