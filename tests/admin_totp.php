<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Identity\Totp;
use Verdant\Identity\TotpRepository;
use Verdant\Identity\TotpService;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Support\PrivateTokenCipher;

$database = getenv('DB_NAME') ?: '';
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'local'
    || preg_match('/^verdant_test_[a-z0-9_]+$/D', $database) !== 1) {
    fwrite(STDERR, "Use APP_ENV=local and an isolated verdant_test_ database.\n");
    exit(2);
}

$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
    echo "PASS: $message\n";
};
$statement = static function (string $sql, array $parameters = []) use ($db): mysqli_stmt {
    $query = $db->prepare($sql);
    $query->execute($parameters);
    return $query;
};
$one = static function (string $sql, array $parameters = []) use ($statement): ?array {
    $query = $statement($sql, $parameters);
    try {
        return $query->get_result()->fetch_assoc() ?: null;
    } finally {
        $query->close();
    }
};

$customerIds = [];
// A fresh source address per run keeps hourly TOTP buckets isolated across reruns.
$source = '127.0.2.' . random_int(2, 254);
try {
    $rfcSecret = '12345678901234567890';
    $vectors = [59 => '287082', 1111111109 => '081804', 1111111111 => '050471',
        1234567890 => '005924', 2000000000 => '279037', 20000000000 => '353130'];
    $vectorsMatch = true;
    foreach ($vectors as $time => $expected) {
        $code = Totp::codeForCounter($rfcSecret, intdiv($time, 30));
        $vectorsMatch = $vectorsMatch && $code === $expected && Totp::verify($rfcSecret, $expected, $time, 0);
    }
    $check($vectorsMatch, 'TOTP codes match the RFC 6238 vectors at every reference time.');

    $raw = random_bytes(20);
    $encoded = Totp::base32Encode($raw);
    $check(Totp::base32Decode($encoded) === $raw && Totp::base32Decode('!!!!') === null
        && Totp::base32Decode('') === null, 'Base32 secrets round-trip and malformed input is rejected.');

    $stepCode = Totp::codeForCounter($raw, 1000);
    $check(Totp::verify($raw, Totp::codeForCounter($raw, 1001), 1000 * 30, 1)
        && !Totp::verify($raw, Totp::codeForCounter($raw, 1001), 1000 * 30, 0)
        && !Totp::verify($raw, Totp::codeForCounter($raw, 1003), 1000 * 30, 1)
        && !Totp::verify($raw, 'abcdef', 1000 * 30, 1),
        'Verification honours the drift window and rejects distant or malformed codes.');

    $uri = Totp::provisioningUri('Admin@Example.test', $raw);
    $check(str_starts_with($uri, 'otpauth://totp/Verdant%3Aadmin%40example.test?')
        && str_contains($uri, 'secret=' . $encoded) && str_contains($uri, 'issuer=Verdant')
        && str_contains($uri, 'digits=6') && str_contains($uri, 'period=30'),
        'Provisioning URIs carry the account, secret, and TOTP parameters.');

    $backup = Totp::generateBackupCode();
    $check(preg_match('/^[A-Z2-9]{8}$/D', $backup) === 1
        && Totp::normalizeBackupCode(strtolower(substr($backup, 0, 4) . '-' . substr($backup, 4))) === $backup
        && Totp::isBackupCodeFormat($backup) && !Totp::isBackupCodeFormat('123456'),
        'Backup codes use an unambiguous alphabet and tolerate user formatting.');

    $sealed = PrivateTokenCipher::sealOpaque($raw);
    $check(PrivateTokenCipher::openOpaque($sealed) === $raw && !str_contains($sealed, $raw),
        'Opaque secrets seal and open without exposing plaintext.');

    $repository = new TotpRepository($db);
    $service = new TotpService($db, $repository);
    $makeAdmin = static function (string $label) use ($statement, $db, &$customerIds): int {
        $query = $statement(
            "INSERT INTO customers(email,password_hash,name,role,account_state) VALUES(?,'unused-totp-fixture',?,'admin','active')",
            ['totp-' . $label . '-' . bin2hex(random_bytes(4)) . '@example.test', 'TOTP Fixture ' . $label],
        );
        $query->close();
        $id = (int) $db->insert_id;
        $customerIds[] = $id;
        return $id;
    };

    $adminA = $makeAdmin('a');
    $check($service->needsSetup($adminA), 'Administrators without a credential need enrolment.');
    $setup = $service->beginSetup($adminA, 'totp-a@example.test');
    $check($setup !== null && strlen((string) Totp::base32Decode($setup['secret'])) === 20
        && str_starts_with($setup['uri'], 'otpauth://totp/'), 'Enrolment issues a fresh secret and provisioning URI.');
    $repeat = $service->beginSetup($adminA, 'totp-a@example.test');
    $check($repeat !== null && $repeat['secret'] === $setup['secret'], 'Resumed enrolment reuses the pending secret.');

    $check($service->confirmSetup('000000', $adminA, $source) === null && $service->needsSetup($adminA),
        'Enrolment rejects a wrong confirmation code.');
    $secretA = Totp::base32Decode($setup['secret']);
    $codes = $service->confirmSetup(Totp::codeForCounter($secretA, intdiv(time(), 30)), $adminA, $source);
    $check(is_array($codes) && count($codes) === 10 && count(array_unique($codes)) === 10
        && !$service->needsSetup($adminA), 'A valid confirmation code enrols the admin and issues ten backup codes.');
    $check($service->beginSetup($adminA, 'totp-a@example.test') === null,
        'Enrolled administrators cannot start a second enrolment.');

    $check($service->challenge(Totp::codeForCounter($secretA, intdiv(time(), 30)), $adminA, $source),
        'The challenge accepts a current authenticator code.');
    $check(!$service->challenge('000000', $adminA, $source), 'The challenge rejects a wrong code.');
    $check($service->challenge($codes[0], $adminA, $source), 'The challenge accepts an unused backup code.');
    $check(!$service->challenge($codes[0], $adminA, $source), 'A consumed backup code cannot be reused.');
    $check($service->remainingBackupCodes($adminA) === 9, 'Consumed backup codes leave nine remaining.');

    $fresh = $service->regenerateBackupCodes($adminA);
    $check(is_array($fresh) && count($fresh) === 10 && $service->remainingBackupCodes($adminA) === 10
        && !$service->challenge($codes[1], $adminA, $source), 'Regeneration retires every old backup code.');

    $adminB = $makeAdmin('b');
    $setupB = $service->beginSetup($adminB, 'totp-b@example.test');
    $secretB = Totp::base32Decode($setupB['secret']);
    $service->confirmSetup(Totp::codeForCounter($secretB, intdiv(time(), 30)), $adminB, $source);
    for ($attempt = 0; $attempt < 9; ++$attempt) {
        $service->challenge('000000', $adminB, $source);
    }
    $check(!$service->challenge(Totp::codeForCounter($secretB, intdiv(time(), 30)), $adminB, $source),
        'Code attempts are rate limited per account even for a correct code.');

    $check($service->resetForAdmin($adminA) && $service->needsSetup($adminA)
        && !$service->challenge(Totp::codeForCounter($secretA, intdiv(time(), 30)), $adminA, $source),
        'Reset destroys the credential and forces re-enrolment.');

    $adminC = $makeAdmin('c');
    $target = $service->resettableAdmin('totp-a-' . 'x@example.test', $adminC);
    $check($target === null, 'Unknown addresses resolve to no resettable administrator.');
    $selfEmail = $one('SELECT email FROM customers WHERE customer_id = ?', [$adminC]);
    $check($service->resettableAdmin((string) $selfEmail['email'], $adminC) === null,
        'Administrators cannot reset their own two-factor authentication.');
    $otherEmail = $one('SELECT email FROM customers WHERE customer_id = ?', [$adminA]);
    $found = $service->resettableAdmin((string) $otherEmail['email'], $adminC);
    $check($found !== null && $found['customer_id'] === $adminA, 'Another active administrator can be reset.');

    echo "Admin TOTP checks: $checks passed.\n";
} finally {
    foreach (array_unique($customerIds) as $customerId) {
        $statement('DELETE FROM admin_totp_backup_codes WHERE customer_id = ?', [$customerId])->close();
        $statement('DELETE FROM admin_totp_credentials WHERE customer_id = ?', [$customerId])->close();
        $statement('DELETE FROM customers WHERE customer_id = ?', [$customerId])->close();
    }
    $db->close();
}
