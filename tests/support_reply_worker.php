<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Admin\AdminAccess;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Support\SupportConversationService;

$database = getenv('DB_NAME') ?: '';
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'local' || preg_match('/^verdant_test_[a-z0-9_]+$/D', $database) !== 1) exit(2);
$enquiryId = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$key = $argv[2] ?? '';
if (!is_int($enquiryId) || preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) exit(2);
$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
try {
    $service = new SupportConversationService($db, new AdminAccess($db));
    echo $service->reply($enquiryId, ['body' => 'Concurrent duplicate reply', 'submission_key' => $key], [], $enquiryId) ? 'created' : 'duplicate';
} finally { $db->close(); }
