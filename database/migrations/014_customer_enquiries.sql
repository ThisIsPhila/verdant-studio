-- Customer enquiries are private customer data and are readable only through protected admin code.
CREATE TABLE customer_enquiries (
    enquiry_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(254) NOT NULL,
    topic VARCHAR(20) NOT NULL DEFAULT 'customer',
    subject VARCHAR(160) NOT NULL,
    order_reference VARCHAR(80) NULL,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (enquiry_id),
    UNIQUE KEY uq_customer_enquiries_submission_key (submission_key),
    KEY ix_customer_enquiries_created (created_at, enquiry_id),
    CONSTRAINT chk_customer_enquiries_topic CHECK (topic = 'customer'),
    CONSTRAINT chk_customer_enquiries_order_reference CHECK (order_reference IS NULL OR CHAR_LENGTH(order_reference) <= 80),
    CONSTRAINT chk_customer_enquiries_message_length CHECK (CHAR_LENGTH(message) BETWEEN 1 AND 5000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
