<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Admin\AdminAccess;
use Verdant\Config\TrustedOrigins;
use Verdant\Identity\PasswordRecoveryRepository;
use Verdant\Notifications\EmailActivity;
use Verdant\Notifications\EmailRenderer;
use Verdant\Notifications\PasswordRecoveryMailer;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Support\Mail\EmailConfig;
use Verdant\Support\Mail\EmailDispatcher;
use Verdant\Support\Mail\LocalCaptureTransport;
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
$put = static function (string $sql, array $parameters = []) use ($statement, $db): int {
    $query = $statement($sql, $parameters);
    $query->close();
    return (int) $db->insert_id;
};

$resetIds = [];
$outboxIds = [];
$transactionOpen = false;
$captureDirectory = sys_get_temp_dir() . '/verdant-password-recovery-' . bin2hex(random_bytes(8));
try {
    $fixture = static function (string $email, string $role) use ($one, $put): array {
        $account = $one('SELECT customer_id, email, role, account_state FROM customers WHERE email = ? LIMIT 1', [$email]);
        if ($account === null) {
            $id = $put(
                'INSERT INTO customers(email,password_hash,name,role,account_state) VALUES(?,?,?, ?, \'active\')',
                [$email, 'unused-local-recovery-fixture', 'Local recovery fixture', $role],
            );
            $account = ['customer_id' => $id, 'email' => $email, 'role' => $role, 'account_state' => 'active'];
        }
        if ($account['role'] !== $role || $account['account_state'] !== 'active') {
            throw new RuntimeException('An approved local-capture fixture has an unexpected account state.');
        }
        return $account;
    };
    $customer = $fixture('customer@thisisphila.co.za', 'customer');
    $admin = $fixture('admin@thisisphila.co.za', 'admin');
    $customerId = (int) $customer['customer_id'];
    $adminId = (int) $admin['customer_id'];
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $repository = new PasswordRecoveryRepository($db);
    $mailer = new PasswordRecoveryMailer($db);
    $customerToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

    $db->begin_transaction();
    $transactionOpen = true;
    $customerResetId = $repository->issueForActiveCustomer($customerId, 'store', $customerToken, $now->modify('+30 minutes'));
    if ($customerResetId === null) {
        throw new RuntimeException('Customer token fixture was not issued.');
    }
    $resetIds[] = $customerResetId;
    $customerOutboxId = $mailer->queueReset(
        $customerResetId,
        $customerId,
        (string) $customer['email'],
        (string) $customer['role'],
        'store',
        $customerToken,
    );
    $sameCustomerOutboxId = $mailer->queueReset(
        $customerResetId,
        $customerId,
        (string) $customer['email'],
        (string) $customer['role'],
        'store',
        $customerToken,
    );
    $adminToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $adminResetId = $repository->issueForActiveCustomer($adminId, 'admin', $adminToken, $now->modify('+30 minutes'));
    if ($adminResetId === null) {
        throw new RuntimeException('Administrator token fixture was not issued.');
    }
    $resetIds[] = $adminResetId;
    $adminOutboxId = $mailer->queueReset(
        $adminResetId,
        $adminId,
        (string) $admin['email'],
        (string) $admin['role'],
        'admin',
        $adminToken,
    );
    $db->commit();
    $transactionOpen = false;
    $outboxIds = [$customerOutboxId, $adminOutboxId];

    $customerRow = $one('SELECT * FROM email_outbox WHERE outbox_id = ?', [$customerOutboxId]);
    $customerContext = json_decode((string) ($customerRow['context_json'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
    $sealed = $customerContext['reset_token_sealed'] ?? '';
    $check($customerOutboxId === $sameCustomerOutboxId, 'Duplicate enqueue for the same token and template is idempotent.');
    $check(($customerRow['password_reset_id'] ?? null) == $customerResetId
        && ($customerRow['delivery_state'] ?? null) === 'held'
        && ($customerRow['transport'] ?? null) === 'local_capture', 'Reset mail uses the recovery source and stays held for local capture.');
    $check(is_string($sealed) && !str_contains((string) $customerRow['context_json'], $customerToken)
        && PrivateTokenCipher::open($sealed) === $customerToken, 'Outbox stores a sealed token, never its plaintext.');

    $customerMessage = EmailRenderer::render($customerRow, 'http://127.0.0.1:8088', TrustedOrigins::local());
    $check(str_contains($customerMessage['text'], 'http://verdant.localhost:8088/password-reset#token=' . $customerToken)
        && !str_contains($customerMessage['text'], '?token='), 'Customer reset mail uses the trusted store origin and a fragment token.');
    $adminRow = $one('SELECT * FROM email_outbox WHERE outbox_id = ?', [$adminOutboxId]);
    $adminMessage = EmailRenderer::render($adminRow, 'http://127.0.0.1:8088', TrustedOrigins::local());
    $check(str_contains($adminMessage['text'], 'http://admin.verdant.localhost:8088/password-reset#token=' . $adminToken),
        'Administrator reset mail uses the trusted admin origin.');

    $activity = new EmailActivity($db, new AdminAccess($db));
    $preview = $activity->detail(['customer_id' => $adminId], $customerOutboxId);
    $check(is_array($preview) && !isset($preview['context']['reset_token_sealed']) && !isset($preview['context_json'])
        && !str_contains(json_encode($preview['preview'], JSON_THROW_ON_ERROR), $customerToken),
        'Admin email preview redacts the reset token and message link.');

    $config = new EmailConfig(
        'local_capture',
        'owner@example.test',
        'Verdant Test',
        'reply@example.test',
        'http://127.0.0.1:8088',
        EmailConfig::TEST_RECIPIENTS,
        $captureDirectory,
    );
    $transport = new LocalCaptureTransport($captureDirectory, $config);
    $dispatcher = new EmailDispatcher($db, $config, $transport);
    $check($dispatcher->releaseSelected([$customerOutboxId, $adminOutboxId]) === 2,
        'Only the approved local test identities can release recovery mail.');
    $delivery = $dispatcher->dispatchSelected([$customerOutboxId, $adminOutboxId]);
    $check($delivery['captured'] === 2, 'Customer and administrator reset messages are captured without external delivery.');
    $customerCapture = file_get_contents($captureDirectory . '/' . $customerOutboxId . '.eml');
    $check(is_string($customerCapture) && str_contains($customerCapture, $customerToken)
        && (fileperms($captureDirectory . '/' . $customerOutboxId . '.eml') & 0077) === 0,
        'Local reset capture contains the usable link in a private file.');

    $changedToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $db->begin_transaction();
    $transactionOpen = true;
    $changedResetId = $repository->issueForActiveCustomer($customerId, 'store', $changedToken, $now->modify('+30 minutes'));
    if ($changedResetId === null || !$repository->consume($changedResetId)) {
        throw new RuntimeException('Consumed password-change fixture was not created.');
    }
    $resetIds[] = $changedResetId;
    $changedOutboxId = $mailer->queuePasswordChanged(
        $changedResetId,
        $customerId,
        (string) $customer['email'],
        (string) $customer['role'],
    );
    $db->commit();
    $transactionOpen = false;
    $outboxIds[] = $changedOutboxId;
    $changedRow = $one('SELECT * FROM email_outbox WHERE outbox_id = ?', [$changedOutboxId]);
    $changedMessage = EmailRenderer::render($changedRow, 'http://127.0.0.1:8088', TrustedOrigins::local());
    $check(!str_contains($changedMessage['text'], $changedToken)
        && !str_contains($changedMessage['text'], '/password-reset')
        && str_contains($changedMessage['text'], 'password has been changed'), 'Password-changed mail contains no reset link or token.');
    $check($dispatcher->releaseSelected([$changedOutboxId]) === 1
        && $dispatcher->dispatchSelected([$changedOutboxId])['captured'] === 1,
        'The post-reset notice is captured only after token consumption.');
    $changedCapture = file_get_contents($captureDirectory . '/' . $changedOutboxId . '.eml');
    $check(is_string($changedCapture) && !str_contains($changedCapture, $changedToken)
        && !str_contains($changedCapture, 'password-reset'), 'Local password-change capture contains no reset credential.');

    $expiredToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $expiredResetId = $repository->issueForActiveCustomer($adminId, 'admin', $expiredToken, $now->modify('+30 minutes'));
    if ($expiredResetId === null) {
        throw new RuntimeException('Expired token fixture was not issued.');
    }
    $resetIds[] = $expiredResetId;
    $expiredOutboxId = $mailer->queueReset(
        $expiredResetId,
        $adminId,
        (string) $admin['email'],
        (string) $admin['role'],
        'admin',
        $expiredToken,
    );
    $outboxIds[] = $expiredOutboxId;
    $db->query('UPDATE password_reset_tokens SET created_at = UTC_TIMESTAMP() - INTERVAL 2 HOUR,
        expires_at = UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE password_reset_id = ' . $expiredResetId);
    $dispatcher->releaseSelected([$expiredOutboxId]);
    $expiredDelivery = $dispatcher->dispatchSelected([$expiredOutboxId]);
    $expiredState = $one('SELECT delivery_state, failure_code FROM email_outbox WHERE outbox_id = ?', [$expiredOutboxId]);
    $check($expiredDelivery['held'] === 1 && ($expiredState['failure_code'] ?? null) === 'password_reset_inactive'
        && !is_file($captureDirectory . '/' . $expiredOutboxId . '.eml'), 'Expired reset mail is held and never captured or delivered.');

    $autoQueueKeys = ['EMAIL_AUTO_QUEUE', 'EMAIL_TRANSPORT', 'EMAIL_FROM_ADDRESS', 'EMAIL_FROM_NAME',
        'EMAIL_REPLY_TO', 'EMAIL_BASE_URL', 'EMAIL_TEST_RECIPIENTS'];
    $autoQueuePrevious = [];
    foreach ($autoQueueKeys as $key) {
        $autoQueuePrevious[$key] = getenv($key);
    }
    putenv('EMAIL_AUTO_QUEUE=1');
    putenv('EMAIL_TRANSPORT=local_capture');
    putenv('EMAIL_FROM_ADDRESS=owner@example.test');
    putenv('EMAIL_FROM_NAME=Verdant Test');
    putenv('EMAIL_REPLY_TO=reply@example.test');
    putenv('EMAIL_BASE_URL=http://127.0.0.1:8088');
    putenv('EMAIL_TEST_RECIPIENTS=' . implode(',', EmailConfig::TEST_RECIPIENTS));
    try {
        $autoToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $autoResetId = $repository->issueForActiveCustomer($customerId, 'store', $autoToken, $now->modify('+30 minutes'));
        if ($autoResetId === null) {
            throw new RuntimeException('Auto-queue token fixture was not issued.');
        }
        $resetIds[] = $autoResetId;
        $autoOutboxId = $mailer->queueReset(
            $autoResetId, $customerId, (string) $customer['email'], (string) $customer['role'], 'store', $autoToken,
        );
        $outboxIds[] = $autoOutboxId;
        $autoRow = $one('SELECT delivery_state, transport FROM email_outbox WHERE outbox_id = ?', [$autoOutboxId]);
        $check(($autoRow['delivery_state'] ?? null) === 'queued'
            && ($autoRow['transport'] ?? null) === 'local_capture',
            'Reset mail to an allowlisted address is queued directly when auto-queue is configured.');
        $outsider = $fixture('outsider@example.test', 'customer');
        $outsiderToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $outsiderResetId = $repository->issueForActiveCustomer((int) $outsider['customer_id'], 'store', $outsiderToken, $now->modify('+30 minutes'));
        if ($outsiderResetId === null) {
            throw new RuntimeException('Outsider token fixture was not issued.');
        }
        $resetIds[] = $outsiderResetId;
        $outsiderOutboxId = $mailer->queueReset(
            $outsiderResetId, (int) $outsider['customer_id'], (string) $outsider['email'], 'customer', 'store', $outsiderToken,
        );
        $outboxIds[] = $outsiderOutboxId;
        $outsiderRow = $one('SELECT delivery_state FROM email_outbox WHERE outbox_id = ?', [$outsiderOutboxId]);
        $check(($outsiderRow['delivery_state'] ?? null) === 'held',
            'Reset mail to an address outside the allowlist stays held even when auto-queue is configured.');
    } finally {
        foreach ($autoQueueKeys as $key) {
            $oldValue = $autoQueuePrevious[$key];
            putenv($oldValue === false ? $key : $key . '=' . $oldValue);
        }
    }

    echo "Password recovery mail checks: $checks passed; no external email sent.\n";
} finally {
    if ($transactionOpen) {
        $db->rollback();
    }
    if ($outboxIds !== []) {
        $delete = $db->prepare('DELETE FROM email_outbox WHERE outbox_id = ?');
        foreach (array_unique($outboxIds) as $id) {
            $delete->execute([$id]);
        }
        $delete->close();
    }
    if ($resetIds !== []) {
        $delete = $db->prepare('UPDATE password_reset_tokens SET revoked_at = UTC_TIMESTAMP() WHERE password_reset_id = ? AND consumed_at IS NULL AND revoked_at IS NULL');
        foreach (array_unique($resetIds) as $id) {
            $delete->execute([$id]);
        }
        $delete->close();
    }
    foreach (glob($captureDirectory . '/*') ?: [] as $file) {
        @unlink($file);
    }
    if (is_dir($captureDirectory)) {
        @rmdir($captureDirectory);
    }
    $db->close();
}
