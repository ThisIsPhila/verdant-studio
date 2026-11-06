<?php

declare(strict_types=1);

namespace Verdant\Catalogue;

use Verdant\Persistence\Repository;

final class ProductRepository extends Repository
{
    public function browse(array $filters, int $limit, int $offset): array
    {
        [$where, $parameters] = $this->filteredWhere($filters);
        $parameters[] = $limit;
        $parameters[] = $offset;
        return $this->select(
            'SELECT p.product_id, p.name, p.image_path, p.image_alt, p.product_kind,
                    p.selling_unit, p.size_label, p.colour_label, p.details_status,
                    p.light_level, p.watering_level, p.toxicity,
                    p.buy_enabled, p.rent_enabled, p.retail_price_minor, p.rental_daily_rate_minor,
                    c.name AS category_name
             FROM products p JOIN categories c ON c.category_id = p.category_id WHERE '
                . implode(' AND ', $where) . ' ORDER BY p.name, p.product_id LIMIT ? OFFSET ?',
            $parameters,
        );
    }

    public function count(array $filters): int
    {
        [$where, $parameters] = $this->filteredWhere($filters);
        $row = $this->select(
            'SELECT COUNT(*) AS total
             FROM products p JOIN categories c ON c.category_id = p.category_id WHERE '
                . implode(' AND ', $where),
            $parameters,
        );
        return (int) ($row[0]['total'] ?? 0);
    }

    /** @return array{0: list<string>, 1: list<string>} */
    private function filteredWhere(array $filters): array
    {
        $where = ['p.archived_at IS NULL', 'c.archived_at IS NULL'];
        $parameters = [];
        foreach (['category_id', 'light_level', 'watering_level', 'toxicity'] as $column) {
            if (($filters[$column] ?? '') !== '') {
                if ($column !== 'category_id') {
                    $where[] = "(p.product_kind = 'plant' AND p.$column <> 'unknown')";
                }
                $where[] = "p.$column = ?";
                $parameters[] = $filters[$column];
            }
        }
        return [$where, $parameters];
    }

    public function categories(): array
    {
        return $this->select('SELECT category_id, name FROM categories WHERE archived_at IS NULL ORDER BY name');
    }

    public function filterOptions(): array
    {
        $options = [];
        foreach (['light_level', 'watering_level', 'toxicity'] as $column) {
            $options[$column] = array_column($this->select(
                "SELECT DISTINCT p.$column FROM products p JOIN categories c ON c.category_id = p.category_id
                 WHERE p.archived_at IS NULL AND c.archived_at IS NULL
                   AND p.product_kind = 'plant' AND p.$column <> 'unknown'
                 ORDER BY p.$column",
            ), $column);
        }
        return $options;
    }

    public function findActive(int $productId): ?array
    {
        return $this->select(
            'SELECT p.product_id, p.sku, p.name, p.description, c.name AS category_name, p.light_level, p.watering_level,
                    p.toxicity, p.image_path, p.image_alt, p.product_kind, p.selling_unit,
                    p.size_label, p.colour_label, p.details_status, p.buy_enabled, p.rent_enabled,
                    p.retail_price_minor, p.rental_daily_rate_minor, p.category_id
             FROM products p JOIN categories c ON c.category_id = p.category_id
             WHERE p.product_id = ? AND p.archived_at IS NULL AND c.archived_at IS NULL',
            [$productId],
        )[0] ?? null;
    }
}
