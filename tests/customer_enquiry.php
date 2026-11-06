<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Admin\AdminAccess;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Support\CustomerEnquiryService;
use Verdant\Support\ValidationException;

$database = getenv('DB_NAME') ?: '';
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'local' || preg_match('/^verdant_test_[a-z0-9_]+$/', $database) !== 1) {
    fwrite(STDERR, "Use APP_ENV=local and an isolated verdant_test_ database.\n");
    exit(2);
}

$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException($label);
    ++$checks;
    echo "PASS: $label\n";
};
$keys = [];
$adminId = null;
$customerId = null;
try {
    $email = 'enquiry-admin-' . bin2hex(random_bytes(8)) . '@example.test';
    $insertAdmin = $db->prepare("INSERT INTO customers (email, password_hash, name, role, account_state) VALUES (?, 'test-hash', 'Test Admin', 'admin', 'active')");
    $insertAdmin->execute([$email]);
    $adminId = (int) $db->insert_id;
    $insertAdmin->close();

    $_SESSION = [];
    $service = new CustomerEnquiryService($db, new AdminAccess($db));
    $valid = ['name' => 'Test Customer', 'email' => 'customer@example.test', 'topic' => 'customer',
        'subject' => 'Plant care', 'order_reference' => 'ORD-123', 'message' => "Please help me.\nThanks."];
    $expectValidation = static function (array $input, string $label) use ($service, $check): void {
        try { $service->submit($input, null); } catch (ValidationException) { $check(true, $label); return; }
        $check(false, $label);
    };

    $_SESSION['_customer_enquiry_key'] = str_repeat('a', 64);
    $expectValidation(array_replace($valid, ['topic' => 'corporate', 'submission_key' => str_repeat('a', 64)]), 'Corporate enquiries are rejected.');
    $expectValidation(array_replace($valid, ['email' => 'not-an-email', 'submission_key' => str_repeat('a', 64)]), 'Invalid email addresses are rejected.');
    $expectValidation(array_replace($valid, ['message' => str_repeat('x', 5001), 'submission_key' => str_repeat('a', 64)]), 'Oversized messages are rejected.');
    $expectValidation(array_replace($valid, ['name' => str_repeat('x', 121), 'submission_key' => str_repeat('a', 64)]), 'Overlength names are rejected before flash truncation.');
    $expectValidation(array_replace($valid, ['order_reference' => str_repeat('x', 81), 'submission_key' => str_repeat('a', 64)]), 'Overlength order references are rejected.');

    $expectValidation(array_replace($valid, ['submission_key' => str_repeat('f', 64)]), 'A key from another session is rejected.');

    $key = str_repeat('b', 64);
    $_SESSION['_customer_enquiry_key'] = $key;
    $created = $service->submit($valid + ['submission_key' => $key], null);
    $keys[] = $key;
    $check($created, 'A valid customer enquiry is persisted.');
    $_SESSION['_customer_enquiry_key'] = str_repeat('c', 64);
    $check(!$service->submit($valid + ['submission_key' => $key], null), 'A duplicate POST with the same key is idempotent.');
    $inbox = $service->inbox(['customer_id' => $adminId]);
    $row = array_values(array_filter($inbox, static fn (array $item): bool => $item['email'] === 'customer@example.test'))[0] ?? null;
    $check(is_array($row) && $row['subject'] === 'Plant care' && $row['order_reference'] === 'ORD-123'
        && $row['message'] === "Please help me.\nThanks.", 'The protected inbox returns the stored message and order reference.');
    $check($row['customer_id'] === null, 'A guest enquiry is never associated to an account by matching email.');

    $conflictKey = str_repeat('9', 64);
    $conflict = $db->prepare("INSERT INTO enquiry_messages (enquiry_id, author_kind, body, submission_key) VALUES (?, 'guest', 'Existing message key', ?)");
    $conflict->execute([(int) $row['enquiry_id'], $conflictKey]);
    $conflict->close();
    $counts = static fn (): array => array_map(
        static fn (string $table): int => (int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetch_row()[0],
        ['customer_enquiries', 'enquiry_messages', 'support_notifications', 'email_outbox'],
    );
    $beforeFailure = $counts();
    $_SESSION['_customer_enquiry_key'] = $conflictKey;
    try {
        $service->submit($valid + ['submission_key' => $conflictKey], null);
        $check(false, 'An unrelated unique-key conflict is surfaced instead of reported as a successful retry.');
    } catch (mysqli_sql_exception $error) {
        $check((int) $error->getCode() === 1062, 'An unrelated unique-key conflict is surfaced instead of reported as a successful retry.');
    }
    $check($counts() === $beforeFailure, 'A failed message write rolls back the enquiry and leaves all event counts unchanged.');

    try {
        $service->inbox(['customer_id' => 999999999]);
        $check(false, 'An unverified actor cannot read the inbox.');
    } catch (DomainException) {
        $check(true, 'An unverified actor cannot read the inbox.');
    }

    $accountName = str_repeat('A', 180);
    $customerEmail = 'enquiry-customer-' . bin2hex(random_bytes(8)) . '@example.test';
    $insertCustomer = $db->prepare("INSERT INTO customers (email, password_hash, name, account_state) VALUES (?, 'test-hash', ?, 'active')");
    $insertCustomer->execute([$customerEmail, $accountName]);
    $customerId = (int) $db->insert_id;
    $insertCustomer->close();
    $accountKey = str_repeat('d', 64);
    $_SESSION['_customer_enquiry_key'] = $accountKey;
    $accountInput = array_replace($valid, [
        'name' => 'Forged posted identity', 'email' => 'different@example.test', 'submission_key' => $accountKey,
    ]);
    $check($service->submit($accountInput, ['customer_id' => $customerId]), 'A signed-in enquiry accepts the full 180-character account name.');
    $keys[] = $accountKey;
    $stored = $db->prepare('SELECT customer_id, name, email FROM customer_enquiries WHERE submission_key = ?');
    $stored->execute([$accountKey]);
    $identity = $stored->get_result()->fetch_assoc();
    $stored->close();
    $check((int) ($identity['customer_id'] ?? 0) === $customerId && $identity['name'] === $accountName
        && $identity['email'] === $customerEmail, 'Account ownership and contact snapshot come from the authenticated customer row.');
    $events = $db->prepare(
        'SELECT COUNT(DISTINCT n.notification_id), COUNT(DISTINCT o.outbox_id) FROM enquiry_messages m
         JOIN support_notifications n ON n.message_id = m.message_id
         JOIN email_outbox o ON o.message_id = m.message_id
         WHERE m.submission_key = ? AND m.author_kind = \'customer\'
           AND n.event_kind = \'new_customer_message\'
           AND o.delivery_state = \'held\' AND o.template_key = \'support_admin_new_message\''
    );
    $events->execute([$accountKey]);
    $eventCounts = $events->get_result()->fetch_row();
    $check((int) $eventCounts[0] >= 1 && $eventCounts[0] === $eventCounts[1], 'Message, admin notification and held outbox event persist together.');
    $events->close();

    $shortName = $db->prepare('UPDATE customers SET name = ? WHERE customer_id = ?');
    $shortName->execute(['A', $customerId]);
    $shortName->close();
    $shortKey = str_repeat('7', 64);
    $_SESSION['_customer_enquiry_key'] = $shortKey;
    $check($service->submit(array_replace($accountInput, ['submission_key' => $shortKey]), ['customer_id' => $customerId]),
        'A valid one-character account name remains valid for support.');
    $keys[] = $shortKey;

    $archive = $db->prepare("UPDATE customers SET account_state = 'archived', archived_at = UTC_TIMESTAMP() WHERE customer_id = ?");
    $archive->execute([$customerId]);
    $archive->close();
    try {
        $service->submit($accountInput + ['submission_key' => str_repeat('f', 64)], ['customer_id' => $customerId]);
        $check(false, 'An archived account cannot create an account-owned enquiry.');
    } catch (DomainException) {
        $check(true, 'An archived account cannot create an account-owned enquiry.');
    }
    $restore = $db->prepare("UPDATE customers SET account_state = 'active', archived_at = NULL WHERE customer_id = ?");
    $restore->execute([$customerId]);
    $restore->close();

    $draftController = new \Verdant\Http\CustomerEnquiryController($service);
    $_SESSION['_customer_enquiry_draft'] = [
        'saved_at' => time(), 'values' => CustomerEnquiryService::safeValues(['message' => 'Draft restored']),
    ];
    $draftController->restoreContactDraft(['customer_id' => $customerId], 'support');
    $restoredDraft = \Verdant\Identity\SessionManager::takeFlash('customer_enquiry');
    $check(($restoredDraft['status'] ?? null) === 'draft'
        && ($restoredDraft['values']['message'] ?? null) === 'Draft restored'
        && isset($_SESSION['_customer_enquiry_draft']), 'A valid support draft stays available until submission, expiry or sign-out.');
    $_SESSION['_customer_enquiry_draft'] = [
        'saved_at' => time() - 1801, 'values' => CustomerEnquiryService::safeValues(['message' => 'Expired draft']),
    ];
    $draftController->restoreContactDraft(['customer_id' => $customerId], 'support');
    $check(\Verdant\Identity\SessionManager::takeFlash('customer_enquiry') === null
        && !isset($_SESSION['_customer_enquiry_draft']), 'Expired support drafts are discarded.');

    $_SESSION['_customer_enquiry_key'] = str_repeat('e', 64);
    $_SESSION['_customer_enquiry_times'] = [time(), time(), time()];
    $expectValidation($valid + ['submission_key' => str_repeat('e', 64)], 'Repeated enquiries are throttled in the session.');
    echo "Customer enquiry checks: $checks passed, 0 failed.\n";
} finally {
    if ($keys !== []) {
        $delete = $db->prepare('DELETE FROM customer_enquiries WHERE submission_key = ?');
        foreach ($keys as $key) $delete->execute([$key]);
        $delete->close();
    }
    if ($adminId !== null) {
        $deleteAdmin = $db->prepare('DELETE FROM customers WHERE customer_id = ?');
        $deleteAdmin->execute([$adminId]);
        $deleteAdmin->close();
    }
    if ($customerId !== null) {
        $deleteCustomer = $db->prepare('DELETE FROM customers WHERE customer_id = ?');
        $deleteCustomer->execute([$customerId]);
        $deleteCustomer->close();
    }
}
