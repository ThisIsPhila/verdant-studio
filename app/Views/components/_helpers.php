<?php
/** Small presentation-only guards shared by PHP partials. */
if (!function_exists('verdant_view_local_path')) {
    function verdant_view_local_path(mixed $value, string $fallback = '/'): string
    {
        if (!is_string($value) || $value === '' || str_starts_with($value, '//') || str_contains($value, '\\') || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            return $fallback;
        }
        $path = parse_url($value, PHP_URL_PATH);
        if (!is_string($path) || !str_starts_with($path, '/') || preg_match('~(?:^|/)\.\.(?:/|$)~', $path) === 1) {
            return $fallback;
        }
        return $value;
    }
}

if (!function_exists('verdant_view_asset_path')) {
    function verdant_view_asset_path(mixed $value): ?string
    {
        $path = verdant_view_local_path($value, '');
        if ($path === '' || !str_starts_with($path, '/assets/')) {
            return null;
        }
        return $path;
    }
}

/** Decorative interface icons; the caller supplies the accessible label. */
function verdant_view_icon(string $name): string
{
    $path = match ($name) {
        'search' => '<circle cx="10" cy="10" r="6"/><path d="m15 15 6 6"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 2v6m10-6v6M3 11h18"/>',
        'heart' => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/>',
        'account' => '<circle cx="12" cy="7" r="4"/><path d="M4 22v-3a8 8 0 0 1 16 0v3Z"/>',
        'bag' => '<path d="M5 7h14l2 15H3L5 7Z"/><path d="M8 8V5a4 4 0 0 1 8 0v3"/>',
        'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'truck' => '<path d="M2 5h12v12H2ZM14 10h5l3 4v3h-8"/><circle cx="6" cy="19" r="2"/><circle cx="18" cy="19" r="2"/>',
        'consult' => '<path d="M21 11a9 8 0 0 1-9 8H8l-5 3 1-6a8 8 0 1 1 17-5Z"/>',
        'leaf' => '<path d="M20 3C8 2 1 10 7 18c8 6 16-1 13-15ZM4 22 17 7M9 17v-6m4 3 6-1"/>',
        default => '<path d="M5 9h14l-2 13H7L5 9ZM12 9V2m0 5C6 7 6 3 6 3c5 0 6 4 6 4m0-1c5 0 6-4 6-4-5 0-6 4-6 4"/>',
    };
    return '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">' . $path . '</svg>';
}
