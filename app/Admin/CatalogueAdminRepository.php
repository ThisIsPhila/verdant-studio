<?php

declare(strict_types=1);

namespace Verdant\Admin;

use Verdant\Persistence\Repository;

final class CatalogueAdminRepository extends Repository
{
    public function products(int $limit, int $offset): array
    {
        return $this->select(
            'SELECT p.product_id, p.category_id, c.name AS category_name, p.sku, p.name, p.description,
                    p.product_kind, p.family_id, f.name AS family_name, p.variant_label, p.accessory_subtype,
                    p.selling_unit, p.size_label, p.colour_label, p.details_status,
                    p.light_level, p.watering_level, p.toxicity, p.image_path, p.image_alt, p.buy_enabled, p.rent_enabled,
                    p.retail_price_minor, p.rental_daily_rate_minor, p.archived_at,
                    i.on_hand, i.version
             FROM products p JOIN categories c ON c.category_id = p.category_id
             LEFT JOIN product_families f ON f.family_id = p.family_id
             JOIN inventory i ON i.product_id = p.product_id
             ORDER BY (p.archived_at IS NOT NULL), p.name, p.product_id LIMIT ? OFFSET ?',
            [$limit, $offset],
        );
    }

    public function product(int $productId, bool $lock = false): ?array
    {
        $suffix = $lock ? ' FOR UPDATE' : '';
        return $this->select(
            'SELECT p.product_id, p.category_id, c.name AS category_name, p.sku, p.name, p.description,
                    p.product_kind, p.family_id, f.name AS family_name, p.variant_label, p.accessory_subtype,
                    p.selling_unit, p.size_label, p.colour_label, p.details_status,
                    p.light_level, p.watering_level, p.toxicity, p.image_path, p.image_alt, p.buy_enabled, p.rent_enabled,
                    p.retail_price_minor, p.rental_daily_rate_minor, p.archived_at,
                    i.on_hand, i.version
             FROM products p JOIN categories c ON c.category_id = p.category_id
             LEFT JOIN product_families f ON f.family_id = p.family_id
             JOIN inventory i ON i.product_id = p.product_id WHERE p.product_id = ?' . $suffix,
            [$productId],
        )[0] ?? null;
    }

    public function categories(int $limit, int $offset): array
    {
        return $this->select(
            'SELECT c.category_id, c.name, c.archived_at, COUNT(p.product_id) AS product_count
             FROM categories c LEFT JOIN products p ON p.category_id = c.category_id
             GROUP BY c.category_id, c.name, c.archived_at
             ORDER BY (c.archived_at IS NOT NULL), c.name, c.category_id LIMIT ? OFFSET ?',
            [$limit, $offset],
        );
    }

    public function activeCategories(): array
    {
        return $this->select('SELECT category_id, name FROM categories WHERE archived_at IS NULL ORDER BY name, category_id');
    }

    public function activeFamilies(): array
    {
        return $this->select(
            'SELECT family_id, family_code, name, product_kind FROM product_families
             WHERE archived_at IS NULL ORDER BY name, family_id',
        );
    }

    public function familyMetadata(int $familyId, bool $lock = false): ?array
    {
        return $this->select(
            'SELECT family_id, product_kind, archived_at FROM product_families WHERE family_id = ?'
            . ($lock ? ' FOR UPDATE' : ''),
            [$familyId],
        )[0] ?? null;
    }

    public function category(int $categoryId, bool $lock = false): ?array
    {
        $suffix = $lock ? ' FOR UPDATE' : '';
        return $this->select(
            'SELECT category_id, name, archived_at FROM categories WHERE category_id = ?' . $suffix,
            [$categoryId],
        )[0] ?? null;
    }

    public function activeCategoryExists(int $categoryId): bool
    {
        return $this->select(
            'SELECT category_id FROM categories WHERE category_id = ? AND archived_at IS NULL FOR UPDATE',
            [$categoryId],
        ) !== [];
    }

    public function hasActiveProducts(int $categoryId): bool
    {
        return $this->select(
            'SELECT product_id FROM products WHERE category_id = ? AND archived_at IS NULL LIMIT 1',
            [$categoryId],
        ) !== [];
    }

    public function insertProduct(array $product): int
    {
        $this->execute(
            'INSERT INTO products
                (category_id, sku, name, description, light_level, watering_level, toxicity, image_path, image_alt,
                 product_kind, family_id, variant_label, accessory_subtype,
                 selling_unit, size_label, colour_label, details_status,
                 buy_enabled, rent_enabled, retail_price_minor, rental_daily_rate_minor)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'unknown\', ?, ?, ?, ?)',
            [
                $product['category_id'], $product['sku'], $product['name'], $product['description'],
                $product['light_level'], $product['watering_level'], $product['toxicity'], $product['image_path'], $product['image_alt'],
                $product['product_kind'], $product['family_id'], $product['variant_label'], $product['accessory_subtype'],
                $product['selling_unit'], $product['size_label'], $product['colour_label'],
                $product['buy_enabled'], $product['rent_enabled'], $product['retail_price_minor'],
                $product['rental_daily_rate_minor'],
            ],
        );
        return (int) $this->connection->insert_id;
    }

    public function updateProduct(int $productId, array $product): void
    {
        $this->execute(
            'UPDATE products SET category_id = ?, sku = ?, name = ?, description = ?, light_level = ?,
                    watering_level = ?, toxicity = ?, image_path = ?, image_alt = ?, product_kind = ?,
                    family_id = ?, variant_label = ?, accessory_subtype = ?,
                    selling_unit = ?, size_label = ?, colour_label = ?, buy_enabled = ?, rent_enabled = ?,
                    retail_price_minor = ?, rental_daily_rate_minor = ? WHERE product_id = ?',
            [
                $product['category_id'], $product['sku'], $product['name'], $product['description'],
                $product['light_level'], $product['watering_level'], $product['toxicity'], $product['image_path'], $product['image_alt'],
                $product['product_kind'], $product['family_id'], $product['variant_label'], $product['accessory_subtype'],
                $product['selling_unit'], $product['size_label'], $product['colour_label'],
                $product['buy_enabled'], $product['rent_enabled'], $product['retail_price_minor'],
                $product['rental_daily_rate_minor'], $productId,
            ],
        );
    }

    public function insertInventory(int $productId, int $onHand): void
    {
        $this->execute('INSERT INTO inventory (product_id, on_hand) VALUES (?, ?)', [$productId, $onHand]);
    }

    public function updateInventory(int $productId, int $onHand, int $expectedVersion): bool
    {
        return $this->execute(
            'UPDATE inventory SET on_hand = ?, version = version + 1
             WHERE product_id = ? AND version = ?',
            [$onHand, $productId, $expectedVersion],
        ) === 1;
    }

    public function setProductArchived(int $productId, bool $archived): void
    {
        $this->execute(
            $archived
                ? 'UPDATE products SET archived_at = UTC_TIMESTAMP() WHERE product_id = ? AND archived_at IS NULL'
                : 'UPDATE products SET archived_at = NULL WHERE product_id = ? AND archived_at IS NOT NULL',
            [$productId],
        );
    }

    public function insertCategory(string $name): int
    {
        $this->execute('INSERT INTO categories (name) VALUES (?)', [$name]);
        return (int) $this->connection->insert_id;
    }

    public function updateCategory(int $categoryId, string $name): void
    {
        $this->execute('UPDATE categories SET name = ? WHERE category_id = ?', [$name, $categoryId]);
    }

    public function setCategoryArchived(int $categoryId, bool $archived): void
    {
        $this->execute(
            $archived
                ? 'UPDATE categories SET archived_at = UTC_TIMESTAMP() WHERE category_id = ? AND archived_at IS NULL'
                : 'UPDATE categories SET archived_at = NULL WHERE category_id = ? AND archived_at IS NOT NULL',
            [$categoryId],
        );
    }
}
