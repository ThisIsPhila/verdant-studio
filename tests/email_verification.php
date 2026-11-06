<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Admin\AdminAccess;
use Verdant\Config\TrustedOrigins;
use Verdant\Identity\CustomerRepository;
use Verdant\Identity\IdentityService;
use Verdant\Identity\VerificationRepository;
use Verdant\Identity\VerificationService;
use Verdant\Notifications\EmailActivity;
use Verdant\Notifications\EmailRenderer;
use Verdant\Notifications\VerificationMailer;
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

$verificationIds = [];
$outboxIds = [];
$customerIds = [];
$adminCreated = null;
$captureDirectory = sys_get_temp_dir() . '/verdant-email-verification-' . bin2hex(random_bytes(8));
$envKeys = ['EMAIL_OPEN_DELIVERY', 'EMAIL_AUTO_QUEUE', 'EMAIL_TRANSPORT', 'EMAIL_FROM_ADDRESS',
    'EMAIL_FROM_NAME', 'EMAIL_REPLY_TO', 'EMAIL_BASE_URL'];
$previous = [];
foreach ($envKeys as $envKey) {
    $previous[$envKey] = getenv($envKey);
}
// A fresh source address per run keeps hourly verification buckets isolated across reruns.
$source = '127.0.1.' . random_int(2, 254);
try {
    putenv('EMAIL_OPEN_DELIVERY=1');
    putenv('EMAIL_AUTO_QUEUE=1');
    putenv('EMAIL_TRANSPORT=local_capture');
    putenv('EMAIL_FROM_ADDRESS=owner@example.test');
    putenv('EMAIL_FROM_NAME=Verdant Test');
    putenv('EMAIL_REPLY_TO=reply@example.test');
    putenv('EMAIL_BASE_URL=http://127.0.0.1:8088');

    $repository = new VerificationRepository($db);
    $mailer = new VerificationMailer($db);
    $service = new VerificationService($db, $repository, $mailer);
    $identity = new IdentityService(new CustomerRepository($db), $service);
    $password = 'synthetic-verification-password';

    $latestMail = static function (int $customerId) use ($one): ?array {
        return $one("SELECT * FROM email_outbox WHERE recipient_customer_id = ? AND template_key = 'verify_email'
            ORDER BY outbox_id DESC LIMIT 1", [$customerId]);
    };
    $tokenFor = static function (array $mailRow): string {
        $context = json_decode((string) $mailRow['context_json'], true);
        $sealed = is_array($context) ? ($context['verification_token_sealed'] ?? null) : null;
        if (!is_string($sealed)) {
            throw new RuntimeException('Verification mail is missing its sealed token.');
        }
        return PrivateTokenCipher::open($sealed);
    };

    $emailA = 'verify-a-' . bin2hex(random_bytes(4)) . '@example.test';
    $idA = $identity->register([
        'email' => $emailA, 'name' => 'Verification Fixture A',
        'password' => $password, 'password_confirmation' => $password,
    ]);
    $customerIds[] = $idA;
    $stateA = $one('SELECT account_state FROM customers WHERE customer_id = ?', [$idA]);
    $check(($stateA['account_state'] ?? null) === 'unverified', 'Registration creates an unverified account.');
    $mailA = $latestMail($idA);
    $check($mailA !== null, 'Registration queues a verification email.');
    if ($mailA !== null) {
        $outboxIds[] = (int) $mailA['outbox_id'];
        $verificationIds[] = (int) $mailA['email_verification_id'];
    }
    $check(($mailA['delivery_state'] ?? null) === 'queued' && ($mailA['transport'] ?? null) === 'local_capture',
        'Verification mail to a new account is queued when open delivery is enabled.');
    $tokenA = $tokenFor($mailA ?? []);
    $check(preg_match('/^[A-Za-z0-9_-]{43}$/D', $tokenA) === 1
        && !str_contains((string) ($mailA['context_json'] ?? ''), $tokenA),
        'Outbox stores a sealed verification token, never its plaintext.');

    $messageA = EmailRenderer::render($mailA, 'http://127.0.0.1:8088', TrustedOrigins::local());
    $check(str_contains($messageA['text'], 'http://verdant.localhost:8088/verify-email?token=' . $tokenA)
        && str_contains($messageA['subject'], 'Verify your Verdant email address'),
        'Verification mail links to the trusted store origin with a query token.');

    $check($identity->authenticate($emailA, $password) === null, 'Unverified accounts cannot authenticate.');
    $check($identity->unverifiedCredentialsValid($emailA, $password), 'Correct unverified credentials are recognised for messaging.');
    $check(!$identity->unverifiedCredentialsValid($emailA, 'wrong synthetic password'),
        'Wrong passwords are not recognised as unverified credentials.');

    $confirmed = $service->confirm($tokenA, $source);
    $check(($confirmed['customer_id'] ?? null) === $idA && ($confirmed['credential_version'] ?? 0) >= 1,
        'A valid verification token activates the account and returns sign-in details.');
    $stateAfter = $one('SELECT account_state FROM customers WHERE customer_id = ?', [$idA]);
    $usedToken = $one('SELECT consumed_at FROM email_verification_tokens WHERE email_verification_id = ?',
        [(int) $mailA['email_verification_id']]);
    $check(($stateAfter['account_state'] ?? null) === 'active' && ($usedToken['consumed_at'] ?? null) !== null,
        'Confirmation flips the account to active and consumes the token.');
    $check($identity->authenticate($emailA, $password) !== null, 'Verified accounts can authenticate.');
    $check($service->confirm($tokenA, $source) === null, 'A consumed verification token cannot be reused.');
    $check($service->confirm('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', $source) === null,
        'An unknown verification token is rejected.');

    $emailB = 'verify-b-' . bin2hex(random_bytes(4)) . '@example.test';
    $idB = $identity->register([
        'email' => $emailB, 'name' => 'Verification Fixture B',
        'password' => $password, 'password_confirmation' => $password,
    ]);
    $customerIds[] = $idB;
    $firstMailB = $latestMail($idB);
    $outboxIds[] = (int) $firstMailB['outbox_id'];
    $verificationIds[] = (int) $firstMailB['email_verification_id'];
    $firstTokenB = $tokenFor($firstMailB);
    $service->resend($emailB, $source);
    $secondMailB = $latestMail($idB);
    $outboxIds[] = (int) $secondMailB['outbox_id'];
    $verificationIds[] = (int) $secondMailB['email_verification_id'];
    $secondTokenB = $tokenFor($secondMailB);
    $check((int) $secondMailB['outbox_id'] !== (int) $firstMailB['outbox_id'] && $secondTokenB !== $firstTokenB,
        'Resend issues a fresh verification token and mail.');
    $check($service->confirm($firstTokenB, $source) === null, 'A superseded verification token is rejected.');

    $config = new EmailConfig(
        'local_capture',
        'owner@example.test',
        'Verdant Test',
        'reply@example.test',
        'http://127.0.0.1:8088',
        EmailConfig::TEST_RECIPIENTS,
        $captureDirectory,
        openDelivery: true,
    );
    $dispatcher = new EmailDispatcher($db, $config, new LocalCaptureTransport($captureDirectory, $config));
    $deliveryB = $dispatcher->dispatchSelected([(int) $secondMailB['outbox_id']]);
    $captureB = @file_get_contents($captureDirectory . '/' . $secondMailB['outbox_id'] . '.eml');
    $check(($deliveryB['captured'] ?? 0) === 1 && is_string($captureB) && str_contains($captureB, $secondTokenB),
        'Verification mail for an unverified account is captured without external delivery.');
    $deliveryA = $dispatcher->dispatchSelected([(int) $mailA['outbox_id']]);
    $staleState = $one('SELECT delivery_state, failure_code FROM email_outbox WHERE outbox_id = ?', [(int) $mailA['outbox_id']]);
    $check(($deliveryA['held'] ?? 0) === 1 && ($staleState['failure_code'] ?? null) === 'recipient_identity_changed',
        'Verification mail is held once its account is already active.');

    $admin = $one("SELECT customer_id FROM customers WHERE role = 'admin' LIMIT 1");
    if ($admin === null) {
        $query = $statement(
            "INSERT INTO customers(email,password_hash,name,role,account_state) VALUES(?,'unused-verification-fixture','Verification admin','admin','active')",
            ['verify-admin-' . bin2hex(random_bytes(4)) . '@example.test'],
        );
        $query->close();
        $adminCreated = (int) $db->insert_id;
        $adminId = $adminCreated;
    } else {
        $adminId = (int) $admin['customer_id'];
    }
    $activity = new EmailActivity($db, new AdminAccess($db));
    $preview = $activity->detail(['customer_id' => $adminId], (int) $secondMailB['outbox_id']);
    $check(is_array($preview) && !isset($preview['context']['verification_token_sealed']) && !isset($preview['context_json'])
        && !str_contains(json_encode($preview['preview'], JSON_THROW_ON_ERROR), $secondTokenB),
        'Admin email preview redacts the verification token and message link.');

    for ($attempt = 0; $attempt < 5; ++$attempt) {
        $service->resend($emailB, $source);
    }
    $tokenCount = $one('SELECT COUNT(*) AS total FROM email_verification_tokens WHERE customer_id = ?', [$idB]);
    foreach ($db->query('SELECT outbox_id, email_verification_id FROM email_outbox WHERE recipient_customer_id = ' . $idB
        . " AND template_key = 'verify_email'")->fetch_all(MYSQLI_ASSOC) as $row) {
        $outboxIds[] = (int) $row['outbox_id'];
        $verificationIds[] = (int) $row['email_verification_id'];
    }
    $check((int) ($tokenCount['total'] ?? 0) === 6, 'Verification resends are rate limited per email address.');

    $freshMailB = $latestMail($idB);
    $db->query('UPDATE email_verification_tokens SET created_at = UTC_TIMESTAMP() - INTERVAL 26 HOUR,
        expires_at = UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE email_verification_id = ' . (int) $freshMailB['email_verification_id']);
    $check($service->confirm($tokenFor($freshMailB), $source) === null, 'An expired verification token is rejected.');

    putenv('EMAIL_OPEN_DELIVERY');
    $emailC = 'verify-c-' . bin2hex(random_bytes(4)) . '@example.test';
    $idC = $identity->register([
        'email' => $emailC, 'name' => 'Verification Fixture C',
        'password' => $password, 'password_confirmation' => $password,
    ]);
    $customerIds[] = $idC;
    $mailC = $latestMail($idC);
    $outboxIds[] = (int) $mailC['outbox_id'];
    $verificationIds[] = (int) $mailC['email_verification_id'];
    $check(($mailC['delivery_state'] ?? null) === 'held',
        'Verification mail to a non-allowlisted address stays held by default.');
    $service->resend($emailC, $source);
    $resendCount = $one('SELECT COUNT(*) AS total FROM email_verification_tokens WHERE customer_id = ?', [$idC]);
    $check((int) ($resendCount['total'] ?? 0) === 1, 'Verification resends for non-allowlisted addresses issue nothing by default.');
    putenv('EMAIL_OPEN_DELIVERY=1');

    // Hosted mode: verification must also issue outside APP_ENV=local, because
    // hosted registration depends on the same Resend mail flow.
    putenv('APP_ENV=production');
    putenv('SUPPORT_TOKEN_KEY=' . base64_encode(random_bytes(32)));
    $emailHosted = 'verify-hosted-' . bin2hex(random_bytes(4)) . '@example.test';
    $idHosted = $identity->register([
        'email' => $emailHosted, 'name' => 'Verification Fixture Hosted',
        'password' => $password, 'password_confirmation' => $password,
    ]);
    $customerIds[] = $idHosted;
    $mailHosted = $latestMail($idHosted);
    $check($mailHosted !== null && ($mailHosted['delivery_state'] ?? null) === 'queued',
        'Hosted registration queues a verification email outside local mode.');
    if ($mailHosted !== null) {
        $outboxIds[] = (int) $mailHosted['outbox_id'];
        $verificationIds[] = (int) $mailHosted['email_verification_id'];
    }
    putenv('APP_ENV=local');
    putenv('SUPPORT_TOKEN_KEY');

    echo "Email verification checks: $checks passed; no external email sent.\n";
} finally {
    foreach ($envKeys as $envKey) {
        $oldValue = $previous[$envKey];
        putenv($oldValue === false ? $envKey : $envKey . '=' . $oldValue);
    }
    foreach (array_unique($outboxIds) as $outboxId) {
        $statement('DELETE FROM email_outbox WHERE outbox_id = ?', [$outboxId])->close();
    }
    foreach (array_unique($verificationIds) as $verificationId) {
        $statement('DELETE FROM email_verification_tokens WHERE email_verification_id = ?', [$verificationId])->close();
    }
    foreach (array_unique($customerIds) as $customerId) {
        $statement('DELETE FROM customers WHERE customer_id = ?', [$customerId])->close();
    }
    if ($adminCreated !== null) {
        $statement('DELETE FROM customers WHERE customer_id = ?', [$adminCreated])->close();
    }
    foreach (glob($captureDirectory . '/*') ?: [] as $file) {
        @unlink($file);
    }
    if (is_dir($captureDirectory)) {
        @rmdir($captureDirectory);
    }
    $db->close();
}
