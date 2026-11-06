<?php

declare(strict_types=1);

namespace Verdant\Storefront;

use Verdant\Support\ValidationException;

/** Fixed content vocabulary; database values can never choose PHP templates or URLs. */
final class SectionRegistry
{
    private const PAGES = [
        'home' => ['label' => 'Home', 'types' => ['hero', 'editorial', 'collection', 'arrangement_grid', 'product_grid', 'configuration_entry', 'rental_collection', 'service_band']],
        'homes' => ['label' => 'Homes', 'types' => ['hero', 'editorial', 'collection', 'arrangement_grid', 'product_grid']],
        'catalogue' => ['label' => 'Catalogue', 'types' => ['editorial', 'product_grid']],
        'arrangements' => ['label' => 'Arrangements', 'types' => ['hero', 'editorial', 'arrangement_grid']],
        'rentals' => ['label' => 'Rentals', 'types' => ['hero', 'editorial', 'product_grid']],
        'about' => ['label' => 'About / Our Story', 'types' => ['info_hero', 'info_text', 'info_card']],
        'sustainability' => ['label' => 'Sustainability', 'types' => ['info_hero', 'info_text', 'info_card']],
        'plant-care' => ['label' => 'Plant Care', 'types' => ['info_hero', 'info_text', 'info_card']],
        'journal' => ['label' => 'Journal', 'types' => ['info_hero', 'info_text', 'info_card']],
        'journal-article' => ['label' => 'Journal article', 'types' => ['info_hero', 'info_text', 'info_card']],
        'journal-houseplants' => ['label' => 'Journal article · Happier houseplants', 'types' => ['info_hero', 'info_text', 'info_card']],
        'journal-pots' => ['label' => 'Journal article · Choosing the right pot', 'types' => ['info_hero', 'info_text', 'info_card']],
        'contact' => ['label' => 'Contact & Enquiries', 'types' => ['info_hero', 'info_text', 'info_card']],
        'delivery' => ['label' => 'Delivery & Collection', 'types' => ['info_hero', 'info_text', 'info_card', 'info_faq']],
        'returns' => ['label' => 'Returns & FAQs', 'types' => ['info_hero', 'info_text', 'info_faq']],
        'privacy' => ['label' => 'Privacy & POPIA', 'types' => ['info_hero', 'info_text']],
        'terms' => ['label' => 'Terms of Use & Sale', 'types' => ['info_hero', 'info_text', 'info_card']],
    ];

    private const ROUTES = [
        '/', '/catalogue', '/catalogue?mode=rent', '/collections/homes', '/arrangements',
        '/configure', '/cart', '/login', '/about', '/sustainability', '/plant-care', '/journal',
        '/journal/article?slug=finding-your-green-corner', '/journal/article?slug=happier-houseplants',
        '/journal/article?slug=choosing-the-right-pot', '/contact', '/delivery', '/returns', '/privacy', '/terms',
        '#our-story', '#our-approach', '#articles',
        '/contact#customer',
    ];
    private const CATALOGUE_KINDS = ['plant', 'flower', 'pot', 'tool'];

    private const MEDIA = [
        '/assets/vision/images/hero.jpg',
        '/assets/vision/images/hero-configure.jpg',
        '/assets/vision/images/r-cluster.jpg',
        '/assets/vision/images/r-olive.jpg',
        '/assets/vision/images/p-monstera.jpg',
        '/assets/vision/images/homes.jpg',
        '/assets/vision/images/workspaces.jpg',
        '/assets/vision/images/gatherings.jpg',
        '/assets/images/botanical.svg',
        '/assets/images/catalogue/placeholders/plant.svg',
        '/assets/images/catalogue/placeholders/plant-arrangement.svg',
        '/assets/images/catalogue/placeholders/floral-arrangement.svg',
        '/assets/images/catalogue/placeholders/cut-flower.svg',
        '/assets/images/catalogue/placeholders/cut-foliage.svg',
        '/assets/images/catalogue/placeholders/accessory.svg',
        '/assets/images/catalogue/placeholders/other.svg',
    ];

    /** @return array<string, array{label:string,types:list<string>}> */
    public static function pages(): array
    {
        return self::PAGES;
    }

    /** @return list<string> */
    public static function typesForPage(string $page): array
    {
        return self::PAGES[$page]['types'] ?? [];
    }

    public static function isPage(string $page): bool
    {
        return isset(self::PAGES[$page]);
    }

    public static function isType(string $page, string $type): bool
    {
        return in_array($type, self::typesForPage($page), true);
    }

    /** @return list<string> */
    public static function mediaPaths(): array
    {
        return self::MEDIA;
    }

    /** @return list<string> */
    public static function linkPaths(): array
    {
        return [
            '/', '/catalogue', '/catalogue?mode=rent',
            '/catalogue?kind=plant', '/catalogue?kind=flower', '/catalogue?kind=pot', '/catalogue?kind=tool',
            '/catalogue?kind=plant&mode=rent', '/catalogue?kind=flower&mode=rent',
            '/catalogue?kind=pot&mode=rent', '/catalogue?kind=tool&mode=rent',
            '/collections/homes', '/arrangements', '/configure', '/cart', '/login',
            '/about', '/sustainability', '/plant-care', '/journal',
            '/journal/article?slug=finding-your-green-corner', '/journal/article?slug=happier-houseplants',
            '/journal/article?slug=choosing-the-right-pot', '/contact', '/delivery', '/returns', '/privacy', '/terms',
            '#our-story', '#our-approach', '#articles',
            '/contact#customer',
        ];
    }

    /** @return list<array<string, int|string|null>> */
    public static function defaults(): array
    {
        return array_merge([
            ['page_key' => 'home', 'section_key' => 'hero', 'section_type' => 'hero', 'position' => 10, 'heading' => 'Nature, curated by hand.', 'body' => 'Curated plants, flowers, pots and botanical styling for homes, events and workspaces across South Africa.', 'media_path' => '/assets/images/botanical.svg', 'media_alt' => 'Botanical illustration', 'link_label' => 'Shop plants', 'link_path' => '/catalogue?kind=plant'],
            ['page_key' => 'home', 'section_key' => 'homes', 'section_type' => 'collection', 'position' => 20, 'heading' => 'Made for the spaces we live in', 'body' => 'Explore a curated starting point for living spaces.', 'media_path' => null, 'media_alt' => '', 'link_label' => 'Explore Homes', 'link_path' => '/collections/homes'],
            ['page_key' => 'home', 'section_key' => 'arrangements', 'section_type' => 'arrangement_grid', 'position' => 30, 'heading' => 'Complete plant styling for every space.', 'body' => '', 'media_path' => '/assets/vision/images/r-cluster.jpg', 'media_alt' => 'Clustered potted plants', 'link_label' => 'Browse arrangements', 'link_path' => '/arrangements'],
            ['page_key' => 'home', 'section_key' => 'plants', 'section_type' => 'product_grid', 'position' => 40, 'heading' => 'Plants, flowers, pots and more.', 'body' => '', 'media_path' => '/assets/vision/images/hero-plants.jpg', 'media_alt' => 'Potted plants in a warm interior', 'link_label' => 'Shop the catalogue', 'link_path' => '/catalogue'],
            ['page_key' => 'home', 'section_key' => 'configuration', 'section_type' => 'configuration_entry', 'position' => 50, 'heading' => 'Plant & Pot Configuration', 'body' => 'Pair your favourite plant with a pot to create a beautiful, ready-to-style set for your space.', 'media_path' => '/assets/vision/images/hero-configure.jpg', 'media_alt' => 'Plant and pot pairing', 'link_label' => 'Start a configuration', 'link_path' => '/configure'],
            ['page_key' => 'home', 'section_key' => 'rentals', 'section_type' => 'rental_collection', 'position' => 60, 'heading' => 'Green spaces for every occasion.', 'body' => 'Beautiful potted plants and trees for events, weddings and corporate spaces.', 'media_path' => '/assets/vision/images/gatherings.jpg', 'media_alt' => 'Plants styled for a gathering', 'link_label' => 'Explore rentals', 'link_path' => '/catalogue?mode=rent'],
            ['page_key' => 'home', 'section_key' => 'service-band', 'section_type' => 'service_band', 'position' => 70, 'heading' => 'Shop · Consult · Collect · Deliver · Install', 'body' => 'Explore plants and arrangements for your space.', 'media_path' => null, 'media_alt' => '', 'link_label' => 'Browse the catalogue', 'link_path' => '/catalogue'],
            ['page_key' => 'homes', 'section_key' => 'hero', 'section_type' => 'hero', 'position' => 10, 'heading' => 'A greener feeling at home', 'body' => 'An inspiration edit of plants and arrangements for residential spaces.', 'media_path' => '/assets/images/botanical.svg', 'media_alt' => 'Botanical illustration', 'link_label' => 'Browse plants', 'link_path' => '/catalogue'],
            ['page_key' => 'homes', 'section_key' => 'collection', 'section_type' => 'collection', 'position' => 20, 'heading' => 'The Homes edit', 'body' => 'Explore existing products and arrangements gathered for inspiration.', 'media_path' => null, 'media_alt' => '', 'link_label' => 'Explore arrangements', 'link_path' => '/arrangements'],
            ['page_key' => 'homes', 'section_key' => 'products', 'section_type' => 'product_grid', 'position' => 30, 'heading' => 'Individual pieces', 'body' => 'Browse available products in the catalogue.', 'media_path' => '/assets/vision/images/p-monstera.jpg', 'media_alt' => 'Monstera in a planter', 'link_label' => 'Shop plants', 'link_path' => '/catalogue'],
            ['page_key' => 'catalogue', 'section_key' => 'intro', 'section_type' => 'editorial', 'position' => 10, 'heading' => 'Plants, flowers and more.', 'body' => 'Explore plants, trees, living walls, flowers, planters and care essentials.', 'media_path' => null, 'media_alt' => '', 'link_label' => '', 'link_path' => null],
            ['page_key' => 'catalogue', 'section_key' => 'rentals', 'section_type' => 'editorial', 'position' => 20, 'heading' => 'Looking for a rental?', 'body' => 'See eligible items and choose dates in the rental journey.', 'media_path' => null, 'media_alt' => '', 'link_label' => 'Browse rentals', 'link_path' => '/catalogue?mode=rent'],
            ['page_key' => 'arrangements', 'section_key' => 'hero', 'section_type' => 'hero', 'position' => 10, 'heading' => 'Arrangements for your space', 'body' => 'Explore existing arrangements or begin a custom configuration.', 'media_path' => '/assets/vision/images/homes.jpg', 'media_alt' => 'Styled interior with plant arrangements', 'link_label' => 'Build your own', 'link_path' => '/configure'],
            ['page_key' => 'arrangements', 'section_key' => 'discovery', 'section_type' => 'arrangement_grid', 'position' => 20, 'heading' => 'Explore arrangements', 'body' => 'See current arrangement options and their available components.', 'media_path' => null, 'media_alt' => '', 'link_label' => 'View the catalogue', 'link_path' => '/catalogue'],
            ['page_key' => 'rentals', 'section_key' => 'hero', 'section_type' => 'hero', 'position' => 10, 'heading' => 'Rent greenery for a while', 'body' => 'Choose eligible items and check availability for your dates.', 'media_path' => '/assets/vision/images/gatherings.jpg', 'media_alt' => 'Plants styled for an event space', 'link_label' => 'Browse rental items', 'link_path' => '/catalogue?mode=rent'],
            ['page_key' => 'rentals', 'section_key' => 'dates', 'section_type' => 'editorial', 'position' => 20, 'heading' => 'Choose dates before availability', 'body' => 'Browsing does not reserve stock. The rental journey checks availability for selected dates.', 'media_path' => null, 'media_alt' => '', 'link_label' => 'Find eligible items', 'link_path' => '/catalogue?mode=rent'],
        ], class_exists(InformationPageContent::class) ? InformationPageContent::defaults() : []);
    }

    /** Validate content values without accepting identifiers or template names from browser input. */
    public static function validateContent(string $page, string $type, array $input): array
    {
        if (!self::isPage($page) || !self::isType($page, $type)) {
            throw new ValidationException(['section_type' => 'Choose a supported section type for this page.']);
        }
        $fields = [
            'heading' => [180, true], 'body' => [4000, true], 'media_alt' => [180, true],
            'link_label' => [80, true],
        ];
        $content = [];
        foreach ($fields as $name => [$max, $required]) {
            $value = $input[$name] ?? '';
            if (!is_string($value)) {
                throw new ValidationException([$name => 'Enter plain text.']);
            }
            $value = trim($value);
            if (preg_match('/<\s*\/?\s*[a-z!][^>]*>|<\?/i', $value) === 1) {
                throw new ValidationException([$name => 'Enter plain text without markup.']);
            }
            if (mb_strlen($value, 'UTF-8') > $max || ($required && $value === '' && $name === 'heading')) {
                throw new ValidationException([$name => $name === 'heading' ? 'Enter a heading of up to 180 characters.' : 'Text is too long.']);
            }
            $content[$name] = $value;
        }

        $media = $input['media_path'] ?? '';
        if ($media !== '' && (!is_string($media) || !in_array($media, self::mediaPaths(), true))) {
            throw new ValidationException(['media_path' => 'Choose an approved local image.']);
        }
        $content['media_path'] = $media === '' ? null : $media;

        $link = $input['link_path'] ?? '';
        if ($link !== '' && (!is_string($link) || !self::isSupportedRoute($link))) {
            throw new ValidationException(['link_path' => 'Choose a supported local destination.']);
        }
        $content['link_path'] = $link === '' ? null : $link;
        $content['collection_id'] = self::optionalId($input['collection_id'] ?? '', 'collection_id');
        $content['arrangement_id'] = self::optionalId($input['arrangement_id'] ?? '', 'arrangement_id');
        if ($content['collection_id'] !== null && $content['arrangement_id'] !== null) {
            throw new ValidationException(['reference' => 'Choose one existing collection or arrangement.']);
        }
        return $content;
    }

    public static function isSupportedRoute(string $path): bool
    {
        if (in_array($path, self::ROUTES, true)) {
            return true;
        }
        if (preg_match('/^\/catalogue\?kind=([a-z_]+)$/D', $path, $match) === 1) {
            return in_array($match[1], self::CATALOGUE_KINDS, true);
        }
        if (preg_match('/^\/catalogue\?kind=([a-z_]+)&mode=rent$/D', $path, $match) === 1) {
            return in_array($match[1], self::CATALOGUE_KINDS, true);
        }
        return preg_match('/^\/(?:compositions|configure)\?code=[a-z0-9][a-z0-9-]{0,79}$/D', $path) === 1;
    }

    private static function optionalId(mixed $value, string $field): ?int
    {
        if ($value === '' || $value === null) {
            return null;
        }
        if ((!is_int($value) && !is_string($value)) || preg_match('/^[1-9][0-9]{0,17}$/D', (string) $value) !== 1) {
            throw new ValidationException([$field => 'Choose an existing item or leave this empty.']);
        }
        return (int) $value;
    }
}
