-- Signup email verification: an unverified account state, one-time tokens, a
-- fourth mutually-exclusive outbox source, and verification rate-limit purposes.
ALTER TABLE customers
    MODIFY account_state ENUM('active', 'archived', 'unverified') NOT NULL,
    DROP CHECK chk_customers_archive_state,
    ADD CONSTRAINT chk_customers_archive_state CHECK (
        (account_state = 'active' AND archived_at IS NULL)
        OR (account_state = 'archived' AND archived_at IS NOT NULL)
        OR (account_state = 'unverified' AND archived_at IS NULL)
    );

CREATE TABLE email_verification_tokens (
    email_verification_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    token_hash BINARY(32) NOT NULL,
    requesting_surface ENUM('store') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    revoked_at DATETIME NULL,
    active_customer_id BIGINT UNSIGNED GENERATED ALWAYS AS (
        CASE WHEN consumed_at IS NULL AND revoked_at IS NULL THEN customer_id ELSE NULL END
    ) STORED,
    PRIMARY KEY (email_verification_id),
    UNIQUE KEY uq_email_verification_token_hash (token_hash),
    UNIQUE KEY uq_email_verification_active_customer (active_customer_id),
    KEY ix_email_verification_expiry (expires_at, email_verification_id),
    KEY ix_email_verification_customer_history (customer_id, created_at, email_verification_id),
    CONSTRAINT fk_email_verification_customer FOREIGN KEY (customer_id)
        REFERENCES customers(customer_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_email_verification_expiry CHECK (expires_at > created_at),
    CONSTRAINT chk_email_verification_terminal_state CHECK (consumed_at IS NULL OR revoked_at IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE auth_recovery_rate_limits
    MODIFY purpose ENUM('request_email', 'request_source', 'complete_source', 'complete_token',
        'verify_email', 'verify_source', 'verify_complete_source', 'verify_complete_token') NOT NULL;

ALTER TABLE email_outbox
    ADD COLUMN email_verification_id BIGINT UNSIGNED NULL AFTER password_reset_id,
    ADD UNIQUE KEY uq_email_verification_recipient_template
        (email_verification_id, recipient_email, template_key),
    ADD CONSTRAINT fk_email_verification FOREIGN KEY (email_verification_id)
        REFERENCES email_verification_tokens(email_verification_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    DROP CHECK chk_email_outbox_source,
    ADD CONSTRAINT chk_email_outbox_source CHECK (
        (message_id IS NOT NULL) + (commerce_event_id IS NOT NULL) + (password_reset_id IS NOT NULL) + (email_verification_id IS NOT NULL) = 1
    );
