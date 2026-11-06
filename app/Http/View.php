<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Support\Format;

final class View
{
    /** @var list<string> */
    private static array $publishedPolicies = [];
    private static array $shopLinks = [];
    private static int $supportUnread = 0;

    public static function setSupportUnread(int $count): void
    {
        self::$supportUnread = max(0, $count);
    }

    public static function setShopLinks(array $categories): void
    {
        self::$shopLinks = ['pots' => '/catalogue', 'tools' => '/catalogue'];
        foreach ($categories as $category) {
            $key = match ($category['name']) {
                'Planters & pots' => 'pots',
                'Tools & care' => 'tools',
                default => null,
            };
            if ($key !== null) {
                self::$shopLinks[$key] = '/catalogue?category_id=' . (int) $category['category_id'];
            }
        }
    }

    public static function setPolicyLinks(array $pageKeys): void
    {
        self::$publishedPolicies = array_values(array_intersect(['privacy', 'terms'], $pageKeys));
    }

    /**
     * Render a view inside a controller-selected layout. Layout selection is
     * deliberately an explicit argument; it is never inferred from the URL or
     * the current actor's role.
     */
    public static function render(string $template, array $data = [], int $status = 200, string $layout = 'customer'): void
    {
        if (!in_array($layout, ['customer', 'internal', 'admin-auth', 'recovery'], true)) {
            throw new \InvalidArgumentException('Unsupported view layout.');
        }

        http_response_code($status);
        $e = Format::escape(...);
        $money = Format::money(...);
        $label = Format::label(...);
        $image = Format::imagePath(...);
        $date = Format::date(...);
        $statusLabel = Format::status(...);
        $informationPolicies = self::$publishedPolicies;
        $footerShopLinks = self::$shopLinks;
        $supportUnread = self::$supportUnread;
        extract($data, EXTR_SKIP);
        ob_start();
        require dirname(__DIR__) . '/Views/' . $template . '.php';
        $content = ob_get_clean();
        $layoutName = $layout;
        if ($layoutName === 'recovery') {
            require dirname(__DIR__) . '/Views/password-recovery-layout.php';
        } elseif ($layoutName === 'admin-auth') {
            require dirname(__DIR__) . '/Views/admin/auth-layout.php';
        } else {
            require dirname(__DIR__) . '/Views/layout.php';
        }
    }
}
