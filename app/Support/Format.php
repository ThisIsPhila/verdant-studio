<?php

declare(strict_types=1);

namespace Verdant\Support;

final class Format
{
    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function money(int|string|null $minor): string
    {
        $digits = (string) $minor;
        if (!ctype_digit($digits)) {
            throw new \InvalidArgumentException('Money must be nonnegative integer cents.');
        }
        $digits = str_pad(ltrim($digits, '0') ?: '0', 3, '0', STR_PAD_LEFT);
        return 'R' . (preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', substr($digits, 0, -2))) . '.' . substr($digits, -2);
    }

    public static function status(string $state): string
    {
        return match ($state) {
            'pending_payment', 'pending' => 'Awaiting payment',
            'confirmed' => 'Confirmed', 'cancelled' => 'Cancelled',
            'reconciliation_required' => 'Verification needed',
            'succeeded' => 'Succeeded', 'failed' => 'Failed',
            default => self::label($state),
        };
    }

    public static function date(?string $date, bool $exclusiveEnd = false): string
    {
        if ($date === null) {
            return '—';
        }
        $value = new \DateTimeImmutable(substr($date, 0, 10), new \DateTimeZone('Africa/Johannesburg'));
        return ($exclusiveEnd ? $value->modify('-1 day') : $value)->format('j M Y');
    }

    public static function imagePath(?string $path): string
    {
        if ($path !== null && preg_match('#^/assets/images/[A-Za-z0-9_./-]+\.(svg|png|jpe?g|webp)$#i', $path)
            && !str_contains($path, '..')
            && is_file(dirname(__DIR__, 2) . '/public' . $path)) {
            return $path;
        }
        return '/assets/images/botanical.svg';
    }

    public static function label(string $value): string
    {
        return ucfirst(str_replace('_', ' ', $value));
    }
}
