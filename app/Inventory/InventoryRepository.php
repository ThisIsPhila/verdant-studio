<?php

declare(strict_types=1);

namespace Verdant\Inventory;

use Verdant\Persistence\Repository;

final class InventoryRepository extends Repository
{
    /** Caller owns the transaction and capacity checks; lock rows in stable order. */
    public function lockProducts(array $productIds): array
    {
        foreach ($productIds as $id) {
            if (!is_int($id) || $id < 1) {
                throw new \InvalidArgumentException('Product IDs must be positive integers.');
            }
        }
        $productIds = array_values(array_unique($productIds));
        sort($productIds, SORT_NUMERIC);
        if ($productIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $rows = $this->select(
            "SELECT product_id, on_hand, version FROM inventory WHERE product_id IN ($placeholders)
             ORDER BY product_id FOR UPDATE", $productIds,
        );
        if (count($rows) !== count($productIds)) {
            throw new \DomainException('Inventory record unavailable.');
        }
        return $rows;
    }
}
