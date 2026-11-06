<?php

declare(strict_types=1);

namespace Verdant\Support;

use mysqli;
use Verdant\Admin\AdminAccess;
use Verdant\Identity\SessionManager;
use Verdant\Support\Mail\OutboxQueuePolicy;

/** Account-scoped support conversation, notification and guest-link operations. */
final class SupportConversationService
{
    private const PAGE_SIZE = 20;
    private const GUEST_SESSION_SECONDS = 900;
    private const REPLY_RATE_WINDOW_SECONDS = 900;
    private const REPLY_RATE_LIMIT = 3;

    public function __construct(private readonly mysqli $connection, private readonly AdminAccess $access)
    {
    }

    /** @return array{items:list<array<string,mixed>>,page:int,pages:int,total:int,unread:int} */
    public function adminInbox(array $actor, array $filters = []): array
    {
        $this->access->require($actor);
        $page = self::boundedPage($filters['page'] ?? 1);
        $state = in_array($filters['state'] ?? '', ['open', 'resolved'], true) ? $filters['state'] : '';
        $query = self::searchText($filters['q'] ?? '');
        $where = [];
        $params = [];
        if ($state !== '') { $where[] = 'e.enquiry_state = ?'; $params[] = $state; }
        if ($query !== '') {
            $where[] = '(e.subject LIKE ? OR e.name LIKE ? OR e.email LIKE ? OR CAST(e.enquiry_id AS CHAR) = ?)';
            $like = '%' . $query . '%';
            array_push($params, $like, $like, $like, $query);
        }
        $predicate = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $count = $this->connection->prepare('SELECT COUNT(*) FROM customer_enquiries e' . $predicate);
        $count->execute($params);
        $total = (int) $count->get_result()->fetch_row()[0];
        $count->close();
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $pages);
        $offset = ($page - 1) * self::PAGE_SIZE;
        $sql = "SELECT e.enquiry_id,e.customer_id,e.name,e.email,e.subject,e.order_reference,e.enquiry_state,e.created_at,e.updated_at,
                    (SELECT m.body FROM enquiry_messages m WHERE m.enquiry_id=e.enquiry_id ORDER BY m.message_id DESC LIMIT 1) latest_body,
                    (SELECT m.created_at FROM enquiry_messages m WHERE m.enquiry_id=e.enquiry_id ORDER BY m.message_id DESC LIMIT 1) latest_at,
                    (SELECT COUNT(*) FROM support_notifications n WHERE n.enquiry_id=e.enquiry_id AND n.recipient_customer_id=? AND n.event_kind='new_customer_message' AND n.read_at IS NULL) unread
                FROM customer_enquiries e" . $predicate . ' ORDER BY e.updated_at DESC,e.enquiry_id DESC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . $offset;
        $statement = $this->connection->prepare($sql);
        $statement->execute(array_merge([(int) $actor['customer_id']], $params));
        $items = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        $statement->close();
        return ['items' => $items, 'page' => $page, 'pages' => $pages, 'total' => $total,
            'unread' => $this->unreadCount((int) $actor['customer_id'], 'new_customer_message')];
    }

    /** @return list<array<string,mixed>> Legacy inbox view used by the original controller/tests. */
    public function inbox(array $actor): array
    {
        return $this->adminInbox($actor, ['page' => 1])['items'];
    }

    /** Opening one admin thread clears only that administrator's events for that thread. */
    public function adminThread(int $enquiryId, array $actor, mixed $messagePage = 1): array
    {
        return $this->thread($enquiryId, (int) $actor['customer_id'], null, false, 'new_customer_message', $messagePage, $actor);
    }

    /** @return array{items:list<array<string,mixed>>,unread:int} */
    public function accountInbox(array $actor, array $filters = []): array
    {
        $customer = $this->activeCustomer($actor);
        if ($customer['role'] !== 'customer') throw new \DomainException('Conversation not found.');
        $page = self::boundedPage($filters['page'] ?? 1);
        $totalQuery = $this->connection->prepare('SELECT COUNT(*) FROM customer_enquiries WHERE customer_id=?');
        $totalQuery->execute([$customer['customer_id']]); $total = (int) $totalQuery->get_result()->fetch_row()[0]; $totalQuery->close();
        $pages = max(1, (int) ceil($total / 50));
        $page = min($page, $pages);
        $offset = ($page - 1) * 50;
        $statement = $this->connection->prepare(
            "SELECT e.enquiry_id,e.subject,e.enquiry_state,e.created_at,e.updated_at,
                    (SELECT m.body FROM enquiry_messages m WHERE m.enquiry_id=e.enquiry_id ORDER BY m.message_id DESC LIMIT 1) latest_body,
                    (SELECT COUNT(*) FROM support_notifications n WHERE n.enquiry_id=e.enquiry_id AND n.recipient_customer_id=? AND n.event_kind='admin_reply' AND n.read_at IS NULL) unread
             FROM customer_enquiries e WHERE e.customer_id=? ORDER BY e.updated_at DESC,e.enquiry_id DESC LIMIT 50 OFFSET {$offset}"
        );
        $statement->execute([$customer['customer_id'], $customer['customer_id']]);
        $items = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        $statement->close();
        return ['items' => $items, 'unread' => $this->unreadCount($customer['customer_id'], 'admin_reply'),
            'page' => $page, 'pages' => $pages, 'total' => $total];
    }

    /** Shell badge lookup is read-only and always revalidates the signed-in account. */
    public function notificationCount(array $actor): int
    {
        try { $customer = $this->activeCustomer($actor); }
        catch (\DomainException) { return 0; }
        if (!in_array($customer['role'], ['admin', 'customer'], true)) return 0;
        return $this->unreadCount($customer['customer_id'], $customer['role'] === 'admin' ? 'new_customer_message' : 'admin_reply');
    }

    public function accountThread(int $enquiryId, array $actor, mixed $messagePage = 1): array
    {
        $customer = $this->activeCustomer($actor);
        if ($customer['role'] !== 'customer') throw new \DomainException('Conversation not found.');
        return $this->thread($enquiryId, $customer['customer_id'], $customer['customer_id'], false, 'admin_reply', $messagePage, $actor);
    }

    /** A duplicate submission key is a safe no-op. */
    public function reply(int $enquiryId, array $input, array $actor, ?int $guestEnquiryId = null, bool $adminAction = false): bool
    {
        $body = is_string($input['body'] ?? null) ? trim($input['body']) : '';
        $key = $input['submission_key'] ?? null;
        if ($enquiryId < 1 || $body === '' || mb_strlen($body) > 5000) {
            throw new ValidationException(['body' => 'Enter a message up to 5,000 characters.']);
        }
        if (!is_string($key) || preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) {
            throw new ValidationException(['submission' => 'This reply form has expired. Reload the conversation and try again.']);
        }
        if ($guestEnquiryId !== null && $guestEnquiryId !== $enquiryId) throw new \DomainException('Guest session is not valid for this conversation.');

        $this->connection->begin_transaction();
        try {
            $author = $guestEnquiryId !== null ? null : $this->activeCustomer($actor, true);
            if ($author !== null && $adminAction) {
                $this->access->require($actor, true);
                if ($author['role'] !== 'admin') throw new \DomainException('Administrator access required.');
            } elseif ($author !== null && $author['role'] !== 'customer') {
                throw new \DomainException('Conversation not found.');
            }
            $thread = $this->lockEnquiry($enquiryId);
            if ($author === null) {
                if ($guestEnquiryId !== (int) $thread['enquiry_id'] || $thread['customer_id'] !== null) {
                    throw new \DomainException('Guest session is not valid for this conversation.');
                }
                $authorKind = 'guest';
            } elseif ($adminAction) {
                $authorKind = 'admin';
            } else {
                if ((int) ($thread['customer_id'] ?? 0) !== $author['customer_id']) throw new \DomainException('Conversation not found.');
                $authorKind = 'customer';
            }
            $existing = $this->connection->prepare('SELECT enquiry_id FROM enquiry_messages WHERE submission_key=?');
            $existing->execute([$key]);
            $existingEnquiry = $existing->get_result()->fetch_column();
            $existing->close();
            if ($existingEnquiry !== false) {
                if ((int) $existingEnquiry !== $enquiryId) throw new \DomainException('Reply key is already used.');
                $this->connection->commit();
                return false;
            }
            $this->assertReplyRateLimit($author, $enquiryId);
            $statement = $this->connection->prepare(
                'INSERT INTO enquiry_messages(enquiry_id,author_kind,author_customer_id,body,submission_key) VALUES(?,?,?,?,?)'
            );
            $statement->execute([$enquiryId, $authorKind, $author['customer_id'] ?? null, $body, $key]);
            $messageId = (int) $this->connection->insert_id;
            $statement->close();
            $this->connection->query("UPDATE customer_enquiries SET enquiry_state='open',updated_at=GREATEST(UTC_TIMESTAMP(),DATE_ADD(updated_at,INTERVAL 1 SECOND)) WHERE enquiry_id=" . $enquiryId);
            if ($authorKind === 'admin') {
                if ($thread['customer_id'] !== null) $this->notifyCustomer((int) $thread['customer_id'], $enquiryId, $messageId, (string) $thread['email']);
                else $this->issueGuestAccess($enquiryId, $messageId, (string) $thread['email']);
            } else {
                $this->notifyAdmins($enquiryId, $messageId);
            }
            $this->connection->commit();
            $this->recordReplyTime($author, $enquiryId);
            return true;
        } catch (\Throwable $error) {
            $this->connection->rollback();
            throw $error;
        }
    }

    public function setState(int $enquiryId, string $state, array $input, array $actor): bool
    {
        if (!in_array($state, ['open', 'resolved'], true) || $enquiryId < 1) throw new ValidationException(['state' => 'Choose Open or Resolved.']);
        $expected = $input['updated_at'] ?? null;
        if (!is_string($expected) || strlen($expected) > 32) throw new ValidationException(['state' => 'Reload this conversation before changing its status.']);
        $this->connection->begin_transaction();
        try {
            $this->access->require($actor, true);
            $thread = $this->lockEnquiry($enquiryId);
            $latest = $this->connection->prepare('SELECT COALESCE(MAX(message_id),0) FROM enquiry_messages WHERE enquiry_id=?');
            $latest->execute([$enquiryId]); $latestMessageId = (int) $latest->get_result()->fetch_row()[0]; $latest->close();
            if ((string) $thread['updated_at'] !== $expected || ($input['current_state'] ?? null) !== $thread['enquiry_state']
                || (string) ($input['latest_message_id'] ?? '') !== (string) $latestMessageId) {
                throw new \DomainException('Conversation changed. Reload before updating its status.');
            }
            if ($thread['enquiry_state'] === $state) { $this->connection->commit(); return false; }
            $statement = $this->connection->prepare('UPDATE customer_enquiries SET enquiry_state=?,updated_at=GREATEST(UTC_TIMESTAMP(),DATE_ADD(updated_at,INTERVAL 1 SECOND)) WHERE enquiry_id=?');
            $statement->execute([$state, $enquiryId]);
            $statement->close();
            $this->connection->commit();
            return true;
        } catch (\Throwable $error) { $this->connection->rollback(); throw $error; }
    }

    /** GET uses this only to render a confirmation; only the POST exchange consumes it. */
    public static function validGuestToken(string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $token) === 1;
    }

    public function confirmGuestAccess(string $token): int
    {
        if (!self::validGuestToken($token)) throw new \DomainException('This access link is unavailable or expired.');
        $hash = hash('sha256', $token);
        $this->connection->begin_transaction();
        try {
            $statement = $this->connection->prepare(
                'SELECT g.guest_access_id,g.enquiry_id FROM support_guest_access g JOIN customer_enquiries e ON e.enquiry_id=g.enquiry_id
                 WHERE g.token_hash=? AND g.consumed_at IS NULL AND g.expires_at>UTC_TIMESTAMP() AND e.customer_id IS NULL FOR UPDATE'
            );
            $statement->execute([$hash]);
            $row = $statement->get_result()->fetch_assoc();
            $statement->close();
            if (!is_array($row)) throw new \DomainException('This access link is unavailable or expired.');
            $consume = $this->connection->prepare('UPDATE support_guest_access SET consumed_at=UTC_TIMESTAMP() WHERE guest_access_id=? AND consumed_at IS NULL');
            $consume->execute([(int) $row['guest_access_id']]);
            if ($consume->affected_rows !== 1) throw new \DomainException('This access link is unavailable or expired.');
            $consume->close();
            $this->connection->commit();
            SessionManager::start();
            if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
            $_SESSION['_support_guest'] = ['enquiry_id' => (int) $row['enquiry_id'], 'expires_at' => time() + self::GUEST_SESSION_SECONDS];
            return (int) $row['enquiry_id'];
        } catch (\Throwable $error) { $this->connection->rollback(); throw $error; }
    }

    public function currentGuestEnquiryId(): ?int
    {
        SessionManager::start();
        $guest = $_SESSION['_support_guest'] ?? null;
        if (!is_array($guest) || !is_int($guest['enquiry_id'] ?? null) || !is_int($guest['expires_at'] ?? null)
            || $guest['expires_at'] <= time() || $guest['expires_at'] > time() + self::GUEST_SESSION_SECONDS) {
            unset($_SESSION['_support_guest']);
            return null;
        }
        $statement = $this->connection->prepare('SELECT enquiry_id FROM customer_enquiries WHERE enquiry_id=? AND customer_id IS NULL');
        $statement->execute([$guest['enquiry_id']]);
        $valid = $statement->get_result()->num_rows === 1;
        $statement->close();
        if (!$valid) { unset($_SESSION['_support_guest']); return null; }
        return $guest['enquiry_id'];
    }

    public function guestThread(int $enquiryId, mixed $messagePage = 1): array
    {
        if ($this->currentGuestEnquiryId() !== $enquiryId) throw new \DomainException('Conversation not found.');
        return $this->thread($enquiryId, null, null, true, null, $messagePage);
    }

    /** Generic response; invalid IDs/emails and throttles produce no observable distinction. */
    public function resendGuestAccess(int $enquiryId, string $email): void
    {
        SessionManager::start();
        $now = time();
        $times = array_values(array_filter($_SESSION['_support_resend_times'] ?? [], static fn ($at) => is_int($at) && $at > $now - 900));
        $_SESSION['_support_resend_times'] = $times;
        if (count($times) < 3 && $enquiryId > 0 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $statement = $this->connection->prepare('SELECT enquiry_id,email FROM customer_enquiries WHERE enquiry_id=? AND customer_id IS NULL');
            $statement->execute([$enquiryId]);
            $row = $statement->get_result()->fetch_assoc();
            $statement->close();
            if (is_array($row) && hash_equals(mb_strtolower((string) $row['email']), mb_strtolower(trim($email)))) {
                $this->connection->begin_transaction();
                try {
                    $lock = $this->lockEnquiry($enquiryId);
                    $recent = $this->connection->prepare('SELECT COUNT(*) FROM support_guest_access WHERE enquiry_id=? AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)');
                    $recent->execute([$enquiryId]); $recentCount = (int) $recent->get_result()->fetch_row()[0]; $recent->close();
                    $last = $this->connection->prepare('SELECT message_id FROM enquiry_messages WHERE enquiry_id=? ORDER BY message_id DESC LIMIT 1');
                    $last->execute([$enquiryId]); $messageId = (int) $last->get_result()->fetch_column(); $last->close();
                    if ($recentCount < 4 && $messageId > 0) $this->issueGuestAccess($enquiryId, $messageId, (string) $lock['email'], 'support_guest_access_resend');
                    $this->connection->commit();
                } catch (\Throwable $error) { $this->connection->rollback(); throw $error; }
            }
        }
        if (count($times) < 3) $times[] = $now;
        $_SESSION['_support_resend_times'] = $times;
    }

    private function thread(int $enquiryId, ?int $recipientId, ?int $ownerId, bool $guest = false, ?string $eventKind = null, mixed $messagePage = 1, ?array $actor = null): array
    {
        if ($enquiryId < 1) throw new \DomainException('Conversation not found.');
        if ($recipientId !== null) $this->connection->begin_transaction();
        try {
            if ($recipientId !== null && $actor !== null) {
                if ($eventKind === 'new_customer_message') $this->access->require($actor, true);
                else {
                    $current = $this->activeCustomer($actor, true);
                    if ($current['role'] !== 'customer' || $current['customer_id'] !== $recipientId || $current['customer_id'] !== $ownerId) throw new \DomainException('Conversation not found.');
                }
            }
            $sql = 'SELECT enquiry_id,customer_id,name,email,subject,order_reference,enquiry_state,created_at,updated_at FROM customer_enquiries WHERE enquiry_id=?';
            if ($ownerId !== null) $sql .= ' AND customer_id=?';
            if ($guest) $sql .= ' AND customer_id IS NULL';
            $statement = $this->connection->prepare($sql . ($recipientId !== null ? ' FOR UPDATE' : ''));
            $params = [$enquiryId]; if ($ownerId !== null) $params[] = $ownerId;
            $statement->execute($params);
            $row = $statement->get_result()->fetch_assoc(); $statement->close();
            if (!is_array($row)) throw new \DomainException('Conversation not found.');
            if ($recipientId !== null) {
                $sql = 'UPDATE support_notifications SET read_at=UTC_TIMESTAMP() WHERE recipient_customer_id=? AND enquiry_id=? AND read_at IS NULL';
                $params = [$recipientId, $enquiryId];
                if ($eventKind !== null) { $sql .= ' AND event_kind=?'; $params[] = $eventKind; }
                $read = $this->connection->prepare($sql);
                $read->execute($params); $read->close();
            }
            $countMessages = $this->connection->prepare('SELECT COUNT(*),COALESCE(MAX(message_id),0) FROM enquiry_messages WHERE enquiry_id=?');
            $countMessages->execute([$enquiryId]); [$messageTotal, $latestMessageId] = $countMessages->get_result()->fetch_row(); $countMessages->close();
            $messagePages = max(1, (int) ceil((int) $messageTotal / 50));
            $messagePage = min(self::boundedPage($messagePage), $messagePages);
            $offset = ($messagePages - $messagePage) * 50;
            $messages = $this->connection->prepare('SELECT message_id,author_kind,body,created_at FROM enquiry_messages WHERE enquiry_id=? ORDER BY message_id DESC LIMIT 50 OFFSET ' . $offset);
            $messages->execute([$enquiryId]);
            $history = $messages->get_result()->fetch_all(MYSQLI_ASSOC); $messages->close();
            $row['messages'] = array_reverse($history);
            $row['messages_page'] = $messagePage;
            $row['messages_pages'] = $messagePages;
            $row['latest_message_id'] = (int) $latestMessageId;
            if ($recipientId !== null) $this->connection->commit();
            return $row;
        } catch (\Throwable $error) { if ($recipientId !== null) $this->connection->rollback(); throw $error; }
    }

    private function lockEnquiry(int $id): array
    {
        $statement = $this->connection->prepare('SELECT enquiry_id,customer_id,email,enquiry_state,updated_at FROM customer_enquiries WHERE enquiry_id=? FOR UPDATE');
        $statement->execute([$id]); $row = $statement->get_result()->fetch_assoc(); $statement->close();
        if (!is_array($row)) throw new \DomainException('Conversation not found.');
        if ($row['customer_id'] !== null) $row['customer_id'] = (int) $row['customer_id'];
        return $row;
    }

    private function notifyCustomer(int $customerId, int $enquiryId, int $messageId, string $email): void
    {
        $this->notification($customerId, $enquiryId, $messageId, 'admin_reply');
        $this->outbox($messageId, $email, 'support_customer_reply', ['enquiry_id' => $enquiryId, 'message_id' => $messageId], $customerId, 'customer');
    }

    private function notifyAdmins(int $enquiryId, int $messageId): void
    {
        $admins = $this->connection->query("SELECT customer_id,email FROM customers WHERE role='admin' AND account_state='active'")->fetch_all(MYSQLI_ASSOC);
        foreach ($admins as $admin) {
            $id = (int) $admin['customer_id'];
            $this->notification($id, $enquiryId, $messageId, 'new_customer_message');
            $this->outbox($messageId, (string) $admin['email'], 'support_admin_new_message', ['enquiry_id' => $enquiryId, 'message_id' => $messageId], $id, 'admin');
        }
    }

    private function notification(int $recipient, int $enquiryId, int $messageId, string $kind): void
    {
        $statement = $this->connection->prepare('INSERT INTO support_notifications(recipient_customer_id,enquiry_id,message_id,event_kind) VALUES(?,?,?,?)');
        $statement->execute([$recipient, $enquiryId, $messageId, $kind]); $statement->close();
    }

    private function outbox(int $messageId, string $email, string $template, array $context, ?int $recipientId = null, ?string $recipientRole = null): void
    {
        $delivery = OutboxQueuePolicy::initialDelivery($email);
        $statement = $this->connection->prepare('INSERT INTO email_outbox(message_id,recipient_email,recipient_customer_id,recipient_role,template_key,context_json,delivery_state,transport) VALUES(?,?,?,?,?,?,?,?)');
        $statement->execute([$messageId, $email, $recipientId, $recipientRole, $template,
            json_encode($context, JSON_THROW_ON_ERROR), $delivery['delivery_state'], $delivery['transport']]); $statement->close();
    }

    /** Queue a sealed one-use link during the caller's message transaction. */
    public function queueGuestAccess(int $enquiryId, int $messageId, string $email, string $template = 'support_guest_access'): void
    {
        $this->issueGuestAccess($enquiryId, $messageId, $email, $template);
    }

    private function issueGuestAccess(int $enquiryId, int $messageId, string $email, string $template = 'support_guest_access'): void
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $statement = $this->connection->prepare('INSERT INTO support_guest_access(enquiry_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE))');
        $statement->execute([$enquiryId, $hash]); $statement->close();
        if (str_starts_with($template, 'support_guest_access_resend')) {
            $template .= '_' . substr(hash('sha256', $token), 0, 16);
        }
        $context = ['enquiry_id' => $enquiryId, 'message_id' => $messageId, 'access_token_sealed' => SupportTokenCipher::seal($token)];
        $this->outbox($messageId, $email, $template, $context);
    }

    private function activeCustomer(array $actor, bool $lock = false): array
    {
        $id = $actor['customer_id'] ?? null;
        if (!is_int($id) || $id < 1) throw new \DomainException('Account is unavailable.');
        $statement = $this->connection->prepare("SELECT customer_id,role FROM customers WHERE customer_id=? AND account_state='active'" . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$id]); $row = $statement->get_result()->fetch_assoc(); $statement->close();
        if (!is_array($row)) throw new \DomainException('Account is unavailable.');
        $row['customer_id'] = (int) $row['customer_id'];
        return $row;
    }

    private function unreadCount(int $recipientId, ?string $eventKind = null): int
    {
        $sql = 'SELECT COUNT(*) FROM support_notifications WHERE recipient_customer_id=? AND read_at IS NULL';
        $params = [$recipientId];
        if ($eventKind !== null) { $sql .= ' AND event_kind=?'; $params[] = $eventKind; }
        $statement = $this->connection->prepare($sql);
        $statement->execute($params); $count = (int) $statement->get_result()->fetch_row()[0]; $statement->close();
        return $count;
    }

    private function assertReplyRateLimit(?array $author, int $enquiryId): void
    {
        SessionManager::start();
        $scope = $author === null ? 'guest:' . $enquiryId : 'customer:' . $author['customer_id'];
        $now = time();
        $all = is_array($_SESSION['_support_reply_times'] ?? null) ? $_SESSION['_support_reply_times'] : [];
        $times = array_values(array_filter($all[$scope] ?? [], static fn (mixed $at): bool => is_int($at) && $at > $now - self::REPLY_RATE_WINDOW_SECONDS));
        if (count($times) >= self::REPLY_RATE_LIMIT) throw new ValidationException(['body' => 'Please wait before sending another reply.']);
        if ($author === null) {
            $statement = $this->connection->prepare("SELECT COUNT(*) FROM enquiry_messages WHERE enquiry_id=? AND author_kind='guest' AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)");
            $statement->execute([$enquiryId]);
        } else {
            $statement = $this->connection->prepare('SELECT COUNT(*) FROM enquiry_messages WHERE author_customer_id=? AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)');
            $statement->execute([$author['customer_id']]);
        }
        $recent = (int) $statement->get_result()->fetch_row()[0]; $statement->close();
        if ($recent >= self::REPLY_RATE_LIMIT) throw new ValidationException(['body' => 'Please wait before sending another reply.']);
    }

    private function recordReplyTime(?array $author, int $enquiryId): void
    {
        SessionManager::start();
        $scope = $author === null ? 'guest:' . $enquiryId : 'customer:' . $author['customer_id'];
        $now = time();
        $all = is_array($_SESSION['_support_reply_times'] ?? null) ? $_SESSION['_support_reply_times'] : [];
        $times = array_values(array_filter($all[$scope] ?? [], static fn (mixed $at): bool => is_int($at) && $at > $now - self::REPLY_RATE_WINDOW_SECONDS));
        $times[] = $now;
        $all[$scope] = array_slice($times, -self::REPLY_RATE_LIMIT);
        $_SESSION['_support_reply_times'] = $all;
    }

    private static function boundedPage(mixed $value): int
    {
        if (is_string($value) && preg_match('/^[0-9]{1,6}$/D', $value)) $value = (int) $value;
        return is_int($value) ? max(1, min(100000, $value)) : 1;
    }

    private static function searchText(mixed $value): string
    {
        if (!is_string($value)) return '';
        return mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? ''), 0, 100);
    }
}
