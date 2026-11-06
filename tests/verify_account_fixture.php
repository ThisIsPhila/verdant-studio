<?php

declare(strict_types=1);

// Test-only helper: print the verification link for the latest usable token
// issued to an email address. Usage:
//   APP_ENV=local DB_NAME=<verdant_test_*> php tests/verify_account_fixture.php <email>

require dirname(__DIR__) . '/app/bootstrap.php';

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
    fwrite(STDERR, "Provide the registered email address.\n");
    exit(2);
}

$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$statement = $db->prepare(
    "SELECT o.context_json FROM email_outbox o
     JOIN email_verification_tokens t ON t.email_verification_id = o.email_verification_id
     WHERE o.template_key = 'verify_email' AND LOWER(o.recipient_email) = ?
       AND t.consumed_at IS NULL AND t.revoked_at IS NULL AND t.expires_at > UTC_TIMESTAMP()
     ORDER BY o.outbox_id DESC LIMIT 1"
);
$statement->execute([$email]);
$row = $statement->get_result()->fetch_assoc() ?: null;
$statement->close();
$db->close();

$context = is_array($row) ? json_decode((string) $row['context_json'], true) : null;
$sealed = is_array($context) ? ($context['verification_token_sealed'] ?? null) : null;
if (!is_string($sealed)) {
    fwrite(STDERR, "No usable verification link for that address.\n");
    exit(1);
}
$token = PrivateTokenCipher::open($sealed);
if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
    fwrite(STDERR, "No usable verification link for that address.\n");
    exit(1);
}
echo '/verify-email?token=' . $token . "\n";
