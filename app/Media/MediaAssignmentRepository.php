<?php

declare(strict_types=1);

namespace Verdant\Media;

use Verdant\Persistence\Repository;

final class MediaAssignmentRepository extends Repository implements MediaBindingLookup
{
    public function product(int $productId, bool $lock = false): ?array
    {
        return $this->select(
            'SELECT p.product_id, p.sku, p.product_kind, p.accessory_subtype, p.archived_at,
                    c.name AS category_name, c.archived_at AS category_archived_at
             FROM products p JOIN categories c ON c.category_id = p.category_id WHERE p.product_id = ?'
            . ($lock ? ' FOR UPDATE' : ''), [$productId],
        )[0] ?? null;
    }

    public function productBySku(string $sku, bool $lock = false): ?array
    {
        return $this->select(
            'SELECT p.product_id, p.sku, p.product_kind, p.accessory_subtype, p.archived_at,
                    c.name AS category_name, c.archived_at AS category_archived_at
             FROM products p JOIN categories c ON c.category_id = p.category_id WHERE p.sku = ?'
            . ($lock ? ' FOR UPDATE' : ''), [$sku],
        )[0] ?? null;
    }

    public function isActiveAdministrator(int $customerId): bool
    {
        return $this->select(
            "SELECT customer_id FROM customers WHERE customer_id = ? AND role = 'admin' AND account_state = 'active' AND archived_at IS NULL",
            [$customerId],
        ) !== [];
    }

    public function bindingForTarget(string $targetKey, string $kind, bool $lock = false): ?array
    {
        $row = $this->select(
            'SELECT * FROM product_media_bindings WHERE target_key = ? AND media_kind = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$targetKey, $kind],
        )[0] ?? null;
        return $row === null ? null : $this->normalize($row);
    }

    public function binding(int $bindingId, bool $lock = false): ?array
    {
        $row = $this->select(
            'SELECT * FROM product_media_bindings WHERE binding_id = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$bindingId],
        )[0] ?? null;
        return $row === null ? null : $this->normalize($row);
    }

    public function insertBinding(array $data, ?int $actorId): int
    {
        $this->execute(
            'INSERT INTO product_media_bindings
                (product_id, target_key, media_kind, asset_id, visible_height_mm, pot_height_mm, measurement_status, camera_scale, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$data['product_id'], $data['target_key'], $data['media_kind'], $data['asset_id'],
                $data['visible_height_mm'], $data['pot_height_mm'], $data['measurement_status'], $data['camera_scale'], $actorId],
        );
        return (int) $this->connection->insert_id;
    }

    public function updateBinding(int $bindingId, int $version, array $data, int $actorId): bool
    {
        return $this->execute(
            'UPDATE product_media_bindings
             SET asset_id = ?, visible_height_mm = ?, pot_height_mm = ?, measurement_status = ?, camera_scale = ?,
                 lock_version = lock_version + 1, updated_by = ?
             WHERE binding_id = ? AND lock_version = ? AND archived_at IS NULL',
            [$data['asset_id'], $data['visible_height_mm'], $data['pot_height_mm'], $data['measurement_status'], $data['camera_scale'], $actorId, $bindingId, $version],
        ) === 1;
    }

    public function changeArchive(int $bindingId, int $version, bool $archived, int $actorId): bool
    {
        $state = $archived ? 'UTC_TIMESTAMP()' : 'NULL';
        return $this->execute(
            "UPDATE product_media_bindings SET archived_at = $state, lock_version = lock_version + 1, updated_by = ?
             WHERE binding_id = ? AND lock_version = ? AND " . ($archived ? 'archived_at IS NULL' : 'archived_at IS NOT NULL'),
            [$actorId, $bindingId, $version],
        ) === 1;
    }

    public function addHistory(int $bindingId, ?int $actorId, string $action, ?array $previous, array $current): void
    {
        $this->execute(
            'INSERT INTO product_media_history
                (binding_id, actor_customer_id, action, previous_asset_id, new_asset_id,
                 previous_visible_height_mm, new_visible_height_mm, previous_pot_height_mm, new_pot_height_mm,
                 previous_measurement_status, new_measurement_status, previous_camera_scale, new_camera_scale, resulting_version)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$bindingId, $actorId, $action, $previous['asset_id'] ?? null, $current['asset_id'],
                $previous['visible_height_mm'] ?? null, $current['visible_height_mm'],
                $previous['pot_height_mm'] ?? null, $current['pot_height_mm'],
                $previous['measurement_status'] ?? null, $current['measurement_status'],
                $previous['camera_scale'] ?? null, $current['camera_scale'], (int) $current['lock_version']],
        );
    }

    public function history(int $bindingId): array
    {
        return $this->select('SELECT * FROM product_media_history WHERE binding_id = ? ORDER BY event_id', [$bindingId]);
    }

    /** Active plant and pot products available as administrator assignment targets. */
    public function adminProducts(): array
    {
        return $this->select(
            "SELECT p.product_id, p.sku, p.name, p.product_kind, p.accessory_subtype,
                    p.variant_label, p.size_label, p.colour_label
             FROM products p JOIN categories c ON c.category_id = p.category_id
             LEFT JOIN product_families f ON f.family_id = p.family_id
             WHERE p.archived_at IS NULL AND c.archived_at IS NULL
               AND (p.product_kind = 'plant'
                    OR (p.product_kind = 'accessory' AND (p.accessory_subtype = 'pot' OR c.name = 'Planters & pots')))
               AND (p.family_id IS NULL OR f.archived_at IS NULL)
             ORDER BY p.product_kind, p.name, p.variant_label, p.product_id",
        );
    }

    /** Current and archived assignments stay visible for reversible lifecycle management. */
    public function adminBindings(): array
    {
        $rows = $this->select(
            'SELECT b.*, p.sku, p.name AS product_name, p.product_kind, p.archived_at AS product_archived_at,
                    c.archived_at AS category_archived_at
             FROM product_media_bindings b
             LEFT JOIN products p ON p.product_id = b.product_id
             LEFT JOIN categories c ON c.category_id = p.category_id
             ORDER BY b.archived_at IS NOT NULL, b.media_kind, b.target_key, b.binding_id',
        );
        return array_map(fn (array $row): array => $this->normalize($row), $rows);
    }

    private function normalize(array $row): array
    {
        foreach (['binding_id', 'product_id', 'visible_height_mm', 'pot_height_mm', 'lock_version', 'updated_by'] as $field) {
            if ($row[$field] !== null) $row[$field] = (int) $row[$field];
        }
        $row['camera_scale'] = isset($row['camera_scale']) ? (float) $row['camera_scale'] : 1.0;
        return $row;
    }
}
