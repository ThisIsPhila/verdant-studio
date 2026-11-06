<?php

declare(strict_types=1);

namespace Verdant\Notifications;

use Verdant\Support\PrivateTokenCipher;

/** Joins the verification transaction; queues mail when automatic delivery permits the recipient, otherwise holds it. */
final class VerificationMailer
{
    public function __construct(private readonly \mysqli $connection)
    {
    }

    public function queueVerification(
        int $emailVerificationId,
        int $customerId,
        string $recipientEmail,
        string $token,
    ): int {
        if ($emailVerificationId < 1 || $customerId < 1 || filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1
            || !$this->matchesVerificationSource($emailVerificationId, $customerId, $recipientEmail)) {
            throw new \InvalidArgumentException('Invalid email verification message.');
        }

        $context = json_encode([
            'surface' => 'store',
            'verification_token_sealed' => PrivateTokenCipher::seal($token),
        ], JSON_THROW_ON_ERROR);
        return $this->queue($emailVerificationId, $customerId, $recipientEmail, 'verify_email', $context);
    }

    private function matchesVerificationSource(
        int $emailVerificationId,
        int $customerId,
        string $recipientEmail,
    ): bool {
        $statement = $this->connection->prepare(
            "SELECT t.email_verification_id FROM email_verification_tokens t
             JOIN customers c ON c.customer_id = t.customer_id
             WHERE t.email_verification_id = ? AND t.customer_id = ?
               AND LOWER(c.email) = ? AND c.role = 'customer' AND c.account_state = 'unverified'
               AND t.requesting_surface = 'store'
               AND t.expires_at > UTC_TIMESTAMP() AND t.consumed_at IS NULL AND t.revoked_at IS NULL
             FOR UPDATE"
        );
        try {
            $statement->execute([
                $emailVerificationId,
                $customerId,
                strtolower(trim($recipientEmail)),
            ]);
            return $statement->get_result()->num_rows === 1;
        } finally {
            $statement->close();
        }
    }

    private function queue(
        int $emailVerificationId,
        int $customerId,
        string $recipientEmail,
        string $template,
        string $context,
    ): int {
        $address = strtolower(trim($recipientEmail));
        $delivery = \Verdant\Support\Mail\OutboxQueuePolicy::initialDelivery($address);
        $statement = $this->connection->prepare(
            "INSERT INTO email_outbox
                (email_verification_id, recipient_email, recipient_customer_id, recipient_role,
                 template_key, context_json, transport, delivery_state)
             VALUES (?, ?, ?, 'customer', ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE outbox_id = LAST_INSERT_ID(outbox_id)"
        );
        try {
            $statement->execute([$emailVerificationId, $address, $customerId, $template, $context,
                $delivery['transport'], $delivery['delivery_state']]);
            return (int) $this->connection->insert_id;
        } finally {
            $statement->close();
        }
    }
}
