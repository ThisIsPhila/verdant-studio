<?php

declare(strict_types=1);

namespace Verdant\Http;

/**
 * URL helpers shared by controllers and views.
 *
 * local() marks a link as an application-owned path for readability at call
 * sites and returns it unchanged. redirectTarget() additionally refuses any
 * destination that is not a local path, so redirects can never leave the
 * application through a user-controlled value.
 */
final class Url
{
    public static function local(string $url): string
    {
        return $url;
    }

    public static function redirectTarget(string $destination): string
    {
        if (!str_starts_with($destination, '/') || str_starts_with($destination, '//')
            || preg_match('/[\\x00-\\x20\\\\]/', $destination)) {
            throw new \InvalidArgumentException('Expected a local redirect path.');
        }
        return $destination;
    }
}
