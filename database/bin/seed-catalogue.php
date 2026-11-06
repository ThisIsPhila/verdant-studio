<?php

declare(strict_types=1);

use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== 'apply' || isset($argv[2])) {
    fwrite(STDERR, "Usage: php database/bin/seed-catalogue.php apply\n");
    exit(1);
}

function catalogueStatement(mysqli $connection, string $sql, array $parameters = []): mysqli_stmt
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
    $products = require dirname(__DIR__) . '/seeds/catalogue.php';
    if (count($products) !== 33) {
        throw new RuntimeException('Starter catalogue must contain exactly 33 offers.');
    }
    $config = DatabaseConfig::fromEnvironment();
    $connection = ConnectionFactory::connect($config);
    $lockName = 'verdant:catalogue-fixture:' . substr(hash('sha256', $config->name), 0, 35);
    $lock = catalogueStatement($connection, 'SELECT GET_LOCK(?, 5)', [$lockName]);
    $locked = (int) $lock->get_result()->fetch_row()[0] === 1;
    $lock->close();
    if (!$locked) {
        throw new RuntimeException('Another catalogue fixture run is active.');
    }

    $migration = catalogueStatement(
        $connection,
        "SELECT m.status, m.checksum FROM schema_migrations m WHERE m.migration_name = '005_catalogue_kinds.sql'",
    );
    $migrationRow = $migration->get_result()->fetch_assoc();
    $migration->close();
    $migrationPath = dirname(__DIR__) . '/migrations/005_catalogue_kinds.sql';
    if (($migrationRow['status'] ?? null) !== 'applied'
        || !hash_equals((string) ($migrationRow['checksum'] ?? ''), (string) hash_file('sha256', $migrationPath))) {
        throw new RuntimeException('Apply the current migrations before the catalogue fixture.');
    }

    $connection->begin_transaction();
    $inTransaction = true;
    $categoryIds = [];
    $counts = ['inserted' => 0, 'collision' => 0, 'already processed' => 0];
    $placeholderByKind = [
        'plant' => 'plant.svg', 'cut_flower' => 'cut-flower.svg',
        'cut_foliage' => 'cut-foliage.svg', 'accessory' => 'accessory.svg',
        'plant_arrangement' => 'plant-arrangement.svg',
        'floral_arrangement' => 'floral-arrangement.svg',
    ];
    // Customer-facing catalogue copy, shared with the Lovable prototype listings.
    $descriptionsBySku = [
        'PL-MON-S' => 'Split leaves give this compact Monstera a bold, sculptural character.',
        'PL-MON-M' => 'Split leaves give this Monstera a bold, sculptural character.',
        'PL-MON-L' => 'Large split leaves make this Monstera a striking focal point.',
        'PL-FIG-S' => 'Broad fiddle-shaped leaves bring a strong, upright form to a room.',
        'PL-FIG-M' => 'Broad fiddle-shaped leaves bring a strong, upright form to a room.',
        'PL-FIG-L' => 'A taller Fiddle Leaf Fig with broad leaves and a sculptural silhouette.',
        'PL-BIRD-S' => 'Upright stems and paddle-shaped leaves add a clean, architectural line.',
        'PL-BIRD-M' => 'Upright stems and paddle-shaped leaves add a clean, architectural line.',
        'PL-BIRD-L' => 'A tall Bird of Paradise with broad leaves for a dramatic focal point.',
        'PL-ZZ' => 'Glossy paired leaflets form a tidy, architectural silhouette.',
        'TREE-CHERRY' => 'Delicate pink blossom brings a seasonal note to this flowering tree.',
        'TREE-OLIVE' => 'Fine silvery foliage and a branching trunk give this tree a calm, sculptural presence.',
        'TOP-BOX' => 'Clipped evergreen foliage creates a neat, sculpted shape.',
        'SHR-PITT' => 'Fine evergreen foliage forms a softly rounded green silhouette.',
        'VG-N' => 'A narrow planted panel brings a vertical layer of greenery to a wall.',
        'VG-M' => 'A medium planted panel creates a composed vertical garden feature.',
        'VG-L' => 'A larger planted panel makes greenery a defining feature of the space.',
        'FL-ROSE' => 'A bunch of ten fresh rose stems, ready to arrange.',
        'FL-LILY' => 'Five fresh lily stems bring height and graceful form to a vase.',
        'FL-PROTEA' => 'A distinctive protea stem adds sculptural form to a floral display.',
        'FL-EUC' => 'A mixed bunch of eucalyptus foliage for bouquets and vase arrangements.',
        'BQ-WHITE' => 'A softly composed bouquet in a timeless palette of white blooms and fresh foliage.',
        'BQ-WILD' => 'A relaxed, textural bouquet inspired by the varied forms of a garden in bloom.',
        'BQ-PASTEL' => 'A gentle mix of pastel flowers, finished with seasonal foliage.',
        'POT-STONE-S' => 'A softly textured stone planter with a rounded, understated profile.',
        'POT-STONE-L' => 'A larger stone planter with a rounded profile and natural surface.',
        'POT-TERRA' => 'Warm terracotta gives this classic planter a natural clay finish.',
        'POT-CHAR' => 'A deep charcoal finish gives this planter a quiet, contemporary presence.',
        'POT-SAND' => 'A sand-toned ribbed surface adds subtle texture to this planter.',
        'TOOL-SHEARS' => 'A pair of pruning shears for trimming and maintaining plants.',
        'TOOL-CAN' => 'A considered watering can for everyday indoor and garden care.',
        'CARE-FOOD' => 'Plant food for routine care across a home collection of plants.',
        'TOOL-BRUSH' => 'A compact brush for tidying soil around planters and potting areas.',
    ];

    foreach ($products as $row) {
        [$sku, $categoryName, $kind, $name, $size, $colour, $unit, $mode, $retail, $rental, $stock] = $row;
        $statement = catalogueStatement(
            $connection,
            'SELECT disposition FROM catalogue_fixture_ledger WHERE fixture_code = ? AND sku = ?',
            ['starter-catalogue-v1', $sku],
        );
        $processed = $statement->get_result()->fetch_assoc();
        $statement->close();
        if ($processed !== null) {
            ++$counts['already processed'];
            echo $sku . ': already processed (' . $processed['disposition'] . ").\n";
            continue;
        }

        if (!isset($categoryIds[$categoryName])) {
            $statement = catalogueStatement($connection, 'SELECT category_id, archived_at FROM categories WHERE name = ?', [$categoryName]);
            $category = $statement->get_result()->fetch_assoc();
            $statement->close();
            if ($category !== null) {
                if ($category['archived_at'] !== null) {
                    throw new RuntimeException('A starter category name is occupied by an archived category; inspect that category before proceeding.');
                }
                $categoryIds[$categoryName] = (int) $category['category_id'];
            } else {
                catalogueStatement($connection, 'INSERT INTO categories (name) VALUES (?)', [$categoryName])->close();
                $categoryIds[$categoryName] = (int) $connection->insert_id;
            }
        }

        $statement = catalogueStatement($connection, 'SELECT product_id FROM products WHERE sku = ?', [$sku]);
        $collision = $statement->get_result()->fetch_assoc();
        $statement->close();
        if ($collision !== null) {
            catalogueStatement(
                $connection,
                "INSERT INTO catalogue_fixture_ledger (fixture_code, sku, disposition, product_id) VALUES (?, ?, 'collision', NULL)",
                ['starter-catalogue-v1', $sku],
            )->close();
            ++$counts['collision'];
            echo $sku . ": collision; existing product preserved.\n";
            continue;
        }

        $buy = in_array($mode, ['sale', 'both'], true);
        $rent = $mode === 'both';
        $imageFile = $placeholderByKind[$kind] ?? 'other.svg';
        $imagePath = '/assets/images/catalogue/placeholders/' . $imageFile;
        $kindLabel = ucfirst(str_replace('_', ' ', $kind));
        $description = $descriptionsBySku[$sku]
            ?? 'Synthetic demonstration offer. Product specifications and care information have not been verified.';
        $alt = 'Illustrative ' . strtolower($kindLabel) . ' placeholder; not product photography.';
        catalogueStatement(
            $connection,
            'INSERT INTO products
                (category_id, sku, name, description, light_level, watering_level, toxicity,
                 image_path, image_alt, product_kind, selling_unit, size_label, colour_label, details_status,
                 buy_enabled, rent_enabled, retail_price_minor, rental_daily_rate_minor)
             VALUES (?, ?, ?, ?, \'unknown\', \'unknown\', \'unknown\', ?, ?, ?, ?, ?, ?, \'demonstration\', ?, ?, ?, ?)',
            [
                $categoryIds[$categoryName], $sku, $name, $description, $imagePath, $alt,
                $kind, $unit, $size, $colour, (int) $buy, (int) $rent,
                $buy ? $retail : null, $rent ? $rental : null,
            ],
        )->close();
        $productId = (int) $connection->insert_id;
        catalogueStatement($connection, 'INSERT INTO inventory (product_id, on_hand) VALUES (?, ?)', [$productId, $stock])->close();
        catalogueStatement(
            $connection,
            "INSERT INTO catalogue_fixture_ledger (fixture_code, sku, disposition, product_id) VALUES (?, ?, 'inserted', ?)",
            ['starter-catalogue-v1', $sku, $productId],
        )->close();
        ++$counts['inserted'];
        echo $sku . ": inserted.\n";
    }
    $connection->commit();
    $inTransaction = false;
    echo 'Catalogue fixture complete: ' . $counts['inserted'] . ' inserted, '
        . $counts['collision'] . ' collisions, ' . $counts['already processed'] . " already processed.\n";
} catch (Throwable $error) {
    if ($connection !== null && $inTransaction) {
        try {
            $connection->rollback();
        } catch (Throwable) {
            // Preserve the original failure; a closed connection has no live transaction.
        }
    }
    $message = $error instanceof RuntimeException && !$error instanceof mysqli_sql_exception
        ? $error->getMessage() : 'Catalogue fixture failed; inspect the isolated database privately.';
    fwrite(STDERR, $message . "\n");
    $failed = true;
} finally {
    if ($connection !== null) {
        try {
            if ($locked) {
                catalogueStatement($connection, 'SELECT RELEASE_LOCK(?)', [$lockName])->close();
            }
            $connection->close();
        } catch (Throwable) {
            fwrite(STDERR, "Database cleanup unavailable; connection will be released on exit.\n");
            $failed = true;
        }
    }
}
exit(isset($failed) ? 1 : 0);
