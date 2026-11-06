-- Administrator access allowlist: only listed addresses may use the admin
-- surface, on top of the admin role. Existing admins are seeded so no current
-- administrator is locked out by this migration.
CREATE TABLE admin_allowlist (
    email VARCHAR(254) NOT NULL,
    added_by_customer_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (email),
    CONSTRAINT fk_admin_allowlist_added_by FOREIGN KEY (added_by_customer_id)
        REFERENCES customers(customer_id) ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO admin_allowlist (email)
    SELECT DISTINCT LOWER(email) FROM customers WHERE role = 'admin';
