-- Verdant foundation schema.
-- Requires MySQL 8.0.16+ with enforced CHECK constraints, InnoDB, and utf8mb4.
-- Verified local baseline: MySQL 8.4.11.
-- Apply once to an empty application database. The migration runner owns its
-- schema_migrations ledger; this file intentionally does not create one.
-- Cross-row quantity bounds, cumulative returns, and amount-to-checkout
-- equality are enforced by transactional services, as specified in Phase 002.

CREATE TABLE customers (
    customer_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(180) NOT NULL,
    phone VARCHAR(30) NULL,
    role ENUM('customer', 'admin') NOT NULL DEFAULT 'customer',
    account_state ENUM('active', 'archived') NOT NULL,
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (customer_id),
    UNIQUE KEY uq_customers_email (email),
    CONSTRAINT chk_customers_archive_state CHECK (
        (account_state = 'active' AND archived_at IS NULL)
        OR (account_state = 'archived' AND archived_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
    category_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (category_id),
    UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
    product_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id BIGINT UNSIGNED NOT NULL,
    sku VARCHAR(64) NOT NULL,
    name VARCHAR(180) NOT NULL,
    description TEXT NOT NULL,
    light_level VARCHAR(40) NOT NULL,
    watering_level VARCHAR(40) NOT NULL,
    toxicity VARCHAR(40) NOT NULL,
    image_path VARCHAR(255) NULL,
    buy_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    rent_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    retail_price_minor BIGINT NULL,
    rental_daily_rate_minor BIGINT NULL,
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (product_id),
    UNIQUE KEY uq_products_sku (sku),
    KEY ix_products_category_id (category_id),
    CONSTRAINT fk_products_category
        FOREIGN KEY (category_id) REFERENCES categories (category_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_products_has_capability CHECK (buy_enabled OR rent_enabled),
    CONSTRAINT chk_products_boolean_flags CHECK (buy_enabled IN (0, 1) AND rent_enabled IN (0, 1)),
    CONSTRAINT chk_products_enabled_prices CHECK (
        (NOT buy_enabled OR (retail_price_minor IS NOT NULL AND retail_price_minor >= 0))
        AND (NOT rent_enabled OR (rental_daily_rate_minor IS NOT NULL AND rental_daily_rate_minor >= 0))
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory (
    product_id BIGINT UNSIGNED NOT NULL,
    on_hand INT NOT NULL,
    version BIGINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (product_id),
    CONSTRAINT fk_inventory_product
        FOREIGN KEY (product_id) REFERENCES products (product_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_inventory_nonnegative CHECK (on_hand >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sales_orders (
    order_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    checkout_key VARCHAR(80) NOT NULL,
    state ENUM('pending_payment', 'confirmed', 'cancelled', 'reconciliation_required') NOT NULL,
    fulfilled_at DATETIME NULL,
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (order_id),
    UNIQUE KEY uq_sales_orders_customer_checkout (customer_id, checkout_key),
    KEY ix_sales_orders_state (state),
    CONSTRAINT fk_sales_orders_customer
        FOREIGN KEY (customer_id) REFERENCES customers (customer_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
    order_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    product_name_snapshot VARCHAR(180) NOT NULL,
    sku_snapshot VARCHAR(64) NOT NULL,
    quantity INT NOT NULL,
    unit_price_minor BIGINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (order_item_id),
    KEY ix_order_items_order_id (order_id),
    KEY ix_order_items_product_id (product_id),
    CONSTRAINT fk_order_items_order
        FOREIGN KEY (order_id) REFERENCES sales_orders (order_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_order_items_product
        FOREIGN KEY (product_id) REFERENCES products (product_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_order_items_quantity CHECK (quantity > 0),
    CONSTRAINT chk_order_items_unit_price CHECK (unit_price_minor >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE retail_holds (
    order_item_id BIGINT UNSIGNED NOT NULL,
    quantity INT NOT NULL,
    state ENUM('active', 'consumed', 'released', 'reconciliation_required') NOT NULL,
    expires_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (order_item_id),
    CONSTRAINT fk_retail_holds_order_item
        FOREIGN KEY (order_item_id) REFERENCES order_items (order_item_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_retail_holds_quantity CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rentals (
    rental_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    checkout_key VARCHAR(80) NOT NULL,
    state ENUM('pending_payment', 'confirmed', 'cancelled', 'reconciliation_required') NOT NULL,
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (rental_id),
    UNIQUE KEY uq_rentals_customer_checkout (customer_id, checkout_key),
    KEY ix_rentals_state (state),
    CONSTRAINT fk_rentals_customer
        FOREIGN KEY (customer_id) REFERENCES customers (customer_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rental_items (
    rental_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rental_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    product_name_snapshot VARCHAR(180) NOT NULL,
    sku_snapshot VARCHAR(64) NOT NULL,
    quantity INT NOT NULL,
    start_date DATE NOT NULL,
    end_date_exclusive DATE NOT NULL,
    daily_rate_minor BIGINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (rental_item_id),
    KEY ix_rental_items_rental_id (rental_id),
    KEY ix_rental_items_product_dates (product_id, start_date, end_date_exclusive),
    CONSTRAINT fk_rental_items_rental
        FOREIGN KEY (rental_id) REFERENCES rentals (rental_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_rental_items_product
        FOREIGN KEY (product_id) REFERENCES products (product_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_rental_items_quantity CHECK (quantity > 0),
    CONSTRAINT chk_rental_items_date_range CHECK (end_date_exclusive > start_date),
    CONSTRAINT chk_rental_items_daily_rate CHECK (daily_rate_minor >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rental_allocations (
    rental_item_id BIGINT UNSIGNED NOT NULL,
    quantity INT NOT NULL,
    state ENUM('held', 'confirmed', 'issued', 'closed', 'released', 'reconciliation_required') NOT NULL,
    expires_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (rental_item_id),
    CONSTRAINT fk_rental_allocations_item
        FOREIGN KEY (rental_item_id) REFERENCES rental_items (rental_item_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_rental_allocations_quantity CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rental_issues (
    rental_item_id BIGINT UNSIGNED NOT NULL,
    quantity INT NOT NULL,
    issued_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (rental_item_id),
    CONSTRAINT fk_rental_issues_allocation
        FOREIGN KEY (rental_item_id) REFERENCES rental_allocations (rental_item_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_rental_issues_quantity CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rental_returns (
    rental_return_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rental_item_id BIGINT UNSIGNED NOT NULL,
    quantity INT NOT NULL,
    returned_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (rental_return_id),
    KEY ix_rental_returns_item (rental_item_id),
    CONSTRAINT fk_rental_returns_issue
        FOREIGN KEY (rental_item_id) REFERENCES rental_issues (rental_item_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_rental_returns_quantity CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_payments (
    order_payment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    provider_code VARCHAR(40) NOT NULL,
    provider_reference VARCHAR(160) NULL,
    amount_minor BIGINT NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'ZAR',
    state ENUM('pending', 'succeeded', 'failed', 'cancelled', 'reconciliation_required') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (order_payment_id),
    UNIQUE KEY uq_order_payments_provider_reference (provider_code, provider_reference),
    KEY ix_order_payments_order_id (order_id),
    CONSTRAINT fk_order_payments_order
        FOREIGN KEY (order_id) REFERENCES sales_orders (order_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_order_payments_amount CHECK (amount_minor >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rental_payments (
    rental_payment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rental_id BIGINT UNSIGNED NOT NULL,
    provider_code VARCHAR(40) NOT NULL,
    provider_reference VARCHAR(160) NULL,
    amount_minor BIGINT NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'ZAR',
    state ENUM('pending', 'succeeded', 'failed', 'cancelled', 'reconciliation_required') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (rental_payment_id),
    UNIQUE KEY uq_rental_payments_provider_reference (provider_code, provider_reference),
    KEY ix_rental_payments_rental_id (rental_id),
    CONSTRAINT fk_rental_payments_rental
        FOREIGN KEY (rental_id) REFERENCES rentals (rental_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_rental_payments_amount CHECK (amount_minor >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE provider_events (
    provider_event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_code VARCHAR(40) NOT NULL,
    event_reference VARCHAR(160) NOT NULL,
    payment_kind ENUM('order', 'rental') NULL,
    order_payment_id BIGINT UNSIGNED NULL,
    rental_payment_id BIGINT UNSIGNED NULL,
    payload_hash CHAR(64) NOT NULL,
    received_at DATETIME NOT NULL,
    processing_state ENUM('received', 'applied', 'ignored', 'quarantined') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (provider_event_id),
    UNIQUE KEY uq_provider_events_identity (provider_code, event_reference),
    KEY ix_provider_events_order_payment (order_payment_id),
    KEY ix_provider_events_rental_payment (rental_payment_id),
    CONSTRAINT fk_provider_events_order_payment
        FOREIGN KEY (order_payment_id) REFERENCES order_payments (order_payment_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_provider_events_rental_payment
        FOREIGN KEY (rental_payment_id) REFERENCES rental_payments (rental_payment_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_provider_events_payment_ownership CHECK (
        (processing_state = 'quarantined'
            AND payment_kind IS NULL
            AND order_payment_id IS NULL
            AND rental_payment_id IS NULL)
        OR
        (processing_state <> 'quarantined'
            AND payment_kind IS NOT NULL
            AND payment_kind = 'order'
            AND order_payment_id IS NOT NULL
            AND rental_payment_id IS NULL)
        OR
        (processing_state <> 'quarantined'
            AND payment_kind IS NOT NULL
            AND payment_kind = 'rental'
            AND order_payment_id IS NULL
            AND rental_payment_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
