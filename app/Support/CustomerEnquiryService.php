<?php

declare(strict_types=1);

namespace Verdant\Support;

use Verdant\Admin\AdminAccess;
use Verdant\Identity\SessionManager;
use Verdant\Support\Mail\OutboxQueuePolicy;

/** Persists customer enquiries for the protected administration inbox. */
final class CustomerEnquiryService
{
    private const RATE_WINDOW_SECONDS = 900;
    private const RATE_LIMIT = 3;

    public function __construct(
        private readonly \mysqli $connection,
        private readonly AdminAccess $access,
    ) {
    }

    public function conversations(): SupportConversationService
    {
        return new SupportConversationService($this->connection, $this->access);
    }

    /** Returns false when a retry with the same one-use key was already stored. */
    public function submit(array $input, ?array $actor): bool
    {
        SessionManager::start();
        $values = self::rawValues($input);
        $errors = [];
        $customer = $this->trustedCustomer($actor);
        $nameLimit = $customer === null ? 120 : 180;
        $nameMinimum = $customer === null ? 2 : 1;
        if ($customer !== null) {
            $values['name'] = (string) $customer['name'];
            $values['email'] = (string) $customer['email'];
        }
        if (mb_strlen($values['name']) < $nameMinimum || mb_strlen($values['name']) > $nameLimit) {
            $errors['name'] = "Enter your name ({$nameMinimum} to {$nameLimit} characters).";
        }
        if (strlen($values['email']) > 254 || filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (($input['topic'] ?? null) !== 'customer') {
            $errors['topic'] = 'Choose customer support. Corporate and trade enquiries are not available yet.';
        }
        if ($values['subject'] === '' || mb_strlen($values['subject']) > 160) {
            $errors['subject'] = 'Enter a subject up to 160 characters.';
        }
        if ($values['message'] === '' || mb_strlen($values['message']) > 5000) {
            $errors['message'] = 'Enter a message up to 5,000 characters.';
        }
        if (mb_strlen($values['order_reference']) > 80) {
            $errors['order_reference'] = 'Order reference must be 80 characters or fewer.';
        }
        $key = $input['submission_key'] ?? null;
        $sessionKey = $_SESSION['_customer_enquiry_key'] ?? null;
        $completedKeys = self::completedKeys();
        $isSessionKey = is_string($sessionKey) && is_string($key) && hash_equals($sessionKey, $key);
        $isCompletedKey = is_string($key) && in_array($key, $completedKeys, true);
        if (!is_string($key) || preg_match('/^[a-f0-9]{64}$/D', $key) !== 1
            || (!$isSessionKey && !$isCompletedKey)) {
            $errors['submission'] = 'This form has expired. Reload the page and try again.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // The unique key makes retries safe even if the browser repeats a POST after a lost response.
        if ($this->submissionExists($key)) {
            self::rememberCompletedKey($key);
            return false;
        }

        $now = time();
        $recent = array_values(array_filter(
            is_array($_SESSION['_customer_enquiry_times'] ?? null) ? $_SESSION['_customer_enquiry_times'] : [],
            static fn (mixed $time): bool => is_int($time) && $time > $now - self::RATE_WINDOW_SECONDS,
        ));
        $_SESSION['_customer_enquiry_times'] = $recent;
        if (count($recent) >= self::RATE_LIMIT) {
            throw new ValidationException(['form' => 'Please wait before sending another enquiry.']);
        }

        $this->connection->begin_transaction();
        try {
            // Re-read and lock the active identity within the write transaction
            // so an archive/account change cannot race the owner association.
            $customer = $this->trustedCustomer($actor, true);
            if ($customer !== null) {
                $values['name'] = (string) $customer['name'];
                $values['email'] = (string) $customer['email'];
            }
            $statement = $this->connection->prepare(
                'INSERT INTO customer_enquiries (customer_id, submission_key, name, email, topic, subject, order_reference, message)
                 VALUES (?, ?, ?, ?, \'customer\', ?, ?, ?)'
            );
            $statement->execute([
                $customer['customer_id'] ?? null, $key, $values['name'], $values['email'],
                $values['subject'], $values['order_reference'] === '' ? null : $values['order_reference'], $values['message'],
            ]);
            $enquiryId = (int) $this->connection->insert_id;
            $statement->close();

            $authorKind = $customer === null ? 'guest' : ($customer['role'] === 'admin' ? 'admin' : 'customer');
            $statement = $this->connection->prepare(
                'INSERT INTO enquiry_messages (enquiry_id, author_kind, author_customer_id, body, submission_key)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $statement->execute([$enquiryId, $authorKind, $customer['customer_id'] ?? null, $values['message'], $key]);
            $messageId = (int) $this->connection->insert_id;
            $statement->close();

            if ($authorKind === 'guest') {
                // The one-use URL itself exists only sealed in the private outbox;
                // the access table stores only its SHA-256 digest.
                (new SupportConversationService($this->connection, $this->access))
                    ->queueGuestAccess($enquiryId, $messageId, $values['email']);
            }

            // Each active admin gets a durable event. Valid recipients enter
            // automatic delivery; the rest stay held for explicit release.
            if ($authorKind !== 'admin') {
                $admins = $this->connection->query(
                    "SELECT customer_id, email FROM customers WHERE role = 'admin' AND account_state = 'active'"
                )->fetch_all(MYSQLI_ASSOC);
                $notification = $this->connection->prepare(
                    "INSERT INTO support_notifications (recipient_customer_id, enquiry_id, message_id, event_kind)
                     VALUES (?, ?, ?, 'new_customer_message')"
                );
                $outbox = $this->connection->prepare(
                    "INSERT INTO email_outbox
                       (message_id, recipient_email, recipient_customer_id, recipient_role,
                        template_key, context_json, delivery_state, transport)
                     VALUES (?, ?, ?, 'admin', 'support_admin_new_message', ?, ?, ?)"
                );
                $context = json_encode(['enquiry_id' => $enquiryId, 'message_id' => $messageId], JSON_THROW_ON_ERROR);
                foreach ($admins as $admin) {
                    $adminId = (int) $admin['customer_id'];
                    $delivery = OutboxQueuePolicy::initialDelivery((string) $admin['email']);
                    $notification->execute([$adminId, $enquiryId, $messageId]);
                    $outbox->execute([$messageId, (string) $admin['email'], $adminId, $context,
                        $delivery['delivery_state'], $delivery['transport']]);
                }
                $notification->close();
                $outbox->close();
            }
            $this->connection->commit();
        } catch (\Throwable $error) {
            $this->connection->rollback();
            if ($error instanceof \mysqli_sql_exception && (int) $error->getCode() === 1062
                && $this->submissionExists($key)) {
                self::rememberCompletedKey($key);
                return false;
            }
            throw $error;
        }

        $_SESSION['_customer_enquiry_times'][] = $now;
        self::rememberCompletedKey($key);
        return true;
    }

    /** @return list<array<string,mixed>> */
    public function inbox(array $actor): array
    {
        $this->access->require($actor);
        $result = $this->connection->query(
            'SELECT enquiry_id, customer_id, name, email, topic, subject, order_reference, message,
                    enquiry_state, created_at, updated_at
             FROM customer_enquiries ORDER BY created_at DESC, enquiry_id DESC LIMIT 200'
        );
        try {
            return $result->fetch_all(MYSQLI_ASSOC);
        } finally {
            $result->free();
        }
    }

    /** @return array{name:string,email:string,subject:string,order_reference:string,message:string} */
    public static function safeValues(array $input): array
    {
        $limits = ['name' => 120, 'email' => 254, 'subject' => 160, 'order_reference' => 80, 'message' => 5000];
        $values = [];
        foreach ($limits as $field => $limit) {
            $value = self::inputString($input[$field] ?? null);
            $values[$field] = mb_substr($value, 0, $limit);
        }
        return $values;
    }

    /** @return array{name:string,email:string,subject:string,order_reference:string,message:string} */
    private static function rawValues(array $input): array
    {
        $values = [];
        foreach (['name', 'email', 'subject', 'order_reference', 'message'] as $field) {
            $values[$field] = self::inputString($input[$field] ?? null);
        }
        return $values;
    }

    private static function inputString(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function submissionExists(string $key): bool
    {
        $statement = $this->connection->prepare('SELECT enquiry_id FROM customer_enquiries WHERE submission_key = ?');
        try {
            $statement->execute([$key]);
            return $statement->get_result()->num_rows === 1;
        } finally {
            $statement->close();
        }
    }

    /** Fetch identity from the active account; never trust a caller's snapshot. */
    private function trustedCustomer(?array $actor, bool $lock = false): ?array
    {
        if ($actor === null) {
            return null;
        }
        $id = $actor['customer_id'] ?? null;
        if (!is_int($id) || $id < 1) {
            throw new \DomainException('Authenticated customer identity is unavailable.');
        }
        $statement = $this->connection->prepare(
            "SELECT customer_id, name, email, role FROM customers
             WHERE customer_id = ? AND account_state = 'active'" . ($lock ? ' FOR UPDATE' : '')
        );
        try {
            $statement->execute([$id]);
            $customer = $statement->get_result()->fetch_assoc();
            if (!is_array($customer)) {
                throw new \DomainException('Authenticated customer identity is unavailable.');
            }
            $customer['customer_id'] = (int) $customer['customer_id'];
            return $customer;
        } finally {
            $statement->close();
        }
    }

    /** @return list<string> */
    private static function completedKeys(): array
    {
        $keys = $_SESSION['_customer_enquiry_completed_keys'] ?? [];
        if (!is_array($keys)) return [];
        return array_values(array_filter($keys, static fn (mixed $key): bool =>
            is_string($key) && preg_match('/^[a-f0-9]{64}$/D', $key) === 1));
    }

    private static function rememberCompletedKey(string $key): void
    {
        $keys = array_values(array_unique([...self::completedKeys(), $key]));
        $_SESSION['_customer_enquiry_completed_keys'] = array_slice($keys, -5);
    }

    public static function validOpaqueToken(string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $token) === 1;
    }
}
