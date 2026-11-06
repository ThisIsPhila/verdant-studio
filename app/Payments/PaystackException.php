<?php

declare(strict_types=1);

namespace Verdant\Payments;

final class PaystackException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $uncertain)
    {
        parent::__construct($message);
    }
}
