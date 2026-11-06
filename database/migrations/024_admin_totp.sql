-- Admin two-factor authentication: one TOTP credential per admin, hashed
-- single-use backup codes, and TOTP rate-limit purposes.
CREATE TABLE admin_totp_credentials (
    customer_id BIGINT UNSIGNED NOT NULL,
    secret_sealed VARCHAR(255) NOT NULL,
    verified_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (customer_id),
    CONSTRAINT fk_admin_totp_customer FOREIGN KEY (customer_id)
        REFERENCES customers(customer_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_totp_backup_codes (
    backup_code_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    consumed_at DATETIME NULL,
    PRIMARY KEY (backup_code_id),
    KEY ix_admin_totp_backup_customer (customer_id, backup_code_id),
    CONSTRAINT fk_admin_totp_backup_customer FOREIGN KEY (customer_id)
        REFERENCES customers(customer_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE auth_recovery_rate_limits
    MODIFY purpose ENUM('request_email', 'request_source', 'complete_source', 'complete_token',
        'verify_email', 'verify_source', 'verify_complete_source', 'verify_complete_token',
        'totp_account', 'totp_source') NOT NULL;
