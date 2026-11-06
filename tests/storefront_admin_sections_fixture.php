<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;

if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'local' || getenv('DB_NAME') !== 'verdant_test_phase013_baseline') {
    fwrite(STDERR, "Use only APP_ENV=local and DB_NAME=verdant_test_phase013_baseline.\n");
    exit(2);
}

$action = $argv[1] ?? '';
if (!in_array($action, ['snapshot', 'restore'], true)) {
    fwrite(STDERR, "Expected snapshot or restore.\n");
    exit(2);
}

$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$pageQuery = $db->prepare("SELECT * FROM storefront_pages WHERE page_key = 'home'");
$pageQuery->execute();
$page = $pageQuery->get_result()->fetch_assoc();
$pageQuery->close();
if ($page === null) {
    fwrite(STDERR, "The seeded home storefront page is required.\n");
    exit(3);
}

if ($action === 'snapshot') {
    $sectionQuery = $db->prepare('SELECT * FROM page_sections WHERE storefront_page_id = ? ORDER BY page_section_id');
    $sectionQuery->execute([(int) $page['storefront_page_id']]);
    $sections = $sectionQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $sectionQuery->close();
    echo json_encode(['page' => $page, 'sections' => $sections], JSON_THROW_ON_ERROR);
    $db->close();
    exit(0);
}

$snapshot = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($snapshot) || !is_array($snapshot['page'] ?? null) || !is_array($snapshot['sections'] ?? null)
    || (int) ($snapshot['page']['storefront_page_id'] ?? 0) !== (int) $page['storefront_page_id']) {
    fwrite(STDERR, "Snapshot does not match the seeded home page.\n");
    exit(3);
}

$columns = [
    'page_section_id', 'storefront_page_id', 'section_key', 'section_type', 'position', 'is_enabled',
    'heading', 'body', 'media_path', 'media_alt', 'link_label', 'link_path', 'collection_id',
    'arrangement_id', 'archived_at', 'created_at', 'updated_at',
];
$db->begin_transaction();
try {
    $delete = $db->prepare('DELETE FROM page_sections WHERE storefront_page_id = ?');
    $delete->execute([(int) $page['storefront_page_id']]);
    $delete->close();
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $columnSql = implode(',', $columns);
    $insert = $db->prepare("INSERT INTO page_sections ($columnSql) VALUES ($placeholders)");
    foreach ($snapshot['sections'] as $section) {
        if (!is_array($section) || (int) ($section['storefront_page_id'] ?? 0) !== (int) $page['storefront_page_id']) {
            throw new RuntimeException('Snapshot contains a section outside the home page.');
        }
        $insert->execute(array_map(static fn (string $column): mixed => $section[$column] ?? null, $columns));
    }
    $insert->close();
    $db->commit();
    echo "Restored " . count($snapshot['sections']) . " home section rows.\n";
} catch (Throwable $error) {
    $db->rollback();
    throw $error;
} finally {
    $db->close();
}
