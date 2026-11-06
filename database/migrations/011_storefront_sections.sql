-- Persist allowlisted editorial sections; section content never owns commerce state.
CREATE TABLE storefront_pages (
    storefront_page_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    page_key VARCHAR(40) NOT NULL,
    initialized_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (storefront_page_id),
    UNIQUE KEY uq_storefront_pages_key (page_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE page_sections (
    page_section_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    storefront_page_id BIGINT UNSIGNED NOT NULL,
    section_key VARCHAR(80) NOT NULL,
    section_type VARCHAR(40) NOT NULL,
    position INT NOT NULL DEFAULT 0,
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    heading VARCHAR(180) NOT NULL DEFAULT '',
    body TEXT NOT NULL,
    media_path VARCHAR(255) NULL,
    media_alt VARCHAR(180) NOT NULL DEFAULT '',
    link_label VARCHAR(80) NOT NULL DEFAULT '',
    link_path VARCHAR(255) NULL,
    collection_id BIGINT UNSIGNED NULL,
    arrangement_id BIGINT UNSIGNED NULL,
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (page_section_id),
    UNIQUE KEY uq_page_sections_page_key (storefront_page_id, section_key),
    KEY ix_page_sections_order (storefront_page_id, archived_at, position, page_section_id),
    KEY ix_page_sections_collection (collection_id),
    KEY ix_page_sections_arrangement (arrangement_id),
    CONSTRAINT fk_page_sections_page
        FOREIGN KEY (storefront_page_id) REFERENCES storefront_pages (storefront_page_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_page_sections_collection
        FOREIGN KEY (collection_id) REFERENCES collections (collection_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_page_sections_arrangement
        FOREIGN KEY (arrangement_id) REFERENCES composition_definitions (definition_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_page_sections_enabled CHECK (is_enabled IN (0, 1)),
    CONSTRAINT chk_page_sections_position CHECK (position >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
