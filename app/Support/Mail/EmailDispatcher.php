<?php

declare(strict_types=1);

namespace Verdant\Support\Mail;

use mysqli;
use Verdant\Config\TrustedOrigins;
use Verdant\Notifications\EmailRenderer;

final class EmailDispatcher
{
    private const BATCH_LIMIT = 20;
    private const LEASE_SECONDS = 120;

    public function __construct(
        private readonly mysqli $connection,
        private readonly EmailConfig $config,
        private readonly EmailTransport $transport,
        private readonly ?\Closure $renderer = null,
    ) {
    }

    /** @param list<int> $outboxIds */
    public function releaseSelected(array $outboxIds): int
    {
        $ids = self::checkedIds($outboxIds);
        if ($ids === []) {
            return 0;
        }
        $this->config->assertDispatchReady();
        $released = 0;
        foreach (array_chunk($ids, self::BATCH_LIMIT) as $batch) {
            [$in, $values] = self::placeholders($batch);
            $statement = $this->connection->prepare(
                "SELECT outbox_id, recipient_email FROM email_outbox
                 WHERE outbox_id IN ({$in}) AND delivery_state = 'held'"
            );
            $statement->execute($values);
            $eligible = [];
            foreach ($statement->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                if ($this->config->permits((string) $row['recipient_email'])) {
                    $eligible[] = (int) $row['outbox_id'];
                }
            }
            $statement->close();
            if ($eligible === []) {
                continue;
            }
            [$eligibleIn, $eligibleValues] = self::placeholders($eligible);
            $statement = $this->connection->prepare(
                "UPDATE email_outbox SET delivery_state = 'queued', transport = ?, failure_code = NULL,
                    next_attempt_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
                 WHERE outbox_id IN ({$eligibleIn}) AND delivery_state = 'held'"
            );
            $statement->execute(array_merge([$this->config->transport], $eligibleValues));
            $released += $statement->affected_rows;
            $statement->close();
        }
        return $released;
    }

    /** @param list<int> $outboxIds @return array{captured:int,failed:int,held:int,uncertain:int,skipped:int} */
    public function dispatchSelected(array $outboxIds): array
    {
        $ids = self::checkedIds($outboxIds);
        if ($ids === []) {
            return ['captured' => 0, 'failed' => 0, 'held' => 0, 'uncertain' => 0, 'skipped' => 0];
        }
        $this->config->assertDispatchReady();
        $results = ['captured' => 0, 'failed' => 0, 'held' => 0, 'uncertain' => 0, 'skipped' => 0];
        foreach (array_chunk($ids, self::BATCH_LIMIT) as $batch) {
            $this->recoverExpiredSelectedLeases($batch);
            foreach ($this->claimSelected($batch) as $row) {
                $result = $this->deliverOne($row);
                ++$results[$result];
            }
        }
        return $results;
    }

    /** @param list<int> $ids @return list<array<string,mixed>> */
    private function claimSelected(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        [$in, $values] = self::placeholders($ids);
        $this->connection->begin_transaction();
        try {
            $statement = $this->connection->prepare(
                "SELECT * FROM email_outbox WHERE outbox_id IN ({$in}) AND delivery_state = 'queued'
                    AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP())
                    AND (leased_until IS NULL OR leased_until <= UTC_TIMESTAMP())
                    AND attempt_count < 10 ORDER BY outbox_id FOR UPDATE"
            );
            $statement->execute($values);
            $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
            $statement->close();
            $claimed = [];
            foreach ($rows as $row) {
                $token = bin2hex(random_bytes(32));
                $lease = $this->connection->prepare(
                    'UPDATE email_outbox SET lease_token = ?, leased_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL '
                    . self::LEASE_SECONDS . ' SECOND), attempt_count = attempt_count + 1, updated_at = UTC_TIMESTAMP()
                     WHERE outbox_id = ? AND delivery_state = \'queued\' AND attempt_count < 10'
                );
                $lease->execute([$token, (int) $row['outbox_id']]);
                $owned = $lease->affected_rows === 1;
                $lease->close();
                if ($owned) {
                    $row['lease_token'] = $token;
                    $row['attempt_count'] = (int) $row['attempt_count'] + 1;
                    $claimed[] = $row;
                }
            }
            $this->connection->commit();
            return $claimed;
        } catch (\Throwable $error) {
            $this->connection->rollback();
            throw $error;
        }
    }

    /** @param list<int> $ids */
    private function recoverExpiredSelectedLeases(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        [$in, $values] = self::placeholders($ids);
        $this->connection->begin_transaction();
        try {
            $statement = $this->connection->prepare(
                "SELECT outbox_id, transport FROM email_outbox WHERE outbox_id IN ({$in})
                    AND delivery_state = 'queued' AND lease_token IS NOT NULL AND leased_until <= UTC_TIMESTAMP()
                    FOR UPDATE"
            );
            $statement->execute($values);
            $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
            $statement->close();
            foreach ($rows as $row) {
                $id = (int) $row['outbox_id'];
                if ($row['transport'] !== 'local_capture') {
                    $this->finishWithoutLease($id, 'uncertain', 'expired_external_lease');
                    continue;
                }
                if ($this->transport instanceof LocalCaptureTransport && $this->transport->hasCapture($id)) {
                    $this->finishWithoutLease($id, 'captured', null, 'local:' . $id);
                } else {
                    $this->finishWithoutLease($id, 'queued', 'expired_local_lease');
                }
            }
            $this->connection->commit();
        } catch (\Throwable $error) {
            $this->connection->rollback();
            throw $error;
        }
    }

    private function deliverOne(array $row): string
    {
        $id = (int) $row['outbox_id'];
        $token = (string) $row['lease_token'];
        $reason = $this->recipientProblem($row);
        if ($reason !== null) {
            $this->finish($id, $token, 'held', $reason);
            return 'held';
        }
        try {
            $context = json_decode((string) $row['context_json'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($context) || strlen((string) $row['context_json']) > 131072) {
                throw new \RuntimeException('invalid_context');
            }
            $row['context'] = $context;
            if (($row['template_key'] ?? '') === 'password_reset' && !$this->recoveryLinkIsActive($row, $context)) {
                $this->finish($id, $token, 'held', 'password_reset_inactive');
                return 'held';
            }
            if (($row['template_key'] ?? '') === 'verify_email' && !$this->verificationLinkIsActive($row, $context)) {
                $this->finish($id, $token, 'held', 'verification_inactive');
                return 'held';
            }
            $message = $this->renderer === null
                ? EmailRenderer::render($row, $this->config->baseUrl,
                    (getenv('APP_ENV') ?: '') === 'local' ? TrustedOrigins::local() : TrustedOrigins::hosted())
                : ($this->renderer)($row, $this->config->baseUrl);
            if (!is_array($message) || !is_string($message['subject'] ?? null)
                || !is_string($message['html'] ?? null) || !is_string($message['text'] ?? null)
                || strlen($message['subject']) > 255 || strlen($message['html']) > 262144
                || strlen($message['text']) > 131072) {
                throw new \RuntimeException('invalid_render');
            }
            $transportResult = $this->transport->deliver($row, $message);
            if (!$this->finish($id, $token, $transportResult->state, null, $transportResult->receipt)) {
                return 'uncertain';
            }
            return $transportResult->state === 'captured' ? 'captured' : 'skipped';
        } catch (\Throwable $error) {
            $attempt = (int) $row['attempt_count'];
            $safeLocalRetry = $row['transport'] === 'local_capture';
            if ($safeLocalRetry && $attempt < 10) {
                $delay = min(3600, 30 * (2 ** min(6, max(0, $attempt - 1))));
                $state = 'queued';
                $failure = self::safeFailure($error);
                $next = gmdate('Y-m-d H:i:s', time() + $delay);
                if (!$this->finish($id, $token, $state, $failure, null, $next)) {
                    return 'uncertain';
                }
                return 'failed';
            }
            $state = ($safeLocalRetry || ($error instanceof MailTransportException && !$error->uncertain)) ? 'failed' : 'uncertain';
            if (!$this->finish($id, $token, $state, self::safeFailure($error))) {
                return 'uncertain';
            }
            return $state;
        }
    }

    private function recipientProblem(array $row): ?string
    {
        $email = mb_strtolower(trim((string) ($row['recipient_email'] ?? '')));
        if (!$this->config->permits($email)) {
            return 'recipient_not_allowlisted';
        }
        $customerId = (int) ($row['recipient_customer_id'] ?? 0);
        $role = (string) ($row['recipient_role'] ?? '');
        if ($customerId > 0 && in_array($role, ['customer', 'admin'], true)) {
            // Verification mail is the only template addressed to an account that
            // must not be active yet; every other template keeps the active rule.
            $expectedState = ($row['template_key'] ?? '') === 'verify_email' ? 'unverified' : 'active';
            return $this->identityMatchesInState($customerId, $role, $email, $expectedState) ? null : 'recipient_identity_changed';
        }
        if ((int) ($row['message_id'] ?? 0) > 0
            && (($row['template_key'] ?? '') === 'support_guest_access' || preg_match('/^support_guest_access_resend_[a-f0-9]{16}$/D', (string)($row['template_key'] ?? '')) === 1)) {
            $statement = $this->connection->prepare(
                'SELECT e.enquiry_id FROM enquiry_messages m JOIN customer_enquiries e ON e.enquiry_id=m.enquiry_id
                 WHERE m.message_id=? AND e.customer_id IS NULL AND LOWER(e.email)=?'
            );
            $statement->execute([(int)$row['message_id'], $email]);
            $matches = $statement->get_result()->num_rows === 1;
            $statement->close();
            return $matches ? null : 'guest_recipient_changed';
        }
        // Phase017 support rows predate recipient identity snapshots. Resolve only an admin
        // recipient by its exact stored address; never infer a customer from email alone.
        if ((int) ($row['message_id'] ?? 0) > 0
            && str_starts_with((string) ($row['template_key'] ?? ''), 'support_admin_')) {
            return $this->activeAdminAddressMatches($email) ? null : 'missing_recipient_identity';
        }
        return 'missing_recipient_identity';
    }

    private function identityMatchesInState(int $customerId, string $role, string $email, string $expectedState): bool
    {
        if (!in_array($expectedState, ['active', 'unverified'], true)) {
            return false;
        }
        $statement = $this->connection->prepare(
            "SELECT customer_id FROM customers WHERE customer_id = ? AND role = ? AND account_state = ? AND LOWER(email) = ?"
        );
        $statement->execute([$customerId, $role, $expectedState, $email]);
        $matches = $statement->get_result()->num_rows === 1;
        $statement->close();
        return $matches;
    }

    private function activeAdminAddressMatches(string $email): bool
    {
        $statement = $this->connection->prepare(
            "SELECT customer_id FROM customers WHERE role = 'admin' AND account_state = 'active' AND LOWER(email) = ?"
        );
        $statement->execute([$email]);
        $matches = $statement->get_result()->num_rows === 1;
        $statement->close();
        return $matches;
    }

    private function recoveryLinkIsActive(array $row, array $context): bool
    {
        $resetId = (int) ($row['password_reset_id'] ?? 0);
        $surface = $context['surface'] ?? null;
        if ($resetId < 1 || !in_array($surface, ['store', 'admin'], true)) {
            return false;
        }
        $statement = $this->connection->prepare(
            'SELECT password_reset_id FROM password_reset_tokens
             WHERE password_reset_id = ? AND requesting_surface = ?
               AND expires_at > UTC_TIMESTAMP() AND consumed_at IS NULL AND revoked_at IS NULL'
        );
        try {
            $statement->execute([$resetId, $surface]);
            return $statement->get_result()->num_rows === 1;
        } finally {
            $statement->close();
        }
    }

    private function verificationLinkIsActive(array $row, array $context): bool
    {
        $verificationId = (int) ($row['email_verification_id'] ?? 0);
        $surface = $context['surface'] ?? null;
        if ($verificationId < 1 || $surface !== 'store') {
            return false;
        }
        $statement = $this->connection->prepare(
            'SELECT email_verification_id FROM email_verification_tokens
             WHERE email_verification_id = ? AND requesting_surface = ?
               AND expires_at > UTC_TIMESTAMP() AND consumed_at IS NULL AND revoked_at IS NULL'
        );
        try {
            $statement->execute([$verificationId, $surface]);
            return $statement->get_result()->num_rows === 1;
        } finally {
            $statement->close();
        }
    }

    private function finish(int $id, string $token, string $state, ?string $failure, ?string $receipt = null, ?string $nextAttempt = null): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE email_outbox SET delivery_state = ?, failure_code = ?, provider_receipt = ?, next_attempt_at = ?,
                lease_token = NULL, leased_until = NULL, updated_at = UTC_TIMESTAMP()
             WHERE outbox_id = ? AND lease_token = ?'
        );
        $statement->execute([$state, $failure, $receipt, $nextAttempt, $id, $token]);
        $updated = $statement->affected_rows === 1;
        $statement->close();
        return $updated;
    }

    private function finishWithoutLease(int $id, string $state, ?string $failure, ?string $receipt = null): void
    {
        $statement = $this->connection->prepare(
            'UPDATE email_outbox SET delivery_state = ?, failure_code = ?, provider_receipt = ?,
                lease_token = NULL, leased_until = NULL, next_attempt_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE outbox_id = ? AND lease_token IS NOT NULL'
        );
        $statement->execute([$state, $failure, $receipt, $id]);
        $statement->close();
    }

    private static function safeFailure(\Throwable $error): string
    {
        $message = $error->getMessage();
        if (preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $message) === 1) {
            return $message;
        }
        return 'transport_error';
    }

    /** @param list<int> $ids @return list<int> */
    private static function checkedIds(array $ids): array
    {
        if (count($ids) > 100) {
            throw new \InvalidArgumentException('Select at most 100 outbox identifiers.');
        }
        $checked = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) {
                throw new \InvalidArgumentException('Outbox identifiers must be positive integers.');
            }
            $checked[] = $id;
        }
        return array_values(array_unique($checked));
    }

    /** @param list<int> $ids @return array{string,list<int>} */
    private static function placeholders(array $ids): array
    {
        return [implode(',', array_fill(0, count($ids), '?')), $ids];
    }
}
