<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Admin\AdminAccess;
use Verdant\Notifications\CommerceNotifier;
use Verdant\Notifications\EmailActivity;
use Verdant\Notifications\EmailRenderer;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;

$databaseName = getenv('DB_NAME') ?: '';
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'local'
    || preg_match('/^verdant_test_[a-z0-9_]+$/D', $databaseName) !== 1) {
    fwrite(STDERR, "Use APP_ENV=local and an isolated verdant_test_ database.\n");
    exit(2);
}

$connection = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
};
$one = static function (string $sql, array $values = []) use ($connection): ?array {
    $statement = $connection->prepare($sql);
    try {
        $statement->execute($values);
        return $statement->get_result()->fetch_assoc() ?: null;
    } finally {
        $statement->close();
    }
};

$connection->begin_transaction();
try {
    $adminRow = $one("SELECT customer_id FROM customers WHERE role='admin' AND account_state='active' ORDER BY customer_id LIMIT 1");
    if ($adminRow === null) {
        throw new RuntimeException('The isolated test database needs its synthetic active admin fixture.');
    }
    $admin = ['customer_id' => (int) $adminRow['customer_id']];
    $tag = bin2hex(random_bytes(12));
    $customerStatement = $connection->prepare(
        "INSERT INTO customers(email,password_hash,name,role,account_state) VALUES(?,'test-hash','Activity Test','customer','active')"
    );
    $customerStatement->execute(["activity-$tag@example.test"]);
    $customerId = (int) $connection->insert_id;
    $customerStatement->close();
    $customer = ['customer_id' => $customerId];

    $submissionKey = hash('sha256', 'enquiry:' . $tag);
    $enquiry = $connection->prepare(
        "INSERT INTO customer_enquiries(customer_id,submission_key,name,email,topic,subject,order_reference,message)
         VALUES(NULL,?,'Activity Test','activity@example.test','customer','Activity fixture',NULL,'Synthetic activity message')"
    );
    $enquiry->execute([$submissionKey]);
    $enquiryId = (int) $connection->insert_id;
    $enquiry->close();
    $messageKey = hash('sha256', 'message:' . $tag);
    $message = $connection->prepare(
        "INSERT INTO enquiry_messages(enquiry_id,author_kind,author_customer_id,body,submission_key)
         VALUES(?,'guest',NULL,'Synthetic activity message',?)"
    );
    $message->execute([$enquiryId, $messageKey]);
    $messageId = (int) $connection->insert_id;
    $message->close();

    $fixtureMessages = [];
    $messageInsert = $connection->prepare(
        "INSERT INTO enquiry_messages(enquiry_id,author_kind,author_customer_id,body,submission_key)
         VALUES(?,'guest',NULL,'Synthetic activity message',?)"
    );
    foreach (['held', 'failed', 'captured'] as $kind) {
        $messageInsert->execute([$enquiryId, hash('sha256', $kind . ':' . $tag)]);
        $fixtureMessages[$kind] = (int) $connection->insert_id;
    }
    $messageInsert->close();
    $insertOutbox = $connection->prepare(
        "INSERT INTO email_outbox(message_id,recipient_email,template_key,context_json,transport,delivery_state,attempt_count,failure_code)
         VALUES(?,?,'support_admin_new_message',?,'local_capture',?,?,?)"
    );
    $safeContext = json_encode(['enquiry_id' => $enquiryId, 'message_id' => $fixtureMessages['held']], JSON_THROW_ON_ERROR);
    $insertOutbox->execute([$fixtureMessages['held'], 'admin@thisisphila.co.za', $safeContext, 'held', 0, null]);
    $heldId = (int) $connection->insert_id;
    $unsafeContext = json_encode(['enquiry_id' => 'javascript:alert(1)', 'message_id' => '<script>alert(1)</script>'], JSON_THROW_ON_ERROR);
    $insertOutbox->execute([$fixtureMessages['failed'], 'admin@thisisphila.co.za', $unsafeContext, 'failed', 2, 'transport_error']);
    $failedId = (int) $connection->insert_id;
    $capturedContext = json_encode(['enquiry_id' => $enquiryId, 'message_id' => $fixtureMessages['captured']], JSON_THROW_ON_ERROR);
    $insertOutbox->execute([$fixtureMessages['captured'], 'admin@thisisphila.co.za', $capturedContext, 'captured', 1, null]);
    $capturedId = (int) $connection->insert_id;
    $insertOutbox->close();

    $access = new AdminAccess($connection);
    $activity = new EmailActivity($connection, $access, 'https://verdant.example.test');
    $orderStatement = $connection->prepare("INSERT INTO sales_orders(customer_id,checkout_key,state) VALUES(?,?,'confirmed')");
    $orderStatement->execute([$customerId, 'activity-' . $tag]);
    $orderId = (int) $connection->insert_id;
    $orderStatement->close();
    $notifier = new CommerceNotifier($connection);
    $notifier->record('purchase_confirmed', 'activity-purchase:' . $tag, 'order', $orderId);
    $notifier->record('order_fulfilled', 'activity-fulfilled:' . $tag, 'order', $orderId);
    $cancelOrder = $connection->prepare("INSERT INTO sales_orders(customer_id,checkout_key,state) VALUES(?,?,'pending_payment')");
    $cancelOrder->execute([$customerId, 'activity-cancel-' . $tag]);
    $cancelOrderId = (int) $connection->insert_id;
    $cancelOrder->close();
    $markCancelled = $connection->prepare("UPDATE sales_orders SET state='cancelled' WHERE order_id=?");
    $markCancelled->execute([$cancelOrderId]);
    $markCancelled->close();
    $notifier->record('order_cancelled', 'activity-cancelled:' . $tag, 'order', $cancelOrderId);
    $listing = $activity->listRows($admin, 1, 'held');
    $foundHeld = false;
    for ($page = 1; $page <= (int) ceil($listing['total'] / EmailActivity::PAGE_SIZE); ++$page) {
        $pageRows = $page === 1 ? $listing['rows'] : $activity->listRows($admin, $page, 'held')['rows'];
        $foundHeld = $foundHeld || in_array($heldId, array_column($pageRows, 'outbox_id'), true);
        if ($foundHeld) {
            break;
        }
    }
    $check($listing['total'] >= 1 && $foundHeld,
        'Email activity list joins support rows and applies the status filter.');
    $detail = $activity->detail($admin, $heldId);
    $check($detail !== null && $detail['event_label'] === 'New customer enquiry'
        && str_contains($detail['preview']['text'] ?? '', '/admin/enquiry?id='),
        'Email activity detail renders the stored support message safely.');
    $commerceOutbox = $one(
        "SELECT o.outbox_id FROM email_outbox o JOIN commerce_notification_events e ON e.commerce_event_id=o.commerce_event_id
         WHERE e.event_key=? AND o.recipient_customer_id=? AND o.template_key='purchase_confirmed'",
        ['activity-purchase:' . $tag, $customerId],
    );
    $commerceDetail = $activity->detail($admin, (int) ($commerceOutbox['outbox_id'] ?? 0));
    $check($commerceDetail !== null && $commerceDetail['record_type'] === 'order'
        && (int) $commerceDetail['record_id'] === $orderId && $commerceDetail['record_label'] === 'Order #' . $orderId,
        'Email detail derives order type and identifier from the commerce event source columns.');
    $unsafeDetail = $activity->detail($admin, $failedId);
    $check($unsafeDetail !== null && $unsafeDetail['preview'] === null && $unsafeDetail['preview_error'] !== null,
        'Malformed support identifiers cannot render a conversation link.');
    $check($unsafeDetail['can_retry'] === true, 'An eligible failed local capture below the attempt cap exposes retry.');
    $check($activity->detail($admin, $capturedId)['can_retry'] === false,
        'A captured message never exposes a retry action.');

    $mailEnvKeys = ['APP_ENV', 'EMAIL_AUTO_QUEUE', 'EMAIL_TRANSPORT', 'EMAIL_FROM_ADDRESS', 'EMAIL_FROM_NAME',
        'EMAIL_REPLY_TO', 'EMAIL_BASE_URL', 'EMAIL_OPEN_DELIVERY', 'RESEND_API_KEY'];
    $previousMailEnv = [];
    foreach ($mailEnvKeys as $key) {
        $previousMailEnv[$key] = getenv($key);
    }
    try {
        putenv('APP_ENV=production');
        putenv('EMAIL_AUTO_QUEUE=1');
        putenv('EMAIL_TRANSPORT=resend');
        putenv('EMAIL_FROM_ADDRESS=verdant@thisisphila.co.za');
        putenv('EMAIL_FROM_NAME=Verdant');
        putenv('EMAIL_REPLY_TO=admin@thisisphila.co.za');
        putenv('EMAIL_BASE_URL=https://verdant.x10.mx');
        putenv('EMAIL_OPEN_DELIVERY=1');
        putenv('RESEND_API_KEY=re_test_key');
        $check(str_contains($activity->deliveryNotice(), 'Email delivery is on'),
            'Production email activity states plainly that automatic delivery is on.');
        $heldDetail = $activity->detail($admin, $heldId);
        $check($heldDetail !== null && $heldDetail['can_release'] === true,
            'A held message exposes release when production delivery permits its recipient.');
        $check($activity->release($admin, $heldId, (string) $heldDetail['updated_at']),
            'An administrator can release one held message for delivery.');
        $released = $one('SELECT delivery_state, transport FROM email_outbox WHERE outbox_id=?', [$heldId]);
        $check(($released['delivery_state'] ?? null) === 'queued' && ($released['transport'] ?? null) === 'resend',
            'Release queues the held message on the configured production transport.');
        $check(!$activity->release($admin, $heldId, (string) $heldDetail['updated_at']),
            'A stale release cannot queue a changed outbox row.');
    } finally {
        foreach ($previousMailEnv as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    try {
        $activity->listRows($customer);
        $check(false, 'A customer role cannot list private email activity.');
    } catch (DomainException) {
        $check(true, 'A customer role cannot list private email activity.');
    }
    try {
        $activity->detail($customer, $heldId);
        $check(false, 'A customer role cannot inspect a recipient preview.');
    } catch (DomainException) {
        $check(true, 'A customer role cannot inspect a recipient preview.');
    }

    $stale = (string) $unsafeDetail['updated_at'];
    $check($activity->retry($admin, $failedId, $stale), 'A failed local capture is requeued with its current row version.');
    $check(!$activity->retry($admin, $failedId, $stale), 'A stale retry cannot requeue a changed outbox row.');
    try {
        $activity->retry($customer, $heldId, (string) $detail['updated_at']);
        $check(false, 'A customer role cannot retry a private message.');
    } catch (DomainException) {
        $check(true, 'A customer role cannot retry a private message.');
    }

    $rendered = EmailRenderer::render([
        'template_key' => 'purchase_confirmed',
        'context' => [
            'event_kind' => 'purchase_confirmed', 'record_type' => 'order', 'record_id' => 72,
            'path' => 'https://attacker.example/steal', 'customer_name' => '<script>alert(1)</script>',
            'items' => [['name' => '<img src=x onerror=alert(1)>', 'quantity' => '2']],
            'total_minor' => 2199, 'currency' => 'ZAR',
        ],
    ], 'https://verdant.example.test');
    $check(str_contains($rendered['html'], 'https://verdant.example.test/account/order?id=72')
        && !str_contains($rendered['html'], '<script>') && !str_contains($rendered['html'], '<img src=x')
        && str_contains($rendered['text'], 'R 21.99'),
        'Commerce template escapes hostile context, ignores arbitrary paths and preserves amount formatting.');
    $adminRental = EmailRenderer::render([
        'template_key' => 'rental_confirmed', 'recipient_role' => 'admin',
        'context' => [
            'event_kind' => 'rental_confirmed', 'record_type' => 'rental', 'record_id' => 44,
            'path' => '/account/rental?id=44', 'customer_name' => 'Synthetic customer',
            'items' => [['name' => 'Fern', 'quantity' => 1, 'start_date' => '2026-10-22', 'end_date_exclusive' => '2026-10-23']],
            'currency' => 'ZAR',
        ],
    ], 'https://verdant.example.test');
    $check(str_contains($adminRental['subject'], 'New confirmed rental')
        && str_contains($adminRental['text'], 'Hello Admin,')
        && str_contains($adminRental['text'], 'Customer: Synthetic customer')
        && str_contains($adminRental['text'], 'Rental period: 22 October 2026 to 22 October 2026')
        && str_contains($adminRental['text'], 'Return boundary: 23 October 2026')
        && str_contains($adminRental['text'], 'https://verdant.example.test/admin/rental?id=44')
        && !str_contains($adminRental['text'], 'Your rental'),
        'Administrator rental mail uses admin copy and a protected admin link with clear booked and return dates.');
    $adminComposition = EmailRenderer::render([
        'template_key' => 'composition_confirmed', 'recipient_role' => 'admin',
        'context' => ['event_kind' => 'composition_confirmed', 'record_type' => 'composition', 'record_id' => 7,
            'path' => '/account/order?id=33', 'items' => [], 'currency' => 'ZAR'],
    ], 'https://verdant.example.test');
    $check(str_contains($adminComposition['text'], 'https://verdant.example.test/admin/order?id=33'),
        'Administrator arrangement mail links to the trusted linked order record.');
    $returned = EmailRenderer::render([
        'template_key' => 'rental_returned', 'context' => ['event_kind' => 'rental_returned', 'record_type' => 'rental',
            'record_id' => 44, 'items' => [], 'newly_returned_quantity' => 2, 'currency' => 'ZAR'],
    ], 'https://verdant.example.test');
    $check(str_contains($returned['text'], 'Newly returned in this operation: 2'),
        'Rental return templates consume the notifier snapshot field name.');
    foreach ([
        'order_fulfilled' => 'Your Verdant order has been fulfilled',
        'order_cancelled' => 'Your Verdant order was cancelled',
    ] as $kind => $expectedSubject) {
        $message = EmailRenderer::render([
            'template_key' => $kind,
            'context' => ['event_kind' => $kind, 'record_type' => 'order', 'record_id' => $orderId, 'items' => [], 'currency' => 'ZAR'],
        ], 'https://verdant.example.test');
        $check(str_contains($message['subject'], $expectedSubject), ucfirst(str_replace('_', ' ', $kind)) . ' template is available.');
        $event = $one('SELECT event_kind FROM commerce_notification_events WHERE event_key=?', [
            ($kind === 'order_fulfilled' ? 'activity-fulfilled:' : 'activity-cancelled:') . $tag,
        ]);
        $check(($event['event_kind'] ?? null) === $kind, ucfirst(str_replace('_', ' ', $kind)) . ' event persists with its approved kind.');
    }

    echo "Admin email activity checks: {$checks} passed, 0 failed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    $connection->rollback();
    $connection->close();
}
