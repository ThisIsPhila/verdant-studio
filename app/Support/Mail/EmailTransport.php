<?php

declare(strict_types=1);

namespace Verdant\Support\Mail;

interface EmailTransport
{
    /** @param array{subject:string,html:string,text:string} $message */
    public function deliver(array $row, array $message): TransportResult;
}
