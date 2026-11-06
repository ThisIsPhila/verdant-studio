-- Include the existing administrator fulfil and unattempted cancellation actions.
ALTER TABLE commerce_notification_events MODIFY event_kind
    ENUM('purchase_confirmed','rental_confirmed','composition_confirmed','rental_issued','rental_returned','rental_cancelled','order_fulfilled','order_cancelled') NOT NULL;
