-- Password recovery records, privacy-preserving rate limits, and session versioning.
-- The outbox retains its existing support and commerce source rows; reset messages
-- become a third mutually-exclusive source and keep their token record for audit.

ALTER TABLE customers
    ADD COLUMN credential_version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER password_hash;

CREATE TABLE password_reset_tokens (
    password_reset_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    token_hash BINARY(32) NOT NULL,
    requesting_surface ENUM('store', 'admin') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    revoked_at DATETIME NULL,
    active_customer_id BIGINT UNSIGNED GENERATED ALWAYS AS (
        CASE WHEN consumed_at IS NULL AND revoked_at IS NULL THEN customer_id ELSE NULL END
    ) STORED,
    PRIMARY KEY (password_reset_id),
    UNIQUE KEY uq_password_reset_token_hash (token_hash),
    UNIQUE KEY uq_password_reset_active_customer (active_customer_id),
    KEY ix_password_reset_expiry (expires_at, password_reset_id),
    KEY ix_password_reset_customer_history (customer_id, created_at, password_reset_id),
    CONSTRAINT fk_password_reset_customer FOREIGN KEY (customer_id)
        REFERENCES customers(customer_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_password_reset_expiry CHECK (expires_at > created_at),
    CONSTRAINT chk_password_reset_terminal_state CHECK (consumed_at IS NULL OR revoked_at IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_recovery_rate_limits (
    purpose ENUM('request_email', 'request_source', 'complete_source', 'complete_token') NOT NULL,
    subject_fingerprint BINARY(32) NOT NULL,
    window_started_at DATETIME NOT NULL,
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (purpose, subject_fingerprint, window_started_at),
    KEY ix_auth_recovery_rate_expiry (expires_at, purpose),
    CONSTRAINT chk_auth_recovery_attempt_count CHECK (attempt_count > 0),
    CONSTRAINT chk_auth_recovery_rate_expiry CHECK (expires_at > window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE email_outbox
    ADD COLUMN password_reset_id BIGINT UNSIGNED NULL AFTER commerce_event_id,
    ADD UNIQUE KEY uq_email_password_reset_recipient_template
        (password_reset_id, recipient_email, template_key),
    ADD CONSTRAINT fk_email_password_reset FOREIGN KEY (password_reset_id)
        REFERENCES password_reset_tokens(password_reset_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    DROP CHECK chk_email_outbox_source,
    ADD CONSTRAINT chk_email_outbox_source CHECK (
        (message_id IS NOT NULL) + (commerce_event_id IS NOT NULL) + (password_reset_id IS NOT NULL) = 1
    );
