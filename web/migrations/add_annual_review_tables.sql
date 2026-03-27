-- Migration: Add Annual Vendor Review System
-- Date: 2026-02-05
-- Author: Tim Rice - Hack Range
-- Disclaimer: Use this software at your own risk. No warranty provided.
--
-- What this does: Adds all the database tables needed for the annual vendor review system.
-- This includes the reviews table (tracks who reviewed what and when), reminders table
-- (tracks what emails we've sent), permissions for the ACL system, and SMTP config
-- for sending those reminder emails.

-- =============================================================================
-- SECTION 1: UPDATE VENDOR ONBOARDING REQUESTS TABLE
-- =============================================================================

-- Add last_annual_review_due column to track next review due date (if not exists)
SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'vendor_onboarding_requests'
    AND COLUMN_NAME = 'last_annual_review_due'
);

SET @sql = IF(@column_exists = 0,
    'ALTER TABLE vendor_onboarding_requests ADD COLUMN last_annual_review_due DATE DEFAULT NULL COMMENT ''Next annual review due date (calculated from last review or approval date)''',
    'SELECT ''Column last_annual_review_due already exists'' AS msg'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create index for efficient due date queries
CREATE INDEX IF NOT EXISTS idx_last_annual_review_due ON vendor_onboarding_requests(last_annual_review_due);

-- =============================================================================
-- SECTION 2: VENDOR ANNUAL REVIEWS TABLE
-- =============================================================================

-- Complete review history with all responses
CREATE TABLE IF NOT EXISTS vendor_annual_reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendor_request_id INT UNSIGNED NOT NULL,
    review_date DATETIME NOT NULL,
    due_date DATE NOT NULL,
    reviewer_user_id INT UNSIGNED NOT NULL,

    -- Review responses
    is_still_stakeholder ENUM('yes', 'no') NOT NULL,
    new_stakeholder_id INT UNSIGNED DEFAULT NULL COMMENT 'If stakeholder changed, new stakeholder user ID',
    scope_changes TEXT COMMENT 'Description of any scope changes',
    contact_updates JSON DEFAULT NULL COMMENT 'JSON object with updated contact information',
    review_notes TEXT COMMENT 'Additional notes from reviewer',

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Indexes
    INDEX idx_vendor_request (vendor_request_id),
    INDEX idx_review_date (review_date),
    INDEX idx_due_date (due_date),
    INDEX idx_reviewer (reviewer_user_id),

    -- Foreign key
    FOREIGN KEY (vendor_request_id) REFERENCES vendor_onboarding_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 3: VENDOR REVIEW REMINDERS TABLE
-- =============================================================================

-- Track email reminder history to prevent duplicates
CREATE TABLE IF NOT EXISTS vendor_review_reminders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendor_request_id INT UNSIGNED NOT NULL,
    stakeholder_user_id INT UNSIGNED NOT NULL,
    reminder_type ENUM('30_days_before', 'due_date', 'overdue') NOT NULL,
    due_date DATE NOT NULL,
    sent_at DATETIME DEFAULT NULL,
    email_sent_to VARCHAR(255) DEFAULT NULL,
    status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
    error_message TEXT,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Indexes
    INDEX idx_vendor_request (vendor_request_id),
    INDEX idx_stakeholder (stakeholder_user_id),
    INDEX idx_status (status),
    INDEX idx_sent_at (sent_at),

    -- Unique constraint to prevent duplicate reminders
    UNIQUE KEY unique_reminder (vendor_request_id, stakeholder_user_id, reminder_type, due_date),

    -- Foreign key
    FOREIGN KEY (vendor_request_id) REFERENCES vendor_onboarding_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 4: ACL PERMISSIONS
-- =============================================================================

-- Add annual review permissions
INSERT INTO acl_permissions (permission_code, name, module, resource, action, description) VALUES
('annual_review.read', 'View All Annual Reviews', 'annual_review', 'review', 'read', 'View all vendor annual reviews'),
('annual_review.create', 'Complete Annual Reviews', 'annual_review', 'review', 'create', 'Complete annual vendor reviews'),
('annual_review.read_assigned', 'View Assigned Annual Reviews', 'annual_review', 'review', 'read_assigned', 'View annual reviews for assigned vendors only')
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

-- Grant all annual review permissions to Administrator
INSERT IGNORE INTO acl_group_permissions (group_id, permission_id, granted_by)
SELECT (SELECT id FROM acl_groups WHERE group_name = 'administrator'), id, 1
FROM acl_permissions WHERE permission_code LIKE 'annual_review.%';

-- Grant all annual review permissions to Cyber TPRM
INSERT IGNORE INTO acl_group_permissions (group_id, permission_id, granted_by)
SELECT (SELECT id FROM acl_groups WHERE group_name = 'cyber_tprm'), id, 1
FROM acl_permissions WHERE permission_code LIKE 'annual_review.%';

-- Grant read and create to Procurement
INSERT IGNORE INTO acl_group_permissions (group_id, permission_id, granted_by)
SELECT (SELECT id FROM acl_groups WHERE group_name = 'procurement'), id, 1
FROM acl_permissions WHERE permission_code IN ('annual_review.read', 'annual_review.create');

-- Grant read_assigned and create to Stakeholder
INSERT IGNORE INTO acl_group_permissions (group_id, permission_id, granted_by)
SELECT (SELECT id FROM acl_groups WHERE group_name = 'stakeholder'), id, 1
FROM acl_permissions WHERE permission_code IN ('annual_review.read_assigned', 'annual_review.create');

-- =============================================================================
-- SECTION 5: SMTP EMAIL CONFIGURATION
-- =============================================================================

-- Add SMTP configuration entries to app_config
INSERT INTO app_config (config_key, config_value, is_encrypted, description) VALUES
('email_enabled', '0', 0, 'Enable/disable email notifications (0=disabled, 1=enabled)'),
('smtp_host', 'localhost', 0, 'SMTP server hostname'),
('smtp_port', '25', 0, 'SMTP server port (25, 587, 465)'),
('smtp_username', '', 0, 'SMTP authentication username'),
('smtp_password', '', 1, 'SMTP authentication password (encrypted)'),
('smtp_encryption', 'none', 0, 'SMTP encryption type (none, tls, ssl)'),
('email_from_email', 'noreply@example.com', 0, 'From email address for system notifications'),
('email_from_name', 'TPRM System', 0, 'From name for system notifications')
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

-- =============================================================================
-- MIGRATION COMPLETE
-- =============================================================================
