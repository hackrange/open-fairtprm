-- Migration: Add Cyber Todo Support
-- Date: 2024
-- Description: Add last_annual_review column and cyber_todo_activities table
-- Note: Uses IF NOT EXISTS / IF EXISTS so this is safe to re-run.

-- Add last_annual_review column to vendor_onboarding_requests
ALTER TABLE vendor_onboarding_requests
ADD COLUMN IF NOT EXISTS last_annual_review DATETIME DEFAULT NULL COMMENT 'Last annual review date with stakeholder';

-- Create cyber_todo_activities table for tracking activities on todo items
CREATE TABLE IF NOT EXISTS cyber_todo_activities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Reference to the related entity
    todo_type ENUM('cert_expiry', 'srs_rescore', 'score_drop', 'annual_review', 'not_approved', 'not_tiered', 'custom', 'user_import', 'vendor_import') NOT NULL,
    reference_type VARCHAR(50) NOT NULL COMMENT 'Table name: vendor_assessments, vendor_onboarding_requests, etc.',
    reference_id INT UNSIGNED NOT NULL COMMENT 'ID of the related record',

    -- Activity details
    activity_type ENUM('note', 'action', 'status_change', 'reminder', 'import', 'revert') DEFAULT 'note',
    title VARCHAR(255) DEFAULT NULL,
    description TEXT NOT NULL,
    metadata JSON DEFAULT NULL COMMENT 'Stores import data for revert capability',

    -- Status tracking
    status ENUM('open', 'in_progress', 'closed', 'deferred', 'reverted') DEFAULT 'open',
    due_date DATE DEFAULT NULL,
    closed_at DATETIME DEFAULT NULL,

    -- User tracking
    created_by INT UNSIGNED NOT NULL,
    closed_by INT UNSIGNED DEFAULT NULL,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Indexes
    INDEX idx_todo_type (todo_type),
    INDEX idx_reference (reference_type, reference_id),
    INDEX idx_status (status),
    INDEX idx_created_by (created_by),
    INDEX idx_due_date (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create index on last_annual_review for faster queries
CREATE INDEX IF NOT EXISTS idx_last_annual_review ON vendor_onboarding_requests(last_annual_review);
