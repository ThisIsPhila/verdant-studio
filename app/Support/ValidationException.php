<?php

declare(strict_types=1);

namespace Verdant\Support;

final class ValidationException extends \DomainException
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Please check the highlighted fields.');
    }
}
