-- Preserve Paystack's hosted authorization URL so a customer retry resumes the
-- same provider transaction instead of creating a second charge attempt.
ALTER TABLE order_payments
    ADD COLUMN provider_checkout_url VARCHAR(512) NULL AFTER provider_reference;
