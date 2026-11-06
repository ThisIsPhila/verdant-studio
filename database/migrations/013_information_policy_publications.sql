-- Keep working policy sections private until an administrator explicitly publishes a snapshot.
CREATE TABLE information_policy_state (
    page_key VARCHAR(40) NOT NULL,
    status ENUM('draft', 'published') NOT NULL DEFAULT 'draft',
    draft_revision INT UNSIGNED NOT NULL DEFAULT 1,
    published_revision INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (page_key),
    CONSTRAINT chk_information_policy_state_page CHECK (page_key IN ('privacy', 'terms')),
    CONSTRAINT chk_information_policy_state_revisions CHECK (
        published_revision IS NULL OR published_revision <= draft_revision
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Published rows are append-only snapshots. The application exposes insert and read paths only.
CREATE TABLE information_policy_publications (
    publication_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    page_key VARCHAR(40) NOT NULL,
    version VARCHAR(32) NOT NULL,
    effective_date DATE NOT NULL,
    draft_revision INT UNSIGNED NOT NULL,
    sections_json LONGTEXT NOT NULL,
    published_by VARCHAR(80) NULL,
    published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (publication_id),
    UNIQUE KEY uq_information_policy_version (page_key, version),
    UNIQUE KEY uq_information_policy_revision (page_key, draft_revision),
    KEY ix_information_policy_history (page_key, published_at, publication_id),
    CONSTRAINT fk_information_policy_state
        FOREIGN KEY (page_key) REFERENCES information_policy_state (page_key)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_information_policy_version CHECK (CHAR_LENGTH(TRIM(version)) BETWEEN 1 AND 32),
    CONSTRAINT chk_information_policy_json CHECK (JSON_VALID(sections_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO information_policy_state (page_key, status, draft_revision)
VALUES ('privacy', 'draft', 1), ('terms', 'draft', 1);
