-- SQL Migration: Add approved_by columns and other fixes
-- Date: 2026-02-10
-- Purpose: Track which admin approved/rejected loans, requests, returns, and suggestions

-- Add approved_by to loans table
ALTER TABLE loans ADD COLUMN IF NOT EXISTS approved_by INT NULL DEFAULT NULL AFTER approved_at;

-- Add return_approved_by to loans table  
ALTER TABLE loans ADD COLUMN IF NOT EXISTS return_approved_by INT NULL DEFAULT NULL AFTER returned_at;

-- Add return_approved_at to loans table (if not exists)
ALTER TABLE loans ADD COLUMN IF NOT EXISTS return_approved_at TIMESTAMP NULL DEFAULT NULL AFTER return_approved_by;

-- Add approved_by to requests table
ALTER TABLE requests ADD COLUMN IF NOT EXISTS approved_by INT NULL DEFAULT NULL AFTER approved_at;

-- Add rejected_by to loans table
ALTER TABLE loans ADD COLUMN IF NOT EXISTS rejected_by INT NULL DEFAULT NULL;

-- Add rejected_by to requests table
ALTER TABLE requests ADD COLUMN IF NOT EXISTS rejected_by INT NULL DEFAULT NULL;
