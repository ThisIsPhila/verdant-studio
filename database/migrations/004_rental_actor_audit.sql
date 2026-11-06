-- Existing issue/return rows retain a NULL actor because historical attribution is unknown.
ALTER TABLE rental_issues
    ADD COLUMN actor_id BIGINT UNSIGNED NULL AFTER quantity,
    ADD KEY ix_rental_issues_actor (actor_id),
    ADD CONSTRAINT fk_rental_issues_actor
        FOREIGN KEY (actor_id) REFERENCES customers (customer_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE rental_returns
    ADD COLUMN actor_id BIGINT UNSIGNED NULL AFTER request_key,
    ADD KEY ix_rental_returns_actor (actor_id),
    ADD CONSTRAINT fk_rental_returns_actor
        FOREIGN KEY (actor_id) REFERENCES customers (customer_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT;
