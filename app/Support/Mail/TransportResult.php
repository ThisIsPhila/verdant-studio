<?php

declare(strict_types=1);

namespace Verdant\Support\Mail;

final class TransportResult
{
    public function __construct(public readonly string $state, public readonly ?string $receipt = null)
    {
        if (!in_array($state, ['captured', 'provider_accepted'], true)) {
            throw new \InvalidArgumentException('Unsupported successful transport state.');
        }
    }
}
