<?php

declare(strict_types=1);

namespace Verdant\Admin;

/** Presentation only; saving and eligibility remain in CompositionAdminService. */
final class CompositionEditor
{
    public static function rows(array $definition, array $values): array
    {
        $rows = $values['components'] ?? $definition['components'];
        $result = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) continue;
            foreach (['product', 'family'] as $type) {
                $field = $type . '_ids';
                if (!isset($row[$field]) || !is_array($row[$field])) {
                    $row[$field] = array_column(array_filter($row[$type . '_allowlist'] ?? [],
                        static fn (array $allowed): bool => $allowed['archived_at'] === null), $type . '_id');
                }
                $row[$field] = array_map('intval', $row[$field]);
            }
            $row['archived'] = isset($row['archived']) ? (string) $row['archived'] === '1' : !empty($row['archived_at']);
            $row['required'] = !empty($row['required']);
            $row = array_replace(self::blank(), $row);
            $row['customer_choice'] = !$row['required'] || ($row['source_type'] ?? '') !== 'product'
                || count($row['product_ids']) !== 1
                || (int) ($row['min_quantity'] ?? 1) !== (int) ($row['max_quantity'] ?? 1)
                || (int) ($row['max_selections'] ?? 1) > 1;
            $result[] = $row;
        }
        return $result;
    }

    public static function blank(bool $choice = false): array
    {
        return [
            'component_id' => '', 'component_key' => 'item-' . bin2hex(random_bytes(8)), 'label' => '',
            'component_role' => 'plant', 'source_type' => $choice ? 'family' : 'product',
            'required' => true, 'archived' => false, 'customer_choice' => $choice,
            'min_selections' => 1, 'max_selections' => 1, 'min_quantity' => 1, 'max_quantity' => 1,
            'permitted_mode' => 'sale', 'sort_order' => 0, 'anchor_key' => '',
            'product_ids' => [], 'family_ids' => [],
        ];
    }
}
