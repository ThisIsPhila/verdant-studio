-- Primary-reviewed additive link to the existing sandbox payment record.
-- Associates one existing aggregate-capable order payment with a composition checkout.
ALTER TABLE composition_checkouts
    ADD COLUMN order_payment_id BIGINT UNSIGNED NULL AFTER order_id,
    ADD UNIQUE KEY uq_composition_checkouts_payment (order_payment_id),
    ADD CONSTRAINT fk_composition_checkouts_payment
        FOREIGN KEY (order_payment_id) REFERENCES order_payments (order_payment_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT;

