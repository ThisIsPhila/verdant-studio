<?php

declare(strict_types=1);

namespace Verdant\Admin;

use Verdant\Persistence\Repository;

/** Protected-edit persistence; child rows are archived to retain checkout history. */
final class CompositionAdminRepository extends Repository
{
    public function definitions(): array
    {
        $definitions = $this->select(
            'SELECT d.definition_id, d.definition_code, d.name, d.arrangement_kind, d.strategy, d.finished_product_id,
                    d.status, d.created_at, d.updated_at,
                    (SELECT COUNT(*) FROM saved_compositions s WHERE s.definition_id = d.definition_id) AS saved_count,
                    (SELECT COUNT(*) FROM composition_checkouts c WHERE c.definition_id = d.definition_id) AS checkout_count
             FROM composition_definitions d ORDER BY d.status, d.name, d.definition_id',
        );
        foreach ($definitions as &$definition) {
            $definition['definition_id'] = (int) $definition['definition_id'];
            $definition['finished_product_id'] = $definition['finished_product_id'] === null ? null : (int) $definition['finished_product_id'];
            $definition['components'] = $this->components((int) $definition['definition_id']);
        }
        unset($definition);
        return $definitions;
    }

    public function definition(int $id, bool $lock = false): ?array
    {
        $row = $this->select(
            'SELECT definition_id, definition_code, name, arrangement_kind, strategy, finished_product_id, status
             FROM composition_definitions WHERE definition_id = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$id],
        )[0] ?? null;
        if ($row === null) {
            return null;
        }
        $row['definition_id'] = (int) $row['definition_id'];
        $row['finished_product_id'] = $row['finished_product_id'] === null ? null : (int) $row['finished_product_id'];
        $row['components'] = $this->components($id, $lock);
        return $row;
    }

    public function products(): array
    {
        return $this->select(
            'SELECT p.product_id, p.sku, p.name, p.product_kind, p.accessory_subtype, p.archived_at,
                    c.archived_at AS category_archived_at
             FROM products p JOIN categories c ON c.category_id = p.category_id
             ORDER BY p.archived_at IS NOT NULL, p.name, p.sku',
        );
    }

    public function families(): array
    {
        return $this->select(
            'SELECT family_id, family_code, name, product_kind, archived_at FROM product_families
             ORDER BY archived_at IS NOT NULL, name, family_id',
        );
    }

    public function product(int $id): ?array
    {
        return $this->select(
            'SELECT p.product_id, p.product_kind, p.accessory_subtype, p.archived_at,
                    c.archived_at AS category_archived_at
             FROM products p JOIN categories c ON c.category_id = p.category_id WHERE p.product_id = ?',
            [$id],
        )[0] ?? null;
    }

    public function family(int $id): ?array
    {
        return $this->select('SELECT family_id, product_kind, archived_at FROM product_families WHERE family_id = ?', [$id])[0] ?? null;
    }

    public function familyProductRoles(int $id): array
    {
        return $this->select(
            'SELECT p.product_kind, p.accessory_subtype FROM products p JOIN categories c ON c.category_id = p.category_id
             WHERE p.family_id = ? AND p.archived_at IS NULL AND c.archived_at IS NULL ORDER BY p.product_id',
            [$id],
        );
    }

    public function updateDefinition(int $id, string $name, string $status): void
    {
        $this->execute('UPDATE composition_definitions SET name = ?, status = ? WHERE definition_id = ?', [$name, $status, $id]);
    }

    public function upsertComponent(int $definitionId, ?int $componentId, array $values, ?int $anchorId): int
    {
        if ($componentId === null) {
            $this->execute(
                'INSERT INTO composition_definition_components
                    (definition_id, component_key, label, component_role, source_type, compatibility_anchor_component_id,
                     required, min_selections, max_selections, min_quantity, max_quantity, permitted_mode, archived_at, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$definitionId, $values['component_key'], $values['label'], $values['component_role'], $values['source_type'],
                    $anchorId, $values['required'], $values['min_selections'], $values['max_selections'], $values['min_quantity'],
                    $values['max_quantity'], $values['permitted_mode'], $values['archived_at'], $values['sort_order']],
            );
            return (int) $this->connection->insert_id;
        }
        $this->execute(
            'UPDATE composition_definition_components SET component_key = ?, label = ?, component_role = ?, source_type = ?,
                compatibility_anchor_component_id = ?, required = ?, min_selections = ?, max_selections = ?, min_quantity = ?,
                max_quantity = ?, permitted_mode = ?, archived_at = ?, sort_order = ?
             WHERE definition_id = ? AND component_id = ?',
            [$values['component_key'], $values['label'], $values['component_role'], $values['source_type'], $anchorId,
                $values['required'], $values['min_selections'], $values['max_selections'], $values['min_quantity'],
                $values['max_quantity'], $values['permitted_mode'], $values['archived_at'], $values['sort_order'],
                $definitionId, $componentId],
        );
        return $componentId;
    }

    public function syncProductAllowlist(int $componentId, array $productIds): void
    {
        $existing = array_fill_keys(array_map('intval', array_column(
            $this->select('SELECT product_id FROM composition_component_product_allowlist WHERE component_id = ?', [$componentId]),
            'product_id',
        )), true);
        foreach ($productIds as $productId) {
            if (isset($existing[$productId])) {
                $this->execute(
                    'UPDATE composition_component_product_allowlist SET archived_at = NULL WHERE component_id = ? AND product_id = ?',
                    [$componentId, $productId],
                );
                unset($existing[$productId]);
            } else {
                $this->execute('INSERT INTO composition_component_product_allowlist(component_id, product_id) VALUES(?, ?)', [$componentId, $productId]);
            }
        }
        foreach (array_keys($existing) as $productId) {
            $this->execute(
                'UPDATE composition_component_product_allowlist SET archived_at = UTC_TIMESTAMP() WHERE component_id = ? AND product_id = ?',
                [$componentId, (int) $productId],
            );
        }
    }

    public function syncFamilyAllowlist(int $componentId, array $familyIds): void
    {
        $existing = array_fill_keys(array_map('intval', array_column(
            $this->select('SELECT family_id FROM composition_component_family_allowlist WHERE component_id = ?', [$componentId]),
            'family_id',
        )), true);
        foreach ($familyIds as $familyId) {
            if (isset($existing[$familyId])) {
                $this->execute(
                    'UPDATE composition_component_family_allowlist SET archived_at = NULL WHERE component_id = ? AND family_id = ?',
                    [$componentId, $familyId],
                );
                unset($existing[$familyId]);
            } else {
                $this->execute('INSERT INTO composition_component_family_allowlist(component_id, family_id) VALUES(?, ?)', [$componentId, $familyId]);
            }
        }
        foreach (array_keys($existing) as $familyId) {
            $this->execute(
                'UPDATE composition_component_family_allowlist SET archived_at = UTC_TIMESTAMP() WHERE component_id = ? AND family_id = ?',
                [$componentId, (int) $familyId],
            );
        }
    }

    private function components(int $definitionId, bool $lock = false): array
    {
        $rows = $this->select(
            'SELECT component_id, component_key, label, component_role, source_type, compatibility_anchor_component_id,
                    required, min_selections, max_selections, min_quantity, max_quantity, permitted_mode, archived_at, sort_order
             FROM composition_definition_components WHERE definition_id = ? ORDER BY sort_order, component_id' . ($lock ? ' FOR UPDATE' : ''),
            [$definitionId],
        );
        foreach ($rows as &$row) {
            foreach (['component_id', 'compatibility_anchor_component_id', 'min_selections', 'max_selections', 'min_quantity', 'max_quantity', 'sort_order'] as $field) {
                $row[$field] = $row[$field] === null ? null : (int) $row[$field];
            }
            $row['required'] = (bool) $row['required'];
            $row['product_allowlist'] = $this->select(
                'SELECT a.product_id, a.archived_at, p.sku, p.name FROM composition_component_product_allowlist a
                 JOIN products p ON p.product_id = a.product_id WHERE a.component_id = ? ORDER BY p.name, p.sku' . ($lock ? ' FOR UPDATE' : ''),
                [$row['component_id']],
            );
            foreach ($row['product_allowlist'] as &$allowed) {
                $allowed['product_id'] = (int) $allowed['product_id'];
            }
            unset($allowed);
            $row['family_allowlist'] = $this->select(
                'SELECT a.family_id, a.archived_at, f.family_code, f.name FROM composition_component_family_allowlist a
                 JOIN product_families f ON f.family_id = a.family_id WHERE a.component_id = ? ORDER BY f.name, f.family_code' . ($lock ? ' FOR UPDATE' : ''),
                [$row['component_id']],
            );
            foreach ($row['family_allowlist'] as &$allowed) {
                $allowed['family_id'] = (int) $allowed['family_id'];
            }
            unset($allowed);
        }
        unset($row);
        return $rows;
    }
}
