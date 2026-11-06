<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Admin\AdminAccess;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Support\CustomerEnquiryService;
use Verdant\Support\SupportConversationService;
use Verdant\Support\SupportTokenCipher;
use Verdant\Support\ValidationException;
use Verdant\Identity\SessionManager;

$database = getenv('DB_NAME') ?: '';
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'local' || preg_match('/^verdant_test_[a-z0-9_]+$/D', $database) !== 1) {
    fwrite(STDERR, "Use APP_ENV=local and an isolated verdant_test_ database.\n");
    exit(2);
}

$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$checks = [];
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException($label);
    $checks[] = $label;
};
$adminId = $adminTwoId = $customerId = null;
$submissionKeys = [];
$adminEmail = 'support-admin-' . bin2hex(random_bytes(8)) . '@example.test';
$customerEmail = 'support-customer-' . bin2hex(random_bytes(8)) . '@example.test';
$adminInsert = $db->prepare("INSERT INTO customers(email,password_hash,name,role,account_state) VALUES(?,'test-hash','Support Admin','admin','active')");
$adminInsert->execute([$adminEmail]); $adminId = (int) $db->insert_id; $adminInsert->close();
$adminTwoInsert = $db->prepare("INSERT INTO customers(email,password_hash,name,role,account_state) VALUES(?,'test-hash','Second Support Admin','admin','active')");
$adminTwoInsert->execute(['support-admin-two-' . bin2hex(random_bytes(8)) . '@example.test']); $adminTwoId = (int) $db->insert_id; $adminTwoInsert->close();
$customerInsert = $db->prepare("INSERT INTO customers(email,password_hash,name,role,account_state) VALUES(?,'test-hash','Support Customer','customer','active')");
$customerInsert->execute([$customerEmail]); $customerId = (int) $db->insert_id; $customerInsert->close();
$access = new AdminAccess($db);
$service = new CustomerEnquiryService($db, $access);
$conversations = new SupportConversationService($db, $access);
$admin = ['customer_id' => $adminId, 'role' => 'admin'];
$adminTwo = ['customer_id' => $adminTwoId, 'role' => 'admin'];
$customer = ['customer_id' => $customerId, 'role' => 'customer'];
$submit = static function (array $input, ?array $actor, string $key) use ($service, &$submissionKeys): bool {
    $_SESSION['_customer_enquiry_key'] = $key;
    $_SESSION['_customer_enquiry_times'] = [];
    try { $created = $service->submit($input + ['submission_key' => $key], $actor); }
    catch (ValidationException $error) { throw new RuntimeException('Fixture submission failed validation: ' . implode(',', array_keys($error->errors))); }
    $submissionKeys[] = $key;
    return $created;
};
$guestKey = bin2hex(random_bytes(32));
$accountKey = bin2hex(random_bytes(32));
$guestValues = ['name' => 'Guest Shopper', 'email' => 'customer@thisisphila.co.za', 'topic' => 'customer',
    'subject' => 'Guest access test', 'order_reference' => '', 'message' => 'Please help with my order.'];
$accountValues = ['name' => 'Forged name', 'email' => 'forged@example.test', 'topic' => 'customer',
    'subject' => 'Account conversation test', 'order_reference' => 'REF-1', 'message' => 'I need account help.'];
$guestId = $accountEnquiryId = null;

try {
    SessionManager::start();
    $_SESSION = [];
    $check($submit($guestValues, null, $guestKey), 'Guest enquiry and initial support events save.');
    $check($submit($accountValues, $customer, $accountKey), 'Signed-in enquiry saves against trusted account identity.');
    $lookup = $db->prepare('SELECT enquiry_id,customer_id FROM customer_enquiries WHERE submission_key=?');
    $lookup->execute([$guestKey]); $guestRow = $lookup->get_result()->fetch_assoc();
    $lookup->execute([$accountKey]); $accountRow = $lookup->get_result()->fetch_assoc(); $lookup->close();
    $guestId = (int) $guestRow['enquiry_id']; $accountEnquiryId = (int) $accountRow['enquiry_id'];
    $check($guestRow['customer_id'] === null, 'Guest enquiry remains unowned even when the email matches an allowlisted test address.');
    $check((int) $accountRow['customer_id'] === $customerId, 'Account submission is owned by the authenticated customer, not posted identity.');

    $inbox = $conversations->adminInbox($admin, ['q' => 'Guest access test', 'state' => 'open', 'page' => '1']);
    $check($inbox['total'] === 1 && (int) $inbox['items'][0]['enquiry_id'] === $guestId, 'Admin inbox search and state filter return the matching thread.');
    try { $conversations->accountThread($accountEnquiryId, $admin); $check(false, 'Administrator role cannot use the customer reply endpoint.'); }
    catch (DomainException) { $check(true, 'Administrator role cannot use the customer reply endpoint.'); }
    $beforeOpen = $conversations->notificationCount($admin);
    $beforeSecondAdminOpen = $conversations->notificationCount($adminTwo);
    $conversations->adminThread($guestId, $admin);
    $check($conversations->notificationCount($admin) === $beforeOpen - 1, 'Opening one admin thread marks only that recipient/thread notification read.');
    $check($conversations->notificationCount($adminTwo) === $beforeSecondAdminOpen, 'One administrator opening a thread leaves another administrator unread.');
    $afterSpecificOpen = $conversations->notificationCount($admin);
    $conversations->adminInbox($admin, []);
    $check($conversations->notificationCount($admin) === $afterSpecificOpen, 'Listing/searching inbox never marks notifications read.');

    $replyKey = bin2hex(random_bytes(32));
    $check($conversations->reply($accountEnquiryId, ['body' => 'We can help.', 'submission_key' => $replyKey], $admin, null, true), 'Admin reply persists for an account-owned thread.');
    $check($conversations->notificationCount($customer) === 1, 'Account owner receives a persistent unread reply notification.');
    $accountList = $conversations->accountInbox($customer);
    $check($accountList['unread'] === 1 && (int) $accountList['items'][0]['unread'] === 1, 'Account list shows unread state without consuming it.');
    $conversations->accountInbox($customer);
    $check($conversations->notificationCount($customer) === 1, 'Repeated account listing leaves the unread count unchanged.');
    $customerThread = $conversations->accountThread($accountEnquiryId, $customer);
    $check(count($customerThread['messages']) === 2 && $conversations->notificationCount($customer) === 0, 'Opening only the owned account thread marks its reply read.');

    $latest = $customerThread['messages'][array_key_last($customerThread['messages'])];
    $resolved = $conversations->setState($accountEnquiryId, 'resolved', [
        'updated_at' => $customerThread['updated_at'], 'current_state' => 'open', 'latest_message_id' => (string) $latest['message_id'],
    ], $admin);
    $check($resolved, 'Admin can resolve an open conversation with a current version.');
    try {
        $conversations->setState($accountEnquiryId, 'open', [
            'updated_at' => $customerThread['updated_at'], 'current_state' => 'open', 'latest_message_id' => (string) $latest['message_id'],
        ], $admin);
        $check(false, 'Stale conversation status updates are rejected.');
    } catch (DomainException) { $check(true, 'Stale conversation status updates are rejected.'); }
    $customerReplyKey = bin2hex(random_bytes(32));
    $check($conversations->reply($accountEnquiryId, ['body' => 'One more detail.', 'submission_key' => $customerReplyKey], $customer), 'Customer can reply only to their own thread.');
    $check(!$conversations->reply($accountEnquiryId, ['body' => 'One more detail.', 'submission_key' => $customerReplyKey], $customer), 'Repeated reply with the same idempotency key creates no duplicate.');
    $latestState = $db->prepare('SELECT enquiry_state FROM customer_enquiries WHERE enquiry_id=?');
    $latestState->execute([$accountEnquiryId]);
    $check($latestState->get_result()->fetch_column() === 'open', 'A customer reply reopens a resolved conversation.');
    $latestState->close();
    $thirdCustomerReplyKey = bin2hex(random_bytes(32));
    $check($conversations->reply($accountEnquiryId, ['body' => 'A final detail.', 'submission_key' => $thirdCustomerReplyKey], $customer), 'Reply rate limit permits a bounded third customer message in the window.');
    try {
        $conversations->reply($accountEnquiryId, ['body' => 'One more.', 'submission_key' => bin2hex(random_bytes(32))], $customer);
        $check(false, 'Database reply rate limit blocks further messages across sessions.');
    } catch (ValidationException) { $check(true, 'Database reply rate limit blocks further messages across sessions.'); }

    $tokenQuery = $db->prepare("SELECT token_hash FROM support_guest_access WHERE enquiry_id=? ORDER BY guest_access_id LIMIT 1");
    $tokenQuery->execute([$guestId]); $firstHash = (string) $tokenQuery->get_result()->fetch_column(); $tokenQuery->close();
    $outbox = $db->prepare("SELECT context_json FROM email_outbox WHERE message_id=(SELECT message_id FROM enquiry_messages WHERE submission_key=?) AND template_key='support_guest_access'");
    $outbox->execute([$guestKey]); $contextJson = (string) $outbox->get_result()->fetch_column(); $outbox->close();
    $context = json_decode($contextJson, true, 16, JSON_THROW_ON_ERROR);
    $token = SupportTokenCipher::open((string) $context['access_token_sealed']);
    $check($firstHash === hash('sha256', $token) && !str_contains($contextJson, $token), 'Database stores a token hash and private outbox stores only a sealed token.');
    $check(SupportConversationService::validGuestToken($token), 'Guest token has 256 bits of URL-safe hexadecimal entropy.');
    $notConsumed = $db->prepare('SELECT consumed_at FROM support_guest_access WHERE token_hash=?');
    $notConsumed->execute([$firstHash]);
    $check($notConsumed->get_result()->fetch_column() === null, 'A scanner-style GET does not consume the guest access token.');
    $notConsumed->close();
    $check($conversations->confirmGuestAccess($token) === $guestId, 'Explicit confirmation POST consumes the valid token and creates a scoped session.');
    $check($conversations->guestThread($guestId)['enquiry_id'] === $guestId, 'Guest session opens its verified conversation.');
    try { $conversations->guestThread($accountEnquiryId); $check(false, 'Guest session cannot cross to a guessed account enquiry ID.'); }
    catch (DomainException) { $check(true, 'Guest session cannot cross to a guessed account enquiry ID.'); }
    try { $conversations->accountThread($guestId, $customer); $check(false, 'Account owner cannot read an unrelated guest conversation.'); }
    catch (DomainException) { $check(true, 'Account owner cannot read an unrelated guest conversation.'); }
    try { $conversations->confirmGuestAccess($token); $check(false, 'A consumed guest token cannot be replayed.'); }
    catch (DomainException) { $check(true, 'A consumed guest token cannot be replayed.'); }

    $guestReplyKey = bin2hex(random_bytes(32));
    $check($conversations->reply($guestId, ['body' => 'I have more details.', 'submission_key' => $guestReplyKey], [], $guestId), 'Verified guest can reply to the scoped thread.');
    $concurrentKey = bin2hex(random_bytes(32));
    $workers = [];
    for ($workerIndex = 0; $workerIndex < 2; ++$workerIndex) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __DIR__ . '/support_reply_worker.php', (string) $guestId, $concurrentKey],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
        if (!is_resource($process)) throw new RuntimeException('Could not start concurrent reply worker.');
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $workerResults = [];
    foreach ($workers as [$process, $pipes]) {
        $workerResults[] = trim(stream_get_contents($pipes[1])); fclose($pipes[1]);
        $workerErrors = trim(stream_get_contents($pipes[2])); fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0 || $workerErrors !== '') throw new RuntimeException('Concurrent reply worker failed.');
    }
    sort($workerResults);
    $check($workerResults === ['created', 'duplicate'], 'Concurrent duplicate posts create exactly one message and event.');
    $beforeInvalidResend = (int) $db->query('SELECT COUNT(*) FROM support_guest_access WHERE enquiry_id=' . $guestId)->fetch_row()[0];
    $conversations->resendGuestAccess(999999999, 'nobody@example.test');
    $afterInvalidResend = (int) $db->query('SELECT COUNT(*) FROM support_guest_access WHERE enquiry_id=' . $guestId)->fetch_row()[0];
    $check($afterInvalidResend === $beforeInvalidResend, 'Generic resend for unknown IDs makes no guest token or response distinction.');
    $conversations->resendGuestAccess($guestId, $guestValues['email']);
    $afterValidResend = (int) $db->query('SELECT COUNT(*) FROM support_guest_access WHERE enquiry_id=' . $guestId)->fetch_row()[0];
    $check($afterValidResend === $beforeInvalidResend + 1, 'A valid generic resend queues a fresh expiring access token.');

    $guestAccess = $db->prepare('SELECT token_hash FROM support_guest_access WHERE enquiry_id=? ORDER BY guest_access_id DESC LIMIT 1');
    $guestAccess->execute([$guestId]); $expiredHash = (string) $guestAccess->get_result()->fetch_column(); $guestAccess->close();
    $expire = $db->prepare('UPDATE support_guest_access SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE token_hash=?');
    $expire->execute([$expiredHash]); $expire->close();
    try { $conversations->confirmGuestAccess(SupportTokenCipher::open((string) $context['access_token_sealed'])); $check(false, 'Expired guest tokens are rejected.'); }
    catch (DomainException) { $check(true, 'Expired guest tokens are rejected.'); }

    $adminDowngrade = $db->prepare("UPDATE customers SET role='customer' WHERE customer_id=?");
    $adminDowngrade->execute([$adminId]); $adminDowngrade->close();
    try { $conversations->adminInbox($admin, []); $check(false, 'A downgraded administrator cannot continue reading the inbox.'); }
    catch (DomainException) { $check(true, 'A downgraded administrator cannot continue reading the inbox.'); }
    $archive = $db->prepare("UPDATE customers SET account_state='archived',archived_at=UTC_TIMESTAMP() WHERE customer_id=?");
    $archive->execute([$customerId]); $archive->close();
    try { $conversations->accountInbox($customer); $check(false, 'An archived account cannot read saved conversations.'); }
    catch (DomainException) { $check(true, 'An archived account cannot read saved conversations.'); }

    foreach ($checks as $label) echo "PASS: $label\n";
    echo 'Support conversation checks: ' . count($checks) . " passed, 0 failed.\n";
} finally {
    if ($submissionKeys !== []) {
        $delete = $db->prepare('DELETE FROM customer_enquiries WHERE submission_key=?');
        foreach ($submissionKeys as $key) $delete->execute([$key]);
        $delete->close();
    }
    foreach ([$adminId, $adminTwoId] as $id) if ($id !== null) { $statement=$db->prepare('DELETE FROM customers WHERE customer_id=?'); $statement->execute([$id]); $statement->close(); }
    if ($customerId !== null) { $statement=$db->prepare('DELETE FROM customers WHERE customer_id=?'); $statement->execute([$customerId]); $statement->close(); }
}
