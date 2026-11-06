<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;

if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'local' || getenv('DB_NAME') !== 'verdant_test_phase014') {
    fwrite(STDERR, "Use only APP_ENV=local and DB_NAME=verdant_test_phase014.\n");
    exit(2);
}
$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$action = $argv[1] ?? '';
if ($action === 'create') {
    $suffix = bin2hex(random_bytes(6));
    $password = password_hash('VerdantMediaHttp!2026', PASSWORD_DEFAULT);
    $emails = ["media-customer-$suffix@example.test", "media-admin-$suffix@example.test"];
    $ids = [];
    try {
        $statement = $db->prepare('INSERT INTO customers (email,password_hash,name,role,account_state) VALUES (?,?,?,? ,\'active\')');
        foreach ([['Media HTTP Customer', 'customer'], ['Media HTTP Admin', 'admin']] as $index => [$name, $role]) {
            $statement->execute([$emails[$index], $password, $name, $role]);
            $ids[] = (int) $db->insert_id;
        }
        $statement->close();
        $allow = $db->prepare('INSERT IGNORE INTO admin_allowlist (email) VALUES (?)');
        $allow->execute([$emails[1]]);
        $allow->close();
    } catch (Throwable $error) {
        foreach ($ids as $id) {
            $cleanup = $db->prepare('DELETE FROM customers WHERE customer_id = ?');
            $cleanup->execute([$id]);
            $cleanup->close();
        }
        throw $error;
    }
    echo json_encode(['emails' => $emails, 'ids' => $ids], JSON_THROW_ON_ERROR), "\n";
} elseif ($action === 'cleanup' && isset($argv[2])) {
    $fixture = json_decode($argv[2], true, 8, JSON_THROW_ON_ERROR);
    foreach (($fixture['ids'] ?? []) as $id) {
        if (!is_int($id) || $id < 1) continue;
        foreach (['admin_totp_backup_codes', 'admin_totp_credentials'] as $table) {
            $statement = $db->prepare("DELETE FROM $table WHERE customer_id = ?");
            $statement->execute([$id]);
            $statement->close();
        }
        $statement = $db->prepare('DELETE FROM customers WHERE customer_id = ?');
        $statement->execute([$id]);
        $statement->close();
    }
    foreach (($fixture['emails'] ?? []) as $email) {
        if (!is_string($email) || $email === '') continue;
        $statement = $db->prepare('DELETE FROM admin_allowlist WHERE email = ?');
        $statement->execute([$email]);
        $statement->close();
    }
} else {
    fwrite(STDERR, "Use create or cleanup <fixture-json>.\n");
    exit(2);
}
$db->close();
