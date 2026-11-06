-- Phase 021: product size variants for the catalogue.
-- Adds a size label to products so each size is listed as its own product.

ALTER TABLE products
    ADD COLUMN size_label VARCHAR(20) NOT NULL DEFAULT 'standard' AFTER name;

ALTER TABLE products
    ADD KEY ix_products_size_label (size_label);

-- Backfill existing rows as 'standard'.
UPDATE products SET size_label = 'standard' WHERE size_label = '';
