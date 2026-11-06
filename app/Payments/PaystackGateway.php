<?php

declare(strict_types=1);

namespace Verdant\Payments;

interface PaystackGateway
{
    /** @return array<string, mixed> */
    public function initialize(string $email, int $amountMinor, string $reference, string $callbackUrl): array;

    /** @return array<string, mixed> */
    public function verify(string $reference): array;
}
