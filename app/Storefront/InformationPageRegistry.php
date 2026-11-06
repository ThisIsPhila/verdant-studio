<?php

declare(strict_types=1);

namespace Verdant\Storefront;

/** Fixed page routes and presentation metadata. Database content cannot choose a route or template. */
final class InformationPageRegistry
{
    private const PAGES = [
        'about' => ['label' => 'About / Our Story', 'route' => '/about', 'layout' => 'editorial'],
        'sustainability' => ['label' => 'Sustainability', 'route' => '/sustainability', 'layout' => 'editorial'],
        'plant-care' => ['label' => 'Plant Care', 'route' => '/plant-care', 'layout' => 'learning'],
        'journal' => ['label' => 'Journal', 'route' => '/journal', 'layout' => 'learning'],
        'journal-article' => ['label' => 'Journal article', 'route' => '/journal/article?slug=finding-your-green-corner', 'layout' => 'article', 'article_slug' => 'finding-your-green-corner'],
        'journal-houseplants' => ['label' => 'Happier houseplants', 'route' => '/journal/article?slug=happier-houseplants', 'layout' => 'article', 'article_slug' => 'happier-houseplants'],
        'journal-pots' => ['label' => 'Choosing the right pot', 'route' => '/journal/article?slug=choosing-the-right-pot', 'layout' => 'article', 'article_slug' => 'choosing-the-right-pot'],
        'contact' => ['label' => 'Contact & Enquiries', 'route' => '/contact', 'layout' => 'contact-help'],
        'delivery' => ['label' => 'Delivery & Collection', 'route' => '/delivery', 'layout' => 'contact-help'],
        'returns' => ['label' => 'Returns & FAQs', 'route' => '/returns', 'layout' => 'contact-help'],
        'privacy' => ['label' => 'Privacy & POPIA', 'route' => '/privacy', 'layout' => 'policy', 'policy' => true],
        'terms' => ['label' => 'Terms of Use & Sale', 'route' => '/terms', 'layout' => 'policy', 'policy' => true],
    ];

    /** @return array<string, array{label:string,route:string,layout:string,article_slug?:string,policy?:bool}> */
    public static function pages(): array
    {
        return self::PAGES;
    }

    public static function isPage(string $key): bool
    {
        return isset(self::PAGES[$key]);
    }

    public static function isPolicy(string $key): bool
    {
        return (self::PAGES[$key]['policy'] ?? false) === true;
    }

    public static function byRoute(string $route): ?string
    {
        foreach (self::PAGES as $key => $definition) {
            if ($definition['route'] === $route) {
                return $key;
            }
        }
        return null;
    }

    public static function articleKey(string $slug): ?string
    {
        foreach (self::PAGES as $key => $definition) {
            if (($definition['article_slug'] ?? null) === $slug) {
                return $key;
            }
        }
        return null;
    }
}
