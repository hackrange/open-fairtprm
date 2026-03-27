-- Add 'not_tiered' to the todo_type ENUM in cyber_todo_activities table
-- This allows tracking vendors that haven't been assigned a tier yet
-- Date: 2026-02-04

ALTER TABLE cyber_todo_activities 
MODIFY COLUMN todo_type ENUM(
    'cert_expiry', 
    'srs_rescore', 
    'score_drop', 
    'annual_review', 
    'not_approved', 
    'not_tiered', 
    'custom'
) NOT NULL;
