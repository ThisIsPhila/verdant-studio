<?php

declare(strict_types=1);

namespace Verdant\Retail;

use Verdant\Inventory\CapacityRepository;
use Verdant\Persistence\Repository;

final class RetailAvailabilityRepository extends Repository
{
    public function find(int $productId): ?array
    {
        return $this->forProducts([$productId])[0] ?? null;
    }

    public function forProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        foreach ($productIds as $id) {
            if (!is_int($id) || $id < 1) {
                throw new \InvalidArgumentException('Product IDs must be positive integers.');
            }
        }
        $productIds = array_values(array_unique($productIds));
        sort($productIds, SORT_NUMERIC);
        $marks = implode(',', array_fill(0, count($productIds), '?'));
        $products = $this->select(
            "SELECT p.product_id, p.sku, p.name, p.image_path, p.buy_enabled, p.retail_price_minor
             FROM products p JOIN categories c ON c.category_id = p.category_id
             JOIN inventory i ON i.product_id = p.product_id
             WHERE p.product_id IN ($marks) AND p.archived_at IS NULL AND c.archived_at IS NULL
             ORDER BY p.product_id",
            $productIds,
        );
        $capacity = new CapacityRepository($this->connection);
        foreach ($products as &$product) {
            $product['available_quantity'] = $capacity->retailAvailable((int) $product['product_id']);
        }
        unset($product);
        return $products;
    }
}
