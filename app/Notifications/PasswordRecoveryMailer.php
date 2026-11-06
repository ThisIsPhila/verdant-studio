<?php

declare(strict_types=1);

namespace Verdant\Notifications;

use Verdant\Support\PrivateTokenCipher;

/** Joins the recovery transaction; queues mail when automatic delivery permits the recipient, otherwise holds it. */
final class PasswordRecoveryMailer
{
    public function __construct(private readonly \mysqli $connection)
    {
    }

    public function queueReset(
        int $passwordResetId,
        int $customerId,
        string $recipientEmail,
        string $recipientRole,
        string $surface,
        string $token,
    ): int {
        if ($passwordResetId < 1 || $customerId < 1 || filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false
            || !in_array($recipientRole, ['customer', 'admin'], true)
            || !in_array($surface, ['store', 'admin'], true)
            || preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1
            || !$this->matchesResetSource($passwordResetId, $customerId, $recipientEmail, $recipientRole, 'password_reset', $surface)) {
            throw new \InvalidArgumentException('Invalid password recovery message.');
        }

        $context = json_encode([
            'surface' => $surface,
            'reset_token_sealed' => PrivateTokenCipher::seal($token),
        ], JSON_THROW_ON_ERROR);
        return $this->queue($passwordResetId, $customerId, $recipientEmail, $recipientRole, 'password_reset', $context);
    }

    public function queuePasswordChanged(
        int $passwordResetId,
        int $customerId,
        string $recipientEmail,
        string $recipientRole,
    ): int {
        if ($passwordResetId < 1 || $customerId < 1 || filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false
            || !in_array($recipientRole, ['customer', 'admin'], true)
            || !$this->matchesResetSource($passwordResetId, $customerId, $recipientEmail, $recipientRole, 'password_changed', null)) {
            throw new \InvalidArgumentException('Invalid password change message.');
        }

        return $this->queue(
            $passwordResetId,
            $customerId,
            $recipientEmail,
            $recipientRole,
            'password_changed',
            '{}',
        );
    }

    private function matchesResetSource(
        int $passwordResetId,
        int $customerId,
        string $recipientEmail,
        string $recipientRole,
        string $template,
        ?string $surface,
    ): bool {
        $statement = $this->connection->prepare(
            "SELECT r.password_reset_id FROM password_reset_tokens r
             JOIN customers c ON c.customer_id = r.customer_id
             WHERE r.password_reset_id = ? AND r.customer_id = ?
               AND LOWER(c.email) = ? AND c.role = ? AND c.account_state = 'active'
               AND ((? = 'password_reset' AND r.requesting_surface = ?
                     AND r.expires_at > UTC_TIMESTAMP() AND r.consumed_at IS NULL AND r.revoked_at IS NULL)
                 OR (? = 'password_changed' AND r.consumed_at IS NOT NULL AND r.revoked_at IS NULL))
             FOR UPDATE"
        );
        try {
            $statement->execute([
                $passwordResetId,
                $customerId,
                strtolower(trim($recipientEmail)),
                $recipientRole,
                $template,
                $surface,
                $template,
            ]);
            return $statement->get_result()->num_rows === 1;
        } finally {
            $statement->close();
        }
    }

    private function queue(
        int $passwordResetId,
        int $customerId,
        string $recipientEmail,
        string $recipientRole,
        string $template,
        string $context,
    ): int {
        $address = strtolower(trim($recipientEmail));
        $delivery = \Verdant\Support\Mail\OutboxQueuePolicy::initialDelivery($address);
        $statement = $this->connection->prepare(
            "INSERT INTO email_outbox
                (password_reset_id, recipient_email, recipient_customer_id, recipient_role,
                 template_key, context_json, transport, delivery_state)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE outbox_id = LAST_INSERT_ID(outbox_id)"
        );
        try {
            $statement->execute([$passwordResetId, $address, $customerId, $recipientRole, $template, $context,
                $delivery['transport'], $delivery['delivery_state']]);
            return (int) $this->connection->insert_id;
        } finally {
            $statement->close();
        }
    }
}
