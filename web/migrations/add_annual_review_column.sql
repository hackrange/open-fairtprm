-- Migration: Add last_annual_review column to vendor_onboarding_requests
-- Date: 2026-02-04
-- Description: Adds tracking for annual vendor reviews used in Cyber Todo dashboard

-- Check if column exists and add if missing
SET @dbname = DATABASE();
SET @tablename = 'vendor_onboarding_requests';
SET @columnname = 'last_annual_review';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  "SELECT 1",
  CONCAT("ALTER TABLE ", @tablename, " ADD COLUMN ", @columnname, " DATETIME DEFAULT NULL COMMENT 'Date of last annual vendor review' AFTER last_autosave")
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;
