<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;

if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'local' || getenv('DB_NAME') !== 'verdant_test_phase016') {
    fwrite(STDERR, "Use only APP_ENV=local and DB_NAME=verdant_test_phase016.\n");
    exit(2);
}

$action = $argv[1] ?? '';
if (!in_array($action, ['snapshot', 'restore'], true)) {
    fwrite(STDERR, "Expected snapshot or restore.\n");
    exit(2);
}

$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$keys = "'privacy','terms'";

/** @return list<array<string,mixed>> */
$fetchAll = static function (mysqli $db, string $sql): array {
    $result = $db->query($sql);
    try {
        return $result->fetch_all(MYSQLI_ASSOC);
    } finally {
        $result->free();
    }
};

if ($action === 'snapshot') {
    $snapshot = [
        'pages' => $fetchAll($db, "SELECT * FROM storefront_pages WHERE page_key IN ($keys) ORDER BY page_key"),
        'sections' => $fetchAll($db, "SELECT s.* FROM page_sections s JOIN storefront_pages p USING (storefront_page_id) WHERE p.page_key IN ($keys) ORDER BY s.page_section_id"),
        'states' => $fetchAll($db, "SELECT * FROM information_policy_state WHERE page_key IN ($keys) ORDER BY page_key"),
        'publications' => $fetchAll($db, "SELECT * FROM information_policy_publications WHERE page_key IN ($keys) ORDER BY publication_id"),
    ];
    echo json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $db->close();
    exit(0);
}

$snapshot = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($snapshot)) {
    fwrite(STDERR, "Invalid policy snapshot.\n");
    exit(3);
}
foreach (['pages', 'sections', 'states', 'publications'] as $key) {
    if (!is_array($snapshot[$key] ?? null)) {
        fwrite(STDERR, "Snapshot is missing $key rows.\n");
        exit(3);
    }
}

/** @param array<string,mixed> $row */
$insertRow = static function (mysqli $db, string $table, array $row): void {
    $allowedTables = ['storefront_pages', 'page_sections', 'information_policy_state', 'information_policy_publications'];
    if (!in_array($table, $allowedTables, true) || $row === []) {
        throw new RuntimeException('Unexpected table or empty snapshot row.');
    }
    foreach (array_keys($row) as $column) {
        if (!is_string($column) || preg_match('/^[a-z][a-z0-9_]*$/D', $column) !== 1) {
            throw new RuntimeException('Invalid snapshot column.');
        }
    }
    $columns = array_keys($row);
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $columns) . '`) VALUES ('
        . implode(',', array_fill(0, count($columns), '?')) . ')';
    $statement = $db->prepare($sql);
    try {
        $statement->execute(array_values($row));
    } finally {
        $statement->close();
    }
};

$db->begin_transaction();
try {
    $db->query("DELETE FROM information_policy_publications WHERE page_key IN ($keys)");
    $db->query("DELETE FROM information_policy_state WHERE page_key IN ($keys)");
    $db->query("DELETE s FROM page_sections s JOIN storefront_pages p USING (storefront_page_id) WHERE p.page_key IN ($keys)");
    $db->query("DELETE FROM storefront_pages WHERE page_key IN ($keys)");
    foreach ($snapshot['pages'] as $row) {
        if (!is_array($row) || !in_array($row['page_key'] ?? null, ['privacy', 'terms'], true)) {
            throw new RuntimeException('Snapshot contains an unregistered policy page.');
        }
        $insertRow($db, 'storefront_pages', $row);
    }
    foreach ($snapshot['sections'] as $row) {
        if (!is_array($row) || !isset($row['storefront_page_id'])) {
            throw new RuntimeException('Snapshot contains an invalid section.');
        }
        $insertRow($db, 'page_sections', $row);
    }
    foreach ($snapshot['states'] as $row) {
        if (!is_array($row) || !in_array($row['page_key'] ?? null, ['privacy', 'terms'], true)) {
            throw new RuntimeException('Snapshot contains an invalid policy state.');
        }
        $insertRow($db, 'information_policy_state', $row);
    }
    foreach ($snapshot['publications'] as $row) {
        if (!is_array($row) || !in_array($row['page_key'] ?? null, ['privacy', 'terms'], true)) {
            throw new RuntimeException('Snapshot contains an invalid publication.');
        }
        $insertRow($db, 'information_policy_publications', $row);
    }
    $db->commit();
    echo 'Restored only the isolated privacy and terms information-page records.';
} catch (Throwable $error) {
    $db->rollback();
    throw $error;
} finally {
    $db->close();
}
