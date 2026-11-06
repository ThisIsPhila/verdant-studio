-- Curated discovery overlays existing sellable SKUs and configured compositions.
-- These tables carry no quantity, availability, or inventory state.
CREATE TABLE collections (
    collection_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    collection_code VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    description VARCHAR(500) NOT NULL,
    state ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (collection_id),
    UNIQUE KEY uq_collections_code (collection_code),
    KEY ix_collections_active (state, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE collection_products (
    collection_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    explanation VARCHAR(240) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (collection_id, product_id),
    KEY ix_collection_products_product (product_id, collection_id),
    CONSTRAINT fk_collection_products_collection
        FOREIGN KEY (collection_id) REFERENCES collections (collection_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_collection_products_product
        FOREIGN KEY (product_id) REFERENCES products (product_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE collection_compositions (
    collection_id BIGINT UNSIGNED NOT NULL,
    definition_id BIGINT UNSIGNED NOT NULL,
    explanation VARCHAR(240) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (collection_id, definition_id),
    KEY ix_collection_compositions_definition (definition_id, collection_id),
    CONSTRAINT fk_collection_compositions_collection
        FOREIGN KEY (collection_id) REFERENCES collections (collection_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_collection_compositions_definition
        FOREIGN KEY (definition_id) REFERENCES composition_definitions (definition_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE collection_fixture_ledger (
    fixture_code VARCHAR(80) NOT NULL,
    collection_code VARCHAR(80) NOT NULL,
    disposition ENUM('inserted', 'collision') NOT NULL,
    collection_id BIGINT UNSIGNED NULL,
    processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (fixture_code, collection_code),
    KEY ix_collection_fixture_collection (collection_id),
    CONSTRAINT fk_collection_fixture_collection
        FOREIGN KEY (collection_id) REFERENCES collections (collection_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_collection_fixture_result CHECK (
        (disposition = 'inserted' AND collection_id IS NOT NULL)
        OR (disposition = 'collision' AND collection_id IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
