-- One durable commerce event can feed the existing shared mail outbox.
CREATE TABLE commerce_notification_events (
    commerce_event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_key VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_kind ENUM('purchase_confirmed','rental_confirmed','composition_confirmed','rental_issued','rental_returned','rental_cancelled') NOT NULL,
    order_id BIGINT UNSIGNED NULL,
    rental_id BIGINT UNSIGNED NULL,
    composition_checkout_id BIGINT UNSIGNED NULL,
    operation_id BIGINT UNSIGNED NULL,
    context_json JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (commerce_event_id),
    UNIQUE KEY uq_commerce_event_key (event_key),
    CONSTRAINT fk_commerce_event_order FOREIGN KEY (order_id) REFERENCES sales_orders(order_id) ON DELETE RESTRICT,
    CONSTRAINT fk_commerce_event_rental FOREIGN KEY (rental_id) REFERENCES rentals(rental_id) ON DELETE RESTRICT,
    CONSTRAINT fk_commerce_event_composition FOREIGN KEY (composition_checkout_id) REFERENCES composition_checkouts(composition_checkout_id) ON DELETE RESTRICT,
    CONSTRAINT chk_commerce_event_source CHECK ((order_id IS NOT NULL) + (rental_id IS NOT NULL) + (composition_checkout_id IS NOT NULL) = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE email_outbox
    MODIFY message_id BIGINT UNSIGNED NULL,
    ADD COLUMN commerce_event_id BIGINT UNSIGNED NULL AFTER message_id,
    ADD COLUMN recipient_customer_id BIGINT UNSIGNED NULL AFTER recipient_email,
    ADD COLUMN recipient_role ENUM('customer','admin') NULL AFTER recipient_customer_id,
    ADD UNIQUE KEY uq_email_commerce_recipient_template (commerce_event_id,recipient_email,template_key),
    ADD CONSTRAINT fk_email_commerce_event FOREIGN KEY (commerce_event_id) REFERENCES commerce_notification_events(commerce_event_id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_email_recipient_customer FOREIGN KEY (recipient_customer_id) REFERENCES customers(customer_id) ON DELETE RESTRICT,
    ADD CONSTRAINT chk_email_outbox_source CHECK ((message_id IS NOT NULL) + (commerce_event_id IS NOT NULL) = 1);
