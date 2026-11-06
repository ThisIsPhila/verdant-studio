-- Extend contact enquiries into private, account-aware conversations.
-- The original customer_enquiries row remains the enquiry/contact snapshot.
ALTER TABLE customer_enquiries
    MODIFY name VARCHAR(180) NOT NULL,
    ADD COLUMN customer_id BIGINT UNSIGNED NULL AFTER enquiry_id,
    ADD COLUMN enquiry_state ENUM('open', 'resolved') NOT NULL DEFAULT 'open' AFTER message,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD KEY ix_customer_enquiries_customer (customer_id, created_at, enquiry_id),
    ADD KEY ix_customer_enquiries_state_updated (enquiry_state, updated_at, enquiry_id),
    ADD CONSTRAINT fk_customer_enquiries_customer FOREIGN KEY (customer_id)
        REFERENCES customers(customer_id) ON UPDATE RESTRICT ON DELETE RESTRICT;

CREATE TABLE enquiry_messages (
    message_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    enquiry_id BIGINT UNSIGNED NOT NULL,
    author_kind ENUM('guest', 'customer', 'admin') NOT NULL,
    author_customer_id BIGINT UNSIGNED NULL,
    body TEXT NOT NULL,
    submission_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (message_id),
    UNIQUE KEY uq_enquiry_messages_submission (submission_key),
    KEY ix_enquiry_messages_history (enquiry_id, created_at, message_id),
    CONSTRAINT fk_enquiry_messages_enquiry FOREIGN KEY (enquiry_id)
        REFERENCES customer_enquiries(enquiry_id) ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT fk_enquiry_messages_author FOREIGN KEY (author_customer_id)
        REFERENCES customers(customer_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_enquiry_messages_body CHECK (CHAR_LENGTH(body) BETWEEN 1 AND 5000),
    CONSTRAINT chk_enquiry_messages_author CHECK (
        (author_kind = 'guest' AND author_customer_id IS NULL)
        OR (author_kind IN ('customer', 'admin') AND author_customer_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill the original message once. Its existing one-use key becomes the
-- unique message key, so rerunning this statement cannot duplicate history.
INSERT IGNORE INTO enquiry_messages
    (enquiry_id, author_kind, author_customer_id, body, submission_key, created_at)
SELECT enquiry_id, 'guest', NULL, message, submission_key, created_at
FROM customer_enquiries;

CREATE TABLE support_notifications (
    notification_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    recipient_customer_id BIGINT UNSIGNED NOT NULL,
    enquiry_id BIGINT UNSIGNED NOT NULL,
    message_id BIGINT UNSIGNED NOT NULL,
    event_kind ENUM('new_customer_message', 'admin_reply') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    PRIMARY KEY (notification_id),
    UNIQUE KEY uq_support_notification_event (recipient_customer_id, message_id, event_kind),
    KEY ix_support_notifications_unread (recipient_customer_id, read_at, created_at, notification_id),
    CONSTRAINT fk_support_notifications_recipient FOREIGN KEY (recipient_customer_id)
        REFERENCES customers(customer_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_support_notifications_enquiry FOREIGN KEY (enquiry_id)
        REFERENCES customer_enquiries(enquiry_id) ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT fk_support_notifications_message FOREIGN KEY (message_id)
        REFERENCES enquiry_messages(message_id) ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE support_guest_access (
    guest_access_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    enquiry_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (guest_access_id),
    UNIQUE KEY uq_support_guest_access_token (token_hash),
    KEY ix_support_guest_access_enquiry_expiry (enquiry_id, expires_at),
    CONSTRAINT fk_support_guest_access_enquiry FOREIGN KEY (enquiry_id)
        REFERENCES customer_enquiries(enquiry_id) ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_outbox (
    outbox_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    message_id BIGINT UNSIGNED NOT NULL,
    recipient_email VARCHAR(254) NOT NULL,
    template_key VARCHAR(80) NOT NULL,
    context_json JSON NOT NULL,
    transport ENUM('local_capture', 'brevo') NOT NULL DEFAULT 'local_capture',
    delivery_state ENUM('held', 'queued', 'captured', 'provider_accepted', 'failed', 'uncertain') NOT NULL DEFAULT 'held',
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NULL,
    lease_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    leased_until DATETIME NULL,
    provider_receipt VARCHAR(160) NULL,
    failure_code VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (outbox_id),
    UNIQUE KEY uq_email_outbox_message_recipient_template (message_id, recipient_email, template_key),
    KEY ix_email_outbox_dispatch (delivery_state, next_attempt_at, leased_until, outbox_id),
    CONSTRAINT fk_email_outbox_message FOREIGN KEY (message_id)
        REFERENCES enquiry_messages(message_id) ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT chk_email_outbox_attempts CHECK (attempt_count <= 10),
    CONSTRAINT chk_email_outbox_lease CHECK ((lease_token IS NULL) = (leased_until IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
