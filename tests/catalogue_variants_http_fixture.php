<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;

if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'local' || getenv('DB_NAME') !== 'verdant_test_phase012') {
    fwrite(STDERR, "Use only the isolated Phase 012 HTTP test database.\n");
    exit(2);
}

$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$tag = bin2hex(random_bytes(8));
$accounts = [];
foreach ([['admin', 'admin'], ['customer', 'customer']] as [$label, $role]) {
    $email = 'variants-http-' . $label . '-' . $tag . '@example.test';
    $password = bin2hex(random_bytes(18));
    $statement = $db->prepare(
        'INSERT INTO customers (email, password_hash, name, role, account_state) VALUES (?, ?, ?, ?, \'active\')',
    );
    $statement->execute([$email, password_hash($password, PASSWORD_DEFAULT), 'Synthetic HTTP ' . $label, $role]);
    $accounts[$label . '_email'] = $email;
    $accounts[$label . '_password'] = $password;
    if ($role === 'admin') {
        $allow = $db->prepare('INSERT IGNORE INTO admin_allowlist (email) VALUES (?)');
        $allow->execute([$email]);
        $allow->close();
    }
}
echo json_encode($accounts, JSON_THROW_ON_ERROR);
$db->close();
