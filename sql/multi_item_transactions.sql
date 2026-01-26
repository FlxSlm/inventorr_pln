-- Migration: Multi-item transaction support
-- Run this SQL in phpMyAdmin or MySQL command line

USE inventory_db;

-- Add group_id column to loans table for grouping multiple items in one transaction
ALTER TABLE loans
ADD COLUMN IF NOT EXISTS `group_id` VARCHAR(36) NULL AFTER `id`,
ADD INDEX IF NOT EXISTS `idx_loans_group_id` (`group_id`);

-- Add group_id column to requests table for grouping multiple items in one transaction  
ALTER TABLE requests
ADD COLUMN IF NOT EXISTS `group_id` VARCHAR(36) NULL AFTER `id`,
ADD INDEX IF NOT EXISTS `idx_requests_group_id` (`group_id`);

-- Note: group_id will be a UUID that links multiple items in the same transaction
-- Example: When user borrows 3 items at once, all 3 loan records will have the same group_id
-- This allows us to show them as one transaction with expandable details

-- Existing loans/requests without group_id will be treated as single-item transactions
