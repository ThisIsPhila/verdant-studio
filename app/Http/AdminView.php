<?php

declare(strict_types=1);

namespace Verdant\Http;

/** Internal admin pages always opt into the administration layout explicitly. */
final class AdminView
{
    public static function render(string $template, array $data = [], int $status = 200): void
    {
        View::render($template, $data, $status, 'internal');
    }
}
