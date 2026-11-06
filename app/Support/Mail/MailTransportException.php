<?php

declare(strict_types=1);

namespace Verdant\Support\Mail;

final class MailTransportException extends \RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        public readonly bool $uncertain,
    ) {
        parent::__construct($failureCode);
    }
}
