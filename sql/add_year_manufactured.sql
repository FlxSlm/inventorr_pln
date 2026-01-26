-- Migration: Add year_manufactured column and remove item_condition
-- Run this SQL in phpMyAdmin or MySQL command line

USE inventory_db;

-- Add year_manufactured column if not exists
ALTER TABLE inventories
ADD COLUMN IF NOT EXISTS `year_manufactured` VARCHAR(4) NULL AFTER `year_acquired`;

-- Optional: Remove item_condition column (uncomment if you want to drop it)
-- ALTER TABLE inventories DROP COLUMN IF EXISTS `item_condition`;

-- Note: If you want to keep the data but hide the feature, 
-- leave the column and just don't use it in the UI
