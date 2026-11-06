<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Storefront\StorefrontSectionService;

if (PHP_SAPI !== 'cli' || preg_match('/^verdant_test_[a-z0-9_]+$/', getenv('DB_NAME') ?: '') !== 1
    || count($argv) !== 4 || !in_array($argv[3], ['-1', '1'], true)) {
    fwrite(STDERR, "Invalid isolated reorder worker invocation.\n");
    exit(2);
}

try {
    $db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
    (new StorefrontSectionService($db))->move($argv[1], $argv[2], (int) $argv[3]);
    $db->close();
    fwrite(STDOUT, "reordered\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Isolated reorder worker failed: ' . $error::class . ' code=' . $error->getCode() . ".\n");
    exit(1);
}
