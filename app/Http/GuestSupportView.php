<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Support\Format;

/** Minimal guest-link surface with no shared shell, trackers, fonts or third-party requests. */
final class GuestSupportView
{
    public static function render(string $template, array $data = [], int $status = 200): void
    {
        http_response_code($status);
        $e = Format::escape(...);
        extract($data, EXTR_SKIP);
        ob_start();
        require dirname(__DIR__) . '/Views/' . $template . '.php';
        $content = ob_get_clean();
        ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= $e($title ?? 'Support') ?> · Verdant</title><link rel="stylesheet" href="/assets/css/support.css"></head><body class="support-guest"><main id="main" tabindex="-1"><?= $content ?></main></body></html><?php
    }
}
