<?php

declare(strict_types=1);

// Test-only helper: print the base32 TOTP secret for an enrolled administrator.
// Usage:
//   APP_ENV=local DB_NAME=<verdant_test_*> php tests/totp_secret_fixture.php <email>

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Identity\Totp;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Support\PrivateTokenCipher;

if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'local'
    || preg_match('/^verdant_test_[a-z0-9_]+$/D', getenv('DB_NAME') ?: '') !== 1) {
    fwrite(STDERR, "Use APP_ENV=local and an isolated verdant_test_ database.\n");
    exit(2);
}

$email = strtolower(trim($argv[1] ?? ''));
if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Provide the administrator email address.\n");
    exit(2);
}

$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$statement = $db->prepare(
    "SELECT t.secret_sealed FROM admin_totp_credentials t
     JOIN customers c ON c.customer_id = t.customer_id
     WHERE LOWER(c.email) = ? AND c.role = 'admin' AND c.account_state = 'active'"
);
$statement->execute([$email]);
$row = $statement->get_result()->fetch_assoc() ?: null;
$statement->close();
$db->close();

if (!is_array($row)) {
    fwrite(STDERR, "No TOTP credential for that address.\n");
    exit(1);
}
$secret = PrivateTokenCipher::openOpaque((string) $row['secret_sealed']);
if (strlen($secret) !== Totp::SECRET_BYTES) {
    fwrite(STDERR, "No TOTP credential for that address.\n");
    exit(1);
}
echo Totp::base32Encode($secret) . "\n";
