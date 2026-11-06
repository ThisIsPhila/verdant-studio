-- Persist allowlisted composition definitions, customer saves and atomic checkout snapshots.
CREATE TABLE composition_definitions (
    definition_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    definition_code VARCHAR(80) NOT NULL,
    name VARCHAR(180) NOT NULL,
    arrangement_kind ENUM('plant_arrangement', 'floral_arrangement') NOT NULL,
    strategy ENUM('fixed', 'custom', 'finished_stock') NOT NULL,
    finished_product_id BIGINT UNSIGNED NULL,
    status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (definition_id),
    UNIQUE KEY uq_composition_definitions_code (definition_code),
    KEY ix_composition_definitions_active (status, arrangement_kind, name),
    CONSTRAINT fk_composition_definitions_finished_product
        FOREIGN KEY (finished_product_id) REFERENCES products (product_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_composition_definitions_finished_stock CHECK (
        (strategy = 'finished_stock' AND finished_product_id IS NOT NULL)
        OR (strategy <> 'finished_stock' AND finished_product_id IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE composition_definition_components (
    component_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    definition_id BIGINT UNSIGNED NOT NULL,
    component_key VARCHAR(64) NOT NULL,
    label VARCHAR(120) NOT NULL,
    component_role ENUM('plant', 'pot', 'flower', 'foliage', 'container', 'other') NOT NULL,
    source_type ENUM('product', 'family', 'compatible_pot') NOT NULL,
    compatibility_anchor_component_id BIGINT UNSIGNED NULL,
    required BOOLEAN NOT NULL DEFAULT TRUE,
    min_selections SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    max_selections SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    min_quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    max_quantity SMALLINT UNSIGNED NOT NULL DEFAULT 20,
    permitted_mode ENUM('sale', 'rent', 'either') NOT NULL DEFAULT 'sale',
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (component_id),
    UNIQUE KEY uq_composition_components_key (definition_id, component_key),
    KEY ix_composition_components_definition (definition_id, sort_order, component_id),
    CONSTRAINT fk_composition_components_definition
        FOREIGN KEY (definition_id) REFERENCES composition_definitions (definition_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_composition_components_anchor
        FOREIGN KEY (compatibility_anchor_component_id) REFERENCES composition_definition_components (component_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_composition_components_selection_count CHECK (
        min_selections > 0 AND max_selections >= min_selections
    ),
    CONSTRAINT chk_composition_components_quantity CHECK (
        min_quantity > 0 AND max_quantity >= min_quantity
    ),
    CONSTRAINT chk_composition_components_compatibility_anchor CHECK (
        (source_type = 'compatible_pot' AND compatibility_anchor_component_id IS NOT NULL)
        OR (source_type <> 'compatible_pot' AND compatibility_anchor_component_id IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE composition_component_product_allowlist (
    component_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (component_id, product_id),
    KEY ix_composition_product_allowlist_product (product_id),
    CONSTRAINT fk_composition_product_allowlist_component
        FOREIGN KEY (component_id) REFERENCES composition_definition_components (component_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_composition_product_allowlist_product
        FOREIGN KEY (product_id) REFERENCES products (product_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE composition_component_family_allowlist (
    component_id BIGINT UNSIGNED NOT NULL,
    family_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (component_id, family_id),
    KEY ix_composition_family_allowlist_family (family_id),
    CONSTRAINT fk_composition_family_allowlist_component
        FOREIGN KEY (component_id) REFERENCES composition_definition_components (component_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_composition_family_allowlist_family
        FOREIGN KEY (family_id) REFERENCES product_families (family_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE saved_compositions (
    saved_composition_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    definition_id BIGINT UNSIGNED NOT NULL,
    definition_code_snapshot VARCHAR(80) NOT NULL,
    definition_name_snapshot VARCHAR(180) NOT NULL,
    name VARCHAR(120) NOT NULL,
    state ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (saved_composition_id),
    KEY ix_saved_compositions_customer (customer_id, state, updated_at),
    CONSTRAINT fk_saved_compositions_customer
        FOREIGN KEY (customer_id) REFERENCES customers (customer_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_saved_compositions_definition
        FOREIGN KEY (definition_id) REFERENCES composition_definitions (definition_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE saved_composition_components (
    saved_component_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    saved_composition_id BIGINT UNSIGNED NOT NULL,
    component_key_snapshot VARCHAR(64) NOT NULL,
    component_label_snapshot VARCHAR(120) NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    product_name_snapshot VARCHAR(180) NOT NULL,
    sku_snapshot VARCHAR(64) NOT NULL,
    mode ENUM('sale', 'rent') NOT NULL,
    quantity INT NOT NULL,
    start_date DATE NULL,
    end_date_exclusive DATE NULL,
    retail_price_minor_snapshot BIGINT NULL,
    rental_daily_rate_minor_snapshot BIGINT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (saved_component_id),
    KEY ix_saved_composition_components_parent (saved_composition_id, saved_component_id),
    CONSTRAINT fk_saved_components_parent
        FOREIGN KEY (saved_composition_id) REFERENCES saved_compositions (saved_composition_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_saved_components_product
        FOREIGN KEY (product_id) REFERENCES products (product_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_saved_components_quantity CHECK (quantity > 0),
    CONSTRAINT chk_saved_components_mode_snapshot CHECK (
        (mode = 'sale' AND start_date IS NULL AND end_date_exclusive IS NULL
            AND retail_price_minor_snapshot >= 0 AND rental_daily_rate_minor_snapshot IS NULL)
        OR (mode = 'rent' AND start_date IS NOT NULL AND end_date_exclusive > start_date
            AND retail_price_minor_snapshot IS NULL AND rental_daily_rate_minor_snapshot >= 0)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE composition_checkouts (
    composition_checkout_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    definition_id BIGINT UNSIGNED NOT NULL,
    definition_code_snapshot VARCHAR(80) NOT NULL,
    definition_name_snapshot VARCHAR(180) NOT NULL,
    checkout_key VARCHAR(80) NOT NULL,
    selection_sha256 CHAR(64) NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    order_role ENUM('sale', 'payment_anchor') NOT NULL,
    rental_id BIGINT UNSIGNED NULL,
    state ENUM('pending_payment', 'confirmed', 'cancelled', 'reconciliation_required') NOT NULL,
    total_minor BIGINT NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'ZAR',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (composition_checkout_id),
    UNIQUE KEY uq_composition_checkout_key (customer_id, checkout_key),
    UNIQUE KEY uq_composition_checkout_order (order_id),
    UNIQUE KEY uq_composition_checkout_rental (rental_id),
    KEY ix_composition_checkouts_state (state, created_at),
    CONSTRAINT fk_composition_checkouts_customer
        FOREIGN KEY (customer_id) REFERENCES customers (customer_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_composition_checkouts_definition
        FOREIGN KEY (definition_id) REFERENCES composition_definitions (definition_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_composition_checkouts_order
        FOREIGN KEY (order_id) REFERENCES sales_orders (order_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_composition_checkouts_rental
        FOREIGN KEY (rental_id) REFERENCES rentals (rental_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_composition_checkouts_total CHECK (total_minor >= 0),
    CONSTRAINT chk_composition_checkouts_order_role CHECK (
        (order_role = 'sale') OR order_role = 'payment_anchor'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE composition_checkout_lines (
    composition_checkout_line_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    composition_checkout_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    product_name_snapshot VARCHAR(180) NOT NULL,
    sku_snapshot VARCHAR(64) NOT NULL,
    mode ENUM('sale', 'rent') NOT NULL,
    quantity INT NOT NULL,
    start_date DATE NULL,
    end_date_exclusive DATE NULL,
    unit_price_minor BIGINT NULL,
    daily_rate_minor BIGINT NULL,
    order_item_id BIGINT UNSIGNED NULL,
    rental_item_id BIGINT UNSIGNED NULL,
    components_snapshot JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (composition_checkout_line_id),
    KEY ix_composition_checkout_lines_parent (composition_checkout_id, mode, product_id),
    KEY ix_composition_checkout_lines_product_dates (product_id, start_date, end_date_exclusive),
    UNIQUE KEY uq_composition_checkout_line_order_item (order_item_id),
    UNIQUE KEY uq_composition_checkout_line_rental_item (rental_item_id),
    CONSTRAINT fk_composition_checkout_lines_parent
        FOREIGN KEY (composition_checkout_id) REFERENCES composition_checkouts (composition_checkout_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_composition_checkout_lines_product
        FOREIGN KEY (product_id) REFERENCES products (product_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_composition_checkout_lines_order_item
        FOREIGN KEY (order_item_id) REFERENCES order_items (order_item_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_composition_checkout_lines_rental_item
        FOREIGN KEY (rental_item_id) REFERENCES rental_items (rental_item_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_composition_checkout_line_quantity CHECK (quantity > 0),
    CONSTRAINT chk_composition_checkout_line_leg CHECK (
        (mode = 'sale' AND start_date IS NULL AND end_date_exclusive IS NULL
            AND unit_price_minor >= 0 AND daily_rate_minor IS NULL
            AND order_item_id IS NOT NULL AND rental_item_id IS NULL)
        OR (mode = 'rent' AND start_date IS NOT NULL AND end_date_exclusive > start_date
            AND unit_price_minor IS NULL AND daily_rate_minor >= 0
            AND order_item_id IS NULL AND rental_item_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE composition_fixture_ledger (
    fixture_code VARCHAR(80) NOT NULL,
    definition_code VARCHAR(80) NOT NULL,
    disposition ENUM('inserted', 'collision', 'skipped') NOT NULL,
    definition_id BIGINT UNSIGNED NULL,
    processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (fixture_code, definition_code),
    KEY ix_composition_fixture_definition (definition_id),
    CONSTRAINT fk_composition_fixture_definition
        FOREIGN KEY (definition_id) REFERENCES composition_definitions (definition_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
