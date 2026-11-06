<?php

declare(strict_types=1);

namespace Verdant\Support\Mail;

/** Decides whether a newly saved message may enter automatic delivery. */
final class OutboxQueuePolicy
{
    /** @return array{delivery_state:string, transport:string} */
    public static function initialDelivery(string $recipient): array
    {
        if (getenv('EMAIL_AUTO_QUEUE') !== '1') {
            return ['delivery_state' => 'held', 'transport' => 'local_capture'];
        }
        try {
            $config = EmailConfig::fromEnvironment();
            $config->assertDispatchReady();
        } catch (\Throwable) {
            return ['delivery_state' => 'held', 'transport' => 'local_capture'];
        }
        return [
            'delivery_state' => $config->permits($recipient) ? 'queued' : 'held',
            'transport' => $config->transport,
        ];
    }
}
