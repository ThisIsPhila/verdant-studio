-- Synthetic demonstration seed for a fresh database after 001_foundation.sql.
-- This file is intentionally insert-only: it never overwrites existing rows.
-- The migration/seed runner checks that business tables are empty before
-- applying this file. Duplicate keys also fail without modifying prior rows.
--
-- Synthetic demo logins (never reuse these outside local demonstration):
--   admin@example.test / VerdantDemo!2026 (role: admin)
--   customer@example.com / VerdantDemo!2026 (role: customer)
-- Password hashes below were generated with PHP password_hash(...,
-- PASSWORD_DEFAULT). Seeded payment references use the local "demo" code;
-- Paystack hosted checkout is configured separately in TEST mode only.

INSERT INTO customers
    (customer_id, email, password_hash, name, phone, role, account_state, archived_at)
VALUES
    (1001, 'customer@example.com', '$2y$12$gWMIzLjfzgU1fZfR95FUj.7aw8uwnYn4e2WM1HyJpwv82wUb8cOZm',
     'Demo Customer', NULL, 'customer', 'active', NULL),
    (1002, 'admin@example.test', '$2y$12$hmRyN2usrHBqFlBYAO8g0O619GAvTOhQkxnEgVCcnldBH32IIDJ/a',
     'Demo Administrator', NULL, 'admin', 'active', NULL);

INSERT INTO categories (category_id, name, archived_at)
VALUES
    (2001, 'Indoor Plants', NULL),
    (2002, 'Botanical Displays', NULL);

INSERT INTO products
    (product_id, category_id, sku, name, size_label, description, light_level, watering_level,
     toxicity, image_path, buy_enabled, rent_enabled, retail_price_minor,
     rental_daily_rate_minor, archived_at)
VALUES
    (3001, 2001, 'VER-RETAIL-001-S', 'Golden Pothos', 'small',
     'A trailing indoor plant for desks and shelves.', 'bright_indirect',
     'weekly', 'toxic_to_pets', NULL, TRUE, FALSE, 9900, NULL, NULL),
    (3004, 2001, 'VER-RETAIL-001-M', 'Golden Pothos', 'medium',
     'A trailing indoor plant for bright indirect-light spaces.', 'bright_indirect',
     'weekly', 'toxic_to_pets', NULL, TRUE, FALSE, 12900, NULL, NULL),
    (3005, 2001, 'VER-RETAIL-001-L', 'Golden Pothos', 'large',
     'A large trailing indoor plant for floor display.', 'bright_indirect',
     'weekly', 'toxic_to_pets', NULL, TRUE, FALSE, 18900, NULL, NULL),
    (3002, 2001, 'VER-RENT-001', 'Large Fern Display', 'large',
     'A reusable fern arrangement for temporary botanical displays.', 'indirect',
     'keep_moist', 'non_toxic', NULL, FALSE, TRUE, NULL, 750, NULL),
    (3003, 2002, 'VER-BOTH-001', 'Ceramic Herb Planter', 'standard',
     'A tabletop herb planter available for purchase or short-term hire.', 'full_sun',
     'regular', 'non_toxic', NULL, TRUE, TRUE, 24900, 1200, NULL),
    (3006, 2002, 'VER-BOUQUET-STD', 'Wild Garden Bouquet', 'standard',
     'A hand-tied seasonal bouquet, standard size.', 'bright_indirect',
     'keep_moist', 'non_toxic', NULL, TRUE, FALSE, 9500, NULL, NULL),
    (3007, 2002, 'VER-BOUQUET-LG', 'Wild Garden Bouquet', 'large',
     'A hand-tied seasonal bouquet, large size.', 'bright_indirect',
     'keep_moist', 'non_toxic', NULL, TRUE, FALSE, 14500, NULL, NULL);

INSERT INTO inventory (product_id, on_hand, version)
VALUES
    (3001, 8, 0),
    (3002, 4, 0),
    (3003, 6, 0);

INSERT INTO sales_orders
    (order_id, customer_id, checkout_key, state, fulfilled_at, archived_at)
VALUES
    (5001, 1001, 'demo-retail-checkout-5001', 'confirmed', NULL, NULL);

INSERT INTO order_items
    (order_item_id, order_id, product_id, product_name_snapshot, sku_snapshot,
     quantity, unit_price_minor)
VALUES
    (5101, 5001, 3001, 'Golden Pothos', 'VER-RETAIL-001', 2, 12900);

INSERT INTO retail_holds (order_item_id, quantity, state, expires_at)
VALUES (5101, 2, 'consumed', NULL);

INSERT INTO order_payments
    (order_payment_id, order_id, provider_code, provider_reference,
     amount_minor, currency, state)
VALUES
    (5201, 5001, 'demo', 'demo-order-payment-5201', 25800, 'ZAR', 'succeeded');

INSERT INTO rentals
    (rental_id, customer_id, checkout_key, state, archived_at)
VALUES
    (6001, 1001, 'demo-rental-checkout-6001', 'confirmed', NULL);

INSERT INTO rental_items
    (rental_item_id, rental_id, product_id, product_name_snapshot, sku_snapshot,
     quantity, start_date, end_date_exclusive, daily_rate_minor)
VALUES
    (6101, 6001, 3002, 'Large Fern Display', 'VER-RENT-001', 2,
     '2026-10-06', '2026-10-09', 750);

INSERT INTO rental_allocations (rental_item_id, quantity, state, expires_at)
VALUES (6101, 2, 'issued', NULL);

INSERT INTO rental_issues (rental_item_id, quantity, issued_at)
VALUES (6101, 2, '2026-10-06 09:00:00');

INSERT INTO rental_returns (rental_return_id, rental_item_id, quantity, returned_at)
VALUES (6201, 6101, 1, '2026-10-06 12:00:00');

INSERT INTO rental_payments
    (rental_payment_id, rental_id, provider_code, provider_reference,
     amount_minor, currency, state)
VALUES
    (6301, 6001, 'demo', 'demo-rental-payment-6301', 4500, 'ZAR', 'succeeded');

INSERT INTO provider_events
    (provider_event_id, provider_code, event_reference, payment_kind,
     order_payment_id, rental_payment_id, payload_hash, received_at, processing_state)
VALUES
    (7001, 'demo', 'demo-order-event-7001', 'order', 5201, NULL,
     SHA2('synthetic order payment event 7001', 256), '2026-10-06 08:00:00', 'applied'),
    (7002, 'demo', 'demo-rental-event-7002', 'rental', NULL, 6301,
     SHA2('synthetic rental payment event 7002', 256), '2026-10-06 08:30:00', 'applied');
