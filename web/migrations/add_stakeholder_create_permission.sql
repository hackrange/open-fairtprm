-- Migration: Add onboarding.create permission to stakeholder group
-- and add missing columns to tprm_results
-- Run this on existing databases to update permissions

-- Add missing columns to tprm_results (will error if column exists, which is OK)
-- Run each separately, ignore errors for columns that already exist

-- vendor_cyber_insurance_coverage
ALTER TABLE tprm_results
ADD COLUMN vendor_cyber_insurance_coverage TEXT
COMMENT 'Encrypted: Vendor cyber insurance coverage amount'
AFTER third_party_compliance_questionnaire;

-- total_cost_of_breach
ALTER TABLE tprm_results
ADD COLUMN total_cost_of_breach TEXT
COMMENT 'Encrypted: Total cost of breach calculation'
AFTER compliance_fines;

-- pii_breach_cost
ALTER TABLE tprm_results
ADD COLUMN pii_breach_cost TEXT
COMMENT 'Encrypted: PII breach cost calculation'
AFTER total_cost_of_breach;

-- spii_breach_cost
ALTER TABLE tprm_results
ADD COLUMN spii_breach_cost TEXT
COMMENT 'Encrypted: SPII breach cost calculation'
AFTER pii_breach_cost;

-- sox_breach_cost
ALTER TABLE tprm_results
ADD COLUMN sox_breach_cost TEXT
COMMENT 'Encrypted: SOX breach cost calculation'
AFTER spii_breach_cost;

-- Add create, read_own, and update_own permissions to stakeholder group
INSERT IGNORE INTO acl_group_permissions (group_id, permission_id)
SELECT
    (SELECT id FROM acl_groups WHERE group_name = 'stakeholder'),
    id
FROM acl_permissions
WHERE permission_code IN (
    'onboarding.create',
    'onboarding.read_own',
    'onboarding.update_own'
);

-- Verify administrator group has all permissions (in case it's missing any)
INSERT IGNORE INTO acl_group_permissions (group_id, permission_id, granted_by)
SELECT
    (SELECT id FROM acl_groups WHERE group_name = 'administrator'),
    id,
    1
FROM acl_permissions;

-- Verify cyber_tprm group has all permissions (in case it's missing any)
INSERT IGNORE INTO acl_group_permissions (group_id, permission_id, granted_by)
SELECT
    (SELECT id FROM acl_groups WHERE group_name = 'cyber_tprm'),
    id,
    1
FROM acl_permissions;

-- Verify procurement group has onboarding.create permission
INSERT IGNORE INTO acl_group_permissions (group_id, permission_id)
SELECT
    (SELECT id FROM acl_groups WHERE group_name = 'procurement'),
    id
FROM acl_permissions
WHERE permission_code = 'onboarding.create';
