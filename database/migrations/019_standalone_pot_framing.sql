-- Store bounded presentation framing separately from product measurements.
ALTER TABLE product_media_bindings
    ADD COLUMN camera_scale DECIMAL(3,2) NOT NULL DEFAULT 1.00 AFTER measurement_status,
    ADD CONSTRAINT chk_product_media_camera_scale CHECK (camera_scale BETWEEN 0.85 AND 1.15);

ALTER TABLE product_media_history
    ADD COLUMN previous_camera_scale DECIMAL(3,2) NULL AFTER new_measurement_status,
    ADD COLUMN new_camera_scale DECIMAL(3,2) NOT NULL DEFAULT 1.00 AFTER previous_camera_scale,
    ADD CONSTRAINT chk_product_media_history_camera_scale CHECK (
        new_camera_scale BETWEEN 0.85 AND 1.15
        AND (previous_camera_scale IS NULL OR previous_camera_scale BETWEEN 0.85 AND 1.15)
    );
