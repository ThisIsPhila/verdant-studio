<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Identity\PasswordRecoveryRepository;
use Verdant\Identity\RecoveryIdentifier;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;

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
$put = static function (string $sql, array $parameters = []) use ($statement, $db): int {
    $query = $statement($sql, $parameters);
    $query->close();
    return (int) $db->insert_id;
};
$reject = static function (callable $operation, int $code, string $message) use ($check): void {
    try {
        $operation();
        $check(false, $message);
    } catch (mysqli_sql_exception $error) {
        $check((int) $error->getCode() === $code, $message);
    }
};

$tag = bin2hex(random_bytes(8));
$customerId = null;
$db->begin_transaction();
try {
    $customerId = $put(
        "INSERT INTO customers (email, password_hash, name, role, account_state)
         VALUES (?, 'unused-test-hash', 'Recovery fixture', 'customer', 'active')",
        ["recovery-$tag@example.test"],
    );
    $repository = new PasswordRecoveryRepository($db);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $firstToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $firstId = $repository->issueForActiveCustomer($customerId, 'store', $firstToken, $now->modify('+30 minutes'));
    $check($firstId !== null && $firstId > 0, 'An active customer receives one reset record.');
    $check($repository->findValidToken($firstToken, 'store') !== null, 'The token works only on its issuing surface.');
    $check($repository->findValidToken($firstToken, 'admin') === null, 'The token is rejected on the other surface.');
    $stored = $one('SELECT token_hash, requesting_surface FROM password_reset_tokens WHERE password_reset_id = ?', [$firstId]);
    $check($stored !== null && hash_equals($stored['token_hash'], hash('sha256', $firstToken, true))
        && !hash_equals($stored['token_hash'], $firstToken), 'Only the one-way token hash is persisted.');

    $enquiryId = $put(
        'INSERT INTO customer_enquiries (submission_key, name, email, topic, subject, message)
         VALUES (?, ?, ?, \'customer\', ?, ?)',
        [str_repeat('a', 64), 'Recovery fixture', "recovery-$tag@example.test", 'Test enquiry', 'Test message'],
    );
    $messageId = $put(
        "INSERT INTO enquiry_messages (enquiry_id, author_kind, author_customer_id, body, submission_key)
         VALUES (?, 'customer', ?, 'Test enquiry message', ?)",
        [$enquiryId, $customerId, str_repeat('b', 64)],
    );
    $outboxId = $put(
        "INSERT INTO email_outbox (password_reset_id, recipient_email, template_key, context_json)
         VALUES (?, ?, 'password_reset', '{}')",
        [$firstId, "recovery-$tag@example.test"],
    );
    $check($outboxId > 0, 'A reset message can use the new outbox source.');
    $reject(
        static fn () => $db->query("INSERT INTO email_outbox (recipient_email, template_key, context_json) VALUES ('none@example.test', 'invalid', '{}')"),
        3819,
        'The outbox rejects a row with no source.',
    );
    $reject(
        static fn () => $put(
            "INSERT INTO email_outbox (message_id, password_reset_id, recipient_email, template_key, context_json)
             VALUES (?, ?, 'multiple@example.test', 'invalid', '{}')",
            [$messageId, $firstId],
        ),
        3819,
        'The outbox rejects multiple sources while preserving valid support references.',
    );

    $secondToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $secondId = $repository->issueForActiveCustomer($customerId, 'admin', $secondToken, $now->modify('+30 minutes'));
    $check($secondId !== null && $repository->findValidToken($firstToken, 'store') === null,
        'A new request revokes the previous unused token.');
    $check($repository->findValidToken($secondToken, 'admin') !== null, 'The replacement token is valid on its own surface.');
    $check((int) $one(
        'SELECT COUNT(*) AS total FROM password_reset_tokens WHERE customer_id = ? AND consumed_at IS NULL AND revoked_at IS NULL',
        [$customerId],
    )['total'] === 1, 'The account has no more than one active token.');

    $thirdToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $duplicateActiveToken = hash('sha256', $thirdToken, true);
    $reject(
        static fn () => $put(
            "INSERT INTO password_reset_tokens (customer_id, token_hash, requesting_surface, expires_at)
             VALUES (?, ?, 'store', UTC_TIMESTAMP() + INTERVAL 30 MINUTE)",
            [$customerId, $duplicateActiveToken],
        ),
        1062,
        'The database prevents a second active token for one account.',
    );

    $emailFingerprint = RecoveryIdentifier::email("recovery-$tag@example.test");
    $window = $now->setTime((int) $now->format('H'), (int) $now->format('i'), 0);
    $firstAttempt = $repository->recordRateLimitAttempt('request_email', $emailFingerprint, $window, $window->modify('+1 hour'));
    $secondAttempt = $repository->recordRateLimitAttempt('request_email', $emailFingerprint, $window, $window->modify('+1 hour'));
    $check($firstAttempt === 1 && $secondAttempt === 2, 'Rate-limit attempts increment atomically in a fixed window.');
    $limiter = $one(
        'SELECT subject_fingerprint FROM auth_recovery_rate_limits WHERE purpose = ? AND subject_fingerprint = ? AND window_started_at = ?',
        ['request_email', $emailFingerprint, $window->format('Y-m-d H:i:s')],
    );
    $check($limiter !== null && hash_equals($limiter['subject_fingerprint'], $emailFingerprint)
        && !hash_equals($limiter['subject_fingerprint'], "recovery-$tag@example.test"), 'Rate-limit storage contains only fixed-size keyed fingerprints.');

    $expiredFingerprint = RecoveryIdentifier::sourceAddress('192.0.2.44');
    $oldWindow = $window->modify('-2 hours');
    $put(
        'INSERT INTO auth_recovery_rate_limits (purpose, subject_fingerprint, window_started_at, attempt_count, expires_at)
         VALUES (?, ?, ?, 1, ?)',
        ['request_source', $expiredFingerprint, $oldWindow->format('Y-m-d H:i:s'), $oldWindow->modify('+1 hour')->format('Y-m-d H:i:s')],
    );
    $check($repository->deleteExpiredRateLimitBuckets(1) === 1, 'Expired limiter rows are pruned in bounded batches.');

    $consumed = $repository->consume($secondId);
    $check($consumed && !$repository->consume($secondId), 'Token consumption has a single winner and cannot replay.');
    $check($repository->findValidToken($secondToken, 'admin') === null, 'A consumed token is no longer valid.');

    $beforeVersion = (int) $one('SELECT credential_version FROM customers WHERE customer_id = ?', [$customerId])['credential_version'];
    $replacementHash = password_hash('A replacement recovery password 2026', PASSWORD_DEFAULT);
    $check($repository->replacePasswordHash($customerId, $replacementHash), 'A valid password reset updates the active account.');
    $afterPasswordUpdate = $one('SELECT password_hash, credential_version FROM customers WHERE customer_id = ?', [$customerId]);
    $check($afterPasswordUpdate !== null && password_verify('A replacement recovery password 2026', $afterPasswordUpdate['password_hash'])
        && (int) $afterPasswordUpdate['credential_version'] === $beforeVersion + 1,
        'Password replacement increments the account credential version atomically.');

    $oldToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $oldId = $repository->issueForActiveCustomer($customerId, 'store', $oldToken, $now->modify('+30 minutes'));
    $db->query('UPDATE password_reset_tokens SET created_at = UTC_TIMESTAMP() - INTERVAL 2 HOUR,
        expires_at = UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE password_reset_id = ' . (int) $oldId);
    $check($repository->invalidateExpiredTokens(1) === 1, 'Expired tokens are invalidated in bounded batches.');
    $check($repository->findValidToken($oldToken, 'store') === null, 'An invalidated expired token cannot be used.');

    $archive = $statement("UPDATE customers SET account_state = 'archived', archived_at = UTC_TIMESTAMP() WHERE customer_id = ?", [$customerId]);
    $archive->close();
    $check($repository->issueForActiveCustomer($customerId, 'store', $thirdToken, $now->modify('+30 minutes')) === null,
        'Archived accounts cannot receive reset tokens.');
    $check($repository->findValidToken($oldToken, 'store') === null, 'An archived account cannot use an existing token.');

    $db->rollback();
    echo "Password recovery persistence checks: $checks passed.\n";
} catch (Throwable $error) {
    $db->rollback();
    throw $error;
}
