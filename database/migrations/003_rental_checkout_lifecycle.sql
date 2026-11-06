-- Resume a rental's hosted sandbox checkout and make retried return requests idempotent.
ALTER TABLE rental_payments
    ADD COLUMN provider_checkout_url VARCHAR(512) NULL AFTER provider_reference;

ALTER TABLE rental_returns
    ADD COLUMN request_key VARCHAR(80) NULL AFTER returned_at,
    ADD UNIQUE KEY uq_rental_returns_request_key (request_key);
