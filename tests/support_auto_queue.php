<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Admin\AdminAccess;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Identity\SessionManager;
use Verdant\Support\CustomerEnquiryService;

$database = getenv('DB_NAME') ?: '';
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'local'
    || preg_match('/^verdant_test_[a-z0-9_]+$/D', $database) !== 1) {
    fwrite(STDERR, "Use APP_ENV=local and an isolated verdant_test_ database.\n");
    exit(2);
}

$_SERVER['HTTP_HOST'] = 'verdant.localhost:8088';
$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    ++$checks;
    echo "PASS: {$label}\n";
};

$tag = bin2hex(random_bytes(6));
$key = bin2hex(random_bytes(32));
$adminId = null;
$mailEnvKeys = ['EMAIL_AUTO_QUEUE', 'EMAIL_TRANSPORT', 'EMAIL_FROM_ADDRESS', 'EMAIL_FROM_NAME',
    'EMAIL_REPLY_TO', 'EMAIL_BASE_URL', 'EMAIL_OPEN_DELIVERY', 'EMAIL_TEST_RECIPIENTS'];
$previousMailEnv = [];
foreach ($mailEnvKeys as $mailKey) {
    $previousMailEnv[$mailKey] = getenv($mailKey);
}

try {
    putenv('EMAIL_AUTO_QUEUE=1');
    putenv('EMAIL_TRANSPORT=local_capture');
    putenv('EMAIL_FROM_ADDRESS=verdant@example.test');
    putenv('EMAIL_FROM_NAME=Verdant');
    putenv('EMAIL_REPLY_TO=admin@thisisphila.co.za');
    putenv('EMAIL_BASE_URL=http://127.0.0.1:8088');
    putenv('EMAIL_OPEN_DELIVERY=1');
    putenv('EMAIL_TEST_RECIPIENTS=');

    $insertAdmin = $db->prepare(
        "INSERT INTO customers(email,password_hash,name,role,account_state)
         VALUES(?,'test-hash','Support Auto Queue Admin','admin','active')"
    );
    $insertAdmin->execute(["support-auto-$tag@example.test"]);
    $adminId = (int) $db->insert_id;
    $insertAdmin->close();

    SessionManager::start();
    $_SESSION = [];
    $_SESSION['_customer_enquiry_key'] = $key;
    $_SESSION['_customer_enquiry_times'] = [];
    $service = new CustomerEnquiryService($db, new AdminAccess($db));
    $created = $service->submit([
        'name' => 'Guest Shopper',
        'email' => "support-auto-$tag@example.test",
        'topic' => 'customer',
        'subject' => 'Automatic delivery',
        'order_reference' => '',
        'message' => 'Please help with my order.',
        'submission_key' => $key,
    ], null);
    $check($created, 'A guest enquiry is persisted with automatic delivery configured.');

    $statement = $db->prepare(
        'SELECT o.delivery_state, o.transport FROM email_outbox o
         JOIN enquiry_messages m ON m.message_id = o.message_id
         WHERE m.submission_key = ?'
    );
    $statement->execute([$key]);
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    $allQueued = $rows !== [];
    foreach ($rows as $row) {
        $allQueued = $allQueued && $row['delivery_state'] === 'queued'
            && $row['transport'] === 'local_capture';
    }
    $check($allQueued, 'New support messages queue automatically for permitted recipients.');

    echo "Support auto-queue checks: {$checks} passed, 0 failed.\n";
} finally {
    foreach ($previousMailEnv as $mailKey => $value) {
        putenv($value === false ? $mailKey : $mailKey . '=' . $value);
    }
    $delete = $db->prepare('DELETE FROM customer_enquiries WHERE submission_key = ?');
    $delete->execute([$key]);
    $delete->close();
    if ($adminId !== null) {
        $deleteAdmin = $db->prepare('DELETE FROM customers WHERE customer_id = ?');
        $deleteAdmin->execute([$adminId]);
        $deleteAdmin->close();
    }
    $db->close();
}
