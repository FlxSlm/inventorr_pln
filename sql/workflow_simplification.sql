-- SQL Migration for Workflow Simplification
-- Date: 2026-01-27
-- Purpose: Simplify loan/request/return workflow to single approval with admin BAST upload

-- Add admin_document_path column to loans table (for BAST uploaded by admin)
ALTER TABLE loans ADD COLUMN IF NOT EXISTS admin_document_path VARCHAR(255) DEFAULT NULL AFTER document_path;

-- Add admin_document_path column to requests table
ALTER TABLE requests ADD COLUMN IF NOT EXISTS admin_document_path VARCHAR(255) DEFAULT NULL;

-- Add return_admin_document_path column for return BAST
ALTER TABLE loans ADD COLUMN IF NOT EXISTS return_admin_document_path VARCHAR(255) DEFAULT NULL;

-- Simplify the stage values
-- New stages: 'pending', 'approved', 'rejected' (remove awaiting_document and submitted)
-- For returns: 'pending_return', 'return_approved', 'return_rejected'

-- Update any loans stuck in awaiting_document/submitted to pending (for fresh start)
-- Uncomment if needed:
-- UPDATE loans SET stage = 'pending' WHERE stage IN ('awaiting_document', 'submitted');
-- UPDATE requests SET stage = 'pending' WHERE stage IN ('awaiting_document', 'submitted');
-- UPDATE loans SET return_stage = 'pending_return' WHERE return_stage IN ('awaiting_return_doc', 'return_submitted');

-- Note: The group_id column already exists from multi_item_transactions.sql
