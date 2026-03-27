-- Migration: Add assigned_to column to cyber_todo_activities
-- Date: 2026-02
-- Description: Allows todo activities to be assigned to specific users
-- Note: Uses IF NOT EXISTS so this is safe to re-run.

ALTER TABLE cyber_todo_activities ADD COLUMN IF NOT EXISTS assigned_to INT UNSIGNED DEFAULT NULL COMMENT 'User this activity is assigned to';
CREATE INDEX IF NOT EXISTS idx_assigned_to ON cyber_todo_activities(assigned_to);
