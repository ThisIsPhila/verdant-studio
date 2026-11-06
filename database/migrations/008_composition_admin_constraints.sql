-- Keeps the reviewed 007 migration immutable while enabling reversible admin edits.

ALTER TABLE composition_definition_components
    ADD COLUMN archived_at DATETIME NULL AFTER permitted_mode,
    ADD KEY ix_composition_components_active (definition_id, archived_at, sort_order, component_id);

ALTER TABLE composition_component_product_allowlist
    ADD COLUMN archived_at DATETIME NULL AFTER product_id,
    ADD KEY ix_composition_product_allowlist_active (component_id, archived_at, product_id);

ALTER TABLE composition_component_family_allowlist
    ADD COLUMN archived_at DATETIME NULL AFTER family_id,
    ADD KEY ix_composition_family_allowlist_active (component_id, archived_at, family_id);

ALTER TABLE saved_composition_components
    DROP CHECK chk_saved_components_mode_snapshot,
    ADD CONSTRAINT chk_saved_components_mode_snapshot CHECK (
        (mode = 'sale' AND start_date IS NULL AND end_date_exclusive IS NULL
            AND retail_price_minor_snapshot IS NOT NULL AND retail_price_minor_snapshot >= 0
            AND rental_daily_rate_minor_snapshot IS NULL)
        OR (mode = 'rent' AND start_date IS NOT NULL AND end_date_exclusive IS NOT NULL AND end_date_exclusive > start_date
            AND retail_price_minor_snapshot IS NULL
            AND rental_daily_rate_minor_snapshot IS NOT NULL AND rental_daily_rate_minor_snapshot >= 0)
    );

ALTER TABLE composition_checkout_lines
    DROP CHECK chk_composition_checkout_line_leg,
    ADD CONSTRAINT chk_composition_checkout_line_leg CHECK (
        (mode = 'sale' AND start_date IS NULL AND end_date_exclusive IS NULL
            AND unit_price_minor IS NOT NULL AND unit_price_minor >= 0 AND daily_rate_minor IS NULL
            AND order_item_id IS NOT NULL AND rental_item_id IS NULL)
        OR (mode = 'rent' AND start_date IS NOT NULL AND end_date_exclusive IS NOT NULL AND end_date_exclusive > start_date
            AND unit_price_minor IS NULL
            AND daily_rate_minor IS NOT NULL AND daily_rate_minor >= 0
            AND order_item_id IS NULL AND rental_item_id IS NOT NULL)
    );
