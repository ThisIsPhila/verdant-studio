<?php

declare(strict_types=1);

// Focused checks: password-reset requests honor open delivery for any valid account.

use Verdant\Identity\PasswordRecoveryRepository;
use Verdant\Identity\PasswordRecoveryService;
use Verdant\Notifications\PasswordRecoveryMailer;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;

require dirname(__DIR__) . '/app/bootstrap.php';

$database = getenv('DB_NAME') ?: '';
if (preg_match('/^verdant_test_[a-z0-9_]+$/D', $database) !== 1) {
    fwrite(STDERR, "Set DB_NAME=verdant_test_<name> before running this check.\n");
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
$envKeys = ['EMAIL_OPEN_DELIVERY', 'EMAIL_AUTO_QUEUE', 'EMAIL_TRANSPORT', 'EMAIL_FROM_ADDRESS',
    'EMAIL_FROM_NAME', 'EMAIL_REPLY_TO', 'EMAIL_BASE_URL'];
$previous = [];
foreach ($envKeys as $envKey) {
    $previous[$envKey] = getenv($envKey);
}
try {
    $email = 'opendelivery-' . bin2hex(random_bytes(4)) . '@example.test';
    $account = $one('SELECT customer_id FROM customers WHERE email = ? LIMIT 1', [$email]);
    if ($account === null) {
        $id = $put(
            'INSERT INTO customers(email,password_hash,name,role,account_state) VALUES(?,?,?,\'customer\',\'active\')',
            [$email, 'unused-open-delivery-fixture', 'Open delivery fixture'],
        );
    } else {
        $id = (int) $account['customer_id'];
    }
    $service = new PasswordRecoveryService($db, new PasswordRecoveryRepository($db), new PasswordRecoveryMailer($db));

    putenv('EMAIL_OPEN_DELIVERY');
    $service->request($email, 'store', '127.0.0.11');
    $closedToken = $one('SELECT password_reset_id FROM password_reset_tokens WHERE customer_id = ? ORDER BY password_reset_id DESC LIMIT 1', [$id]);
    $check($closedToken === null, 'Reset requests for non-allowlisted addresses issue nothing by default.');

    putenv('EMAIL_OPEN_DELIVERY=1');
    putenv('EMAIL_AUTO_QUEUE=1');
    putenv('EMAIL_TRANSPORT=local_capture');
    putenv('EMAIL_FROM_ADDRESS=owner@example.test');
    putenv('EMAIL_FROM_NAME=Verdant Test');
    putenv('EMAIL_REPLY_TO=reply@example.test');
    putenv('EMAIL_BASE_URL=http://127.0.0.1:8088');
    $service->request($email, 'store', '127.0.0.12');
    $openToken = $one('SELECT password_reset_id FROM password_reset_tokens WHERE customer_id = ? ORDER BY password_reset_id DESC LIMIT 1', [$id]);
    $check($openToken !== null, 'Reset requests for any valid account issue a token when open delivery is enabled.');
    if ($openToken !== null) {
        $resetIds[] = (int) $openToken['password_reset_id'];
        $mail = $one('SELECT outbox_id, delivery_state FROM email_outbox WHERE password_reset_id = ? LIMIT 1', [(int) $openToken['password_reset_id']]);
        if ($mail !== null) {
            $outboxIds[] = (int) $mail['outbox_id'];
        }
        $check(($mail['delivery_state'] ?? null) === 'queued', 'Reset mail for any valid account is queued when open delivery is enabled.');
    }

    echo "Password recovery open-delivery checks: $checks passed; no external email sent.\n";
} finally {
    foreach ($envKeys as $envKey) {
        $oldValue = $previous[$envKey];
        putenv($oldValue === false ? $envKey : $envKey . '=' . $oldValue);
    }
    foreach ($outboxIds as $outboxId) {
        $statement('DELETE FROM email_outbox WHERE outbox_id = ?', [$outboxId])->close();
    }
    foreach ($resetIds as $resetId) {
        $statement('DELETE FROM password_reset_tokens WHERE password_reset_id = ?', [$resetId])->close();
    }
    $db->close();
}
