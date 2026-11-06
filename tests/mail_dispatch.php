<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Support\Mail\EmailConfig;
use Verdant\Support\Mail\EmailDispatcher;
use Verdant\Support\Mail\ICloudSmtpTransport;
use Verdant\Support\Mail\LocalCaptureTransport;
use Verdant\Support\Mail\MailTransportException;
use Verdant\Support\Mail\ResendTransport;

$transportOnly = in_array('--transport-only', $argv, true);
$database = getenv('DB_NAME') ?: '';
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'local'
    || (!$transportOnly && preg_match('/^verdant_test_[a-z0-9_]+$/D', $database) !== 1)) {
    fwrite(STDERR, $transportOnly
        ? "Use APP_ENV=local for transport-only checks.\n"
        : "Use APP_ENV=local and an isolated verdant_test_ database.\n");
    exit(2);
}

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    ++$checks;
    echo "PASS: {$label}\n";
};
$expectException = static function (callable $action, string $label) use ($check): void {
    try {
        $action();
    } catch (Throwable) {
        $check(true, $label);
        return;
    }
    $check(false, $label);
};

$tempDirectory = sys_get_temp_dir() . '/verdant-mail-test-' . bin2hex(random_bytes(8));
$connection = null;
$createdCustomerId = null;
$enquiryId = null;
$messageId = null;
$outboxIds = [];
$smtpOutboxIds = [];
$legacyOutboxId = null;
try {
    $expectException(static fn () => new EmailConfig(
        'local_capture', 'sender@example.test', 'Verdant', 'reply@example.test', 'http://localhost',
        ['other@example.test'], $tempDirectory,
    ), 'Configuration rejects a destination outside the two-address allowlist.');
    $expectException(static fn () => new EmailConfig(
        'local_capture', 'sender@example.test', 'Verdant', 'reply@example.test', 'http://mail.example.test',
        EmailConfig::TEST_RECIPIENTS, $tempDirectory,
    ), 'Remote application URLs require HTTPS.');
    $expectException(static fn () => new EmailConfig(
        'local_capture', 'sender@example.test', "Verdant\r\nBcc: attacker@example.test", 'reply@example.test', 'https://mail.example.test',
        EmailConfig::TEST_RECIPIENTS, $tempDirectory,
    ), 'Sender name rejects header injection.');
    $expectException(static fn () => new EmailConfig(
        'local_capture', "sender@example.test\r\nBcc: attacker@example.test", 'Verdant', 'reply@example.test', 'https://mail.example.test',
        EmailConfig::TEST_RECIPIENTS, $tempDirectory,
    ), 'Sender address rejects header injection.');
    $projectRoot = dirname(__DIR__);
    $expectException(static fn () => new EmailConfig(
        'local_capture', 'sender@example.test', 'Verdant', 'reply@example.test', 'http://localhost',
        EmailConfig::TEST_RECIPIENTS, $projectRoot . '/Docs/Technical/mail-captures',
    ), 'Custom capture paths under Docs are rejected.');
    $expectException(static fn () => new LocalCaptureTransport($projectRoot . '/public/mail-captures'),
        'The capture transport independently rejects public web-root paths.');

    $environmentKeys = [
        'EMAIL_TRANSPORT', 'EMAIL_FROM_ADDRESS', 'EMAIL_FROM_NAME', 'EMAIL_REPLY_TO', 'EMAIL_BASE_URL', 'EMAIL_TEST_RECIPIENTS',
        'FROM_ADDRESS', 'FROM_NAME', 'REPLY_TO', 'BASE_URL', 'TEST_RECIPIENTS',
    ];
    $previousEnvironment = [];
    foreach ($environmentKeys as $key) {
        $previousEnvironment[$key] = getenv($key);
    }
    putenv('EMAIL_TRANSPORT=local_capture');
    putenv('EMAIL_FROM_ADDRESS=approved@example.test');
    putenv('EMAIL_FROM_NAME=Approved Name');
    putenv('EMAIL_REPLY_TO=reply@example.test');
    putenv('EMAIL_BASE_URL=http://127.0.0.1:8088');
    putenv('EMAIL_TEST_RECIPIENTS=' . implode(',', EmailConfig::TEST_RECIPIENTS));
    putenv('FROM_ADDRESS=wrong@example.test');
    putenv('FROM_NAME=Wrong Name');
    putenv('REPLY_TO=wrong@example.test');
    putenv('BASE_URL=http://example.test');
    putenv('TEST_RECIPIENTS=wrong@example.test');
    $envConfig = EmailConfig::fromEnvironment($tempDirectory);
    foreach ($environmentKeys as $key) {
        $oldValue = $previousEnvironment[$key];
        putenv($oldValue === false ? $key : $key . '=' . $oldValue);
    }
    $check($envConfig->fromAddress === 'approved@example.test' && $envConfig->fromName === 'Approved Name'
        && $envConfig->replyTo === 'reply@example.test' && $envConfig->baseUrl === 'http://127.0.0.1:8088',
        'Configuration reads the approved EMAIL_* variables and ignores generic environment names.');

    $icloudConfig = new EmailConfig(
        'icloud_smtp', 'owner@icloud.com', 'Verdant Studio', 'owner@icloud.com', 'https://store.example.test',
        EmailConfig::TEST_RECIPIENTS, '', null, 'owner@icloud.com', 'fake-app-password',
    );
    $smtpRequest = null;
    $smtpExecutor = static function (array $request) use (&$smtpRequest): array {
        $smtpRequest = $request;
        return ['success' => true, 'smtp_code' => 250, 'curl_code' => 0];
    };
    $smtp = new ICloudSmtpTransport('owner@icloud.com', 'fake-app-password', $icloudConfig, $smtpExecutor);
    $smtpResult = $smtp->deliver(
        ['outbox_id' => 773, 'recipient_email' => EmailConfig::TEST_RECIPIENTS[0]],
        ['subject' => 'Your order is ready', 'html' => '<p>Thank you.</p>', 'text' => 'Thank you.'],
    );
    $check($smtpResult->state === 'provider_accepted', 'Only a completed SMTP 250 result is marked provider accepted.');
    $check($smtpRequest['url'] === 'smtp://smtp.mail.me.com:587/' && $smtpRequest['use_ssl'] === CURLUSESSL_ALL
        && $smtpRequest['verify_peer'] === true && $smtpRequest['verify_host'] === 2
        && !array_key_exists('username', $smtpRequest) && !array_key_exists('password', $smtpRequest),
        'iCloud transport uses the fixed STARTTLS endpoint, verifies TLS peers, and keeps credentials out of the test seam.');
    $check(str_contains($smtpRequest['payload'], 'Content-Type: text/plain; charset=UTF-8')
        && str_contains($smtpRequest['payload'], 'Content-Type: text/html; charset=UTF-8')
        && str_contains($smtpRequest['payload'], 'Message-ID: <verdant-outbox-773@icloud.com>')
        && !str_contains($smtpRequest['payload'], 'fake-app-password'),
        'SMTP MIME contains plain and HTML alternatives with stable outbox Message-ID and no credentials.');
    $rejectingExecutor = static fn (array $request): array => ['success' => false, 'smtp_code' => 535, 'curl_code' => 67];
    try {
        (new ICloudSmtpTransport('owner@icloud.com', 'fake-app-password', $icloudConfig, $rejectingExecutor))->deliver(
            ['outbox_id' => 774, 'recipient_email' => EmailConfig::TEST_RECIPIENTS[0]],
            ['subject' => 'Rejected', 'html' => '<p>x</p>', 'text' => 'x'],
        );
        $check(false, 'SMTP authentication rejection is classified as a definite failure.');
    } catch (MailTransportException $error) {
        $check(!$error->uncertain && $error->failureCode === 'smtp_auth_rejected',
            'SMTP authentication rejection is classified as a definite failure.');
    }
    $ambiguousExecutor = static fn (array $request): array => ['success' => false, 'smtp_code' => 354, 'curl_code' => 28];
    try {
        (new ICloudSmtpTransport('owner@icloud.com', 'fake-app-password', $icloudConfig, $ambiguousExecutor))->deliver(
            ['outbox_id' => 775, 'recipient_email' => EmailConfig::TEST_RECIPIENTS[0]],
            ['subject' => 'Uncertain', 'html' => '<p>x</p>', 'text' => 'x'],
        );
        $check(false, 'A transfer interrupted after DATA begins is uncertain.');
    } catch (MailTransportException $error) {
        $check($error->uncertain && $error->failureCode === 'smtp_submission_uncertain',
            'A transfer interrupted after DATA begins is uncertain.');
    }
    $expectException(static fn () => $smtp->deliver(
        ['outbox_id' => 776, 'recipient_email' => 'unapproved@example.test'],
        ['subject' => 'Blocked', 'html' => '<p>x</p>', 'text' => 'x'],
    ), 'The SMTP adapter applies the two-address recipient allowlist itself.');
    try {
        $smtp->deliver(
            ['outbox_id' => 777, 'recipient_email' => EmailConfig::TEST_RECIPIENTS[0]],
            ['subject' => "Header\r\nBcc: attacker@example.test", 'html' => '<p>x</p>', 'text' => 'x'],
        );
        $check(false, 'SMTP subject line breaks are rejected.');
    } catch (MailTransportException $error) {
        $check(!$error->uncertain && $error->failureCode === 'smtp_message_invalid', 'SMTP subject line breaks are rejected.');
    }

    $resendConfig = new EmailConfig(
        'resend', 'verdant@example.test', 'Verdant Studio', 'reply@example.test', 'https://store.example.test',
        EmailConfig::TEST_RECIPIENTS, '', null, '', '', 're_test_key',
    );
    $resendRequest = null;
    $resendExecutor = static function (array $request) use (&$resendRequest): array {
        $resendRequest = $request;
        return ['success' => true, 'http_code' => 200, 'curl_code' => 0, 'response' => '{"id":"re_123"}'];
    };
    $resend = new ResendTransport('re_test_key', $resendConfig, $resendExecutor);
    $resendResult = $resend->deliver(
        ['outbox_id' => 881, 'recipient_email' => EmailConfig::TEST_RECIPIENTS[0]],
        ['subject' => 'Your order is ready', 'html' => '<p>Thank you.</p>', 'text' => 'Thank you.'],
    );
    $check($resendResult->state === 'provider_accepted' && $resendResult->receipt === 'resend:re_123',
        'A Resend 200 result with an email ID is marked provider accepted with its receipt.');
    $resendPayload = json_decode((string) ($resendRequest['payload'] ?? ''), true);
    $check($resendRequest['url'] === 'https://api.resend.com/emails'
        && $resendRequest['verify_peer'] === true && $resendRequest['verify_host'] === 2
        && !array_key_exists('key', $resendRequest) && !array_key_exists('authorization', $resendRequest)
        && is_array($resendPayload) && $resendPayload['to'] === [EmailConfig::TEST_RECIPIENTS[0]]
        && $resendPayload['subject'] === 'Your order is ready'
        && str_contains((string) $resendPayload['from'], 'verdant@example.test')
        && !str_contains((string) $resendRequest['payload'], 're_test_key'),
        'Resend transport posts verified HTTPS JSON without credentials in the test seam.');
    $authExecutor = static fn (array $request): array => ['success' => false, 'http_code' => 401, 'curl_code' => 0,
        'response' => '{"message":"Invalid API key"}'];
    try {
        (new ResendTransport('re_bad_key', $resendConfig, $authExecutor))->deliver(
            ['outbox_id' => 882, 'recipient_email' => EmailConfig::TEST_RECIPIENTS[0]],
            ['subject' => 'Rejected', 'html' => '<p>x</p>', 'text' => 'x'],
        );
        $check(false, 'Resend authentication rejection is classified as a definite failure.');
    } catch (MailTransportException $error) {
        $check(!$error->uncertain && $error->failureCode === 'resend_auth_rejected',
            'Resend authentication rejection is classified as a definite failure.');
    }
    $timeoutExecutor = static fn (array $request): array => ['success' => false, 'http_code' => 0, 'curl_code' => 28, 'response' => ''];
    try {
        (new ResendTransport('re_test_key', $resendConfig, $timeoutExecutor))->deliver(
            ['outbox_id' => 883, 'recipient_email' => EmailConfig::TEST_RECIPIENTS[0]],
            ['subject' => 'Uncertain', 'html' => '<p>x</p>', 'text' => 'x'],
        );
        $check(false, 'A timed-out Resend submission is uncertain.');
    } catch (MailTransportException $error) {
        $check($error->uncertain && $error->failureCode === 'resend_submission_uncertain',
            'A timed-out Resend submission is uncertain.');
    }
    $expectException(static fn () => $resend->deliver(
        ['outbox_id' => 884, 'recipient_email' => 'unapproved@example.test'],
        ['subject' => 'Blocked', 'html' => '<p>x</p>', 'text' => 'x'],
    ), 'The Resend adapter applies the two-address recipient allowlist itself.');
    try {
        $resend->deliver(
            ['outbox_id' => 885, 'recipient_email' => EmailConfig::TEST_RECIPIENTS[0]],
            ['subject' => "Header\r\nBcc: attacker@example.test", 'html' => '<p>x</p>', 'text' => 'x'],
        );
        $check(false, 'Resend subject line breaks are rejected.');
    } catch (MailTransportException $error) {
        $check(!$error->uncertain && $error->failureCode === 'resend_message_invalid', 'Resend subject line breaks are rejected.');
    }

    $permitDefault = new EmailConfig('local_capture', 'owner@example.test', 'Verdant', 'reply@example.test', 'http://127.0.0.1:8088');
    $check($permitDefault->permits(EmailConfig::TEST_RECIPIENTS[0]) && !$permitDefault->permits('outsider@example.test'),
        'Recipient delivery stays restricted to the allowlist by default.');
    $permitOpen = new EmailConfig('local_capture', 'owner@example.test', 'Verdant', 'reply@example.test', 'http://127.0.0.1:8088',
        EmailConfig::TEST_RECIPIENTS, '', null, '', '', '', true);
    $check($permitOpen->permits('newcustomer@example.test') && !$permitOpen->permits('not-an-email'),
        'Open delivery permits any valid recipient address while still rejecting malformed ones.');
    $permitCustom = new EmailConfig('local_capture', 'owner@example.test', 'Verdant', 'reply@example.test', 'http://127.0.0.1:8088',
        ['shop@example.test'], '', null, '', '', '', true);
    $check($permitCustom->permits('shop@example.test') && $permitCustom->permits('anyone@example.test'),
        'Open delivery lifts the two-address restriction for configured lists.');
    putenv('EMAIL_OPEN_DELIVERY=1');
    $permitEnv = EmailConfig::fromEnvironment();
    putenv('EMAIL_OPEN_DELIVERY');
    $check($permitEnv->openDelivery === true && $permitEnv->permits('newcustomer@example.test'),
        'EMAIL_OPEN_DELIVERY=1 enables open delivery from the environment.');

    $captureConfig = new EmailConfig(
        'local_capture', 'sender@example.test', 'Verdant', 'reply@example.test', 'http://localhost',
        EmailConfig::TEST_RECIPIENTS, $tempDirectory,
    );
    $capture = new LocalCaptureTransport($tempDirectory, $captureConfig);
    $captureRow = ['outbox_id' => 91, 'recipient_email' => EmailConfig::TEST_RECIPIENTS[1]];
    $captureMessage = ['subject' => "Hello\r\nBcc: attacker@example.test", 'html' => '<p>Private</p>', 'text' => 'Private'];
    $firstCapture = $capture->deliver($captureRow, $captureMessage);
    $secondCapture = $capture->deliver($captureRow, $captureMessage);
    $capturePath = $tempDirectory . '/91.eml';
    $check($firstCapture->state === 'captured' && $secondCapture->receipt === 'local:91',
        'Repeated capture for one outbox ID is deterministic.');
    $captureContents = file_get_contents($capturePath);
    $check(is_string($captureContents) && !str_contains($captureContents, "\r\nBcc:"),
        'Capture headers remove newline injection from the subject.');
    $check((fileperms($tempDirectory) & 0777) === 0700 && (fileperms($capturePath) & 0777) === 0600,
        'Capture directory and message use private filesystem permissions.');
    $expectException(static fn () => $capture->deliver($captureRow, ['subject' => 'Changed', 'html' => 'x', 'text' => 'x']),
        'A repeated outbox ID cannot overwrite a different captured message.');

    if ($transportOnly) {
        foreach (glob($tempDirectory . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($tempDirectory);
        echo "Mail transport checks: {$checks} passed, 0 failed.\n";
        exit(0);
    }

    $connection = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
    $approvedEmail = 'admin@thisisphila.co.za';
    $findAdmin = $connection->prepare(
        "SELECT customer_id FROM customers WHERE email = ? AND role = 'admin' AND account_state = 'active' LIMIT 1"
    );
    $findAdmin->execute([$approvedEmail]);
    $adminRow = $findAdmin->get_result()->fetch_assoc();
    $findAdmin->close();
    if ($adminRow === null) {
        $findExisting = $connection->prepare('SELECT customer_id FROM customers WHERE email = ? LIMIT 1');
        $findExisting->execute([$approvedEmail]);
        $existingRow = $findExisting->get_result()->fetch_assoc();
        $findExisting->close();
        if ($existingRow !== null) {
            throw new RuntimeException('The isolated fixture admin address is already attached to a non-active or non-admin account.');
        }
        $insertAdmin = $connection->prepare(
            "INSERT INTO customers (email, password_hash, name, role, account_state) VALUES (?, 'test-hash', 'Mail Test Admin', 'admin', 'active')"
        );
        $insertAdmin->execute([$approvedEmail]);
        $createdCustomerId = (int) $connection->insert_id;
        $insertAdmin->close();
        $adminId = $createdCustomerId;
    } else {
        $adminId = (int) $adminRow['customer_id'];
    }
    $submissionKey = bin2hex(random_bytes(32));
    $insertEnquiry = $connection->prepare(
        "INSERT INTO customer_enquiries (customer_id, submission_key, name, email, topic, subject, order_reference, message)
         VALUES (?, ?, 'Mail Test', ?, 'customer', 'Mail dispatch test', NULL, 'Synthetic test message')"
    );
    $insertEnquiry->execute([$adminId, $submissionKey, $approvedEmail]);
    $enquiryId = (int) $connection->insert_id;
    $insertEnquiry->close();
    $insertMessage = $connection->prepare(
        "INSERT INTO enquiry_messages (enquiry_id, author_kind, author_customer_id, body, submission_key)
         VALUES (?, 'admin', ?, 'Synthetic test message', ?)"
    );
    $insertMessage->execute([$enquiryId, $adminId, bin2hex(random_bytes(32))]);
    $messageId = (int) $connection->insert_id;
    $insertMessage->close();

    $insertSmtpOutbox = $connection->prepare(
        "INSERT INTO email_outbox
           (message_id, commerce_event_id, recipient_customer_id, recipient_role, recipient_email,
            template_key, context_json, transport, delivery_state)
         VALUES (?, NULL, ?, 'admin', ?, ?, '{\"message_id\":1}', 'icloud_smtp', 'queued')"
    );
    for ($index = 0; $index < 3; ++$index) {
        $insertSmtpOutbox->execute([$messageId, $adminId, $approvedEmail, 'support_admin_smtp_test_' . $index]);
        $smtpOutboxIds[] = (int) $connection->insert_id;
        $outboxIds[] = (int) $connection->insert_id;
    }
    $insertSmtpOutbox->close();

    $insertOutbox = $connection->prepare(
        "INSERT INTO email_outbox
           (message_id, commerce_event_id, recipient_customer_id, recipient_role, recipient_email,
            template_key, context_json, transport, delivery_state)
         VALUES (?, NULL, ?, 'admin', ?, 'support_admin_new_message', '{\"message_id\":1}', 'local_capture', 'held')"
    );
    $insertOutbox->execute([$messageId, $adminId, $approvedEmail]);
    $validOutboxId = (int) $connection->insert_id;
    $outboxIds[] = $validOutboxId;
    $insertOutbox->close();
    $insertOutbox = $connection->prepare(
        "INSERT INTO email_outbox
           (message_id, commerce_event_id, recipient_customer_id, recipient_role, recipient_email,
            template_key, context_json, transport, delivery_state)
         VALUES (?, NULL, ?, 'admin', ?, 'support_admin_legacy_hold', '{\"message_id\":1}', 'local_capture', 'held')"
    );
    $insertOutbox->execute([$messageId, $adminId, $approvedEmail]);
    $secondOutboxId = (int) $connection->insert_id;
    $outboxIds[] = $secondOutboxId;
    $insertOutbox->close();
    $insertOutbox = $connection->prepare(
        "INSERT INTO email_outbox
           (message_id, commerce_event_id, recipient_customer_id, recipient_role, recipient_email,
            template_key, context_json, transport, delivery_state)
         VALUES (?, NULL, NULL, NULL, ?, 'support_admin_legacy_release', '{\"message_id\":1}', 'local_capture', 'held')"
    );
    $insertOutbox->execute([$messageId, $approvedEmail]);
    $legacyOutboxId = (int) $connection->insert_id;
    $outboxIds[] = $legacyOutboxId;
    $insertOutbox->close();
    $insertOutbox = $connection->prepare(
        "INSERT INTO email_outbox
           (message_id, commerce_event_id, recipient_customer_id, recipient_role, recipient_email,
            template_key, context_json, transport, delivery_state)
         VALUES (?, NULL, NULL, NULL, ?, 'support_admin_open_release', '{\"message_id\":1}', 'local_capture', 'held')"
    );
    $insertOutbox->execute([$messageId, 'newcustomer@example.test']);
    $openOutboxId = (int) $connection->insert_id;
    $outboxIds[] = $openOutboxId;
} catch (mysqli_sql_exception $error) {
    if ($connection !== null) {
        if ($outboxIds !== []) {
            $ids = implode(',', array_map('intval', $outboxIds));
            $connection->query("DELETE FROM email_outbox WHERE outbox_id IN ({$ids})");
        }
        if ($messageId !== null) {
            $connection->query('DELETE FROM enquiry_messages WHERE message_id = ' . (int) $messageId);
        }
        if ($enquiryId !== null) {
            $connection->query('DELETE FROM customer_enquiries WHERE enquiry_id = ' . (int) $enquiryId);
        }
        if ($createdCustomerId !== null) {
            $connection->query('DELETE FROM customers WHERE customer_id = ' . (int) $createdCustomerId);
        }
        $connection->close();
    }
    foreach (glob($tempDirectory . '/*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($tempDirectory);
    fwrite(STDERR, "Mail dispatcher test schema is missing or incompatible; apply approved migrations to the isolated test DB first.\n");
    exit(2);
} catch (Throwable $error) {
    if ($connection !== null) {
        if ($outboxIds !== []) {
            $ids = implode(',', array_map('intval', $outboxIds));
            $connection->query("DELETE FROM email_outbox WHERE outbox_id IN ({$ids})");
        }
        if ($messageId !== null) {
            $connection->query('DELETE FROM enquiry_messages WHERE message_id = ' . (int) $messageId);
        }
        if ($enquiryId !== null) {
            $connection->query('DELETE FROM customer_enquiries WHERE enquiry_id = ' . (int) $enquiryId);
        }
        if ($createdCustomerId !== null) {
            $connection->query('DELETE FROM customers WHERE customer_id = ' . (int) $createdCustomerId);
        }
        $connection->close();
    }
    foreach (glob($tempDirectory . '/*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($tempDirectory);
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
}

try {
    $insertOutbox->close();
    $config = new EmailConfig(
        'local_capture', 'sender@example.test', 'Verdant', 'reply@example.test', 'http://localhost',
        EmailConfig::TEST_RECIPIENTS, $tempDirectory,
    );
    $renderer = static fn (array $row, string $baseUrl): array => [
        'subject' => 'Synthetic support message', 'html' => '<p>Private fixture</p>', 'text' => 'Private fixture',
    ];
    $dispatcher = new EmailDispatcher($connection, $config, $capture, $renderer);
    $check($dispatcher->releaseSelected([$validOutboxId]) === 1,
        'Only the explicitly selected held outbox row is released.');
    $check($dispatcher->dispatchSelected([$validOutboxId])['captured'] === 1,
        'A selected queued message for a current active administrator is captured.');
    $check($capture->hasCapture($validOutboxId), 'Dispatcher writes the selected capture.');
    $state = $connection->prepare('SELECT delivery_state, attempt_count, lease_token FROM email_outbox WHERE outbox_id = ?');
    $state->execute([$validOutboxId]);
    $capturedState = $state->get_result()->fetch_assoc();
    $state->close();
    $check($capturedState['delivery_state'] === 'captured' && (int) $capturedState['attempt_count'] === 1
        && $capturedState['lease_token'] === null, 'Capture completion clears the atomic lease and records one attempt.');

    $resetLease = $connection->prepare(
        "UPDATE email_outbox SET delivery_state = 'queued', lease_token = REPEAT('b', 64),
            leased_until = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE outbox_id = ?"
    );
    $resetLease->execute([$validOutboxId]);
    $resetLease->close();
    $dispatcher->dispatchSelected([$validOutboxId]);
    $state = $connection->prepare('SELECT delivery_state, attempt_count, provider_receipt FROM email_outbox WHERE outbox_id = ?');
    $state->execute([$validOutboxId]);
    $recovered = $state->get_result()->fetch_assoc();
    $state->close();
    $check($recovered['delivery_state'] === 'captured' && (int) $recovered['attempt_count'] === 1
        && $recovered['provider_receipt'] === 'local:' . $validOutboxId,
        'An expired local lease recovers an existing deterministic capture without another attempt.');

    $state = $connection->prepare("SELECT delivery_state FROM email_outbox WHERE outbox_id = ?");
    $state->execute([$secondOutboxId]);
    $stillHeld = $state->get_result()->fetch_column();
    $state->close();
    $check($stillHeld === 'held', 'Unselected historical support rows remain held.');

    $check($dispatcher->releaseSelected([$legacyOutboxId]) === 1
        && $dispatcher->dispatchSelected([$legacyOutboxId])['captured'] === 1,
        'An explicitly selected legacy support row resolves only to its current active administrator.');

    $check($dispatcher->releaseSelected([$openOutboxId]) === 0,
        'Closed delivery does not release a held message for a new recipient.');
    $openConfig = new EmailConfig(
        'local_capture', 'sender@example.test', 'Verdant', 'reply@example.test', 'http://localhost',
        EmailConfig::TEST_RECIPIENTS, $tempDirectory, null, '', '', '', true,
    );
    $openDispatcher = new EmailDispatcher($connection, $openConfig, $capture, $renderer);
    $check($openDispatcher->releaseSelected([$openOutboxId]) === 1,
        'Open delivery releases a selected held message for any valid recipient.');

    $connection->query("UPDATE email_outbox SET delivery_state = 'queued', transport = 'brevo',
        lease_token = REPEAT('a', 64), leased_until = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)
        WHERE outbox_id = {$secondOutboxId}");
    $staleResult = $dispatcher->dispatchSelected([$secondOutboxId]);
    $check($staleResult['captured'] === 0 && $staleResult['failed'] === 0,
        'Expired external leases are reconciled without another submission.');
    $state = $connection->prepare('SELECT delivery_state, failure_code, lease_token FROM email_outbox WHERE outbox_id = ?');
    $state->execute([$secondOutboxId]);
    $uncertain = $state->get_result()->fetch_assoc();
    $state->close();
    $check($uncertain['delivery_state'] === 'uncertain' && $uncertain['failure_code'] === 'expired_external_lease'
        && $uncertain['lease_token'] === null, 'An expired possible external submission is marked uncertain and cannot retry.');

    $smtpAcceptedDispatcher = new EmailDispatcher($connection, $icloudConfig, $smtp, $renderer);
    $smtpAcceptedResult = $smtpAcceptedDispatcher->dispatchSelected([$smtpOutboxIds[0]]);
    $state = $connection->prepare('SELECT delivery_state, attempt_count, lease_token FROM email_outbox WHERE outbox_id = ?');
    $state->execute([$smtpOutboxIds[0]]);
    $smtpAcceptedState = $state->get_result()->fetch_assoc();
    $state->close();
    $check($smtpAcceptedResult['skipped'] === 1 && $smtpAcceptedState['delivery_state'] === 'provider_accepted'
        && (int) $smtpAcceptedState['attempt_count'] === 1 && $smtpAcceptedState['lease_token'] === null,
        'The dispatcher records SMTP 250 acceptance and clears the lease without claiming mailbox delivery.');

    $smtpRejected = new ICloudSmtpTransport(
        $icloudConfig->smtpUsername,
        $icloudConfig->smtpPassword,
        $icloudConfig,
        static fn (array $request): array => ['success' => false, 'smtp_code' => 535, 'curl_code' => 67],
    );
    $smtpRejectedDispatcher = new EmailDispatcher($connection, $icloudConfig, $smtpRejected, $renderer);
    $smtpRejectedResult = $smtpRejectedDispatcher->dispatchSelected([$smtpOutboxIds[1]]);
    $state = $connection->prepare('SELECT delivery_state, failure_code, attempt_count FROM email_outbox WHERE outbox_id = ?');
    $state->execute([$smtpOutboxIds[1]]);
    $smtpRejectedState = $state->get_result()->fetch_assoc();
    $state->close();
    $check($smtpRejectedResult['failed'] === 1 && $smtpRejectedState['delivery_state'] === 'failed'
        && $smtpRejectedState['failure_code'] === 'smtp_auth_rejected' && (int) $smtpRejectedState['attempt_count'] === 1,
        'A definite SMTP authentication rejection is stored as a non-retried failed delivery.');

    $smtpUncertain = new ICloudSmtpTransport(
        $icloudConfig->smtpUsername,
        $icloudConfig->smtpPassword,
        $icloudConfig,
        static fn (array $request): array => ['success' => false, 'smtp_code' => 354, 'curl_code' => 28],
    );
    $smtpUncertainDispatcher = new EmailDispatcher($connection, $icloudConfig, $smtpUncertain, $renderer);
    $smtpUncertainResult = $smtpUncertainDispatcher->dispatchSelected([$smtpOutboxIds[2]]);
    $state = $connection->prepare('SELECT delivery_state, failure_code, attempt_count FROM email_outbox WHERE outbox_id = ?');
    $state->execute([$smtpOutboxIds[2]]);
    $smtpUncertainState = $state->get_result()->fetch_assoc();
    $state->close();
    $check($smtpUncertainResult['uncertain'] === 1 && $smtpUncertainState['delivery_state'] === 'uncertain'
        && $smtpUncertainState['failure_code'] === 'smtp_submission_uncertain' && (int) $smtpUncertainState['attempt_count'] === 1,
        'An ambiguous SMTP interruption is recorded uncertain and never blindly retried.');

    $insertResendOutbox = $connection->prepare(
        "INSERT INTO email_outbox
           (message_id, commerce_event_id, recipient_customer_id, recipient_role, recipient_email,
            template_key, context_json, transport, delivery_state)
         VALUES (?, NULL, ?, 'admin', ?, 'support_admin_inrequest_test', '{\"message_id\":1}', 'resend', 'queued')"
    );
    $insertResendOutbox->execute([$messageId, $adminId, $approvedEmail]);
    $resendOutboxId = (int) $connection->insert_id;
    $outboxIds[] = $resendOutboxId;
    $insertResendOutbox->close();
    $inRequestResult = (new \Verdant\Support\Mail\InRequestMailDispatcher(
        $connection, $resendConfig, new ResendTransport('re_test_key', $resendConfig, $resendExecutor), $renderer,
    ))->dispatchQueued();
    $state = $connection->prepare('SELECT delivery_state, provider_receipt FROM email_outbox WHERE outbox_id = ?');
    $state->execute([$resendOutboxId]);
    $resendState = $state->get_result()->fetch_assoc();
    $state->close();
    $check($inRequestResult['skipped'] === 1 && $resendState['delivery_state'] === 'provider_accepted'
        && $resendState['provider_receipt'] === 'resend:re_123',
        'In-request Resend dispatch sends queued mail without a CLI worker.');

    $check($checks >= 10, 'Focused dispatcher checks completed.');
    echo "Mail dispatcher checks: {$checks} passed, 0 failed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    if ($connection !== null) {
        if ($outboxIds !== []) {
            $ids = implode(',', array_map('intval', $outboxIds));
            $connection->query("DELETE FROM email_outbox WHERE outbox_id IN ({$ids})");
        }
        if ($messageId !== null) {
            $connection->query('DELETE FROM enquiry_messages WHERE message_id = ' . (int) $messageId);
        }
        if ($enquiryId !== null) {
            $connection->query('DELETE FROM customer_enquiries WHERE enquiry_id = ' . (int) $enquiryId);
        }
        if ($createdCustomerId !== null) {
            $connection->query('DELETE FROM customers WHERE customer_id = ' . (int) $createdCustomerId);
        }
        $connection->close();
    }
    foreach (glob($tempDirectory . '/*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($tempDirectory);
}
