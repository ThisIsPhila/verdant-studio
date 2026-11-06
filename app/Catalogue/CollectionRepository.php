<?php

declare(strict_types=1);

namespace Verdant\Catalogue;

use Verdant\Persistence\Repository;

/** Discovery over current catalogue rows; collections do not own inventory. */
final class CollectionRepository extends Repository
{
    // Match the customer size badge: named S/M/L variants precede legacy size labels.
    private const SIZE_LABEL_SQL = "CASE LOWER(TRIM(p.variant_label))
        WHEN 'small' THEN 'Small' WHEN 'medium' THEN 'Medium' WHEN 'large' THEN 'Large'
        ELSE TRIM(p.size_label) END";

    public function browse(array $filters, ?string $collectionCode = null, int $limit = 25, int $offset = 0): array
    {
        [$where, $parameters] = $this->filteredWhere($filters, $collectionCode);
        $parameters[] = $limit;
        $parameters[] = $offset;
        $collectionJoin = $collectionCode === null
            ? "LEFT JOIN (
                    SELECT cp0.product_id, MIN(cp0.explanation) AS explanation
                    FROM collection_products cp0 JOIN collections co0 ON co0.collection_id = cp0.collection_id
                    WHERE co0.state = 'active' GROUP BY cp0.product_id
               ) cp ON cp.product_id = p.product_id"
            : 'JOIN collection_products cp ON cp.product_id = p.product_id JOIN collections co ON co.collection_id = cp.collection_id';
        $priceColumn = ($filters['mode'] ?? '') === 'rent' ? 'p.rental_daily_rate_minor' : 'p.retail_price_minor';
        $varietyOrder = 'ROW_NUMBER() OVER (PARTITION BY p.name ORDER BY CASE (' . self::SIZE_LABEL_SQL . ') WHEN \'Small\' THEN 0 WHEN \'Medium\' THEN 1 WHEN \'Large\' THEN 2 ELSE 3 END, p.product_id), p.name, p.product_id';
        $order = match ($filters['sort'] ?? '') {
            'price_asc' => "$priceColumn IS NULL, $priceColumn ASC, p.name, p.product_id",
            'price_desc' => "$priceColumn IS NULL, $priceColumn DESC, p.name, p.product_id",
            'name' => 'p.name, p.product_id',
            'variety' => $varietyOrder,
            default => $collectionCode === null ? $varietyOrder : 'cp.sort_order, p.name, p.product_id',
        };
        return $this->select(
            'SELECT p.product_id, p.sku, p.name, p.description, p.image_path, p.image_alt, p.product_kind,
                    p.selling_unit, p.variant_label, p.size_label, p.colour_label, p.details_status,
                    p.light_level, p.watering_level, p.toxicity, p.buy_enabled, p.rent_enabled,
                    p.retail_price_minor, p.rental_daily_rate_minor, p.category_id, c.name AS category_name,
                    cp.explanation AS collection_explanation
             FROM products p
             JOIN categories c ON c.category_id = p.category_id
             LEFT JOIN product_families f ON f.family_id = p.family_id
             ' . $collectionJoin . '
             WHERE ' . implode(' AND ', $where)
                . ' ORDER BY ' . $order
                . ' LIMIT ? OFFSET ?',
            $parameters,
        );
    }

    public function count(array $filters, ?string $collectionCode = null): int
    {
        [$where, $parameters] = $this->filteredWhere($filters, $collectionCode);
        $join = $collectionCode === null
            ? ''
            : 'JOIN collection_products cp ON cp.product_id = p.product_id JOIN collections co ON co.collection_id = cp.collection_id';
        $row = $this->select(
            'SELECT COUNT(*) AS total
             FROM products p
             JOIN categories c ON c.category_id = p.category_id
             LEFT JOIN product_families f ON f.family_id = p.family_id
             ' . $join . '
             WHERE ' . implode(' AND ', $where),
            $parameters,
        );
        return (int) ($row[0]['total'] ?? 0);
    }

    /** @return array{0: list<string>, 1: list<int|string>} */
    private function filteredWhere(array $filters, ?string $collectionCode): array
    {
        $where = [
            'p.archived_at IS NULL', 'c.archived_at IS NULL',
            '(f.family_id IS NULL OR f.archived_at IS NULL)',
        ];
        $parameters = [];
        if ($collectionCode !== null) {
            $where[] = 'co.collection_code = ? AND co.state = \'active\'';
            $parameters[] = $collectionCode;
        }
        if (($filters['category_id'] ?? '') !== '') {
            $where[] = 'p.category_id = ?';
            $parameters[] = (int) $filters['category_id'];
        }
        if (($filters['kind'] ?? '') !== '') {
            $where[] = 'p.product_kind = ?';
            $parameters[] = $filters['kind'];
        }
        if (($filters['mode'] ?? '') === 'buy') {
            $where[] = 'p.buy_enabled = 1';
        } elseif (($filters['mode'] ?? '') === 'rent') {
            $where[] = 'p.rent_enabled = 1';
        }
        foreach (['size' => 'size_label', 'colour' => 'colour_label'] as $key => $column) {
            if (($filters[$key] ?? '') !== '') {
                $expression = $key === 'size' ? self::SIZE_LABEL_SQL : "p.$column";
                $where[] = "$expression = ? AND LOWER(TRIM($expression)) <> 'unknown'";
                $parameters[] = $filters[$key];
            }
        }
        foreach (['light_level', 'watering_level', 'toxicity'] as $column) {
            if (($filters[$column] ?? '') !== '') {
                $where[] = "p.product_kind = 'plant' AND p.$column = ? AND p.$column <> 'unknown'";
                $parameters[] = $filters[$column];
            }
        }
        if (($filters['q'] ?? '') !== '') {
            $term = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $filters['q']) . '%';
            $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR c.name LIKE ?)';
            $parameters[] = $term;
            $parameters[] = $term;
            $parameters[] = $term;
        }
        if (($filters['budget_minor'] ?? null) !== null) {
            $budget = (int) $filters['budget_minor'];
            if (($filters['mode'] ?? '') === 'buy') {
                $where[] = 'p.buy_enabled = 1 AND p.retail_price_minor IS NOT NULL AND p.retail_price_minor <= ?';
                $parameters[] = $budget;
            } elseif (($filters['mode'] ?? '') === 'rent') {
                $where[] = 'p.rent_enabled = 1 AND p.rental_daily_rate_minor IS NOT NULL AND p.rental_daily_rate_minor <= ?';
                $parameters[] = $budget;
            } else {
                $where[] = '((p.buy_enabled = 1 AND p.retail_price_minor IS NOT NULL AND p.retail_price_minor <= ?)
                    OR (p.rent_enabled = 1 AND p.rental_daily_rate_minor IS NOT NULL AND p.rental_daily_rate_minor <= ?))';
                $parameters[] = $budget;
                $parameters[] = $budget;
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
        $active = 'p.archived_at IS NULL AND c.archived_at IS NULL AND (f.family_id IS NULL OR f.archived_at IS NULL)';
        $kinds = array_column($this->select(
            "SELECT DISTINCT p.product_kind FROM products p JOIN categories c ON c.category_id = p.category_id
             LEFT JOIN product_families f ON f.family_id = p.family_id WHERE $active ORDER BY p.product_kind",
        ), 'product_kind');
        // Fixed customer size taxonomy; legacy size labels stay visible under "Any size".
        $sizes = ['Small', 'Medium', 'Large'];
        $colours = array_column($this->select(
            "SELECT DISTINCT p.colour_label FROM products p JOIN categories c ON c.category_id = p.category_id
             LEFT JOIN product_families f ON f.family_id = p.family_id
             WHERE $active AND p.colour_label IS NOT NULL AND TRIM(p.colour_label) <> ''
               AND LOWER(TRIM(p.colour_label)) <> 'unknown' ORDER BY p.colour_label",
        ), 'colour_label');
        $options = ['kind' => $kinds, 'mode' => ['buy', 'rent'], 'size' => $sizes, 'colour' => $colours];
        foreach (['light_level', 'watering_level', 'toxicity'] as $column) {
            $options[$column] = array_column($this->select(
                "SELECT DISTINCT p.$column FROM products p JOIN categories c ON c.category_id = p.category_id
                 LEFT JOIN product_families f ON f.family_id = p.family_id
                 WHERE $active AND p.product_kind = 'plant' AND p.$column <> 'unknown' ORDER BY p.$column",
            ), $column);
        }
        return $options;
    }

    public function collections(): array
    {
        return $this->select(
            "SELECT co.collection_code, co.name, co.description,
                    (SELECT COUNT(*) FROM collection_products cp
                     JOIN products p ON p.product_id = cp.product_id
                     JOIN categories c ON c.category_id = p.category_id
                     LEFT JOIN product_families f ON f.family_id = p.family_id
                     WHERE cp.collection_id = co.collection_id AND p.archived_at IS NULL
                       AND c.archived_at IS NULL AND (f.family_id IS NULL OR f.archived_at IS NULL)) AS product_count
             FROM collections co WHERE co.state = 'active' ORDER BY co.name",
        );
    }

    public function collection(string $code): ?array
    {
        return $this->select(
            "SELECT collection_id, collection_code, name, description FROM collections
             WHERE collection_code = ? AND state = 'active'",
            [$code],
        )[0] ?? null;
    }

    /** Active linked definitions and plain-language bought/rented component roles. */
    public function compositions(string $collectionCode): array
    {
        $rows = $this->select(
            "SELECT d.definition_code, d.name AS definition_name, d.arrangement_kind, d.strategy,
                    cc.explanation, x.component_key, x.label, x.component_role, x.permitted_mode,
                    x.source_type, x.required, x.sort_order
             FROM collection_compositions cc
             JOIN collections co ON co.collection_id = cc.collection_id AND co.state = 'active'
             JOIN composition_definitions d ON d.definition_id = cc.definition_id AND d.status = 'active'
             LEFT JOIN composition_definition_components x
               ON x.definition_id = d.definition_id AND x.archived_at IS NULL
             WHERE co.collection_code = ?
               AND (d.strategy <> 'finished_stock' OR EXISTS (
                   SELECT 1 FROM products fp JOIN categories fc ON fc.category_id = fp.category_id
                   LEFT JOIN product_families ff ON ff.family_id = fp.family_id
                   WHERE fp.product_id = d.finished_product_id AND fp.archived_at IS NULL AND fc.archived_at IS NULL
                     AND (ff.family_id IS NULL OR ff.archived_at IS NULL)
               ))
               AND NOT EXISTS (
                   SELECT 1 FROM composition_definition_components required_component
                   WHERE required_component.definition_id = d.definition_id AND required_component.required = 1
                     AND required_component.archived_at IS NULL
                     AND NOT (
                         (required_component.source_type = 'product' AND EXISTS (
                             SELECT 1 FROM composition_component_product_allowlist pa
                             JOIN products ap ON ap.product_id = pa.product_id
                             JOIN categories ac ON ac.category_id = ap.category_id
                             LEFT JOIN product_families af ON af.family_id = ap.family_id
                             WHERE pa.component_id = required_component.component_id AND pa.archived_at IS NULL
                               AND ap.archived_at IS NULL
                               AND ac.archived_at IS NULL AND (af.family_id IS NULL OR af.archived_at IS NULL)
                         ))
                         OR (required_component.source_type = 'family' AND EXISTS (
                             SELECT 1 FROM composition_component_family_allowlist fa
                             JOIN product_families active_family ON active_family.family_id = fa.family_id
                             JOIN products ap ON ap.family_id = active_family.family_id
                             JOIN categories ac ON ac.category_id = ap.category_id
                             WHERE fa.component_id = required_component.component_id AND fa.archived_at IS NULL
                               AND active_family.archived_at IS NULL AND ap.product_kind = active_family.product_kind
                               AND ap.archived_at IS NULL AND ac.archived_at IS NULL
                         ))
                         OR (required_component.source_type = 'compatible_pot' AND EXISTS (
                             SELECT 1 FROM product_compatibilities pc
                             JOIN products ap ON ap.product_id = pc.plant_product_id
                             JOIN categories ac ON ac.category_id = ap.category_id
                             LEFT JOIN product_families af ON af.family_id = ap.family_id
                             JOIN products pot ON pot.product_id = pc.pot_product_id
                             JOIN categories potc ON potc.category_id = pot.category_id
                             LEFT JOIN product_families potf ON potf.family_id = pot.family_id
                             WHERE (
                                 EXISTS (SELECT 1 FROM composition_component_product_allowlist anchor_product
                                         WHERE anchor_product.component_id = required_component.compatibility_anchor_component_id
                                           AND anchor_product.product_id = pc.plant_product_id
                                           AND anchor_product.archived_at IS NULL)
                                 OR EXISTS (SELECT 1 FROM composition_component_family_allowlist anchor_family
                                            WHERE anchor_family.component_id = required_component.compatibility_anchor_component_id
                                              AND anchor_family.family_id = ap.family_id
                                              AND anchor_family.archived_at IS NULL)
                             )
                               AND pc.archived_at IS NULL
                               AND ap.archived_at IS NULL AND ac.archived_at IS NULL
                               AND (af.family_id IS NULL OR af.archived_at IS NULL)
                               AND pot.archived_at IS NULL AND potc.archived_at IS NULL
                               AND (potf.family_id IS NULL OR potf.archived_at IS NULL)
                         ))
                     )
               )
             ORDER BY cc.sort_order, d.name, x.sort_order, x.component_id",
            [$collectionCode],
        );
        $definitions = [];
        foreach ($rows as $row) {
            $code = (string) $row['definition_code'];
            $definitions[$code] ??= [
                'definition_code' => $code, 'name' => $row['definition_name'],
                'arrangement_kind' => $row['arrangement_kind'], 'strategy' => $row['strategy'],
                'explanation' => $row['explanation'], 'purchase_components' => [], 'rental_components' => [],
                'choice_components' => [],
            ];
            if ($row['component_key'] === null) {
                continue;
            }
            $component = ['label' => $row['label'], 'role' => $row['component_role'], 'required' => (bool) $row['required']];
            if ($row['permitted_mode'] === 'sale') {
                $definitions[$code]['purchase_components'][] = $component;
            } elseif ($row['permitted_mode'] === 'rent') {
                $definitions[$code]['rental_components'][] = $component;
            } else {
                $definitions[$code]['choice_components'][] = $component;
            }
        }
        return array_values($definitions);
    }
}
