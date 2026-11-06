<?php

declare(strict_types=1);

namespace Verdant\Admin;

use Verdant\Persistence\Repository;

final class VariantAdminRepository extends Repository
{
    public function families(bool $lock = false): array
    {
        return $this->select(
            'SELECT f.family_id, f.family_code, f.name, f.product_kind, f.archived_at,
                    SUM(p.product_id IS NOT NULL AND p.archived_at IS NULL) AS active_variant_count
             FROM product_families f LEFT JOIN products p ON p.family_id = f.family_id
             GROUP BY f.family_id, f.family_code, f.name, f.product_kind, f.archived_at
             ORDER BY (f.archived_at IS NOT NULL), f.name, f.family_id' . ($lock ? ' FOR UPDATE' : ''),
        );
    }

    public function family(int $familyId, bool $lock = false): ?array
    {
        return $this->select(
            'SELECT family_id, family_code, name, product_kind, archived_at
             FROM product_families WHERE family_id = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$familyId],
        )[0] ?? null;
    }

    public function familyByCode(string $familyCode, bool $lock = false): ?array
    {
        return $this->select(
            'SELECT family_id, family_code, name, product_kind, archived_at
             FROM product_families WHERE family_code = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$familyCode],
        )[0] ?? null;
    }

    public function insertFamily(string $code, string $name, string $kind): int
    {
        $this->execute('INSERT INTO product_families (family_code, name, product_kind) VALUES (?, ?, ?)', [$code, $name, $kind]);
        return (int) $this->connection->insert_id;
    }

    public function updateFamily(int $familyId, string $name, string $kind): void
    {
        $this->execute('UPDATE product_families SET name = ?, product_kind = ? WHERE family_id = ?', [$name, $kind, $familyId]);
    }

    public function hasActiveMembers(int $familyId): bool
    {
        return $this->select(
            'SELECT product_id FROM products WHERE family_id = ? AND archived_at IS NULL LIMIT 1', [$familyId],
        ) !== [];
    }

    public function hasMembers(int $familyId): bool
    {
        return $this->select('SELECT product_id FROM products WHERE family_id = ? LIMIT 1', [$familyId]) !== [];
    }

    public function setFamilyArchived(int $familyId, bool $archived): void
    {
        $this->execute(
            $archived
                ? 'UPDATE product_families SET archived_at = UTC_TIMESTAMP() WHERE family_id = ? AND archived_at IS NULL'
                : 'UPDATE product_families SET archived_at = NULL WHERE family_id = ? AND archived_at IS NOT NULL',
            [$familyId],
        );
    }

    public function activeProduct(int $productId, bool $lock = false): ?array
    {
        return $this->select(
            'SELECT p.product_id, p.product_kind, p.accessory_subtype, p.buy_enabled, p.rent_enabled,
                    p.archived_at, c.archived_at AS category_archived_at
             FROM products p JOIN categories c ON c.category_id = p.category_id
             WHERE p.product_id = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$productId],
        )[0] ?? null;
    }

    /** Product rows are locked in ascending ID order when a compatibility pair is changed. */
    public function compatibilityProducts(array $productIds, bool $lock = false): array
    {
        $productIds = array_values(array_unique($productIds));
        sort($productIds, SORT_NUMERIC);
        if ($productIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($productIds), '?'));
        return $this->select(
            'SELECT p.product_id, p.product_kind, p.accessory_subtype, p.buy_enabled, p.rent_enabled,
                    p.archived_at, c.archived_at AS category_archived_at
             FROM products p JOIN categories c ON c.category_id = p.category_id
             WHERE p.product_id IN (' . $marks . ') ORDER BY p.product_id' . ($lock ? ' FOR UPDATE' : ''),
            $productIds,
        );
    }

    public function compatibilityForPair(int $plantId, int $potId, bool $lock = false): ?array
    {
        return $this->select(
            'SELECT compatibility_id, plant_product_id, pot_product_id, source_status, archived_at
             FROM product_compatibilities WHERE plant_product_id = ? AND pot_product_id = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$plantId, $potId],
        )[0] ?? null;
    }

    public function compatibility(int $compatibilityId, bool $lock = false): ?array
    {
        return $this->select(
            'SELECT compatibility_id, plant_product_id, pot_product_id, source_status, archived_at
             FROM product_compatibilities WHERE compatibility_id = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$compatibilityId],
        )[0] ?? null;
    }

    public function compatibilities(): array
    {
        return $this->select(
            'SELECT x.compatibility_id, x.plant_product_id, x.pot_product_id, x.source_status, x.archived_at,
                    plant.sku AS plant_sku, plant.name AS plant_name, plant.variant_label AS plant_variant,
                    pot.sku AS pot_sku, pot.name AS pot_name, pot.variant_label AS pot_variant
             FROM product_compatibilities x
             JOIN products plant ON plant.product_id = x.plant_product_id
             JOIN products pot ON pot.product_id = x.pot_product_id
             ORDER BY (x.archived_at IS NOT NULL), plant.name, plant.sku, pot.name, pot.sku',
        );
    }

    public function eligiblePlants(): array
    {
        return $this->select(
            "SELECT p.product_id, p.sku, p.name, p.variant_label FROM products p JOIN categories c ON c.category_id = p.category_id
             WHERE p.product_kind = 'plant' AND p.archived_at IS NULL AND c.archived_at IS NULL
             ORDER BY p.name, p.variant_label, p.product_id",
        );
    }

    public function eligiblePots(): array
    {
        return $this->select(
            "SELECT p.product_id, p.sku, p.name, p.variant_label FROM products p JOIN categories c ON c.category_id = p.category_id
             WHERE p.product_kind = 'accessory' AND p.accessory_subtype = 'pot'
               AND (p.buy_enabled = 1 OR p.rent_enabled = 1)
               AND p.archived_at IS NULL AND c.archived_at IS NULL
             ORDER BY p.name, p.variant_label, p.product_id",
        );
    }

    public function insertCompatibility(int $plantId, int $potId, string $source): int
    {
        $this->execute(
            'INSERT INTO product_compatibilities (plant_product_id, pot_product_id, source_status) VALUES (?, ?, ?)',
            [$plantId, $potId, $source],
        );
        return (int) $this->connection->insert_id;
    }

    public function updateCompatibilitySource(int $compatibilityId, string $source): void
    {
        $this->execute('UPDATE product_compatibilities SET source_status = ? WHERE compatibility_id = ?', [$source, $compatibilityId]);
    }

    public function setCompatibilityArchived(int $compatibilityId, bool $archived): void
    {
        $this->execute(
            $archived
                ? 'UPDATE product_compatibilities SET archived_at = UTC_TIMESTAMP() WHERE compatibility_id = ? AND archived_at IS NULL'
                : 'UPDATE product_compatibilities SET archived_at = NULL WHERE compatibility_id = ? AND archived_at IS NOT NULL',
            [$compatibilityId],
        );
    }
}
