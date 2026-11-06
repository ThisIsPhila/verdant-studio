-- Group existing stock-bearing SKUs into product families and record explicit plant/pot fits.
CREATE TABLE product_families (
    family_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    family_code VARCHAR(80) NOT NULL,
    name VARCHAR(180) NOT NULL,
    product_kind ENUM(
        'plant', 'cut_flower', 'cut_foliage', 'accessory',
        'plant_arrangement', 'floral_arrangement', 'other'
    ) NOT NULL,
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (family_id),
    UNIQUE KEY uq_product_families_code (family_code),
    KEY ix_product_families_active_kind (archived_at, product_kind, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE products
    ADD COLUMN family_id BIGINT UNSIGNED NULL AFTER product_kind,
    ADD COLUMN variant_label VARCHAR(120) NULL AFTER family_id,
    ADD COLUMN accessory_subtype ENUM('pot', 'tool', 'care', 'other') NOT NULL DEFAULT 'other' AFTER variant_label,
    ADD UNIQUE KEY uq_products_family_variant (family_id, variant_label),
    ADD KEY ix_products_family_active (family_id, archived_at),
    ADD CONSTRAINT fk_products_family
        FOREIGN KEY (family_id) REFERENCES product_families (family_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT chk_products_family_variant CHECK (
        (family_id IS NULL AND variant_label IS NULL)
        OR (family_id IS NOT NULL AND variant_label IS NOT NULL AND TRIM(variant_label) <> '')
    ),
    ADD CONSTRAINT chk_products_accessory_subtype CHECK (
        product_kind = 'accessory' OR accessory_subtype = 'other'
    );

CREATE TABLE product_compatibilities (
    compatibility_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    plant_product_id BIGINT UNSIGNED NOT NULL,
    pot_product_id BIGINT UNSIGNED NOT NULL,
    source_status ENUM('demonstration', 'owner_verified') NOT NULL DEFAULT 'demonstration',
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (compatibility_id),
    UNIQUE KEY uq_product_compatibilities_pair (plant_product_id, pot_product_id),
    KEY ix_product_compatibilities_pot (pot_product_id, archived_at),
    CONSTRAINT fk_product_compatibilities_plant
        FOREIGN KEY (plant_product_id) REFERENCES products (product_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_product_compatibilities_pot
        FOREIGN KEY (pot_product_id) REFERENCES products (product_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_product_compatibilities_distinct CHECK (plant_product_id <> pot_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE catalogue_variant_fixture_ledger (
    fixture_code VARCHAR(80) NOT NULL,
    entry_key VARCHAR(160) NOT NULL,
    disposition ENUM('inserted', 'collision', 'skipped') NOT NULL,
    family_id BIGINT UNSIGNED NULL,
    processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (fixture_code, entry_key),
    KEY ix_catalogue_variant_fixture_family (family_id),
    CONSTRAINT fk_catalogue_variant_fixture_family
        FOREIGN KEY (family_id) REFERENCES product_families (family_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
