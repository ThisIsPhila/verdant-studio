-- Product imagery assignments are separate from products, pricing and inventory.
-- Asset IDs resolve only through the reviewed application media registry.
CREATE TABLE product_media_bindings (
    binding_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id BIGINT UNSIGNED NULL,
    target_key VARCHAR(96) NOT NULL,
    media_kind ENUM('plant', 'pot', 'background') NOT NULL,
    asset_id VARCHAR(100) NOT NULL,
    visible_height_mm INT UNSIGNED NULL,
    pot_height_mm INT UNSIGNED NULL,
    measurement_status ENUM('unknown', 'illustrative', 'measured') NOT NULL DEFAULT 'unknown',
    archived_at DATETIME NULL,
    lock_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (binding_id),
    UNIQUE KEY uq_product_media_target_kind (target_key, media_kind),
    KEY ix_product_media_product (product_id, archived_at, media_kind),
    CONSTRAINT fk_product_media_product FOREIGN KEY (product_id) REFERENCES products (product_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_product_media_updated_by FOREIGN KEY (updated_by) REFERENCES customers (customer_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_product_media_target CHECK (
        (product_id IS NOT NULL AND target_key LIKE 'product:%')
        OR (product_id IS NULL AND media_kind = 'background' AND target_key LIKE 'scene:%')
    ),
    CONSTRAINT chk_product_media_geometry CHECK (
        (visible_height_mm IS NULL OR visible_height_mm BETWEEN 1 AND 5000)
        AND (pot_height_mm IS NULL OR pot_height_mm BETWEEN 1 AND 5000)
        AND (media_kind <> 'plant' OR pot_height_mm IS NULL)
        AND (media_kind <> 'pot' OR visible_height_mm IS NULL)
        AND (media_kind <> 'background' OR (visible_height_mm IS NULL AND pot_height_mm IS NULL))
        AND (measurement_status <> 'measured'
             OR (media_kind = 'plant' AND visible_height_mm IS NOT NULL)
             OR (media_kind = 'pot' AND pot_height_mm IS NOT NULL)
             OR media_kind = 'background')
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_media_history (
    event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    binding_id BIGINT UNSIGNED NOT NULL,
    actor_customer_id BIGINT UNSIGNED NULL,
    action ENUM('seed', 'create', 'update', 'archive', 'restore') NOT NULL,
    previous_asset_id VARCHAR(100) NULL,
    new_asset_id VARCHAR(100) NOT NULL,
    previous_visible_height_mm INT UNSIGNED NULL,
    new_visible_height_mm INT UNSIGNED NULL,
    previous_pot_height_mm INT UNSIGNED NULL,
    new_pot_height_mm INT UNSIGNED NULL,
    previous_measurement_status ENUM('unknown', 'illustrative', 'measured') NULL,
    new_measurement_status ENUM('unknown', 'illustrative', 'measured') NOT NULL,
    resulting_version BIGINT UNSIGNED NOT NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id),
    KEY ix_product_media_history_binding (binding_id, event_id),
    KEY ix_product_media_history_actor (actor_customer_id, event_id),
    CONSTRAINT fk_product_media_history_binding FOREIGN KEY (binding_id) REFERENCES product_media_bindings (binding_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_product_media_history_actor FOREIGN KEY (actor_customer_id) REFERENCES customers (customer_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
