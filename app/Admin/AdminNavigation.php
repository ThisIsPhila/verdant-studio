<?php

declare(strict_types=1);

namespace Verdant\Admin;

final class AdminNavigation
{
    public static function groups(): array
    {
        return [
            'Store catalogue' => [
                ['/admin/products', 'Products', 'leaf'],
                ['/admin/inventory', 'Inventory', 'bag'],
                ['/admin/categories', 'Categories', 'bag'],
                ['/admin/families', 'Product options', 'leaf'],
                ['/admin/compatibility', 'Item compatibility', 'leaf'],
                ['/admin/compositions', 'Arrangements', 'leaf'],
            ],
            'Storefront content' => [
                ['/admin/sections', 'Pages', 'menu'],
                ['/admin/media', 'Media', 'leaf'],
            ],
            'Sales and rentals' => [
                ['/admin/orders', 'Orders', 'bag'],
                ['/admin/rentals', 'Rentals', 'calendar'],
                ['/admin/returns', 'Returns', 'bag'],
                ['/admin/rental-calendar', 'Rental calendar', 'calendar'],
            ],
            'Customers and support' => [
                ['/admin/customers', 'Customers', 'account'],
                ['/admin/enquiries', 'Enquiries', 'menu'],
                ['/admin/email', 'Email activity', 'menu'],
            ],
            'Security' => [
                ['/admin/2fa/codes', 'Two-factor auth', 'menu'],
                ['/admin/access', 'Access allowlist', 'menu'],
            ],
        ];
    }

    public static function activePath(string $uri): string
    {
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/admin');
        if ($path === '/admin/rental') {
            parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
            return match (self::rentalOrigin($query)) {
                'calendar' => '/admin/rental-calendar',
                'returns' => '/admin/returns',
                default => '/admin/rentals',
            };
        }
        return [
            '/admin/product' => '/admin/products',
            '/admin/order' => '/admin/orders',
            '/admin/customer' => '/admin/customers',
        ][$path] ?? $path;
    }

    public static function rentalOrigin(array $input): string
    {
        if (($input['origin'] ?? null) === 'returns') {
            return 'returns';
        }
        $month = $input['return_month'] ?? null;
        $view = $input['return_view'] ?? null;
        if (($input['return_origin'] ?? null) === 'calendar'
            && is_string($month)
            && preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/D', $month) === 1
            && $month >= '2000-01' && $month <= '2100-12'
            && in_array($view, ['month', 'agenda'], true)) {
            return 'calendar';
        }
        return 'rentals';
    }
}
