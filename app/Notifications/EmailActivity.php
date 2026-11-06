<?php

declare(strict_types=1);

namespace Verdant\Notifications;

use Verdant\Admin\AdminAccess;
use Verdant\Config\TrustedOrigins;
use Verdant\Support\Mail\EmailConfig;

/** Protected read/recovery boundary for the administrator email activity screen. */
final class EmailActivity
{
    public const PAGE_SIZE = 20;
    public const STATES = ['held', 'queued', 'captured', 'provider_accepted', 'failed', 'uncertain'];

    private readonly string $baseUrl;
    private readonly ?TrustedOrigins $trustedOrigins;

    public function __construct(
        private readonly \mysqli $connection,
        private readonly AdminAccess $access,
        ?string $baseUrl = null,
    ) {
        $configured = $baseUrl ?? (getenv('EMAIL_BASE_URL') === false ? '' : (string) getenv('EMAIL_BASE_URL'));
        // The local fallback is used only to display previews; dispatch validates its own config.
        $this->baseUrl = trim($configured) === '' ? 'http://127.0.0.1:8088' : rtrim(trim($configured), '/');
        $this->trustedOrigins = $baseUrl === null && (getenv('APP_ENV') ?: '') === 'local' ? TrustedOrigins::local() : null;
    }

    /** Plain-language delivery status for the signed-in administrator. */
    public function deliveryNotice(): string
    {
        if ((getenv('APP_ENV') ?: 'local') === 'local') {
            return 'Local test mode: messages on this page are private previews and are not emailed to customers.';
        }
        if (getenv('EMAIL_AUTO_QUEUE') !== '1') {
            return 'Email delivery is off: new messages stay held until automatic delivery is enabled.';
        }
        $config = $this->deliveryConfig();
        if ($config === null) {
            return 'Email sending is not configured: new messages stay held until the sender settings are complete.';
        }
        if ($config->transport === 'local_capture') {
            return 'Local capture is on: messages are saved as private previews and are not emailed.';
        }
        if ($config->openDelivery) {
            return 'Email delivery is on: new messages send automatically to customers and administrators through Resend. '
                . 'Accepted by provider means Resend received the message; inbox delivery is not tracked.';
        }
        return 'Email delivery is restricted to ' . implode(' and ', EmailConfig::TEST_RECIPIENTS)
            . ': messages to anyone else stay held.';
    }

    private function deliveryConfig(): ?EmailConfig
    {
        try {
            $config = EmailConfig::fromEnvironment();
            $config->assertDispatchReady();
            return $config;
        } catch (\Throwable) {
            return null;
        }
    }

    private function deliveryActionPermitted(string $email, string $rowTransport): bool
    {
        $config = $this->deliveryConfig();
        if ($config !== null && $config->permits($email)) {
            return true;
        }
        return $rowTransport === 'local_capture'
            && in_array(strtolower(trim($email)), EmailConfig::TEST_RECIPIENTS, true);
    }

    /** @return array{rows:list<array<string,mixed>>,page:int,total:int,has_next:bool,status:?string} */
    public function listRows(array $actor, int $page = 1, ?string $status = null): array
    {
        $this->access->require($actor);
        $page = max(1, min(1000000, $page));
        $status = $status !== null && in_array($status, self::STATES, true) ? $status : null;
        $where = $status === null ? '' : ' WHERE o.delivery_state = ?';
        $parameters = $status === null ? [] : [$status];
        $count = $this->connection->prepare('SELECT COUNT(*) AS total FROM email_outbox o' . $where);
        try {
            $count->execute($parameters);
            $total = (int) ($count->get_result()->fetch_assoc()['total'] ?? 0);
        } finally {
            $count->close();
        }
        $offset = ($page - 1) * self::PAGE_SIZE;
        $sql = 'SELECT o.outbox_id,o.commerce_event_id,o.recipient_email,o.template_key,o.delivery_state,
                       o.attempt_count,o.failure_code,o.created_at,o.updated_at,e.event_kind,
                       CASE WHEN e.order_id IS NOT NULL THEN \'order\'
                            WHEN e.rental_id IS NOT NULL THEN \'rental\'
                            WHEN e.composition_checkout_id IS NOT NULL THEN \'composition\' ELSE NULL END AS record_type,
                       COALESCE(e.order_id,e.rental_id,e.composition_checkout_id) AS record_id
                FROM email_outbox o LEFT JOIN commerce_notification_events e ON e.commerce_event_id=o.commerce_event_id'
            . $where . ' ORDER BY o.updated_at DESC,o.outbox_id DESC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . $offset;
        $statement = $this->connection->prepare($sql);
        try {
            $statement->execute($parameters);
            $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        } finally {
            $statement->close();
        }
        foreach ($rows as &$row) {
            $row['outbox_id'] = (int) $row['outbox_id'];
            $row['attempt_count'] = (int) $row['attempt_count'];
            $row['event_label'] = self::eventLabel((string) ($row['event_kind'] ?? $row['template_key']));
            $row['record_label'] = self::recoveryLabel((string) $row['template_key'])
                ?? self::recordLabel($row['record_type'] ?? null, $row['record_id'] ?? null);
            $row['state_label'] = self::stateLabel((string) $row['delivery_state']);
            $row['failure_code'] = self::safeCode($row['failure_code'] ?? null);
        }
        unset($row);

        return [
            'rows' => $rows,
            'page' => $page,
            'total' => $total,
            'has_next' => $offset + count($rows) < $total,
            'status' => $status,
        ];
    }

    /** @return array<string,mixed>|null */
    public function detail(array $actor, int $id): ?array
    {
        $this->access->require($actor);
        if ($id < 1) {
            return null;
        }
        $statement = $this->connection->prepare(
            'SELECT o.outbox_id,o.message_id,o.commerce_event_id,o.recipient_customer_id,o.recipient_role,
                    o.recipient_email,o.template_key,o.context_json,o.transport,o.delivery_state,o.attempt_count,
                    o.next_attempt_at,o.lease_token,o.failure_code,o.created_at,o.updated_at,e.event_kind,
                    CASE WHEN e.order_id IS NOT NULL THEN \'order\'
                         WHEN e.rental_id IS NOT NULL THEN \'rental\'
                         WHEN e.composition_checkout_id IS NOT NULL THEN \'composition\' ELSE NULL END AS record_type,
                    COALESCE(e.order_id,e.rental_id,e.composition_checkout_id) AS record_id
             FROM email_outbox o LEFT JOIN commerce_notification_events e ON e.commerce_event_id=o.commerce_event_id
             WHERE o.outbox_id=? LIMIT 1'
        );
        try {
            $statement->execute([$id]);
            $row = $statement->get_result()->fetch_assoc();
        } finally {
            $statement->close();
        }
        if (!is_array($row)) {
            return null;
        }
        $context = json_decode((string) $row['context_json'], true, 32);
        if (!is_array($context) || strlen((string) $row['context_json']) > 131072) {
            $context = [];
        }
        $row['context'] = $context;
        $row['outbox_id'] = (int) $row['outbox_id'];
        $row['attempt_count'] = (int) $row['attempt_count'];
        $row['event_label'] = self::eventLabel((string) ($row['event_kind'] ?? $row['template_key']));
        $row['record_label'] = self::recoveryLabel((string) $row['template_key'])
            ?? self::recordLabel($row['record_type'] ?? null, $row['record_id'] ?? null);
        $row['state_label'] = self::stateLabel((string) $row['delivery_state']);
        $row['failure_code'] = self::safeCode($row['failure_code'] ?? null);
        $row['can_retry'] = $this->retryEligible($row);
        $row['can_release'] = $this->releaseEligible($row);
        try {
            if ($row['template_key'] === 'password_reset') {
                unset($row['context']['reset_token_sealed']);
                unset($row['context_json']);
                $row['preview'] = [
                    'subject' => 'Password reset instructions',
                    'text' => 'The reset link is private and is not available in this preview.',
                    'html' => '',
                ];
            } elseif ($row['template_key'] === 'verify_email') {
                unset($row['context']['verification_token_sealed']);
                unset($row['context_json']);
                $row['preview'] = [
                    'subject' => 'Email verification instructions',
                    'text' => 'The verification link is private and is not available in this preview.',
                    'html' => '',
                ];
            } elseif ($row['template_key'] === 'support_guest_access'
                || str_starts_with($row['template_key'], 'support_guest_access_resend_')) {
                $row['preview'] = [
                    'subject' => 'Private guest conversation link',
                    'text' => 'Guest access links are private credentials. Their content is available only through the configured delivery transport.',
                    'html' => '',
                ];
            } else {
                $row['preview'] = EmailRenderer::render($row, $this->baseUrl, $this->trustedOrigins);
            }
            $row['preview_error'] = null;
        } catch (\Throwable) {
            $row['preview'] = null;
            $row['preview_error'] = 'This saved message cannot be rendered with its current template data.';
        }
        return $row;
    }

    /** Requeues one failed message only when the viewed version is current. */
    public function retry(array $actor, int $id, string $expectedUpdatedAt): bool
    {
        return $this->queueForDelivery($actor, $id, $expectedUpdatedAt, 'failed');
    }

    /** Queues one held message for delivery only when the viewed version is current. */
    public function release(array $actor, int $id, string $expectedUpdatedAt): bool
    {
        return $this->queueForDelivery($actor, $id, $expectedUpdatedAt, 'held');
    }

    private function queueForDelivery(array $actor, int $id, string $expectedUpdatedAt, string $fromState): bool
    {
        $this->access->require($actor, true);
        if ($id < 1 || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $expectedUpdatedAt) !== 1) {
            return false;
        }
        $statement = $this->connection->prepare(
            'SELECT recipient_email, transport, delivery_state, attempt_count, lease_token
             FROM email_outbox WHERE outbox_id = ? LIMIT 1'
        );
        try {
            $statement->execute([$id]);
            $row = $statement->get_result()->fetch_assoc();
        } finally {
            $statement->close();
        }
        if (!is_array($row)) {
            return false;
        }
        $eligible = $fromState === 'held' ? $this->releaseEligible($row) : $this->retryEligible($row);
        if (!$eligible) {
            return false;
        }
        $config = $this->deliveryConfig();
        $transport = $config !== null && $config->permits((string) $row['recipient_email'])
            ? $config->transport
            : (string) $row['transport'];
        $statement = $this->connection->prepare(
            "UPDATE email_outbox
             SET delivery_state='queued',transport=?,failure_code=NULL,
                 next_attempt_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
             WHERE outbox_id=? AND updated_at=? AND delivery_state=? AND attempt_count<10 AND lease_token IS NULL"
        );
        try {
            $statement->execute([$transport, $id, $expectedUpdatedAt, $fromState]);
            return $statement->affected_rows === 1;
        } finally {
            $statement->close();
        }
    }

    public static function stateLabel(string $state): string
    {
        return match ($state) {
            'held' => 'Saved · held',
            'queued' => 'Queued for processing',
            'captured' => 'Captured locally',
            'provider_accepted' => 'Accepted by provider',
            'failed' => 'Failed',
            'uncertain' => 'Uncertain · review required',
            default => 'Unknown state',
        };
    }

    private function retryEligible(array $row): bool
    {
        return ($row['delivery_state'] ?? null) === 'failed'
            && (int) ($row['attempt_count'] ?? 10) < 10
            && empty($row['lease_token'])
            && $this->deliveryActionPermitted(
                (string) ($row['recipient_email'] ?? ''),
                (string) ($row['transport'] ?? ''),
            );
    }

    private function releaseEligible(array $row): bool
    {
        return ($row['delivery_state'] ?? null) === 'held'
            && (int) ($row['attempt_count'] ?? 10) < 10
            && empty($row['lease_token'])
            && $this->deliveryActionPermitted(
                (string) ($row['recipient_email'] ?? ''),
                (string) ($row['transport'] ?? ''),
            );
    }

    private static function eventLabel(string $kind): string
    {
        return match ($kind) {
            'purchase_confirmed' => 'Purchase confirmed',
            'rental_confirmed' => 'Rental confirmed',
            'composition_confirmed' => 'Arrangement confirmed',
            'rental_issued' => 'Rental issued',
            'rental_returned' => 'Rental return recorded',
            'rental_cancelled' => 'Rental cancelled',
            'order_fulfilled' => 'Order fulfilled',
            'order_cancelled' => 'Order cancelled',
            'password_reset' => 'Password reset requested',
            'password_changed' => 'Password changed',
            'verify_email' => 'Email verification requested',
            'support_admin_new_message' => 'New customer enquiry',
            default => 'Email notice',
        };
    }

    private static function recoveryLabel(string $template): ?string
    {
        return match ($template) {
            'password_reset' => 'Password recovery',
            'password_changed' => 'Password change',
            'verify_email' => 'Email verification',
            default => null,
        };
    }

    private static function recordLabel(mixed $type, mixed $id): string
    {
        if (!in_array($type, ['order', 'rental', 'composition'], true) || (int) $id < 1) {
            return 'Support message';
        }
        $labels = ['order' => 'Order', 'rental' => 'Rental', 'composition' => 'Arrangement'];
        return $labels[$type] . ' #' . (int) $id;
    }

    private static function safeCode(mixed $code): ?string
    {
        return is_string($code) && preg_match('/^[a-z0-9_]{1,80}$/D', $code) === 1 ? $code : null;
    }
}
