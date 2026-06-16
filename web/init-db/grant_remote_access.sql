-- =============================================================================
-- TPRM Database Initialization
-- Grants remote access and seeds default ACL data
-- =============================================================================

-- Grant access from localhost and Docker internal network
GRANT ALL PRIVILEGES ON tprm.* TO 'tprm_user'@'localhost' IDENTIFIED BY 'CHANGEME_USER_PASSWORD';
GRANT ALL PRIVILEGES ON tprm.* TO 'tprm_user'@'127.0.0.1' IDENTIFIED BY 'CHANGEME_USER_PASSWORD';

-- Grant access from RFC 1918 private networks only
-- 10.0.0.0/8
GRANT ALL PRIVILEGES ON tprm.* TO 'tprm_user'@'10.%' IDENTIFIED BY 'CHANGEME_USER_PASSWORD';
-- 172.16.0.0/12 (172.16.x.x - 172.31.x.x)
GRANT ALL PRIVILEGES ON tprm.* TO 'tprm_user'@'172.16.%' IDENTIFIED BY 'CHANGEME_USER_PASSWORD';
GRANT ALL PRIVILEGES ON tprm.* TO 'tprm_user'@'172.17.%' IDENTIFIED BY 'CHANGEME_USER_PASSWORD';
GRANT ALL PRIVILEGES ON tprm.* TO 'tprm_user'@'172.18.%' IDENTIFIED BY 'CHANGEME_USER_PASSWORD';
GRANT ALL PRIVILEGES ON tprm.* TO 'tprm_user'@'172.19.%' IDENTIFIED BY 'CHANGEME_USER_PASSWORD';
GRANT ALL PRIVILEGES ON tprm.* TO 'tprm_user'@'172.20.%' IDENTIFIED BY 'CHANGEME_USER_PASSWORD';
GRANT ALL PRIVILEGES ON tprm.* TO 'tprm_user'@'172.21.%' IDENTIFIED BY 'CHANGEME_USER_PASSWORD';
GRANT ALL PRIVILEGES ON tprm.* TO 'tprm_user'@'172.22.%' IDENTIFIED BY 'CHANGEME_USER_PASSWORD';
GRANT ALL PRIVILEGES ON tprm.* TO 'tprm_user'@'172.23.%' IDENTIFIED BY 'CHANGEME_USER_PASSWORD';
GRANT ALL PRIVILEGES ON tprm.* TO 'tprm_user'@'172.24.%' IDENTIFIED BY 'CHANGEME_USER_PASSWORD';
GRANT ALL PRIVILEGES ON tprm.* TO 'tprm_user'@'172.25.%' IDENTIFIED BY 'CHANGEME_USER_PASSWORD';
GRANT ALL PRIVILEGES ON tprm.* TO 'tprm_user'@'172.26.%' IDENTIFIED BY 'CHANGEME_USER_PASSWORD';
GRANT ALL PRIVILEGES ON tprm.* TO 'tprm_user'@'172.27.%' IDENTIFIED BY 'CHANGEME_USER_PASSWORD';
GRANT ALL PRIVILEGES ON tprm.* TO 'tprm_user'@'172.28.%' IDENTIFIED BY 'CHANGEME_USER_PASSWORD';
GRANT ALL PRIVILEGES ON tprm.* TO 'tprm_user'@'172.29.%' IDENTIFIED BY 'CHANGEME_USER_PASSWORD';
GRANT ALL PRIVILEGES ON tprm.* TO 'tprm_user'@'172.30.%' IDENTIFIED BY 'CHANGEME_USER_PASSWORD';
GRANT ALL PRIVILEGES ON tprm.* TO 'tprm_user'@'172.31.%' IDENTIFIED BY 'CHANGEME_USER_PASSWORD';
-- 192.168.0.0/16
GRANT ALL PRIVILEGES ON tprm.* TO 'tprm_user'@'192.168.%' IDENTIFIED BY 'CHANGEME_USER_PASSWORD';

FLUSH PRIVILEGES;

-- =============================================================================
-- DEFAULT DATA
-- =============================================================================

-- Default admin user (password replaced by entrypoint.sh with generated password)
INSERT INTO users (username, password_hash, email, full_name, is_active, is_admin, is_super_admin, totp_enabled)
VALUES (
    'admin',
    'CHANGEME_ADMIN_HASH',
    'admin@example.com',
    'System Administrator',
    1,
    1,
    1,
    0
) ON DUPLICATE KEY UPDATE username = username;

-- Default ACL groups
INSERT INTO acl_groups (group_name, display_name, description, is_active) VALUES
('administrator', 'Administrator', 'Full administrative access to all features', 1),
('cyber_tprm', 'Cyber TPRM', 'Cyber Third Party Risk Management team - can manage vendor assessments and FAIR analysis', 1),
('procurement', 'Procurement', 'Procurement team - can create and manage vendor onboarding requests', 1),
('stakeholder', 'Stakeholder', 'Stakeholders - can view and update assigned vendor onboarding requests', 1)
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name);

-- Assign admin to administrator group
INSERT IGNORE INTO user_acl_groups (user_id, group_id, assigned_by)
VALUES (1, 1, 1);

-- Default ACL permissions
INSERT INTO acl_permissions (permission_code, name, module, resource, action, description) VALUES
-- Onboarding permissions
('onboarding.create', 'Create Onboarding Request', 'onboarding', 'request', 'create', 'Create new vendor onboarding requests'),
('onboarding.read', 'View All Onboarding Requests', 'onboarding', 'request', 'read', 'View all vendor onboarding requests'),
('onboarding.read_own', 'View Own Onboarding Requests', 'onboarding', 'request', 'read_own', 'View own vendor onboarding requests'),
('onboarding.read_assigned', 'View Assigned Onboarding Requests', 'onboarding', 'request', 'read_assigned', 'View assigned vendor onboarding requests'),
('onboarding.update', 'Update All Onboarding Requests', 'onboarding', 'request', 'update', 'Update all vendor onboarding requests'),
('onboarding.update_own', 'Update Own Onboarding Requests', 'onboarding', 'request', 'update_own', 'Update own vendor onboarding requests'),
('onboarding.update_assigned', 'Update Assigned Onboarding Requests', 'onboarding', 'request', 'update_assigned', 'Update assigned vendor onboarding requests'),
('onboarding.deactivate', 'Deactivate Onboarding Requests', 'onboarding', 'request', 'deactivate', 'Deactivate vendor onboarding requests'),
('onboarding.delete', 'Delete Onboarding Requests', 'onboarding', 'request', 'delete', 'Delete vendor onboarding requests'),
('onboarding.assign_stakeholder', 'Assign Stakeholders', 'onboarding', 'request', 'assign_stakeholder', 'Assign stakeholders to vendor onboarding requests'),
-- Analysis permissions
('analysis.create', 'Create FAIR Analysis', 'analysis', 'fair', 'create', 'Create FAIR risk analyses'),
('analysis.read', 'View FAIR Analysis', 'analysis', 'fair', 'read', 'View FAIR risk analyses'),
-- Assessment permissions
('assessment.create', 'Create Assessments', 'assessment', 'vendor', 'create', 'Create vendor security assessments'),
('assessment.read', 'View Assessments', 'assessment', 'vendor', 'read', 'View vendor security assessments'),
('assessment.update', 'Update Assessments', 'assessment', 'vendor', 'update', 'Update vendor security assessments'),
('assessment.delete', 'Delete Assessments', 'assessment', 'vendor', 'delete', 'Delete vendor security assessments'),
-- SRS permissions
('srs.view', 'View SRS Scores', 'srs', 'score', 'read', 'View vendor SRS security scores'),
('srs.rescore', 'Trigger Rescore', 'srs', 'score', 'update', 'Manually trigger vendor rescoring'),
-- Annual Review permissions
('annual_review.read', 'View All Annual Reviews', 'annual_review', 'review', 'read', 'View all vendor annual reviews'),
('annual_review.create', 'Complete Annual Reviews', 'annual_review', 'review', 'create', 'Complete annual vendor reviews'),
('annual_review.read_assigned', 'View Assigned Annual Reviews', 'annual_review', 'review', 'read_assigned', 'View annual reviews for assigned vendors only')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- =============================================================================
-- ASSIGN PERMISSIONS TO GROUPS
-- =============================================================================

-- Administrator gets ALL permissions
INSERT IGNORE INTO acl_group_permissions (group_id, permission_id, granted_by)
SELECT (SELECT id FROM acl_groups WHERE group_name = 'administrator'), id, 1 FROM acl_permissions;

-- Cyber TPRM gets all permissions
INSERT IGNORE INTO acl_group_permissions (group_id, permission_id, granted_by)
SELECT (SELECT id FROM acl_groups WHERE group_name = 'cyber_tprm'), id, 1 FROM acl_permissions;

-- Procurement gets create, read all, update own, assign stakeholder, view analysis, view assessments, view SRS, annual reviews
INSERT IGNORE INTO acl_group_permissions (group_id, permission_id, granted_by)
SELECT (SELECT id FROM acl_groups WHERE group_name = 'procurement'), id, 1
FROM acl_permissions WHERE permission_code IN (
    'onboarding.create', 'onboarding.read', 'onboarding.update_own',
    'onboarding.assign_stakeholder', 'analysis.read', 'assessment.read', 'srs.view',
    'annual_review.read', 'annual_review.create'
);

-- Stakeholder gets create, read own, read assigned, update own, update assigned, annual review assigned
INSERT IGNORE INTO acl_group_permissions (group_id, permission_id, granted_by)
SELECT (SELECT id FROM acl_groups WHERE group_name = 'stakeholder'), id, 1
FROM acl_permissions WHERE permission_code IN (
    'onboarding.create', 'onboarding.read_own', 'onboarding.read_assigned',
    'onboarding.update_own', 'onboarding.update_assigned',
    'annual_review.read_assigned', 'annual_review.create'
);

-- =============================================================================
-- APPLICATION CONFIGURATION DEFAULTS
-- =============================================================================

INSERT INTO app_config (config_key, config_value, is_encrypted, description) VALUES
-- Authentication settings
('auth_type', 'local', 0, 'Authentication type: local or saml'),
('session_timeout', '3600', 0, 'Session timeout in seconds'),
('max_login_attempts', '5', 0, 'Maximum failed login attempts before lockout'),
('lockout_duration', '1800', 0, 'Account lockout duration in seconds'),
('password_min_length', '12', 0, 'Minimum password length'),
('require_password_complexity', '1', 0, 'Require complex passwords'),
('totp_issuer', 'TPRM FAIR Analysis', 0, 'TOTP issuer name'),
('app_timezone', 'America/New_York', 0, 'Application timezone for displaying dates and times'),

-- FAIR Analysis cost settings
('pii_breach_cost_per_record', '160', 0, 'Cost per PII record if breached'),
('spii_breach_cost_per_record', '200', 0, 'Cost per SPII record if breached'),
('sox_breach_penalty', '5000000', 0, 'SOX compliance penalty if breached'),

-- Theme/Branding settings
('logo_url', 'app/images/logo-default-418x78.png', 0, 'Application-wide header logo URL'),
('footer_logo_url', 'app/images/logo-inverse-416x78.png', 0, 'Application-wide footer logo URL'),
('header_color', '#35a0a3', 0, 'Application-wide header color'),
('footer_color', '#1a365d', 0, 'Application-wide footer color'),
('button_color', '#35a0a3', 0, 'Application-wide button color'),
('nav_fill_color', '#e9ecef', 0, 'Application-wide navigation fill color'),
('nav_font_color', '#1f1e1e', 0, 'Application-wide navigation font color'),
('nav_width', '220', 0, 'Application-wide navigation width in pixels'),

-- AI Integration settings
('openwebui_enabled', '0', 0, 'Enable/disable OpenWebUI AI integration'),
('openwebui_api_url', 'https://your-openwebui-instance.com/api/chat/completions', 0, 'OpenWebUI API endpoint URL'),
('openwebui_jwt_token', '', 1, 'OpenWebUI JWT authentication token (encrypted)'),
('openwebui_model', 'novita.google/gemma-3-27b-it', 0, 'OpenWebUI model for generation'),
('openwebui_temperature', '0.7', 0, 'Model temperature (0.0-1.0)'),
('openwebui_max_tokens', '500', 0, 'Maximum tokens for response generation'),
('fair_ai_enabled', '1', 0, 'Enable AI-Assisted FAIR Analysis button'),

-- Vendor Revalidation settings
('revalidation_tier1_frequency_days', '30', 0, 'Tier 1 vendor revalidation frequency in days'),
('revalidation_tier2_frequency_days', '90', 0, 'Tier 2 vendor revalidation frequency in days'),
('revalidation_tier3_frequency_days', '365', 0, 'Tier 3 vendor revalidation frequency in days'),
('revalidation_reminder_days', '7', 0, 'Send reminder email X days before revalidation due'),
('revalidation_escalation_days', '14', 0, 'Escalate if overdue by X days'),

-- UpGuard SRS Integration settings
('upguard_enabled', '0', 0, 'Whether UpGuard SRS integration is enabled'),
('upguard_api_key', '', 1, 'UpGuard API key (encrypted)'),
('upguard_scoring_method', 'range', 0, 'Scoring method: range or percentage'),
('upguard_max_score', '950', 0, 'Maximum possible score'),
('upguard_grade_a_min', '850', 0, 'Minimum score for grade A'),
('upguard_grade_b_min', '700', 0, 'Minimum score for grade B'),
('upguard_grade_c_min', '500', 0, 'Minimum score for grade C'),
('upguard_grade_d_min', '300', 0, 'Minimum score for grade D'),
('upguard_tier1_days', '30', 0, 'Tier 1 rescore interval in days'),
('upguard_tier2_days', '90', 0, 'Tier 2 rescore interval in days'),
('upguard_tier3_days', '365', 0, 'Tier 3 rescore interval in days'),
('upguard_trending_days', '90', 0, 'Number of days for trend charts'),

-- Email/SMTP settings for annual review reminders
('email_enabled', '0', 0, 'Enable/disable email notifications (0=disabled, 1=enabled)'),
('smtp_host', 'localhost', 0, 'SMTP server hostname'),
('smtp_port', '25', 0, 'SMTP server port (25, 587, 465)'),
('smtp_username', '', 0, 'SMTP authentication username'),
('smtp_password', '', 1, 'SMTP authentication password (encrypted)'),
('smtp_encryption', 'none', 0, 'SMTP encryption type (none, tls, ssl)'),
('email_from_email', 'noreply@example.com', 0, 'From email address for system notifications'),
('email_from_name', 'TPRM System', 0, 'From name for system notifications')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- =============================================================================
-- ASSESSMENT TEMPLATES DATA
-- =============================================================================

INSERT INTO assessment_templates (name, slug, description, allow_certificate_upload, certificate_upload_prompt, is_active) VALUES
('ISO 27001:2022 Assessment', 'iso-27001-2022', 'Comprehensive information security management system assessment based on ISO 27001:2022 standard.', 1, 'If your organization holds a valid ISO 27001:2022 certification, you may upload it here to skip the detailed assessment. Please ensure the certificate is current and includes your organization name.', 1),
('Tier 2 Vendor Assessment', 'tier-2-vendor', 'Streamlined security assessment for lower-risk vendor relationships.', 0, NULL, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);
