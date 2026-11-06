-- Product kinds and neutral descriptive fields for the starter catalogue.
-- Existing products remain valid legacy offers with kind `other` and unknown details.
ALTER TABLE products
    ADD COLUMN product_kind ENUM(
        'plant', 'cut_flower', 'cut_foliage', 'accessory',
        'plant_arrangement', 'floral_arrangement', 'other'
    ) NOT NULL DEFAULT 'other' AFTER sku,
    ADD COLUMN selling_unit VARCHAR(80) NULL AFTER product_kind,
    ADD COLUMN size_label VARCHAR(120) NULL AFTER selling_unit,
    ADD COLUMN colour_label VARCHAR(80) NULL AFTER size_label,
    ADD COLUMN image_alt VARCHAR(180) NULL AFTER image_path,
    ADD COLUMN details_status ENUM('unknown', 'demonstration', 'owner_supplied') NOT NULL DEFAULT 'unknown' AFTER colour_label,
    ADD CONSTRAINT chk_products_floral_sale_only CHECK (
        product_kind NOT IN ('cut_flower', 'cut_foliage', 'floral_arrangement') OR rent_enabled = 0
    ),
    ADD KEY ix_products_kind_category (product_kind, category_id, archived_at);

CREATE TABLE catalogue_fixture_ledger (
    fixture_code VARCHAR(80) NOT NULL,
    sku VARCHAR(64) NOT NULL,
    disposition ENUM('inserted', 'collision') NOT NULL,
    product_id BIGINT UNSIGNED NULL,
    processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (fixture_code, sku),
    KEY ix_catalogue_fixture_product (product_id),
    CONSTRAINT fk_catalogue_fixture_product FOREIGN KEY (product_id)
        REFERENCES products (product_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_catalogue_fixture_product CHECK (
        (disposition = 'inserted' AND product_id IS NOT NULL)
        OR (disposition = 'collision' AND product_id IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
