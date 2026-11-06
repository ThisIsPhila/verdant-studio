<?php

declare(strict_types=1);

namespace Verdant\Catalogue;

use Verdant\Persistence\Repository;

final class ProductFamilyRepository extends Repository
{
    public function familyForActiveProduct(int $productId): ?array
    {
        return $this->select(
            'SELECT f.family_id, f.family_code, f.name, f.product_kind
             FROM products p JOIN categories c ON c.category_id = p.category_id
             JOIN product_families f ON f.family_id = p.family_id
             WHERE p.product_id = ? AND p.archived_at IS NULL AND c.archived_at IS NULL
               AND f.archived_at IS NULL AND f.product_kind = p.product_kind',
            [$productId],
        )[0] ?? null;
    }

    public function variants(int $familyId): array
    {
        return $this->select(
            'SELECT p.product_id, p.family_id, p.sku, p.name, p.variant_label, p.size_label, p.colour_label,
                    p.image_path, p.image_alt, p.buy_enabled, p.rent_enabled,
                    p.retail_price_minor, p.rental_daily_rate_minor
             FROM products p JOIN categories c ON c.category_id = p.category_id
             JOIN product_families f ON f.family_id = p.family_id
             WHERE p.family_id = ? AND p.archived_at IS NULL AND c.archived_at IS NULL
               AND f.archived_at IS NULL AND p.product_kind = f.product_kind
             ORDER BY p.variant_label, p.product_id',
            [$familyId],
        );
    }

    public function compatiblePots(int $plantProductId): array
    {
        return $this->select(
            "SELECT p.product_id, p.family_id, p.sku, p.name, p.variant_label, p.size_label, p.colour_label,
                    p.image_path, p.image_alt, p.buy_enabled, p.rent_enabled,
                    p.retail_price_minor, p.rental_daily_rate_minor, x.source_status
             FROM product_compatibilities x
             JOIN products plant ON plant.product_id = x.plant_product_id
             JOIN categories plant_category ON plant_category.category_id = plant.category_id
             JOIN products p ON p.product_id = x.pot_product_id
             JOIN categories c ON c.category_id = p.category_id
             WHERE x.plant_product_id = ? AND x.archived_at IS NULL
               AND plant.archived_at IS NULL AND plant_category.archived_at IS NULL
               AND plant.product_kind = 'plant'
               AND p.archived_at IS NULL AND c.archived_at IS NULL
               AND p.product_kind = 'accessory' AND p.accessory_subtype = 'pot'
               AND (p.buy_enabled = 1 OR p.rent_enabled = 1)
             ORDER BY p.name, p.variant_label, p.product_id",
            [$plantProductId],
        );
    }
}
