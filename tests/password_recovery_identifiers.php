<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Identity\RecoveryIdentifier;
use Verdant\Support\SupportTokenCipher;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
};

$email = 'person@example.test';
$emailHash = RecoveryIdentifier::email('  PERSON@example.test ');
$check(strlen($emailHash) === 32, 'Email limiter value is a fixed 32-byte fingerprint.');
$check(hash_equals($emailHash, RecoveryIdentifier::email($email)), 'Email fingerprints normalize case and surrounding whitespace.');
$check(!hash_equals(hash('sha256', $email, true), $emailHash), 'Email fingerprints are keyed, not plain hashes.');

$ip = '192.0.2.18';
$sourceHash = RecoveryIdentifier::sourceAddress($ip);
$check(strlen($sourceHash) === 32 && !hash_equals($sourceHash, $ip), 'Source limiter value is a fixed non-reversible fingerprint.');
$check(!hash_equals($emailHash, $sourceHash), 'Email and source purposes produce separate fingerprints.');

$token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$tokenHash = RecoveryIdentifier::token($token);
$check(strlen($tokenHash) === 32 && !hash_equals(hash('sha256', $token, true), $tokenHash), 'Token limiter fingerprint is purpose-keyed.');

try {
    RecoveryIdentifier::sourceAddress('not-an-ip');
    $check(false, 'Invalid source address is rejected.');
} catch (InvalidArgumentException) {
    $check(true, 'Invalid source address is rejected.');
}
try {
    RecoveryIdentifier::token('not-a-token');
    $check(false, 'Malformed recovery token is rejected.');
} catch (InvalidArgumentException) {
    $check(true, 'Malformed recovery token is rejected.');
}
try {
    SupportTokenCipher::fingerprint('unapproved-purpose', 'value');
    $check(false, 'Unapproved fingerprint purpose is rejected.');
} catch (InvalidArgumentException) {
    $check(true, 'Unapproved fingerprint purpose is rejected.');
}

echo "Recovery identifier checks: $checks passed.\n";
