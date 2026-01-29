-- Migration to add item_condition field to inventories table
-- Run this SQL file to add the kondisi barang feature

-- Add item_condition column to inventories table
ALTER TABLE inventories 
ADD COLUMN item_condition ENUM('Baik', 'Rusak Ringan', 'Rusak Berat') DEFAULT 'Baik' 
AFTER low_stock_threshold;

-- Add index for faster filtering by condition
CREATE INDEX idx_item_condition ON inventories(item_condition);
