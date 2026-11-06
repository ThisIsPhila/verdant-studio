<?php

declare(strict_types=1);

namespace Verdant\Compositions;

use Verdant\Persistence\Repository;

/** Persistence for reusable rules, customer saves and immutable checkout snapshots. */
final class CompositionRepository extends Repository
{
    public function definition(string $code, bool $lock = false): ?array
    {
        $definition = $this->select(
            'SELECT definition_id, definition_code, name, arrangement_kind, strategy, finished_product_id, status
             FROM composition_definitions WHERE definition_code = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$code],
        )[0] ?? null;
        if ($definition === null) {
            return null;
        }
        $definition['definition_id'] = (int) $definition['definition_id'];
        $definition['finished_product_id'] = $definition['finished_product_id'] === null ? null : (int) $definition['finished_product_id'];
        $definition['components'] = $this->select(
            'SELECT component_id, component_key, label, component_role, source_type,
                    compatibility_anchor_component_id, required, min_selections, max_selections,
                    min_quantity, max_quantity, permitted_mode, sort_order
             FROM composition_definition_components WHERE definition_id = ? AND archived_at IS NULL
             ORDER BY sort_order, component_id' . ($lock ? ' FOR UPDATE' : ''),
            [$definition['definition_id']],
        );
        foreach ($definition['components'] as &$component) {
            foreach (['component_id', 'min_selections', 'max_selections', 'min_quantity', 'max_quantity', 'sort_order'] as $field) {
                $component[$field] = (int) $component[$field];
            }
            $component['required'] = (bool) $component['required'];
            $component['compatibility_anchor_component_id'] = $component['compatibility_anchor_component_id'] === null
                ? null : (int) $component['compatibility_anchor_component_id'];
            $allowlistSuffix = $lock ? ' FOR UPDATE' : '';
            $component['allowed_product_ids'] = array_map(
                'intval',
                array_column($this->select(
                    'SELECT product_id FROM composition_component_product_allowlist WHERE component_id = ? AND archived_at IS NULL ORDER BY product_id' . $allowlistSuffix,
                    [$component['component_id']],
                ), 'product_id'),
            );
            $component['allowed_family_ids'] = array_map(
                'intval',
                array_column($this->select(
                    'SELECT family_id FROM composition_component_family_allowlist WHERE component_id = ? AND archived_at IS NULL ORDER BY family_id' . $allowlistSuffix,
                    [$component['component_id']],
                ), 'family_id'),
            );
            // Reservation paths use only locking reads before inventory capacity checks. In particular,
            // do not start a REPEATABLE READ snapshot by loading browse-only product options here.
            $component['product_options'] = $lock || $component['source_type'] === 'compatible_pot'
                ? [] : $this->allowedProducts((int) $component['component_id'], (string) $component['source_type']);
            foreach ($component['product_options'] as &$option) {
                foreach (['product_id', 'buy_enabled', 'rent_enabled', 'retail_price_minor', 'rental_daily_rate_minor'] as $field) {
                    if ($option[$field] !== null) {
                        $option[$field] = (int) $option[$field];
                    }
                }
            }
            unset($option);
        }
        unset($component);
        return $definition;
    }

    public function activeDefinitions(): array
    {
        $rows = $this->select("SELECT definition_code FROM composition_definitions WHERE status = 'active' ORDER BY name, definition_id");
        $definitions = [];
        foreach ($rows as $row) {
            $definition = $this->definition((string) $row['definition_code']);
            if ($definition !== null && $definition['status'] === 'active') {
                $definitions[] = $definition;
            }
        }
        return $definitions;
    }

    public function compatibleProducts(int $plantProductId): array
    {
        return $this->select(
            "SELECT p.product_id, p.sku, p.name, p.product_kind, p.family_id, p.accessory_subtype, p.variant_label, p.size_label, p.colour_label,
                    p.image_path, p.image_alt,
                    p.buy_enabled, p.rent_enabled, p.retail_price_minor, p.rental_daily_rate_minor, x.source_status
             FROM product_compatibilities x JOIN products p ON p.product_id = x.pot_product_id
             JOIN categories c ON c.category_id = p.category_id
             WHERE x.plant_product_id = ? AND x.archived_at IS NULL AND p.archived_at IS NULL
               AND c.archived_at IS NULL AND p.product_kind = 'accessory' AND p.accessory_subtype = 'pot'
             ORDER BY p.name, p.variant_label, p.product_id",
            [$plantProductId],
        );
    }

    /** Read only an active pot SKU submitted before a plant-change refresh. */
    public function activePotForPresentation(int $productId): ?array
    {
        return $this->select(
            "SELECT p.product_id, p.sku, p.name, p.product_kind, p.family_id, p.accessory_subtype,
                    p.variant_label, p.size_label, p.colour_label, p.image_path, p.image_alt,
                    p.archived_at, c.archived_at AS category_archived_at
             FROM products p JOIN categories c ON c.category_id = p.category_id
             WHERE p.product_id = ? AND p.product_kind = 'accessory' AND p.accessory_subtype = 'pot'
               AND p.archived_at IS NULL AND c.archived_at IS NULL",
            [$productId],
        )[0] ?? null;
    }

    public function savedList(int $customerId): array
    {
        return $this->select(
            "SELECT saved_composition_id, definition_id, definition_code_snapshot, definition_name_snapshot,
                    name, state, created_at, updated_at
             FROM saved_compositions WHERE customer_id = ? AND state = 'active'
             ORDER BY updated_at DESC, saved_composition_id DESC",
            [$customerId],
        );
    }

    public function archiveSaved(int $customerId, int $savedId): bool
    {
        return $this->execute(
            "UPDATE saved_compositions SET state = 'archived' WHERE customer_id = ? AND saved_composition_id = ? AND state = 'active'",
            [$customerId, $savedId],
        ) === 1;
    }

    private function allowedProducts(int $componentId, string $sourceType): array
    {
        if ($sourceType === 'product') {
            return $this->select(
                'SELECT p.product_id, p.sku, p.name, p.product_kind, p.family_id, p.accessory_subtype, p.variant_label, p.size_label, p.colour_label,
                        p.image_path, p.image_alt,
                        p.buy_enabled, p.rent_enabled, p.retail_price_minor, p.rental_daily_rate_minor
                 FROM composition_component_product_allowlist a JOIN products p ON p.product_id = a.product_id
                 JOIN categories c ON c.category_id = p.category_id
                 LEFT JOIN product_families f ON f.family_id = p.family_id
                 WHERE a.component_id = ? AND a.archived_at IS NULL AND p.archived_at IS NULL AND c.archived_at IS NULL
                   AND (p.family_id IS NULL OR (f.archived_at IS NULL AND f.product_kind = p.product_kind))
                 ORDER BY p.name, p.variant_label, p.product_id',
                [$componentId],
            );
        }
        if ($sourceType === 'family') {
            return $this->select(
                'SELECT p.product_id, p.sku, p.name, p.product_kind, p.family_id, p.accessory_subtype, p.variant_label, p.size_label, p.colour_label,
                        p.image_path, p.image_alt,
                        p.buy_enabled, p.rent_enabled, p.retail_price_minor, p.rental_daily_rate_minor
                 FROM composition_component_family_allowlist a JOIN product_families f ON f.family_id = a.family_id
                 JOIN products p ON p.family_id = f.family_id JOIN categories c ON c.category_id = p.category_id
                 WHERE a.component_id = ? AND a.archived_at IS NULL AND f.archived_at IS NULL AND p.archived_at IS NULL AND c.archived_at IS NULL
                   AND p.product_kind = f.product_kind
                 ORDER BY p.name, p.variant_label, p.product_id',
                [$componentId],
            );
        }
        return [];
    }

    public function activeCustomer(int $customerId, bool $lock = false): bool
    {
        $row = $this->select(
            "SELECT customer_id FROM customers WHERE customer_id = ? AND role = 'customer' AND account_state = 'active'" . ($lock ? ' FOR UPDATE' : ''),
            [$customerId],
        )[0] ?? null;
        return $row !== null;
    }

    public function product(int $productId, bool $lock = false): ?array
    {
        return $this->select(
            'SELECT p.product_id, p.sku, p.name, p.product_kind, p.family_id, p.accessory_subtype,
                    p.buy_enabled, p.rent_enabled, p.retail_price_minor, p.rental_daily_rate_minor,
                    p.archived_at, c.archived_at AS category_archived_at,
                    f.archived_at AS family_archived_at, f.product_kind AS family_product_kind
             FROM products p JOIN categories c ON c.category_id = p.category_id
             LEFT JOIN product_families f ON f.family_id = p.family_id
             WHERE p.product_id = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$productId],
        )[0] ?? null;
    }

    public function explicitlyCompatible(int $plantProductId, int $potProductId, bool $lock = false): bool
    {
        return $this->select(
            'SELECT compatibility_id FROM product_compatibilities
             WHERE plant_product_id = ? AND pot_product_id = ? AND archived_at IS NULL' . ($lock ? ' FOR UPDATE' : ''),
            [$plantProductId, $potProductId],
        ) !== [];
    }

    public function savedForCustomer(int $customerId, int $savedId, bool $lock = false): ?array
    {
        $saved = $this->select(
            'SELECT saved_composition_id, definition_id, definition_code_snapshot, definition_name_snapshot, name, state
             FROM saved_compositions WHERE customer_id = ? AND saved_composition_id = ? AND state = \'active\'' . ($lock ? ' FOR UPDATE' : ''),
            [$customerId, $savedId],
        )[0] ?? null;
        if ($saved === null) {
            return null;
        }
        $saved['saved_composition_id'] = (int) $saved['saved_composition_id'];
        $saved['definition_id'] = (int) $saved['definition_id'];
        $saved['components'] = $this->select(
            'SELECT component_key_snapshot AS component_key, component_label_snapshot,
                    product_id, product_name_snapshot, sku_snapshot, mode, quantity,
                    start_date, end_date_exclusive, retail_price_minor_snapshot, rental_daily_rate_minor_snapshot
             FROM saved_composition_components WHERE saved_composition_id = ? ORDER BY saved_component_id',
            [$saved['saved_composition_id']],
        );
        foreach ($saved['components'] as &$component) {
            $component['product_id'] = (int) $component['product_id'];
            $component['quantity'] = (int) $component['quantity'];
            $component['retail_price_minor_snapshot'] = $component['retail_price_minor_snapshot'] === null
                ? null : (int) $component['retail_price_minor_snapshot'];
            $component['rental_daily_rate_minor_snapshot'] = $component['rental_daily_rate_minor_snapshot'] === null
                ? null : (int) $component['rental_daily_rate_minor_snapshot'];
            if ($component['mode'] === 'rent') {
                $component['end_date_inclusive'] = (new \DateTimeImmutable((string) $component['end_date_exclusive']))
                    ->modify('-1 day')->format('Y-m-d');
            }
            unset($component['end_date_exclusive']);
        }
        unset($component);
        return $saved;
    }

    public function insertSaved(int $customerId, array $definition, string $name): int
    {
        $this->execute(
            'INSERT INTO saved_compositions
                (customer_id, definition_id, definition_code_snapshot, definition_name_snapshot, name)
             VALUES (?, ?, ?, ?, ?)',
            [$customerId, $definition['definition_id'], $definition['definition_code'], $definition['name'], $name],
        );
        return (int) $this->connection->insert_id;
    }

    public function insertSavedComponent(int $savedId, array $selection, array $product): void
    {
        $sale = $selection['mode'] === 'sale';
        $this->execute(
            'INSERT INTO saved_composition_components
                (saved_composition_id, component_key_snapshot, component_label_snapshot,
                 product_id, product_name_snapshot, sku_snapshot, mode, quantity,
                 start_date, end_date_exclusive, retail_price_minor_snapshot, rental_daily_rate_minor_snapshot)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $savedId, $selection['component_key'], $selection['component_label'],
                $selection['product_id'], $product['name'], $product['sku'], $selection['mode'], $selection['quantity'],
                $sale ? null : $selection['start_date'], $sale ? null : $selection['end_date_exclusive'],
                $sale ? $product['retail_price_minor'] : null, $sale ? null : $product['rental_daily_rate_minor'],
            ],
        );
    }

    public function checkoutsForCustomer(int $customerId): array
    {
        return $this->select(
            'SELECT composition_checkout_id, definition_name_snapshot, total_minor, state, created_at
             FROM composition_checkouts WHERE customer_id = ? ORDER BY created_at DESC, composition_checkout_id DESC',
            [$customerId],
        );
    }

    public function checkoutForCustomer(int $customerId, int $checkoutId): ?array
    {
        $row = $this->select(
            'SELECT cc.composition_checkout_id, cc.checkout_key, cc.definition_code_snapshot, cc.definition_name_snapshot,
                    cc.order_id, cc.order_role, cc.rental_id, cc.state, cc.total_minor, cc.currency,
                    p.provider_reference AS payment_reference, p.state AS payment_state
             FROM composition_checkouts cc
             LEFT JOIN order_payments p ON p.order_payment_id = cc.order_payment_id AND p.order_id = cc.order_id
                 AND p.provider_code = \'paystack\'
             WHERE cc.customer_id = ? AND cc.composition_checkout_id = ?',
            [$customerId, $checkoutId],
        )[0] ?? null;
        if ($row !== null) {
            $row['lines'] = $this->checkoutLines($checkoutId);
        }
        return $row;
    }

    public function checkoutForCustomerKey(int $customerId, string $key, bool $lock = false): ?array
    {
        $checkout = $this->select(
            'SELECT composition_checkout_id, definition_id, definition_code_snapshot, definition_name_snapshot,
                    selection_sha256, order_id, order_role, rental_id, state, total_minor, currency
             FROM composition_checkouts WHERE customer_id = ? AND checkout_key = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$customerId, $key],
        )[0] ?? null;
        if ($checkout !== null) {
            foreach (['composition_checkout_id', 'definition_id', 'order_id', 'total_minor'] as $field) {
                $checkout[$field] = (int) $checkout[$field];
            }
            $checkout['rental_id'] = $checkout['rental_id'] === null ? null : (int) $checkout['rental_id'];
            $checkout['lines'] = $this->checkoutLines((int) $checkout['composition_checkout_id']);
            $checkout['existing'] = true;
        }
        return $checkout;
    }

    public function createOrderAnchor(int $customerId, string $checkoutKey): int
    {
        $this->execute(
            "INSERT INTO sales_orders(customer_id, checkout_key, state) VALUES (?, ?, 'pending_payment')",
            [$customerId, $checkoutKey],
        );
        return (int) $this->connection->insert_id;
    }

    public function createRental(int $customerId, string $checkoutKey): int
    {
        $this->execute(
            "INSERT INTO rentals(customer_id, checkout_key, state) VALUES (?, ?, 'pending_payment')",
            [$customerId, $checkoutKey],
        );
        return (int) $this->connection->insert_id;
    }

    public function insertSaleLine(int $orderId, array $line): int
    {
        $this->execute(
            'INSERT INTO order_items(order_id, product_id, product_name_snapshot, sku_snapshot, quantity, unit_price_minor)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$orderId, $line['product_id'], $line['product_name_snapshot'], $line['sku_snapshot'], $line['quantity'], $line['unit_price_minor']],
        );
        $itemId = (int) $this->connection->insert_id;
        $this->execute("INSERT INTO retail_holds(order_item_id, quantity, state) VALUES (?, ?, 'active')", [$itemId, $line['quantity']]);
        return $itemId;
    }

    public function insertRentalLine(int $rentalId, array $line): int
    {
        $this->execute(
            'INSERT INTO rental_items(rental_id, product_id, product_name_snapshot, sku_snapshot, quantity,
                                     start_date, end_date_exclusive, daily_rate_minor)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $rentalId, $line['product_id'], $line['product_name_snapshot'], $line['sku_snapshot'], $line['quantity'],
                $line['start_date'], $line['end_date_exclusive'], $line['daily_rate_minor'],
            ],
        );
        $itemId = (int) $this->connection->insert_id;
        $this->execute("INSERT INTO rental_allocations(rental_item_id, quantity, state) VALUES (?, ?, 'held')", [$itemId, $line['quantity']]);
        return $itemId;
    }

    public function insertCheckout(array $checkout): int
    {
        $this->execute(
            'INSERT INTO composition_checkouts
                (customer_id, definition_id, definition_code_snapshot, definition_name_snapshot,
                 checkout_key, selection_sha256, order_id, order_role, rental_id, state, total_minor, currency)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending_payment\', ?, \'ZAR\')',
            [
                $checkout['customer_id'], $checkout['definition_id'], $checkout['definition_code'], $checkout['definition_name'],
                $checkout['checkout_key'], $checkout['selection_sha256'], $checkout['order_id'], $checkout['order_role'],
                $checkout['rental_id'], $checkout['total_minor'],
            ],
        );
        return (int) $this->connection->insert_id;
    }

    public function insertCheckoutLine(int $checkoutId, array $line, ?int $orderItemId, ?int $rentalItemId): void
    {
        $this->execute(
            'INSERT INTO composition_checkout_lines
                (composition_checkout_id, product_id, product_name_snapshot, sku_snapshot, mode, quantity,
                 start_date, end_date_exclusive, unit_price_minor, daily_rate_minor, order_item_id, rental_item_id, components_snapshot)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $checkoutId, $line['product_id'], $line['product_name_snapshot'], $line['sku_snapshot'], $line['mode'], $line['quantity'],
                $line['start_date'], $line['end_date_exclusive'], $line['unit_price_minor'], $line['daily_rate_minor'],
                $orderItemId, $rentalItemId, json_encode($line['components'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ],
        );
    }

    public function checkoutLines(int $checkoutId): array
    {
        $lines = $this->select(
            'SELECT composition_checkout_line_id, product_id, product_name_snapshot, sku_snapshot, mode, quantity,
                    start_date, end_date_exclusive, unit_price_minor, daily_rate_minor, order_item_id, rental_item_id, components_snapshot
             FROM composition_checkout_lines WHERE composition_checkout_id = ? ORDER BY mode, product_id, start_date, composition_checkout_line_id',
            [$checkoutId],
        );
        foreach ($lines as &$line) {
            foreach (['composition_checkout_line_id', 'product_id', 'quantity'] as $field) {
                $line[$field] = (int) $line[$field];
            }
            foreach (['unit_price_minor', 'daily_rate_minor', 'order_item_id', 'rental_item_id'] as $field) {
                $line[$field] = $line[$field] === null ? null : (int) $line[$field];
            }
            $line['components_snapshot'] = json_decode((string) $line['components_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        }
        unset($line);
        return $lines;
    }
}
