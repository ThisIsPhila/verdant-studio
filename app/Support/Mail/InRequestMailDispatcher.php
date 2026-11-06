<?php

declare(strict_types=1);

namespace Verdant\Support\Mail;

use mysqli;

/**
 * Sends queued mail at the end of the web request that queued it.
 *
 * The outbox row remains the audit record, but the website no longer
 * depends on a CLI worker to deliver: on hosts without cron or shell
 * access (and locally) the Resend transport is invoked in-request,
 * through the same dispatcher, leases and recipient checks as before.
 * Only the Resend transport is sent this way; local capture keeps its
 * existing file-based test flow.
 */
final class InRequestMailDispatcher
{
    public function __construct(
        private readonly mysqli $connection,
        private readonly EmailConfig $config,
        private readonly EmailTransport $transport,
        private readonly ?\Closure $renderer = null,
    ) {
    }

    /** @return array{captured:int,failed:int,held:int,uncertain:int,skipped:int} */
    public function dispatchQueued(): array
    {
        $empty = ['captured' => 0, 'failed' => 0, 'held' => 0, 'uncertain' => 0, 'skipped' => 0];
        if ($this->config->transport !== 'resend') {
            return $empty;
        }
        $this->config->assertDispatchReady();
        $statement = $this->connection->prepare(
            "SELECT outbox_id FROM email_outbox WHERE delivery_state = 'queued' AND transport = ?
                AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP())
             ORDER BY outbox_id LIMIT 20"
        );
        $statement->execute([$this->config->transport]);
        $ids = array_map(
            static fn (array $row): int => (int) $row['outbox_id'],
            $statement->get_result()->fetch_all(MYSQLI_ASSOC),
        );
        $statement->close();
        if ($ids === []) {
            return $empty;
        }
        return (new EmailDispatcher($this->connection, $this->config, $this->transport, $this->renderer))->dispatchSelected($ids);
    }

    /**
     * Best-effort end-of-request delivery. Mail problems must never break
     * the page that queued the mail, so every failure is swallowed here;
     * the outbox row keeps the failure state for the admin activity view.
     */
    public static function afterRequest(mysqli $connection): void
    {
        try {
            if (getenv('EMAIL_AUTO_QUEUE') !== '1') {
                return;
            }
            $config = EmailConfig::fromEnvironment();
            if ($config->transport !== 'resend') {
                return;
            }
            $transport = new ResendTransport($config->resendApiKey, $config);
            (new self($connection, $config, $transport))->dispatchQueued();
        } catch (\Throwable) {
            // Intentionally ignored: delivery state is recorded in email_outbox.
        }
    }
}
