<?php

declare(strict_types=1);

use Verdant\Media\MediaAssetRegistry;
use Verdant\Media\MediaAssignmentRepository;
use Verdant\Media\MediaAssignmentService;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Persistence\TransactionManager;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== 'apply' || isset($argv[2])) {
    fwrite(STDERR, "Usage: php database/bin/seed-product-media.php apply\n");
    exit(1);
}

$db = null;
$locked = false;
$lockName = '';
try {
    $config = DatabaseConfig::fromEnvironment();
    $db = ConnectionFactory::connect($config);
    $lockName = 'verdant:product-media:' . substr(hash('sha256', $config->name), 0, 32);
    $lock = $db->prepare('SELECT GET_LOCK(?, 5)');
    $lock->execute([$lockName]);
    $locked = (int) $lock->get_result()->fetch_row()[0] === 1;
    $lock->close();
    if (!$locked) throw new RuntimeException('Another product-media seed operation is active.');

    $migrationName = '012_product_media.sql';
    $statement = $db->prepare('SELECT status, checksum FROM schema_migrations WHERE migration_name = ?');
    $statement->execute([$migrationName]);
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    $path = dirname(__DIR__) . '/migrations/' . $migrationName;
    if (($row['status'] ?? null) !== 'applied'
        || !hash_equals((string) ($row['checksum'] ?? ''), (string) hash_file('sha256', $path))) {
        throw new RuntimeException('Apply the current database migrations before seeding media.');
    }

    $service = new MediaAssignmentService(new MediaAssignmentRepository($db), new TransactionManager($db), new MediaAssetRegistry());
    $counts = $service->seedDefaults();
    echo 'Media defaults: ' . $counts['inserted'] . ' inserted, ' . $counts['existing'] . ' already present, '
        . count($counts['skipped']) . " skipped.\n";
    foreach ($counts['skipped'] as $reason) echo 'Skipped: ' . $reason . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, ($error instanceof RuntimeException ? $error->getMessage() : 'Media seed failed; inspect private database state.') . "\n");
    exit(1);
} finally {
    if ($db !== null) {
        if ($locked) {
            $release = $db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
            $release->close();
        }
        $db->close();
    }
}
