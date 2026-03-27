-- Migration: Add Cyber Todo Snoozes table
-- Date: 2025
-- Description: Stores snooze state for dashboard to-do items
-- Note: Uses IF NOT EXISTS so this is safe to re-run.

CREATE TABLE IF NOT EXISTS cyber_todo_snoozes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    todo_type VARCHAR(50) NOT NULL,
    reference_type VARCHAR(50) NOT NULL,
    reference_id INT UNSIGNED NOT NULL,
    snoozed_until DATETIME NOT NULL,
    snoozed_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_unique_snooze (todo_type, reference_type, reference_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
