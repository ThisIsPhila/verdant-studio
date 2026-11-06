<?php

declare(strict_types=1);

namespace Verdant\Storefront;

/** Presentation-only matches to the owner's approved sample; never inventory or pricing authority. */
final class VisionLandingPresenter
{
    private const IMAGES = [
        'monstera' => 'p-monstera.jpg', 'monstera deliciosa' => 'p-monstera.jpg',
        'fiddle leaf fig' => 'p-fiddle.jpg', 'bird of paradise' => 'p-bird.jpg',
        'zanzibar gem' => 'zz.jpg', 'zamioculcas' => 'zz.jpg',
        'golden pothos' => 'p-pothos.jpg',
        'cherry blossom' => 'p-cherry.jpg', 'olive tree' => 'p-olive.jpg',
        'box topiary' => 'p-topiary.jpg', 'pittosporum' => 'p-pittosporum.jpg',
        'living wall panel' => 'p-living-wall.jpg',
        'rose stems' => 'p-roses.jpg', 'lily stems' => 'p-lilies.jpg',
        'protea stems' => 'p-protea.jpg', 'eucalyptus blend' => 'p-eucalyptus.jpg',
        'classic white' => 'p-white-bouquet.jpg', 'classic white bouquet' => 'p-white-bouquet.jpg',
        'wild garden' => 'p-wild-bouquet.jpg', 'wild garden bouquet' => 'p-wild-bouquet.jpg',
        'soft pastel' => 'p-pastel-bouquet.jpg', 'soft pastel bouquet' => 'p-pastel-bouquet.jpg',
        'stone planter' => 'p-stone.jpg', 'terracotta planter' => 'p-terracotta.jpg',
        'charcoal planter' => 'p-charcoal.jpg', 'sand ribbed planter' => 'p-sand-ribbed.jpg',
        'pruning shears' => 'p-shears.jpg', 'watering can' => 'p-can.jpg',
        'plant food' => 'p-plant-food.jpg', 'soil brush' => 'p-soil-brush.jpg',
        'large fern display' => 'p-fern-display.jpg', 'ceramic herb planter' => 'p-herb-planter.jpg',
    ];
    private const FEATURED = ['monstera', 'fiddle leaf fig', 'bird of paradise', 'classic white', 'wild garden', 'stone planter', 'terracotta planter', 'watering can'];

    public static function image(array $product): ?string
    {
        $file = self::IMAGES[strtolower(trim((string) ($product['name'] ?? '')))] ?? null;
        return $file === null ? null : '/assets/vision/images/' . $file;
    }

    /** Choose from the actual allowed result set, keeping custom collection membership intact. */
    public static function featured(array $products, int $limit = 8, array $order = self::FEATURED): array
    {
        $selected = [];
        $names = [];
        foreach ($order as $name) {
            foreach ($products as $product) {
                if (strtolower($product['name']) === $name) {
                    $selected[(int) $product['product_id']] = $product;
                    $names[$name] = true;
                    break;
                }
            }
        }
        foreach ($products as $product) {
            $name = strtolower($product['name']);
            if (!isset($names[$name])) {
                $selected[(int) $product['product_id']] = $product;
                $names[$name] = true;
            }
        }
        return array_slice(array_values($selected), 0, $limit);
    }

    public static function category(array $product): string
    {
        return match ($product['product_kind'] ?? '') {
            'plant' => 'Plants',
            'flower', 'cut_flower', 'cut_foliage', 'floral_arrangement' => 'Flowers',
            'accessory' => str_contains(strtolower($product['name'] ?? ''), 'planter') ? 'Pots' : 'Care & Tools',
            default => 'Care & Tools',
        };
    }
}
